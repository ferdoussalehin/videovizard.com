<?
// Your OpenAI API Key
$apiKey ="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";

include 'generate_image_api.php';

// Example 1: Basic usage with defaults
$result = generateAndSaveImage(
    "A professional South Asian woman in her early 30s wearing a sage green button-up blouse, sitting at a modern podcast desk with warm lighting",
    "work_frustration_scene_001",
    "1024x1792",
    "podcast_images",
    $apiKey 
);

if ($result['success']) {
    echo "✅ " . $result['message'] . "<br>";
    echo "📁 File: " . $result['filepath'] . "<br>";
} else {
    echo "❌ Error: " . $result['message'] . "<br>";
}

die;
/*
// Example 2: Different resolution and folder
$result2 = generateAndSaveImage(
    prompt: "Peaceful sunset over calm lake, relaxing atmosphere, photorealistic, 4K quality",
    imageName: "sunset_scene_" . date("Ymd_His"),
    resolution: "1792x1024", // Landscape format
    folder: "generated_images/landscapes",
    apiKey: $apiKey
);

if ($result2['success']) {
    echo "✅ " . $result2['message'] . "<br>";
    echo "📁 File: " . $result2['filepath'] . "<br>";
} else {
    echo "❌ Error: " . $result2['message'] . "<br>";
}

// Example 3: Batch generation for podcast scenes
$scenes = [
    ['name' => 'scene_001', 'prompt' => 'Professional podcast host with empathetic expression...'],
    ['name' => 'scene_002', 'prompt' => 'Same host with hopeful smile and relaxed posture...'],
    ['name' => 'scene_003', 'prompt' => 'Host demonstrating calm breathing, eyes closed...']
];

foreach ($scenes as $scene) {
    $result = generateAndSaveImage(
        prompt: $scene['prompt'],
        imageName: $scene['name'],
        resolution: "1024x1024",
        folder: "podcast_images/work_frustration",
        apiKey: $apiKey
    );
    
    if ($result['success']) {
        echo "✅ {$scene['name']}: {$result['filepath']}<br>";
    } else {
        echo "❌ {$scene['name']}: {$result['message']}<br>";
    }
    
    // Sleep to avoid rate limits (if generating multiple images)
    sleep(1);
}*/

?>