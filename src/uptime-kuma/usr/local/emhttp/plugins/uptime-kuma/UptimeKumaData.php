<?php
/**
 * Uptime Kuma Dashboard Widget - AJAX Backend
 *
 * Reads Uptime Kuma's SQLite database directly and returns
 * monitor status and uptime data as JSON.
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

// Allow overriding dbpath via query param (for Test Connection before saving)
$dbpath = $_GET['dbpath'] ?? $cfg['DBPATH'] ?? '';

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
 * Open the Uptime Kuma SQLite database in read-only mode.
 *
 * @param string $path Path to kuma.db
 * @return SQLite3
 */
function openKumaDb(string $path): SQLite3 {
    if (!file_exists($path)) {
        throw new Exception("Database file not found: {$path}");
    }
    if (!is_readable($path)) {
        throw new Exception("Database file not readable: {$path}. Check permissions.");
    }

    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);

    // Verify this is an Uptime Kuma database
    $result = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='monitor'");
    if (!$result) {
        $db->close();
        throw new Exception("Not a valid Uptime Kuma database (missing 'monitor' table).");
    }

    $result = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='heartbeat'");
    if (!$result) {
        $db->close();
        throw new Exception("Not a valid Uptime Kuma database (missing 'heartbeat' table).");
    }

    return $db;
}

