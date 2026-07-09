<?php
/**
 * Uptime Kuma Dashboard Widget - AJAX Backend
 *
 * Reads Uptime Kuma's database and returns monitor status and uptime data
 * as JSON. Supports SQLite (read directly, read-only) and MariaDB — both
 * v2's embedded MariaDB and an external MariaDB — queried read-only through
 * the Uptime Kuma container's own mariadb client via docker exec.
 */

header('Content-Type: application/json');

$plugin = "uptime-kuma";
$cfgfile = "/boot/config/plugins/{$plugin}/uptime-kuma.cfg";

// Load configuration
if (!file_exists($cfgfile)) {
    echo json_encode(['error' => 'Plugin not configured. Visit Settings > Uptime Kuma.']);
    exit;
}

$cfg = parse_ini_file($cfgfile);
$action = $_GET['action'] ?? 'fetch';

// Allow overriding dbpath/container via query param (for Test Connection before saving)
$dbpath = $_GET['dbpath'] ?? $cfg['DBPATH'] ?? '';
$container = trim($_GET['container'] ?? ($cfg['CONTAINER'] ?? ''));

// Sanitize dbpath to prevent path traversal
if (!empty($dbpath)) {
    $resolved = realpath($dbpath);
    if ($resolved !== false) {
        $dbpath = $resolved;
    }
}

// Valid time periods and their cutoff in seconds
$periods = [
    '1h'   => 3600,
    '12h'  => 43200,
    '24h'  => 86400,
    '7d'   => 604800,
    '30d'  => 2592000,
    '90d'  => 7776000,
    '180d' => 15552000,
];

/**
 * Thrown when the MariaDB client rejects the credentials, so the embedded
 * backend can retry with its other candidate user.
 */
class KumaAuthException extends Exception {}

/**
 * Run a shell command with stdout/stderr captured separately, so client
 * warnings on stderr can never corrupt result parsing.
 *
 * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
 */
function kumaShellRun(string $cmd): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new Exception("Failed to execute command.");
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [$rc, $stdout, $stderr];
}

/**
 * Common query surface over the two database backends, so the actions and
 * the bucketing/aggregation logic stay single-path.
 */
abstract class KumaBackend {
    /**
     * Run a SELECT and return all rows as associative arrays.
     *
     * @param string[] $textCols column aliases that carry free text and must
     *                           survive tabs/newlines (base64-wrapped on MariaDB)
     */
    abstract public function queryAll(string $sql, array $textCols = []): array;

    /**
     * SQL expression to select a free-text column. Alias it in the query
     * and list the alias in queryAll()'s $textCols.
     */
    abstract public function textExpr(string $col): string;

    abstract public function tableExists(string $name): bool;

    /** Human-readable backend name for the Test Connection message. */
    abstract public function describe(): string;

    public function close(): void {}

    /** First column of the first row, or null when there are no rows. */
    public function querySingle(string $sql) {
        $rows = $this->queryAll($sql);
        if (empty($rows)) {
            return null;
        }
        return reset($rows[0]);
    }

    /**
     * Detect Uptime Kuma version (1 or 2).
     * v2 migrates heartbeat data into stat_hourly/stat_daily aggregate tables.
     */
    public function detectKumaVersion(): int {
        try {
            // `key` is a reserved word on MariaDB; backticks also parse on SQLite
            $state = $this->querySingle("SELECT value FROM setting WHERE `key` = 'migrateAggregateTableState'");
            if ($state === 'migrated') {
                return 2;
            }
        } catch (Exception $e) {
            // setting table missing or unreadable - fall through to table check
        }
        return $this->tableExists('stat_hourly') ? 2 : 1;
    }

    protected function validateSchema(): void {
        if (!$this->tableExists('monitor')) {
            throw new Exception("Not a valid Uptime Kuma database (missing 'monitor' table).");
        }
        if (!$this->tableExists('heartbeat')) {
            throw new Exception("Not a valid Uptime Kuma database (missing 'heartbeat' table).");
        }
    }
}

