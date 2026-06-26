<?php
/**
 * Debug Log Viewer
 * View and manage a_debug.log file
 */

// Configuration
$logFile = 'a_debug.log';  // Change this to your log file path

// Handle actions
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'delete':
            if (file_exists($logFile)) {
                unlink($logFile);
                $message = "✓ Log file deleted successfully!";
            } else {
                $message = "⚠ Log file doesn't exist.";
            }
            break;
            
        case 'download':
            if (file_exists($logFile)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="a_debug.log"');
                readfile($logFile);
                exit;
            }
            break;
    }
}

// Read log file
$logContent = '';
$fileSize = 0;
$lineCount = 0;

if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    $fileSize = filesize($logFile);
    $lineCount = count(file($logFile));
} else {
    $logContent = 'Log file not found or empty.';
}

// Format file size
function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Log Viewer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .header-info {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-refresh {
            background: #4CAF50;
            color: white;
        }
        
        .btn-refresh:hover {
            background: #45a049;
            transform: translateY(-2px);
        }
        
        .btn-download {
            background: #2196F3;
            color: white;
        }
        
        .btn-download:hover {
            background: #0b7dda;
            transform: translateY(-2px);
        }
        
        .btn-delete {
            background: #f44336;
            color: white;
        }
        
        .btn-delete:hover {
            background: #da190b;
            transform: translateY(-2px);
        }
        
        .info-bar {
            background: #f8f9fa;
            padding: 15px 30px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 14px;
            color: #666;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-item strong {
            color: #333;
        }
        
        .message {
            margin: 20px 30px;
            padding: 15px;
            border-radius: 5px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .log-content {
            padding: 30px;
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .log-content::-webkit-scrollbar {
            width: 10px;
        }
        
        .log-content::-webkit-scrollbar-track {
            background: #2d2d2d;
        }
        
        .log-content::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 5px;
        }
        
        .log-content::-webkit-scrollbar-thumb:hover {
            background: #666;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 30px;
            color: #999;
        }
        
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .actions {
                width: 100%;
            }
            
            .btn {
                flex: 1;
                justify-content: center;
            }
            
            .info-bar {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>🔍 Debug Log Viewer</h1>
                <div class="header-info">a_debug.log</div>
            </div>
            <div class="actions">
                <a href="?action=refresh" class="btn btn-refresh">
                    <span>🔄</span> Refresh
                </a>
                <a href="?action=download" class="btn btn-download">
                    <span>📥</span> Download
                </a>
				<!--
				<a href="?action=delete" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete the log file?');">
                -->
                <a href="?action=delete" class="btn btn-delete" ;">
                    <span>🗑️</span> Delete Log
                </a>
            </div>
        </div>
        
        <?php if (file_exists($logFile)): ?>
            <div class="info-bar">
                <div class="info-item">
                    <strong>Size:</strong> <?php echo formatBytes($fileSize); ?>
                </div>
                <div class="info-item">
                    <strong>Lines:</strong> <?php echo number_format($lineCount); ?>
                </div>
                <div class="info-item">
                    <strong>Last Modified:</strong> <?php echo date('Y-m-d H:i:s', filemtime($logFile)); ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($message)): ?>
            <div class="message <?php echo strpos($message, '✓') !== false ? 'success' : 'warning'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (file_exists($logFile) && $fileSize > 0): ?>
            <div class="log-content"><?php echo htmlspecialchars($logContent); ?></div>
        <?php else: ?>
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h2>No Log Data</h2>
                <p>The log file is empty or doesn't exist yet.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-scroll to bottom of log
        window.addEventListener('load', function() {
            const logContent = document.querySelector('.log-content');
            if (logContent) {
                logContent.scrollTop = logContent.scrollHeight;
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + R = Refresh
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.href = '?action=refresh';
            }
            // Ctrl/Cmd + D = Download
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                window.location.href = '?action=download';
            }
        });
    </script>
</body>
</html>