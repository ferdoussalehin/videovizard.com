<?php
$logFile = 'a_debug.log';

if (!file_exists($logFile)) {
    die("Log file not found");
}

$content = file_get_contents($logFile);

// Clean the content
$content = str_replace("\0", '[NULL]', $content); // Show null bytes
$encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);

if ($encoding && $encoding !== 'UTF-8') {
    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
}

// Header
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Safe Log Viewer</title>
    <style>
        body { 
            font-family: monospace; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            padding: 20px;
            margin: 0;
        }
        .info {
            background: #2d2d30;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #007acc;
        }
        .log {
            white-space: pre-wrap;
            word-wrap: break-word;
            background: #252526;
            padding: 20px;
            border-radius: 5px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .null { color: #ff0000; font-weight: bold; }
        .control { color: #ffa500; }
    </style>
</head>
<body>
    <div class="info">
        <strong>File:</strong> <?php echo $logFile; ?><br>
        <strong>Size:</strong> <?php echo number_format(filesize($logFile)); ?> bytes<br>
        <strong>Encoding:</strong> <?php echo $encoding ?: 'Unknown'; ?><br>
        <strong>Null bytes:</strong> <?php echo substr_count($content, '[NULL]'); ?><br>
        <a href="?download=1" style="color: #4ec9b0;">Download Raw</a>
    </div>
    <div class="log"><?php 
        // Highlight special characters
        $content = str_replace('[NULL]', '<span class="null">[NULL]</span>', $content);
        echo htmlspecialchars($content, ENT_QUOTES, 'UTF-8'); 
    ?></div>
</body>
</html>

<?php
// Handle download
if (isset($_GET['download'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="a_debug.log"');
    readfile($logFile);
    exit;
}
?>