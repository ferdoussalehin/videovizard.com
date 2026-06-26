<?php
// test_direct_embedding.php
require 'config.php';
require 'dbconnect_hdb.php';

// Get one asset that needs embedding
$result = mysqli_query($conn, "SELECT id, image_name, natural_language_tags 
    FROM hdb_image_data 
    WHERE (embedding IS NULL OR embedding = '')
    AND natural_language_tags IS NOT NULL 
    AND natural_language_tags != ''
    LIMIT 1");

$row = mysqli_fetch_assoc($result);
if (!$row) {
    die("No assets need embedding");
}

echo "Testing with asset ID: {$row['id']}\n";
echo "Image: {$row['image_name']}\n";
echo "NL Tags: " . substr($row['natural_language_tags'], 0, 100) . "...\n\n";

// Clean the tags
$clean = trim(str_replace('|', ' ', $row['natural_language_tags']));
echo "Cleaned text: $clean\n\n";

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

if ($httpCode != 200) {
    die("API Error: $httpCode - $response");
}

$data = json_decode($response, true);
$vector = $data['data'][0]['embedding'];
$dimensions = count($vector);
$json_embedding = json_encode($vector);
$size_bytes = strlen($json_embedding);

echo "API returned: $dimensions dimensions, $size_bytes bytes\n";

if ($dimensions != 3072) {
    die("ERROR: Expected 3072 dimensions, got $dimensions");
}

// Save to database
$safe_emb = mysqli_real_escape_string($conn, $json_embedding);
$update = mysqli_query($conn, "UPDATE hdb_image_data SET embedding='$safe_emb', updated_at=NOW() WHERE id={$row['id']}");

if ($update) {
    echo "✓ Saved to database\n";
    
    // Verify what was saved
    $verify = mysqli_query($conn, "SELECT LENGTH(embedding) as len FROM hdb_image_data WHERE id={$row['id']}");
    $v = mysqli_fetch_assoc($verify);
    echo "Saved size in DB: {$v['len']} bytes\n";
    
    if ($v['len'] == $size_bytes) {
        echo "✅ SUCCESS! Saved correctly as $dimensions dimensions\n";
    } else {
        echo "❌ FAILED! Saved size doesn't match. Expected $size_bytes, got {$v['len']}\n";
    }
} else {
    echo "❌ Database error: " . mysqli_error($conn);
}
?>