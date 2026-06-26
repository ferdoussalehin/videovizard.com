<?php
// test_save_embedding.php
require 'config.php';
require 'dbconnect_hdb.php';

// Get one asset that needs embedding
$result = mysqli_query($conn, "SELECT id, image_name, natural_language_tags 
    FROM hdb_image_data 
    WHERE embedding IS NULL
    AND natural_language_tags IS NOT NULL 
    AND natural_language_tags != ''
    LIMIT 1");

$row = mysqli_fetch_assoc($result);
if (!$row) {
    die("No assets need embedding");
}

echo "Testing with ID: {$row['id']}\n";
echo "Image: {$row['image_name']}\n\n";

// Clean tags
$clean = trim(str_replace('|', ' ', $row['natural_language_tags']));

// Generate embedding
$ch = curl_init('https://api.openai.com/v1/embeddings');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $chatgpt_api_key
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => 'text-embedding-3-large',
        'input' => $clean
    ]),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);
$vector = $data['data'][0]['embedding'];
$json_embedding = json_encode($vector);
$original_size = strlen($json_embedding);

echo "Original JSON size: $original_size bytes\n";
echo "Dimensions: " . count($vector) . "\n\n";

// Method 1: Direct save (no escaping)
$direct_save = "UPDATE hdb_image_data SET embedding='$json_embedding' WHERE id={$row['id']}";
echo "Method 1 - Direct save length: " . strlen($direct_save) . "\n";

// Method 2: With mysqli_real_escape_string
$safe = mysqli_real_escape_string($conn, $json_embedding);
$escaped_save = "UPDATE hdb_image_data SET embedding='$safe' WHERE id={$row['id']}";
echo "Method 2 - Escaped save length: " . strlen($escaped_save) . "\n";
echo "Difference: " . (strlen($escaped_save) - strlen($direct_save)) . " extra bytes\n\n";

// Save using escaped method (what your code does)
$update = mysqli_query($conn, "UPDATE hdb_image_data SET embedding='$safe', updated_at=NOW() WHERE id={$row['id']}");

if ($update) {
    // Verify what was saved
    $verify = mysqli_query($conn, "SELECT LENGTH(embedding) as len FROM hdb_image_data WHERE id={$row['id']}");
    $v = mysqli_fetch_assoc($verify);
    echo "Saved size in DB: {$v['len']} bytes\n";
    
    if ($v['len'] == $original_size) {
        echo "✅ SUCCESS! Saved correctly\n";
    } else {
        echo "❌ FAILED! Expected $original_size, got {$v['len']}\n";
        echo "Lost " . ($original_size - $v['len']) . " bytes!\n";
    }
} else {
    echo "DB Error: " . mysqli_error($conn) . "\n";
}
?>