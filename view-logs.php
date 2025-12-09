<?php
require __DIR__ . '/../../..//includes/load-yourls.php';
require __DIR__ . '/config.php';


// Sprawdź hasło (to samo co w install-db.php)
$admin_password = '1';
if (!isset($_GET['key']) || $_GET['key'] !== $admin_password) {
    die('Unauthorized. Add ?key=PASSWORD');
}

$log_file = __DIR__ . '/debug.log';
$lines = isset($_GET['lines']) ? intval($_GET['lines']) : 100;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>CCS Debug Logs</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            margin: 0;
        }
        .header {
            background: #2d2d30;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .log-content {
            background: #252526;
            padding: 20px;
            border-radius: 4px;
            white-space: pre-wrap;
            font-size: 12px;
            line-height: 1.5;
            max-height: 80vh;
            overflow-y: auto;
        }
        .timestamp { color: #4ec9b0; }
        .error { color: #f48771; }
        .success { color: #4fc1ff; }
        .separator { color: #6a737d; }
        .btn {
            background: #0e639c;
            color: white;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            margin-right: 10px;
        }
    </style>
    <meta http-equiv="refresh" content="60">
</head>
<body>
    <div class="header">
        <h2>🔍 CCS Debug Logs</h2>
        <a href="?key=<?php echo $admin_password; ?>&lines=50" class="btn">Last 50</a>
        <a href="?key=<?php echo $admin_password; ?>&lines=100" class="btn">Last 100</a>
        <a href="?key=<?php echo $admin_password; ?>&lines=500" class="btn">Last 500</a>
        <a href="?key=<?php echo $admin_password; ?>&clear=1" class="btn" style="background: #c5000b;">Clear Logs</a>
        <p style="margin: 10px 0 0 0; font-size: 12px;">
            Auto-refresh: 5s | File: <code><?php echo basename($log_file); ?></code>
        </p>
    </div>
    
    <div class="log-content">
<?php
if (isset($_GET['clear'])) {
    file_put_contents($log_file, '');
    echo "Logs cleared.\n";
} elseif (file_exists($log_file)) {
    $content = file_get_contents($log_file);
    $all_lines = explode("\n", $content);
    $last_lines = array_slice($all_lines, -$lines);
    
    foreach ($last_lines as $line) {
        if (preg_match('/^\[([\d\-: ]+)\]/', $line)) {
            echo '<span class="timestamp">' . htmlspecialchars($line) . '</span>' . "\n";
        } elseif (strpos($line, '---') !== false) {
            echo '<span class="separator">' . htmlspecialchars($line) . '</span>' . "\n";
        } elseif (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
            echo '<span class="error">' . htmlspecialchars($line) . '</span>' . "\n";
        } elseif (stripos($line, 'success') !== false) {
            echo '<span class="success">' . htmlspecialchars($line) . '</span>' . "\n";
        } else {
            echo htmlspecialchars($line) . "\n";
        }
    }
} else {
    echo "No log file found.\n";
    echo "File expected at: $log_file\n";
}
?>
    </div>
</body>
</html>
