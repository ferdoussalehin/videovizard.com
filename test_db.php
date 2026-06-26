<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';

echo "<h2>Database Test</h2>";

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

echo "✓ Database connected<br>";

// Check if table exists
$result = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_video_gen'");
if (mysqli_num_rows($result) > 0) {
    echo "✓ hdb_video_gen table exists<br>";
    
    // Show some records
    $result = mysqli_query($conn, "SELECT id, podcast_id, status, script_text, image_file FROM hdb_video_gen LIMIT 5");
    echo "<h3>Sample records:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Podcast ID</th><th>status</th><th>text</th><th>Image File</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['podcast_id']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>{$row['script_text']}</td>";
        echo "<td>{$row['image_file']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "✗ hdb_video_gen table does NOT exist<br>";
}

// Check folder structure
echo "<h3>Folder Check:</h3>";
$folders = ['podcast_images', 'user_videos', 'logs'];
foreach ($folders as $folder) {
    $path = __DIR__ . '/' . $folder;
    if (file_exists($path)) {
        echo "✓ $folder exists<br>";
    } else {
        echo "✗ $folder missing - creating...<br>";
        mkdir($path, 0755, true);
        echo "  Created $folder<br>";
    }
}

echo "<h3>To add script_text field to your table (if needed):</h3>";
echo "<pre>
ALTER TABLE `hdb_video_gen` 
ADD COLUMN `script_text` TEXT NULL AFTER `image_folder`;
</pre>";
?>