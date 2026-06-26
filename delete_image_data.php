<?php
// Show errors so we see what's wrong instead of blank 500
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "config.php"; // must provide $conn (mysqli)

// ── Safety gate — this script deletes rows, so it must not fire on a bare page load ──
if (($_GET['confirm'] ?? '') !== 'yes') {
    echo "<pre style='background:#111;color:#f5d06f;padding:15px;font-family:monospace'>";
    echo "⚠️  This will PERMANENTLY DELETE rows from hdb_image_data whose file is missing on disk.\n\n";
    echo "Run media_cleanup_preview.php first to see exactly which rows will be affected.\n\n";
    echo "If you've checked the preview and you're sure, click below:\n";
    echo "<a href='?confirm=yes' style='color:#5fd1ff;'>→ Yes, delete the missing-file rows now</a>";
    echo "</pre>";
    exit;
}

echo "<pre style='background:#111;color:#f5d06f;padding:15px;font-family:monospace'>";
echo "MEDIA CLEANUP STARTED...\n";
echo "====================================\n\n";

$result = mysqli_query($conn, "SELECT id, image_name, image_folder, media_type FROM hdb_image_data ORDER BY id ASC");

if (!$result) {
    die("DB Error: " . mysqli_error($conn));
}

$total = 0; $deleted = 0; $kept = 0; $skipped = 0;

while ($row = mysqli_fetch_assoc($result)) {

    $total++;
    $id       = (int)$row['id'];
    $type     = strtolower(trim($row['media_type'] ?? ''));
    $filename = trim($row['image_name']   ?? '');
    $folder   = trim($row['image_folder'] ?? ($type === 'video' ? 'podcast_videos' : 'podcast_images'));

    echo "ID: $id | Type: $type | Folder: $folder | File: $filename\n";

    if (empty($filename)) {
        echo "⚠️  NO FILENAME → SKIPPED\n----\n";
        $skipped++;
        continue;
    }

    // Build full path — folder may be relative like
    // 'podcast_images', 'podcast_videos',
    // or 'user_id_22_company_id_17/podcast_images' etc.
    $full_path = __DIR__ . '/' . ltrim($folder, '/') . '/' . $filename;

    if (!file_exists($full_path)) {
        $del = mysqli_query($conn, "DELETE FROM hdb_image_data WHERE id=$id");
        if ($del && mysqli_affected_rows($conn) > 0) {
            echo "❌ FILE MISSING ($full_path) → DELETED\n----\n";
            $deleted++;
        } else {
            echo "⚠️  DELETE FAILED: " . mysqli_error($conn) . "\n----\n";
        }
    } else {
        echo "✅ EXISTS\n----\n";
        $kept++;
    }
}

echo "\n====================================\n";
echo "TOTAL   : $total\n";
echo "KEPT    : $kept\n";
echo "DELETED : $deleted\n";
echo "SKIPPED : $skipped\n";
echo "====================================\n";
echo "</pre>";
