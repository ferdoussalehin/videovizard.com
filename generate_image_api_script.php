 
 
 <?php

$apiKey ="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";



$prompt = "Peaceful sunset over calm lake, relaxing atmosphere";

$data = [
    "model" => "gpt-image-1",
    "prompt" => $prompt,
    "size" => "1024x1024"
];

$ch = curl_init("https://api.openai.com/v1/images/generations");

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);

if (curl_errno($ch)) {
    die("Curl Error: " . curl_error($ch));
}

curl_close($ch);

// Decode JSON
$result = json_decode($response, true);

if (!isset($result['data'][0]['b64_json'])) {
    die("Image generation failed.");
}

// Get Base64 image
$base64Image = $result['data'][0]['b64_json'];

// Decode image
$imageData = base64_decode($base64Image);

// Folder path
$folder = "podcast_images";

// Create folder if not exists
if (!file_exists($folder)) {
    mkdir($folder, 0755, true);
}

// Generate filename
$filename = $folder . "/image_" . date("Ymd_His") . ".png";

// Save file
file_put_contents($filename, $imageData);

echo "✅ Image saved successfully:<br>";
echo $filename;

?>
