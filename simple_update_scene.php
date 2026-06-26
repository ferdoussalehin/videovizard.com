<?php
session_start();
require_once 'dbconnect_hdb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$row_id = (int)($_POST['row_id'] ?? 0);
$text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? '');
$text_display = mysqli_real_escape_string($conn, $_POST['text_display'] ?? '');
$prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');
$logo_flag = (int)($_POST['logo_flag'] ?? 1);

if (!$row_id) {
    echo json_encode(['success' => false, 'message' => 'No row ID provided']);
    exit;
}

$sql = "UPDATE hdb_podcast_stories SET 
        text_contents = '$text_contents',
        text_display = '$text_display',
        prompt = '$prompt',
        logo_flag = $logo_flag
        WHERE id = $row_id";

if (mysqli_query($conn, $sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
}
?>