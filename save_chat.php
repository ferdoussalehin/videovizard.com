<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once 'dbconnect_hdb.php';

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$user_id = intval($input['user_id'] ?? 0);
$client_id = intval($input['client_id'] ?? 0); // ✅ ADDED
$role = $input['role'] ?? '';
$message = $input['message'] ?? '';
$step_order = intval($input['step_order'] ?? 0);
$topic_id = intval($input['topic_id'] ?? 0);
$subtopic_id = intval($input['subtopic_id'] ?? 0);
$session_id = $input['session_id'] ?? '';

// Validate required fields
if ($user_id <= 0 || $client_id <= 0 || empty($role) || empty($message)) { // ✅ ADDED client_id check
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

// Validate role
if (!in_array($role, ['user', 'bot'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
    exit;
}

// Insert into database
$stmt = $conn->prepare("
    INSERT INTO chat_history 
    (user_id, client_id, session_id, role, message, step_order, topic_id, subtopic_id) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
"); // ✅ ADDED client_id

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database prepare error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("iisssiii", 
    $user_id,
    $client_id,  // ✅ ADDED
    $session_id, 
    $role, 
    $message, 
    $step_order, 
    $topic_id, 
    $subtopic_id
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
?>