class SqliteBackend extends KumaBackend {
    private SQLite3 $db;

    public function __construct(string $path) {
        if (!file_exists($path)) {
            throw new Exception("Database file not found. Check the path in settings.");
        }
        if (!is_readable($path)) {
            throw new Exception("Database file not readable. Check permissions.");
        }
        $this->db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $this->db->busyTimeout(3000);
        $this->validateSchema();
    }

    public function queryAll(string $sql, array $textCols = []): array {
        $result = $this->db->query($sql);
        if ($result === false) {
            throw new Exception("Query failed: " . $this->db->lastErrorMsg());
        }
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function textExpr(string $col): string {
        return $col;
    }

    public function tableExists(string $name): bool {
        $r = $this->db->querySingle(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($name) . "'"
        );
        return (bool)$r;
    }

    public function describe(): string {
        return "SQLite";
    }

    public function close(): void {
        $this->db->close();
    }
}

/**
 * Queries MariaDB through the Uptime Kuma container's own mariadb client
 * (docker exec), since Unraid's PHP ships no mysql driver. Embedded MariaDB
 * is reached over its unix socket inside the container; an external MariaDB
 * with the connection details Uptime Kuma stores in db-config.json.
 */
class MariadbBackend extends KumaBackend {
    private string $container;
    private array $dbConfig;
    private bool $embedded;
    /**
     * @var string[] users to try. Embedded MariaDB authenticates by unix-socket
     * peer uid: docker exec runs as the image's default user (root on the
     * official image), so root is tried first; node covers non-root variants.
     */
    private array $userCandidates;
    private string $flavor;

    public function __construct(array $dbConfig, string $datadir, string $containerOverride) {
        $this->dbConfig = $dbConfig;
        $this->embedded = ($dbConfig['type'] === 'embedded-mariadb');
        $this->flavor = $this->embedded ? 'embedded MariaDB' : 'external MariaDB';
        $this->userCandidates = $this->embedded
            ? ['root', 'node']
            : [(string)($dbConfig['username'] ?? 'kuma')];

        $this->container = $containerOverride !== '' ? $containerOverride : self::detectContainer($datadir);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $this->container)) {
            throw new Exception("Invalid container name.");
        }

