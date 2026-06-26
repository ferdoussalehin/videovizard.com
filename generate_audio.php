<?php
header('Content-Type: application/json');

$text = $_POST['text'] ?? '';
$voice = $_POST['voice'] ?? 'alloy'; // default voice

if(trim($text) === ''){
    echo json_encode(['error'=>'No text provided']);
    exit;
}

$apiKey = "";


$url = "https://api.openai.com/v1/audio/speech";

$data = [
    "model" => "gpt-4o-mini-tts",
    "voice" => $voice,
    "input" => $text
];

$payload = json_encode($data, JSON_UNESCAPED_UNICODE);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer ".$apiKey,
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if($status !== 200){
    echo json_encode([
        "error"=>"API request failed",
        "http_code"=>$status,
        "response"=>$response
    ]);
    exit;
}

// Create audio folder if not exists
if(!file_exists("audio")){
    mkdir("audio",0777,true);
}

$file = "audio/tts_".uniqid().".mp3";
file_put_contents($file,$response);

echo json_encode(['audio'=>$file]);
?>