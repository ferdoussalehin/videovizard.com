<?php
// Show errors so we see what's wrong instead of blank 500
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "config.php"; // must provide $conn (mysqli)

echo "<pre style='background:#111;color:#f5d06f;padding:15px;font-family:monospace'>";
echo "MEDIA CLEANUP — PREVIEW ONLY (no changes made)\n";
echo "====================================\n\n";

$result = mysqli_query($conn, "SELECT id, image_name, image_folder, media_type FROM hdb_image_data ORDER BY id ASC");

if (!$result) {
    die("DB Error: " . mysqli_error($conn));
}

$total = 0; $would_delete = 0; $kept = 0; $skipped = 0;

while ($row = mysqli_fetch_assoc($result)) {

    $total++;
    $id       = (int)$row['id'];
    $type     = strtolower(trim($row['media_type'] ?? ''));
    $filename = trim($row['image_name']   ?? '');
    $folder   = trim($row['image_folder'] ?? ($type === 'video' ? 'podcast_videos' : 'podcast_images'));

    echo "ID: $id | Type: $type | Folder: $folder | File: $filename\n";

    if (empty($filename)) {
        echo "⚠️  NO FILENAME → WOULD SKIP\n----\n";
        $skipped++;
        continue;
    }

    // Build full path — folder may be relative like
    // 'podcast_images', 'podcast_videos',
    // or 'user_id_22_company_id_17/podcast_images' etc.
    $full_path = __DIR__ . '/' . ltrim($folder, '/') . '/' . $filename;

    if (!file_exists($full_path)) {
        echo "❌ FILE MISSING ($full_path) → WOULD DELETE (id=$id)\n----\n";
        $would_delete++;
    } else {
        echo "✅ EXISTS\n----\n";
        $kept++;
    }
}

echo "\n====================================\n";
echo "TOTAL         : $total\n";
echo "WOULD KEEP    : $kept\n";
echo "WOULD DELETE  : $would_delete\n";
echo "WOULD SKIP    : $skipped\n";
echo "====================================\n";
echo "\nNo rows were changed. Run media_cleanup_delete.php (with confirm=yes) to actually delete the rows listed above.\n";
echo "</pre>";
