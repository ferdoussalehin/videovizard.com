<?php
// audio_player.php
// Example link: https://sulaimania.org/inaamalvi/audio_player.php?file=hypnotherapy_experience.mp3

// Sanitize file input to prevent security issues
$file = isset($_GET['file']) ? basename($_GET['file']) : '';

$audio_path = "video_reels/" . $file;

if (!file_exists($audio_path) || empty($file)) {
    die("Audio file not found.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Listen to Audio</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        body {
            background: #f3f6fb;
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 60px;
        }
        h2 {
            color: #333;
        }
        audio {
            margin-top: 30px;
            width: 90%;
            max-width: 500px;
        }
        .share-link {
            margin-top: 20px;
            color: #007bff;
            font-size: 14px;
        }
        .share-link input {
            width: 80%;
            padding: 5px;
        }
    </style>
</head>
<body>
    <h2>🎧 Hypnotherapy Audio Experience</h2>
    <p>Find a quiet space and relax while listening.</p>

    <audio controls autoplay>
        <source src="video_reels/<?= htmlspecialchars($file); ?>" type="audio/mpeg">
        Your browser does not support the audio tag.
    </audio>

    <div class="share-link">
        <p>Share this link:</p>
        <input type="text" value="https://sulaimania.org/inaamalvi/audio_player.php?file=<?= htmlspecialchars($file); ?>" readonly onclick="this.select();">
    </div>
</body>
</html>
