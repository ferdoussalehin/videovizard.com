<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush(true);
ob_end_flush();

require 'config.php';
require 'dbconnect_hdb.php';

function cleanTagsForEmbedding(string $tags): string {
    $clean = str_replace('|', ', ', $tags);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

function getEmbedding(string $text, string $apiKey): ?array {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'text-embedding-3-large',
            'input' => $text
        ])
    ]);
    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "OpenAI error: " . $response . "\n";
        return null;
    }

    $data = json_decode($response, true);
    return $data['data'][0]['embedding'] ?? null;
}

// Reset all embeddings to re-generate with new model + clean format
$reset = mysqli_query($conn, "UPDATE hdb_image_data SET embedding = NULL");
echo "<pre>";
echo "Reset all embeddings — will re-embed with text-embedding-3-large\n\n";

$result = mysqli_query($conn,
    "SELECT id, natural_language_tags, image_name
     FROM hdb_image_data
     WHERE natural_language_tags IS NOT NULL
     AND natural_language_tags != ''"
);

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}
$total = count($rows);
echo "Found {$total} assets to embed\n\n";

$upd = mysqli_prepare($conn,
    "UPDATE hdb_image_data SET embedding = ? WHERE id = ?"
);

if (!$upd) {
    echo "Prepare failed: " . mysqli_error($conn) . "\n";
    exit;
}

foreach ($rows as $i => $row) {
    $num = $i + 1;
    echo "[{$num}/{$total}] Processing: {$row['image_name']} ... ";

    $cleanText = cleanTagsForEmbedding($row['natural_language_tags']);
    $vector    = getEmbedding($cleanText, $apiKey);

    if ($vector === null) {
        echo "SKIPPED (no vector returned)\n";
        continue;
    }

    $json = json_encode($vector);
    mysqli_stmt_bind_param($upd, 'si', $json, $row['id']);

    if (mysqli_stmt_execute($upd)) {
        echo "OK\n";
    } else {
        echo "DB ERROR: " . mysqli_stmt_error($upd) . "\n";
    }

    if ($num % 50 === 0) {
        echo "--- pausing 1 second ---\n";
        sleep(1);
    }
}

mysqli_stmt_close($upd);
echo "\nAll done.\n</pre>";