<?php
// check_missing_files.php
// Run this script to find files in database that don't exist on disk

ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
set_time_limit(300);

require_once 'dbconnect_hdb.php';

// Define folders
$imageFolder = __DIR__ . '/podcast_images/';
$videoFolder = __DIR__ . '/podcast_videos/';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Check Missing Files</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        h1 { color: #333; }
        .summary { background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        .missing { background: #fee2e2; border-left: 4px solid #dc2626; }
        .exists { background: #f0fdf4; border-left: 4px solid #16a34a; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        th { background: #1e293b; color: #fff; padding: 12px; text-align: left; }
        td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f8fafc; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .status-missing { background: #dc2626; color: #fff; }
        .status-exists { background: #16a34a; color: #fff; }
        .status-skip { background: #f59e0b; color: #fff; }
        .actions { margin-top: 20px; display: flex; gap: 10px; }
        button { padding: 10px 20px; background: #3b82f6; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        button:hover { background: #2563eb; }
        .delete-btn { background: #dc2626; }
        .delete-btn:hover { background: #b91c1c; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin-bottom: 20px; border-radius: 6px; }
    </style>
</head>
<body>
<h1>🔍 File Existence Checker</h1>
";

// Query all records from hdb_image_data
$query = "SELECT id, image_name, media_type, skip_embedding, admin_id, created_at 
          FROM hdb_image_data 
          ORDER BY media_type, image_name";
$result = mysqli_query($conn, $query);

if (!$result) {
    die("Query failed: " . mysqli_error($conn));
}

$total = 0;
$existing = 0;
$missing = 0;
$skipped = 0;
$missingFiles = [];

while ($row = mysqli_fetch_assoc($result)) {
    $total++;
    $filename = $row['image_name'];
    $mediaType = strtolower(trim($row['media_type'] ?? ''));
    $skipEmbedding = (int)($row['skip_embedding'] ?? 0);
    
    // Determine folder based on media_type or file extension
    if ($mediaType === 'video') {
        $folder = $videoFolder;
    } elseif ($mediaType === 'image') {
        $folder = $imageFolder;
    } else {
        // Fallback: check by extension
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'])) {
            $folder = $videoFolder;
            $mediaType = 'video';
        } else {
            $folder = $imageFolder;
            $mediaType = 'image';
        }
    }
    
    $filepath = $folder . $filename;
    $exists = file_exists($filepath);
    
    if ($exists) {
        $existing++;
    } else {
        $missing++;
        $missingFiles[] = [
            'id' => $row['id'],
            'filename' => $filename,
            'media_type' => $mediaType,
            'folder' => $folder,
            'skip_embedding' => $skipEmbedding,
            'admin_id' => $row['admin_id'],
            'created_at' => $row['created_at']
        ];
    }
}

// Summary
echo "<div class='summary'>";
echo "<h2>📊 Summary</h2>";
echo "<p><strong>Total records in database:</strong> " . $total . "</p>";
echo "<p style='color: #16a34a;'><strong>✅ Files exist on disk:</strong> " . $existing . "</p>";
echo "<p style='color: #dc2626;'><strong>❌ Missing files:</strong> " . $missing . "</p>";
echo "<p><strong>Skip embedding count:</strong> " . $skipped . "</p>";
echo "</div>";

if ($missing > 0) {
    echo "<div class='warning'>";
    echo "⚠️ <strong>Warning:</strong> " . $missing . " files are in the database but not found on disk!";
    echo "</div>";
    
    echo "<h2>❌ Missing Files List</h2>";
    echo "<table>";
    echo "<thead>";
    echo "<th>ID</th>";
    echo "<th>Filename</th>";
    echo "<th>Media Type</th>";
    echo "<th>Expected Path</th>";
    echo "<th>Skip Embedding</th>";
    echo "<th>Admin ID</th>";
    echo "<th>Created At</th>";
    echo "<th>Action</th>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($missingFiles as $file) {
        $path = $file['folder'] . $file['filename'];
        echo "<tr class='missing'>";
        echo "<td>" . $file['id'] . "</td>";
        echo "<td><strong>" . htmlspecialchars($file['filename']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($file['media_type']) . "</td>";
        echo "<td style='font-family: monospace; font-size: 11px;'>" . htmlspecialchars($path) . "</td>";
        echo "<td>" . ($file['skip_embedding'] ? 'Yes' : 'No') . "</td>";
        echo "<td>" . $file['admin_id'] . "</td>";
        echo "<td>" . $file['created_at'] . "</td>";
        echo "<td>";
        echo "<form method='POST' style='display: inline;' onsubmit='return confirm(\"Delete record for " . addslashes($file['filename']) . " from database?\");'>";
        echo "<input type='hidden' name='delete_id' value='" . $file['id'] . "'>";
        echo "<input type='hidden' name='delete_filename' value='" . htmlspecialchars($file['filename']) . "'>";
        echo "<button type='submit' name='action' value='delete_record' style='background:#dc2626; padding:5px 10px; font-size:12px;'>🗑️ Delete from DB</button>";
        echo "</form>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
    echo "<div class='actions'>";
    echo "<form method='POST' onsubmit='return confirm(\"Delete ALL missing records from database? This action cannot be undone.\");'>";
    echo "<button type='submit' name='action' value='delete_all_missing' class='delete-btn'>🗑️ Delete All Missing Records</button>";
    echo "</form>";
    echo "</div>";
    
} else {
    echo "<div class='summary' style='background: #f0fdf4; border-left: 4px solid #16a34a;'>";
    echo "✅ <strong>All files in database exist on disk!</strong>";
    echo "</div>";
}

// Handle deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'delete_record' && isset($_POST['delete_id'])) {
        $deleteId = (int)$_POST['delete_id'];
        $deleteFilename = mysqli_real_escape_string($conn, $_POST['delete_filename']);
        
        $deleteQuery = "DELETE FROM hdb_image_data WHERE id = $deleteId";
        if (mysqli_query($conn, $deleteQuery)) {
          //  echo "<script>alert('Deleted record for: " . addslashes($deleteFilename) . "'); window.location.href = window.location.pathname;</script>";
        } else {
            echo "<script>alert('Delete failed: " . mysqli_error($conn) . "');</script>";
        }
    }
    
    if ($action === 'delete_all_missing') {
        $ids = array_column($missingFiles, 'id');
        if (!empty($ids)) {
            $idsList = implode(',', $ids);
            $deleteQuery = "DELETE FROM hdb_image_data WHERE id IN ($idsList)";
            if (mysqli_query($conn, $deleteQuery)) {
                echo "<script>alert('Deleted " . count($ids) . " missing records'); window.location.href = window.location.pathname;</script>";
            } else {
                echo "<script>alert('Delete failed: " . mysqli_error($conn) . "');</script>";
            }
        }
    }
}

echo "
<script>
function showPath(path) {
    alert('Expected path: ' + path);
}
</script>
</body>
</html>";
?>