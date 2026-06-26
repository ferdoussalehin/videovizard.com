<?php
session_start();
include 'dbconnect_hdb.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['podcast_id'])) {
    $podcast_id = mysqli_real_escape_string($conn, $_POST['podcast_id']);

    // Example logic: Mark as recorded and update timestamp
    // You can adjust the SET clause to match your column names (e.g., status='recorded')
    $query = "UPDATE hdb_podcasts 
              SET video_status = 'RECORDED', 
                  updated_at = NOW() 
              WHERE id = '$podcast_id'";
			  
	//echo "query ".$query;die;

    if (mysqli_query($conn, $query)) {
        echo json_encode(['success' => true, 'message' => 'Status updated']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Request']);
}
?>