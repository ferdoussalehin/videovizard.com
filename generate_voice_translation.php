<?php

$dbase = "user_hypnotherapy_db2"; 
$dbuser = "user_inaamalvi1403"; 
$dbpass = "AllahuAkbar786";
$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);

if (!$conn) {
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "details" => mysqli_connect_error()
    ]);
    exit();
}

mysqli_set_charset($conn, 'utf8mb4');

$apiKey = "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";

if (empty($apiKey)) {
    die("Error: \$apiKey is not set in config.php\n");
}

// Read 100 rows where translation_flag = 0
$result = mysqli_query($conn,
    "SELECT id, lang_code, voice_text
       FROM hdb_voices
      WHERE voice_source = 'openai'
        AND translation_flag = '0'
        AND voice_text IS NOT NULL
        AND voice_text <> ''
      LIMIT 100"
);

if (!$result) {
    die("Query failed: " . mysqli_error($conn) . "\n");
}

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}
mysqli_free_result($result);

if (empty($rows)) {
    echo "No rows found with translation_flag = 0. Nothing to do.\n";
    mysqli_close($conn);
    exit(0);
}

echo "<br>Found " . count($rows) . " row(s) to translate.\n\n";

$cnt = 0;

foreach ($rows as $row) {
    $id        = (int)$row['id'];
    $langCode  = $row['lang_code'];
    $voiceText = $row['voice_text'];
    $cnt++;

    echo "<br><br>Row #{$id} | lang_code: {$langCode} | original: \"{$voiceText}\"\n";

    $translated = translateText($voiceText, $langCode, $apiKey);

    if ($translated === null) {
        echo "  ✗ Translation failed — skipping.\n\n";
        continue;
    }

    $safe = mysqli_real_escape_string($conn, $translated);
    echo "  → Updating id={$id} with: \"{$translated}\"\n";

    // Update voice_text AND mark translation_flag = 1
    $ok = mysqli_query($conn,
        "UPDATE hdb_voices
            SET voice_text = '{$safe}',
                translation_flag = '1'
          WHERE id = {$id}"
    );

    if (!$ok) {
        echo "  ✗ DB update failed: " . mysqli_error($conn) . "\n\n";
    } else {
        echo "  ✓ Done.\n\n";
    }
}

mysqli_close($conn);
echo "<br><strong>Finished. Processed {$cnt} row(s).</strong>\n";

function translateText(string $text, string $langCode, string $apiKey): ?string
{
    $systemPrompt = "You are a professional translator. "
        . "Translate the user's text into the language identified by this BCP-47/ISO 639-1 code: {$langCode}. "
        . "Return ONLY the translated text — no explanations, no quotes, no extra formatting.";

    echo "<br><br>Prompt: " . $systemPrompt;

    $payload = json_encode([
        'model'      => 'gpt-4o-mini',
        'max_tokens' => 256,
        'messages'   => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $text],
        ],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo "  cURL error: {$curlErr}\n";
        return null;
    }

    if ($httpCode !== 200) {
        echo "  OpenAI API error (HTTP {$httpCode}): {$response}\n";
        return null;
    }

    $data       = json_decode($response, true);
    $translated = trim($data['choices'][0]['message']['content'] ?? '');
    echo "<br>Translation: " . $translated;

    if ($translated === '') {
        echo "  Empty response from OpenAI.\n";
        return null;
    }

    return $translated;
}
?>