// ---- Action: test ----
if ($action === 'test') {
    try {
        $db = openKumaDb($dbpath);
        $monitorCount = $db->querySingle("SELECT COUNT(*) FROM monitor WHERE active = 1");
        $heartbeatCount = $db->querySingle("SELECT COUNT(*) FROM heartbeat");
        $db->close();

        echo json_encode([
            'success' => true,
            'message' => "Connection successful. Found {$monitorCount} active monitor(s) and {$heartbeatCount} heartbeat record(s).",
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---- Action: fetch ----
if ($action === 'fetch') {
    $period = $_GET['period'] ?? ($cfg['DEFAULTPERIOD'] ?? '24h');
    $maxMonitors = (int)($cfg['MAXMONITORS'] ?? 50);

    if (!isset($periods[$period])) {
        echo json_encode(['error' => "Invalid period: {$period}"]);
        exit;
    }

    $cutoffSeconds = $periods[$period];
    $cutoffTime = date('Y-m-d H:i:s', time() - $cutoffSeconds);

    try {
        $db = openKumaDb($dbpath);

        // Get active monitors with their current status and uptime %
        $sql = "
            SELECT
                m.id,
                m.name,
                m.type,
                m.url,
                m.hostname,
                m.port,
                (SELECT h.status
                 FROM heartbeat h
                 WHERE h.monitor_id = m.id
                 ORDER BY h.time DESC
                 LIMIT 1) AS current_status,
                (SELECT h.ping
                 FROM heartbeat h
                 WHERE h.monitor_id = m.id
                 ORDER BY h.time DESC
                 LIMIT 1) AS last_ping,
                (SELECT ROUND(
                    100.0 * SUM(CASE WHEN h2.status = 1 THEN 1 ELSE 0 END) / COUNT(*), 2
                 )
                 FROM heartbeat h2
                 WHERE h2.monitor_id = m.id
                   AND h2.time >= :cutoff) AS uptime_pct,
                (SELECT COUNT(*)
                 FROM heartbeat h3
                 WHERE h3.monitor_id = m.id
                   AND h3.time >= :cutoff2) AS heartbeat_count
            FROM monitor m
            WHERE m.active = 1
            ORDER BY
                CASE
                    WHEN (SELECT h4.status FROM heartbeat h4 WHERE h4.monitor_id = m.id ORDER BY h4.time DESC LIMIT 1) = 0 THEN 0
                    WHEN (SELECT h5.status FROM heartbeat h5 WHERE h5.monitor_id = m.id ORDER BY h5.time DESC LIMIT 1) = 3 THEN 1
                    WHEN (SELECT h6.status FROM heartbeat h6 WHERE h6.monitor_id = m.id ORDER BY h6.time DESC LIMIT 1) = 2 THEN 2
                    ELSE 3
                END ASC,
                m.name ASC
            LIMIT :maxMonitors
        ";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoffTime, SQLITE3_TEXT);
        $stmt->bindValue(':cutoff2', $cutoffTime, SQLITE3_TEXT);
        $stmt->bindValue(':maxMonitors', $maxMonitors, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $monitors = [];
        $totalMonitors = $db->querySingle("SELECT COUNT(*) FROM monitor WHERE active = 1");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $monitors[] = [
                'id'             => (int)$row['id'],
                'name'           => $row['name'],
                'type'           => $row['type'],
                'url'            => $row['url'] ?: $row['hostname'],
                'port'           => $row['port'],
                'status'         => $row['current_status'] !== null ? (int)$row['current_status'] : null,
                'lastPing'       => $row['last_ping'] !== null ? round((float)$row['last_ping'], 1) : null,
                'uptimePct'      => $row['uptime_pct'] !== null ? (float)$row['uptime_pct'] : null,
                'heartbeatCount' => (int)$row['heartbeat_count'],
            ];
        }

        $stmt->close();
        $db->close();

        echo json_encode([
            'monitors'      => $monitors,
            'period'        => $period,
            'totalMonitors' => (int)$totalMonitors,
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
        $db = openKumaDb($dbpath);
        $result = $db->query("SELECT id, name, type, active FROM monitor ORDER BY name ASC");
        $monitors = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $monitors[] = [
                'id'     => (int)$row['id'],
                'name'   => $row['name'],
                'type'   => $row['type'],
                'active' => (int)$row['active'],
            ];
        }
        $db->close();
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
    $cutoffTime = date('Y-m-d H:i:s', time() - $cutoffSeconds);
    $bucketSize = $bucketSizes[$period];
    $numBuckets = (int)ceil($cutoffSeconds / $bucketSize);

    try {
        $db = openKumaDb($dbpath);

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
            SELECT m.id, m.name, m.type, m.url, m.hostname, m.port,
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
        $monStmt = $db->prepare($monSql);
        $monResult = $monStmt->execute();

        $monitors = [];
        $monitorIds = [];
        while ($row = $monResult->fetchArray(SQLITE3_ASSOC)) {
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
        $monStmt->close();

        if (!empty($monitorIds)) {
            // Fetch heartbeats for all monitors in the period
            $idList = implode(',', $monitorIds);
            $beatSql = "
                SELECT monitor_id, status, time, msg, ping
                FROM heartbeat
                WHERE monitor_id IN ({$idList})
                  AND time >= :cutoff
                ORDER BY time ASC
            ";
            $beatStmt = $db->prepare($beatSql);
            $beatStmt->bindValue(':cutoff', $cutoffTime, SQLITE3_TEXT);
            $beatResult = $beatStmt->execute();

            // Collect raw beats per monitor
            $rawBeats = [];
            foreach ($monitorIds as $mid) {
                $rawBeats[$mid] = [];
            }
            while ($beat = $beatResult->fetchArray(SQLITE3_ASSOC)) {
                $mid = (int)$beat['monitor_id'];
                $rawBeats[$mid][] = $beat;
            }
            $beatStmt->close();

            // Bucket beats for each monitor
            $now = time();
            $periodStart = $now - $cutoffSeconds;

            foreach ($monitorIds as $mid) {
                $buckets = [];
                // Initialize empty buckets
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
                    // Update ping to latest in bucket
                    if ($beat['ping'] !== null) {
                        $bucket['ping'] = round((float)$beat['ping'], 1);
                    }
                    unset($bucket);
                }

                // Convert buckets to output format
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

        $totalMonitors = $db->querySingle("SELECT COUNT(*) FROM monitor WHERE active = 1");
        $db->close();

        echo json_encode([
            'monitors'      => array_values($monitors),
            'period'        => $period,
            'totalMonitors' => (int)$totalMonitors,
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Unknown action
echo json_encode(['error' => "Unknown action: {$action}"]);
