<?php
require_once 'generate_image_api.php'; // The file you shared in the first prompt
require_once 'db_connection.php';      // Your DB connection

$rowId = $_POST['row_id'] ?? null;
$prompt = $_POST['prompt'] ?? null;
$imageName = $_POST['image_name'] ?? 'image';
$apiKey = "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"; // PUT YOUR KEY HERE

if (!$rowId || !$prompt) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

// 1. Call your function
$result = generateAndSaveImage($prompt, $imageName, "1024x1792", "podcast_images", $apiKey);

if ($result['success']) {
    $filepath = $result['filepath'];
    $justFilename = basename($filepath);

    // 2. Update Database so the image stays saved
    $stmt = $pdo->prepare("UPDATE hdb_podcast_stories SET image_file = ? WHERE id = ?");
    $stmt->execute([$justFilename, $rowId]);
}

echo json_encode($result);