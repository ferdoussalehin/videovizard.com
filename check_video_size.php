<?php
// check_video_size_once.php
// Script to check ONLY 20 videos under 5MB and stop

require_once 'dbconnect_hdb.php';

$BATCH_SIZE = 100;
$VIDEO_FOLDER = __DIR__ . '/podcast_videos/';
$MIN_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

set_time_limit(0);

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

function parseFilename($filename) {
    $info = [];
    
    if (preg_match('/(\d{3,4})[x_](\d{3,4})/', $filename, $matches)) {
        $info['resolution'] = $matches[1] . 'x' . $matches[2];
    } else {
        $info['resolution'] = 'Unknown';
    }
    
    if (preg_match('/(\d+)fps/i', $filename, $matches)) {
        $info['fps'] = $matches[1] . 'fps';
    } else {
        $info['fps'] = 'Unknown';
    }
    
    if (stripos($filename, 'uhd') !== false || stripos($filename, '4k') !== false) {
        $info['quality'] = '4K UHD';
    } elseif (stripos($filename, 'hd') !== false) {
        $info['quality'] = 'HD';
    } elseif (stripos($filename, 'sd') !== false) {
        $info['quality'] = 'SD';
    } else {
        $info['quality'] = 'Standard';
    }
    
    return $info;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Size Checker - Batch Process</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        .summary-card {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .summary-card.success .number { color: #10b981; }
        .summary-card.warning .number { color: #f59e0b; }
        .summary-card.danger .number { color: #ef4444; }
        .table-container {
            padding: 20px;
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            position: sticky;
            top: 0;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        tr:hover { background: #f8f9fa; }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-under { background: #dcfce7; color: #166534; }
        .status-above { background: #fee2e2; color: #991b1b; }
        .status-error { background: #fef3c7; color: #92400e; }
        .footer {
            padding: 20px 30px;
            background: #f8f9fa;
            text-align: center;
            border-top: 1px solid #e0e0e0;
        }
        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        .refresh-btn:hover { background: #5a67d8; }
        .info-box {
            background: #e0e7ff;
            padding: 10px 20px;
            margin: 10px 20px;
            border-radius: 8px;
            text-align: center;
            color: #3730a3;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎬 Video Size Checker</h1>
        <p>Processing <?php echo $BATCH_SIZE; ?> videos at a time</p>
    </div>

<?php
// Check database connection
if (!$conn) {
    echo "<div class='summary'><div class='summary-card'><div class='number' style='color:red;'>ERROR</div><div class='label'>Database connection failed</div></div></div>";
    exit;
}

// Get pending count before processing
$pendingQuery = "SELECT COUNT(*) as pending FROM hdb_image_data WHERE (resize_flag = 0 OR resize_flag IS NULL) AND media_type = 'video' AND image_name LIKE '%.mp4%'";
$pendingResult = mysqli_query($conn, $pendingQuery);
$pendingCount = ($pendingResult) ? mysqli_fetch_assoc($pendingResult)['pending'] : 0;

$infoMessage = "";
if ($pendingCount == 0) {
    $infoMessage = "✅ No pending videos to process!";
} else {
    $infoMessage = "📊 Found $pendingCount pending videos. Processing only the first " . min($BATCH_SIZE, $pendingCount) . " videos in this batch.";
}

echo "<div class='info-box'>$infoMessage</div>";

// Get counts for summary
$totalQuery = "SELECT COUNT(*) as total FROM hdb_image_data WHERE media_type = 'video' AND image_name LIKE '%.mp4%'";
$totalResult = mysqli_query($conn, $totalQuery);
$totalVideos = ($totalResult) ? mysqli_fetch_assoc($totalResult)['total'] : 0;

$markedQuery = "SELECT COUNT(*) as marked FROM hdb_image_data WHERE resize_flag = 1 AND media_type = 'video'";
$markedResult = mysqli_query($conn, $markedQuery);
$alreadyMarked = ($markedResult) ? mysqli_fetch_assoc($markedResult)['marked'] : 0;

$errorQuery = "SELECT COUNT(*) as error FROM hdb_image_data WHERE resize_flag = 2 AND media_type = 'video'";
$errorResult = mysqli_query($conn, $errorQuery);
$errorVideos = ($errorResult) ? mysqli_fetch_assoc($errorResult)['error'] : 0;

echo "<div class='summary'>";
echo "
    <div class='summary-card'>
        <div class='number'>{$totalVideos}</div>
        <div class='label'>Total Videos</div>
    </div>
    <div class='summary-card success'>
        <div class='number'>{$alreadyMarked}</div>
        <div class='label'>Already Marked (&lt;5MB)</div>
    </div>
    <div class='summary-card warning'>
        <div class='number'>{$pendingCount}</div>
        <div class='label'>Pending Check</div>
    </div>
    <div class='summary-card danger'>
        <div class='number'>{$errorVideos}</div>
        <div class='label'>Errors</div>
    </div>
";
echo "</div>";

// ONLY PROCESS ONE BATCH (20 rows) - NO LOOP
echo "<div class='table-container'>";
echo "<table>";
echo "<thead>";
echo "<tr>";
echo "<th>ID</th><th>Filename</th><th>Quality</th><th>Resolution</th><th>FPS</th><th>File Size</th><th>Status</th><th>Action</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

// Query for only 20 videos
$query = "SELECT id, image_name, resize_flag, file_size 
          FROM hdb_image_data 
          WHERE (resize_flag = 0 OR resize_flag IS NULL)
            AND media_type = 'video' 
            AND image_name LIKE '%.mp4%'
          ORDER BY id ASC
          LIMIT $BATCH_SIZE";

$result = mysqli_query($conn, $query);

if (!$result) {
    echo "<tr><td colspan='8' style='text-align:center;color:red;'>Database error: " . mysqli_error($conn) . "</td></tr>";
} else {
    $rowCount = mysqli_num_rows($result);
    $processed = 0;
    $marked = 0;
    $errors = 0;
    
    if ($rowCount == 0) {
        echo "<tr><td colspan='8' style='text-align:center; padding: 40px;'>✅ No pending videos to check!</td></tr>";
    } else {
        while ($row = mysqli_fetch_assoc($result)) {
            $id = $row['id'];
            $filename = $row['image_name'];
            $filepath = $VIDEO_FOLDER . $filename;
            $fileInfo = parseFilename($filename);
            
            $sizeFormatted = 'N/A';
            $status = '';
            $statusClass = '';
            $action = '';
            
            if (!file_exists($filepath)) {
                $sizeFormatted = 'File Not Found';
                $status = 'ERROR';
                $statusClass = 'status-error';
                $action = '⚠ Marked Error';
                $errors++;
                
                $updateQuery = "UPDATE hdb_image_data SET resize_flag = 2, error_message = 'File not found' WHERE id = $id";
                mysqli_query($conn, $updateQuery);
            } else {
                $fileSizeBytes = filesize($filepath);
                $sizeFormatted = formatFileSize($fileSizeBytes);
                
                if ($fileSizeBytes < $MIN_SIZE_BYTES) {
                    $status = 'UNDER 5MB';
                    $statusClass = 'status-under';
                    $action = '✓ Marked for Resize';
                    $marked++;
                    
                    $updateQuery = "UPDATE hdb_image_data SET resize_flag = '1', file_size = $fileSizeBytes WHERE id = $id";
                    mysqli_query($conn, $updateQuery);
                } else {
                    $status = 'ABOVE 5MB';
                    $statusClass = 'status-above';
                    $action = '○ Skipped';
                    
                    $updateQuery = "UPDATE hdb_image_data SET resize_flag = '3', file_size = $fileSizeBytes WHERE id = $id";
                    mysqli_query($conn, $updateQuery);
                }
            }
            
            echo "<tr>";
            echo "<td>{$id}</td>";
            echo "<td title='{$filename}' style='max-width:250px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;'>{$filename}</td>";
            echo "<td>{$fileInfo['quality']}</td>";
            echo "<td>{$fileInfo['resolution']}</td>";
            echo "<td>{$fileInfo['fps']}</td>";
            echo "<tr><strong>{$sizeFormatted}</strong></td>";
            echo "<td><span class='status-badge {$statusClass}'>{$status}</span></td>";
            echo "<td>{$action}</td>";
            echo "<tr>";
            
            $processed++;
        }
    }
}

echo "</tbody>";
echo "</table>";
echo "</div>";

// Get final counts after processing
$finalMarkedQuery = "SELECT COUNT(*) as marked FROM hdb_image_data WHERE resize_flag = 1 AND media_type = 'video'";
$finalMarkedResult = mysqli_query($conn, $finalMarkedQuery);
$finalMarked = ($finalMarkedResult) ? mysqli_fetch_assoc($finalMarkedResult)['marked'] : $alreadyMarked;

$remainingQuery = "SELECT COUNT(*) as remaining FROM hdb_image_data WHERE (resize_flag = 0 OR resize_flag IS NULL) AND media_type = 'video' AND image_name LIKE '%.mp4%'";
$remainingResult = mysqli_query($conn, $remainingQuery);
$remainingCount = ($remainingResult) ? mysqli_fetch_assoc($remainingResult)['remaining'] : 0;

?>

<div class="footer">
    <p><strong>📊 This Batch Summary</strong></p>
    <p>Processed: <strong><?php echo isset($processed) ? $processed : 0; ?></strong> videos | 
       Marked for resize: <strong style="color:#10b981;"><?php echo isset($marked) ? $marked : 0; ?></strong> | 
       Errors: <strong style="color:#ef4444;"><?php echo isset($errors) ? $errors : 0; ?></strong></p>
    
    <?php if ($remainingCount > 0): ?>
        <p style="margin-top: 10px; color: #f59e0b;">
            ⚠️ <strong><?php echo $remainingCount; ?></strong> videos still pending. 
            <a href="?">Click here</a> to process the next batch.
        </p>
    <?php else: ?>
        <p style="margin-top: 10px; color: #10b981;">✅ All videos have been processed!</p>
    <?php endif; ?>
    
    <button class="refresh-btn" onclick="location.reload()">🔄 Process Next <?php echo $BATCH_SIZE; ?> Videos</button>
    <p style="margin-top: 15px; font-size: 11px;">Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
</div>

</div>
</body>
</html>

<?php
mysqli_close($conn);
?>