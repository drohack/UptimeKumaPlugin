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
$dbpath = $cfg['DBPATH'] ?? '';
$action = $_GET['action'] ?? 'fetch';

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
    $cutoffTime = gmdate('Y-m-d H:i:s', time() - $cutoffSeconds);

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

// Unknown action
echo json_encode(['error' => "Unknown action: {$action}"]);
