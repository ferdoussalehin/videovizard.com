<?php
if(isset($_FILES['video'])) {
    $targetDir = "socialmedia_videos/"; // folder on server
    if(!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = basename($_FILES['video']['name']);
    $targetFile = $targetDir . $fileName;

    if(move_uploaded_file($_FILES['video']['tmp_name'], $targetFile)) {
        echo json_encode([
            'success' => true,
            'file' => $targetFile
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save video on server.'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No video uploaded.'
    ]);
}
?>