        $this->validateSchema(); // also proves docker + connectivity work
    }

    public function queryAll(string $sql, array $textCols = []): array {
        $raw = $this->runClient($sql);
        if (trim($raw) === '') {
            return []; // no rows: the client prints nothing, not even a header
        }
        $lines = explode("\n", rtrim($raw, "\n"));
        $headers = explode("\t", array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $vals = explode("\t", $line);
            $row = [];
            foreach ($headers as $i => $h) {
                $v = $vals[$i] ?? null;
                if (in_array($h, $textCols, true)) {
                    // '#' is the NULL sentinel from textExpr() - never valid base64
                    $row[$h] = ($v === null || $v === '#') ? null : base64_decode($v);
                } else {
                    $row[$h] = ($v === 'NULL') ? null : $v;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function textExpr(string $col): string {
        // TO_BASE64 keeps tabs/newlines in values from breaking the tab-separated
        // output; REPLACE strips the newlines TO_BASE64 itself inserts every 76
        // chars; '#' marks NULL (not in the base64 alphabet, so unambiguous).
        return "IFNULL(REPLACE(TO_BASE64({$col}), '\\n', ''), '#')";
    }

    public function tableExists(string $name): bool {
        $r = $this->querySingle(
            "SELECT table_name FROM information_schema.tables " .
            "WHERE table_schema = DATABASE() AND table_name = '" . addslashes($name) . "'"
        );
        return $r !== null;
    }

    public function describe(): string {
        return $this->flavor;
    }

    private function runClient(string $sql): string {
        foreach ($this->userCandidates as $i => $user) {
            try {
                $out = $this->dockerExec($sql, $user);
                $this->userCandidates = [$user]; // remember the working user
                return $out;
            } catch (KumaAuthException $e) {
                if ($i === count($this->userCandidates) - 1) {
                    throw new Exception($this->embedded
                        ? "MariaDB inside the container denied access (tried users 'node' and 'root')."
                        : "MariaDB denied access with the credentials from db-config.json.");
                }
            }
        }
        throw new Exception("MariaDB query failed."); // unreachable
    }

    private function dockerExec(string $sql, string $user): string {
        $cmd = 'docker exec';

        $password = (string)($this->dbConfig['password'] ?? '');
        if (!$this->embedded && $password !== '') {
            // Name-only -e forwards the value from our environment: the
            // password never appears on any command line, host or container.
            putenv('MYSQL_PWD=' . $password);
            $cmd .= ' -e MYSQL_PWD';
        }

        $args = ['--batch', '--default-character-set=utf8mb4', '-u', $user];
        if ($this->embedded) {
            array_push($args, '--socket=/app/data/run/mariadb.sock', '-D', 'kuma');
        } else {
            if (!empty($this->dbConfig['socketPath'])) {
                $args[] = '--socket=' . $this->dbConfig['socketPath'];
            } else {
                array_push($args, '-h', (string)($this->dbConfig['hostname'] ?? 'localhost'),
                                   '-P', (string)($this->dbConfig['port'] ?? 3306));
            }
            array_push($args, '-D', (string)($this->dbConfig['dbName'] ?? 'kuma'));
        }

        $cmd .= ' ' . escapeshellarg($this->container) . ' mariadb';
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' -e ' . escapeshellarg($sql);

        [$rc, $stdout, $stderr] = kumaShellRun($cmd);
        putenv('MYSQL_PWD'); // clear from our environment again

        if ($rc !== 0) {
            $err = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            $this->throwFriendly($err);
        }
        return $stdout;
    }

    private function throwFriendly(string $err): void {
        $c = $this->container;
        if (stripos($err, 'No such container') !== false) {
            throw new Exception("Could not find the Docker container '{$c}'. Set Container Name in settings.");
        }
        if (stripos($err, 'is not running') !== false || stripos($err, 'not running') !== false) {
            throw new Exception("Uptime Kuma container '{$c}' is not running. MariaDB data is only readable while it runs.");
        }
        if (stripos($err, 'Access denied') !== false) {
            throw new KumaAuthException($err);
        }
        if (stripos($err, "Can't connect") !== false || stripos($err, 'ERROR 2002') !== false) {
            throw new Exception("Could not reach MariaDB inside container '{$c}'. It may still be starting up.");
        }
        if (stripos($err, 'executable file not found') !== false) {
            throw new Exception("No mariadb client found inside container '{$c}'. Is this the Uptime Kuma v2 container?");
        }
        // Never leak credentials: report only the first line, truncated
        $first = strtok($err, "\n");
        throw new Exception("Database query failed: " . substr($first !== false ? $first : 'unknown error', 0, 200));
    }

    /**
     * Find the running Uptime Kuma container: prefer the one whose /app/data
     * mount matches the configured data folder (tolerating /mnt/user vs
     * /mnt/cache|diskN views of the same share), else fall back to the image
     * or container name containing "uptime"/"kuma".
     */
    private static function detectContainer(string $datadir): string {
        [$rc, $stdout, ] = kumaShellRun('docker ps --format ' . escapeshellarg('{{.Names}}\t{{.Image}}'));
        if ($rc !== 0) {
            throw new Exception("Could not query Docker for running containers.");
        }

        $wanted = self::normalizeDataPath($datadir);
        $fallback = '';
        foreach (explode("\n", trim($stdout)) as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $image] = array_pad(explode("\t", $line, 2), 2, '');
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
                continue;
            }

            [$irc, $iout, ] = kumaShellRun(
                'docker inspect --format ' .
                escapeshellarg('{{range .Mounts}}{{.Destination}}|{{.Source}}' . "\n" . '{{end}}') .
                ' ' . escapeshellarg($name)
            );
            if ($irc === 0) {
                foreach (explode("\n", trim($iout)) as $mount) {
                    $parts = explode('|', $mount, 2);
                    if (count($parts) === 2 && $parts[0] === '/app/data'
                            && self::normalizeDataPath($parts[1]) === $wanted) {
                        return $name; // exact data-folder match wins
                    }
                }
            }

            if ($fallback === '' && (stripos($image, 'uptime-kuma') !== false || stripos($name, 'kuma') !== false)) {
                $fallback = $name;
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }
        throw new Exception("Could not find a running Uptime Kuma container. Set Container Name in settings.");
    }

    private static function normalizeDataPath(string $p): string {
        // /mnt/user/appdata/x, /mnt/cache/appdata/x, /mnt/disk1/appdata/x -> appdata/x
        return preg_replace('#^/mnt/[^/]+/#', '', rtrim($p, '/'));
    }
}

/**
 * Open the right backend for the configured path.
 *
 * A .db file is opened as SQLite directly (original behavior). A folder is
 * Uptime Kuma's data directory: db-config.json inside it names the backend
 * (absent on v1 / default installs -> SQLite at <folder>/kuma.db).
 */
function openKumaBackend(string $dbpath, string $containerOverride): KumaBackend {
    if ($dbpath === '') {
        throw new Exception("Database path not configured. Visit Settings > Uptime Kuma.");
    }
    if (is_file($dbpath)) {
        return new SqliteBackend($dbpath);
    }
    if (is_dir($dbpath)) {
        $dir = rtrim($dbpath, '/');
        $type = 'sqlite';
        $dbConfig = null;
        $cfgjson = $dir . '/db-config.json';
        if (is_file($cfgjson) && is_readable($cfgjson)) {
            $dbConfig = json_decode((string)file_get_contents($cfgjson), true);
            if (is_array($dbConfig) && !empty($dbConfig['type']) && is_string($dbConfig['type'])) {
                $type = $dbConfig['type'];
            }
        }
        if ($type === 'sqlite') {
            return new SqliteBackend($dir . '/kuma.db');
        }
        if ($type === 'mariadb' || $type === 'embedded-mariadb') {
            return new MariadbBackend($dbConfig, $dir, $containerOverride);
        }
        throw new Exception("Unsupported database type '{$type}' in db-config.json.");
    }
    throw new Exception("Database file not found. Check the path in settings.");
}

// ---- Action: test ----
if ($action === 'test') {
    try {
        $backend = openKumaBackend($dbpath, $container);
        $kumaVersion = $backend->detectKumaVersion();
        $monitorCount = (int)$backend->querySingle("SELECT COUNT(*) FROM monitor WHERE active = 1");
        $heartbeatCount = (int)$backend->querySingle("SELECT COUNT(*) FROM heartbeat");
        $flavor = $backend->describe();
        $backend->close();

        echo json_encode([
            'success' => true,
            'message' => "Connection successful. Found {$monitorCount} active monitor(s) and {$heartbeatCount} heartbeat record(s). (Uptime Kuma v{$kumaVersion}, {$flavor})",
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---- Action: list ----
// Returns all monitors for the settings page checklist
if ($action === 'list') {
    try {
        $backend = openKumaBackend($dbpath, $container);
        $rows = $backend->queryAll(
            "SELECT id, {$backend->textExpr('name')} AS name, type, active FROM monitor ORDER BY monitor.name ASC",
            ['name']
        );
        $monitors = [];
        foreach ($rows as $row) {
            $monitors[] = [
                'id'     => (int)$row['id'],
                'name'   => $row['name'],
                'type'   => $row['type'],
                'active' => (int)$row['active'],
            ];
        }
        $backend->close();
        echo json_encode(['monitors' => $monitors]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---- Action: webui ----
// Auto-detect Uptime Kuma WebUI URL from Docker template
if ($action === 'webui') {
    $webui = $cfg['WEBUI'] ?? '';
    if (empty($webui)) {
        // Try to auto-detect from Docker templates
        $templateDir = '/boot/config/plugins/dockerMan/templates-user/';
        if (is_dir($templateDir)) {
            $files = glob($templateDir . '*.xml');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (stripos($content, 'uptime') !== false || stripos($content, 'kuma') !== false) {
                    if (preg_match('/<WebUI>(.*?)<\/WebUI>/i', $content, $m)) {
                        $webui = $m[1];
                        // Replace [IP] with server IP
                        $serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $serverIp = preg_replace('/:\d+$/', '', $serverIp);
                        $webui = str_replace('[IP]', $serverIp, $webui);
                        // Replace [PORT:xxxx] with the port
                        $webui = preg_replace('/\[PORT:(\d+)\]/', '$1', $webui);
                        break;
                    }
                }
            }
        }
    }
    // Only return http/https URLs
    if (!empty($webui) && !preg_match('#^https?://#i', $webui)) {
        $webui = '';
    }
    echo json_encode(['webui' => $webui]);
    exit;
}

// ---- Action: beats ----
if ($action === 'beats') {
    $period = $_GET['period'] ?? ($cfg['DEFAULTPERIOD'] ?? '24h');
    $monitorFilter = $cfg['MONITORS'] ?? '';

    if (!isset($periods[$period])) {
        echo json_encode(['error' => "Invalid period: {$period}"]);
        exit;
    }

    // Bucket sizes in seconds for each period (~60-90 bars)
    $bucketSizes = [
        '1h'   => 60,       // 1 min buckets = 60 bars
        '12h'  => 480,      // 8 min buckets = 90 bars
        '24h'  => 960,      // 16 min buckets = 90 bars
        '7d'   => 7200,     // 2 hour buckets = 84 bars
        '30d'  => 28800,    // 8 hour buckets = 90 bars
        '90d'  => 86400,    // 1 day buckets = 90 bars
        '180d' => 172800,   // 2 day buckets = 90 bars
    ];

    $cutoffSeconds = $periods[$period];
    // Uptime Kuma stores timestamps in UTC, so the cutoff must be UTC too.
    // Using local time (date()) on a server ahead of UTC shifts the cutoff
    // forward and drops every beat inside short windows (e.g. 1h). See issue #1.
    $cutoffTime = gmdate('Y-m-d H:i:s', time() - $cutoffSeconds);
    $bucketSize = $bucketSizes[$period];
    $numBuckets = (int)ceil($cutoffSeconds / $bucketSize);

    try {
        $backend = openKumaBackend($dbpath, $container);

        // Build monitor filter
        $monitorWhere = "m.active = 1";
        if (!empty($monitorFilter)) {
            $filterIds = array_map('intval', explode(',', $monitorFilter));
            $filterIds = array_filter($filterIds);
            if (!empty($filterIds)) {
                $idList = implode(',', $filterIds);
                $monitorWhere = "m.id IN ({$idList})";
            }
        }

        // Get monitors
        $monSql = "
            SELECT m.id, {$backend->textExpr('m.name')} AS name, m.type,
                {$backend->textExpr('m.url')} AS url, {$backend->textExpr('m.hostname')} AS hostname, m.port,
                (SELECT h.status FROM heartbeat h WHERE h.monitor_id = m.id ORDER BY h.time DESC LIMIT 1) AS current_status
            FROM monitor m
            WHERE {$monitorWhere}
            ORDER BY
                CASE
                    WHEN (SELECT h2.status FROM heartbeat h2 WHERE h2.monitor_id = m.id ORDER BY h2.time DESC LIMIT 1) = 0 THEN 0
                    WHEN (SELECT h2.status FROM heartbeat h2 WHERE h2.monitor_id = m.id ORDER BY h2.time DESC LIMIT 1) = 3 THEN 1
                    WHEN (SELECT h2.status FROM heartbeat h2 WHERE h2.monitor_id = m.id ORDER BY h2.time DESC LIMIT 1) = 2 THEN 2
                    ELSE 3
                END ASC,
                m.name ASC
        ";
        $monRows = $backend->queryAll($monSql, ['name', 'url', 'hostname']);

        $monitors = [];
        $monitorIds = [];
        foreach ($monRows as $row) {
            $monitors[$row['id']] = [
                'id'     => (int)$row['id'],
                'name'   => $row['name'],
                'type'   => $row['type'],
                'url'    => $row['url'] ?: $row['hostname'],
                'status' => $row['current_status'] !== null ? (int)$row['current_status'] : null,
                'beats'  => [],
                'uptimePct' => null,
            ];
            $monitorIds[] = (int)$row['id'];
        }

        if (!empty($monitorIds)) {
            $kumaVersion = $backend->detectKumaVersion();
            $idList = implode(',', $monitorIds);

            // Determine data source: v2 uses aggregate tables for longer periods
            $useAggregate = false;
            $aggregateTable = '';
            if ($kumaVersion === 2 && in_array($period, ['7d', '30d', '90d', '180d'])) {
                $useAggregate = true;
                $aggregateTable = in_array($period, ['90d', '180d']) ? 'stat_daily' : 'stat_hourly';
            }

            $now = time();
            $periodStart = $now - $cutoffSeconds;

            if ($useAggregate) {
                // ---- v2: Use aggregate tables (stat_hourly or stat_daily) ----
                // stat tables store integer unix timestamps (unlike heartbeat's
                // datetime strings), so the cutoff must be an epoch value too.
                $aggSql = "
                    SELECT monitor_id, timestamp, up, down, ping
                    FROM {$aggregateTable}
                    WHERE monitor_id IN ({$idList})
                      AND timestamp >= {$periodStart}
                    ORDER BY timestamp ASC
                ";
                $aggRows = $backend->queryAll($aggSql);

                // Collect aggregate rows per monitor
                $rawRows = [];
                foreach ($monitorIds as $mid) {
                    $rawRows[$mid] = [];
                }
                foreach ($aggRows as $row) {
                    $mid = (int)$row['monitor_id'];
                    $rawRows[$mid][] = $row;
                }

                foreach ($monitorIds as $mid) {
                    $buckets = [];
                    for ($i = 0; $i < $numBuckets; $i++) {
                        $bucketStart = $periodStart + ($i * $bucketSize);
                        $buckets[$i] = [
                            'status' => null,
                            'time'   => date('Y-m-d H:i', $bucketStart),
                            'msg'    => '',
                            'ping'   => null,
                            'up'     => 0,
                            'down'   => 0,
                        ];
                    }

                    $totalUp = 0;
                    $totalDown = 0;

                    foreach ($rawRows[$mid] as $row) {
                        $rowTime = (int)$row['timestamp'];
                        $bucketIndex = (int)floor(($rowTime - $periodStart) / $bucketSize);
                        if ($bucketIndex < 0) $bucketIndex = 0;
                        if ($bucketIndex >= $numBuckets) $bucketIndex = $numBuckets - 1;

                        $up = (int)($row['up'] ?? 0);
                        $down = (int)($row['down'] ?? 0);
                        $totalUp += $up;
                        $totalDown += $down;

                        $bucket = &$buckets[$bucketIndex];
                        $bucket['up'] += $up;
                        $bucket['down'] += $down;

                        if ($row['ping'] !== null) {
                            $bucket['ping'] = round((float)$row['ping'], 1);
                        }

                        // Determine status: down wins over up
                        if ($down > 0) {
                            $bucket['status'] = 0;
                        } elseif ($up > 0 && $bucket['status'] === null) {
                            $bucket['status'] = 1;
                        }
                        unset($bucket);
                    }

                    $outputBeats = [];
                    foreach ($buckets as $bucket) {
                        $outputBeats[] = [
                            'status' => $bucket['status'],
                            'time'   => $bucket['time'],
                            'msg'    => $bucket['msg'],
                            'ping'   => $bucket['ping'],
                        ];
                    }

                    $monitors[$mid]['beats'] = $outputBeats;
                    $total = $totalUp + $totalDown;
                    $monitors[$mid]['uptimePct'] = $total > 0
                        ? round(100.0 * $totalUp / $total, 2)
                        : null;
                }
            } else {
                // ---- v1 (all periods) or v2 (short periods): Use heartbeat table ----
                $beatSql = "
                    SELECT monitor_id, status, time, {$backend->textExpr('msg')} AS msg, ping
                    FROM heartbeat
                    WHERE monitor_id IN ({$idList})
                      AND time >= '{$cutoffTime}'
                    ORDER BY time ASC
                ";
                $beatRows = $backend->queryAll($beatSql, ['msg']);

                $rawBeats = [];
                foreach ($monitorIds as $mid) {
                    $rawBeats[$mid] = [];
                }
                foreach ($beatRows as $beat) {
                    $mid = (int)$beat['monitor_id'];
                    $rawBeats[$mid][] = $beat;
                }

                foreach ($monitorIds as $mid) {
                    $buckets = [];
                    for ($i = 0; $i < $numBuckets; $i++) {
                        $bucketStart = $periodStart + ($i * $bucketSize);
                        $buckets[$i] = [
                            'status' => null,
                            'time'   => date('Y-m-d H:i', $bucketStart),
                            'msg'    => '',
                            'ping'   => null,
                            'count'  => 0,
                        ];
                    }

                    $upCount = 0;
                    $totalCount = 0;

                    foreach ($rawBeats[$mid] as $beat) {
                        $beatTime = strtotime($beat['time'] . ' UTC');
                        $bucketIndex = (int)floor(($beatTime - $periodStart) / $bucketSize);
                        if ($bucketIndex < 0) $bucketIndex = 0;
                        if ($bucketIndex >= $numBuckets) $bucketIndex = $numBuckets - 1;

                        $beatStatus = (int)$beat['status'];
                        $totalCount++;
                        if ($beatStatus === 1) $upCount++;

                        $bucket = &$buckets[$bucketIndex];
                        $bucket['count']++;

                        // Worst status wins: down(0) > pending(2) > maintenance(3) > up(1)
                        if ($bucket['status'] === null) {
                            $bucket['status'] = $beatStatus;
                            $bucket['msg'] = $beat['msg'] ?? '';
                            $bucket['ping'] = $beat['ping'] !== null ? round((float)$beat['ping'], 1) : null;
                        } elseif ($beatStatus === 0) {
                            $bucket['status'] = 0;
                            $bucket['msg'] = $beat['msg'] ?? '';
                        } elseif ($beatStatus === 2 && $bucket['status'] !== 0) {
                            $bucket['status'] = 2;
                            $bucket['msg'] = $beat['msg'] ?? '';
                        } elseif ($beatStatus === 3 && $bucket['status'] === 1) {
                            $bucket['status'] = 3;
                            $bucket['msg'] = $beat['msg'] ?? '';
                        }
                        if ($beat['ping'] !== null) {
                            $bucket['ping'] = round((float)$beat['ping'], 1);
                        }
                        unset($bucket);
                    }

                    $outputBeats = [];
                    foreach ($buckets as $bucket) {
                        $outputBeats[] = [
                            'status' => $bucket['status'],
                            'time'   => $bucket['time'],
                            'msg'    => $bucket['msg'],
                            'ping'   => $bucket['ping'],
                        ];
                    }

                    $monitors[$mid]['beats'] = $outputBeats;
                    $monitors[$mid]['uptimePct'] = $totalCount > 0
                        ? round(100.0 * $upCount / $totalCount, 2)
                        : null;
                }
            }
        }

        $backend->close();

        echo json_encode([
            'monitors' => array_values($monitors),
            'period'   => $period,
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Unknown action
echo json_encode(['error' => "Unknown action: {$action}"]);
