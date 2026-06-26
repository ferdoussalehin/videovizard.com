<?php
// generate_content_only.php
require_once 'chatgpt_functions.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$prompt = $_POST['prompt'] ?? '';

if (empty($prompt)) {
    echo json_encode(['success' => false, 'message' => 'No prompt provided']);
    exit;
}

$result = callChatGPT_inam($prompt);

if ($result['success']) {
    // Clean up the response
    $content = trim($result['response']);
    // Remove any markdown code blocks if present
    $content = preg_replace('/^```.*?\n/', '', $content);
    $content = preg_replace('/```$/', '', $content);
    $content = trim($content);
    
    echo json_encode(['success' => true, 'content' => $content]);
} else {
    echo json_encode(['success' => false, 'message' => $result['error']]);
}
?>