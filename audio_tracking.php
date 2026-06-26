<?php
// ✅ CAPTURE ALL ERRORS
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/audio_tracking_errors.log');

// ✅ SET JSON HEADER FIRST
header('Content-Type: application/json');

// ✅ CATCH FATAL ERRORS
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error',
            'details' => $error['message']
        ]);
    }
});

try {
    // ✅ INCLUDE DATABASE
    if (!file_exists(__DIR__ . '/dbconnect_hdb.php')) {
        throw new Exception('dbconnect_hdb.php not found');
    }
    
    require_once __DIR__ . '/dbconnect_hdb.php';
    
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection failed');
    }
    
    // ✅ GET INPUT
    $raw_input = file_get_contents('php://input');
    if (empty($raw_input)) {
        throw new Exception('Empty request');
    }
    
    $input = json_decode($raw_input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON');
    }
    
    if (!isset($input['action']) || $input['action'] !== 'track_audio') {
        throw new Exception('Invalid action');
    }
    
    // ✅ VALIDATE FIELDS
    $required = ['client_id', 'user_id', 'audio_url', 'audio_duration', 
                 'play_count', 'total_listen_time', 'engagement_percentage', 
                 'completed', 'session_id'];
    
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing: $field");
        }
    }
    
    // ✅ EXTRACT VALUES
    $client_id = intval($input['client_id']);
    $user_id = intval($input['user_id']);
    $audio_url = mysqli_real_escape_string($conn, $input['audio_url']);
    $audio_duration = intval($input['audio_duration']);
    $play_count = intval($input['play_count']);
    $total_listen_time = intval($input['total_listen_time']);
    $engagement_percentage = intval($input['engagement_percentage']);
    $completed = $input['completed'] ? 1 : 0;
    $session_id = mysqli_real_escape_string($conn, $input['session_id']);
    $is_periodic = isset($input['is_periodic_update']) && $input['is_periodic_update'] ? 1 : 0;
    $current_position = isset($input['current_position']) ? intval($input['current_position']) : 0;
    
    // ✅ CHECK IF RECORD EXISTS
    $check_query = "SELECT id FROM hdb_audio_tracking 
                    WHERE session_id = ? AND audio_url = ?";
    
    $check_stmt = mysqli_prepare($conn, $check_query);
    if (!$check_stmt) {
        throw new Exception('Prepare failed: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($check_stmt, 'ss', $session_id, $audio_url);
    
    if (!mysqli_stmt_execute($check_stmt)) {
        throw new Exception('Execute failed: ' . mysqli_stmt_error($check_stmt));
    }
    
    mysqli_stmt_store_result($check_stmt);
    $num_rows = mysqli_stmt_num_rows($check_stmt);
    
    if ($num_rows > 0) {
        // ✅ UPDATE EXISTING RECORD
        mysqli_stmt_bind_result($check_stmt, $record_id);
        mysqli_stmt_fetch($check_stmt);
        mysqli_stmt_close($check_stmt);
        
        $update_query = "UPDATE hdb_audio_tracking SET
            play_count = ?,
            total_listen_time = ?,
            engagement_percentage = ?,
            completed = ?,
            current_position = ?,
            last_update = NOW()
            WHERE id = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        if (!$update_stmt) {
            throw new Exception('Update prepare failed: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($update_stmt, 'iiiiii', 
            $play_count, 
            $total_listen_time, 
            $engagement_percentage, 
            $completed,
            $current_position,
            $record_id
        );
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Update failed: ' . mysqli_stmt_error($update_stmt));
        }
        
        mysqli_stmt_close($update_stmt);
        
        echo json_encode([
            'success' => true, 
            'action' => 'updated',
            'id' => $record_id,
            'is_periodic' => $is_periodic,
            'listen_time' => $total_listen_time,
            'engagement' => $engagement_percentage
        ]);
        
    } else {
        mysqli_stmt_close($check_stmt);
        
        // ✅ INSERT NEW RECORD
        $insert_query = "INSERT INTO hdb_audio_tracking 
            (client_id, user_id, session_id, audio_url, audio_duration, 
             play_count, total_listen_time, engagement_percentage, completed, 
             current_position, created_at, last_update) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        if (!$insert_stmt) {
            throw new Exception('Insert prepare failed: ' . mysqli_error($conn));
        }
        
        // ✅ CORRECT: 10 type characters for 10 variables
        mysqli_stmt_bind_param($insert_stmt, 'iissiiiiii', 
            $client_id,              // i
            $user_id,                // i
            $session_id,             // s
            $audio_url,              // s
            $audio_duration,         // i
            $play_count,             // i
            $total_listen_time,      // i
            $engagement_percentage,  // i
            $completed,              // i
            $current_position        // i
        );
        
        if (!mysqli_stmt_execute($insert_stmt)) {
            throw new Exception('Insert failed: ' . mysqli_stmt_error($insert_stmt));
        }
        
        $new_id = mysqli_insert_id($conn);
        mysqli_stmt_close($insert_stmt);
        
        echo json_encode([
            'success' => true, 
            'action' => 'inserted',
            'id' => $new_id,
            'listen_time' => $total_listen_time,
            'engagement' => $engagement_percentage
        ]);
    }
    
} catch (Exception $e) {
    error_log('Audio Tracking ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}

if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>
