<?php
/**
 * Zuzoo Therapeutic Session Manager
 * Handles client authentication and session tracking for hypnotherapy sessions
 */

/**
 * Ensures client is authenticated and has an active therapeutic session
 * Call this at the beginning of every chat-related file
 * 
 * @param mysqli $conn Database connection
 * @param array $data Current data array (by reference, will be updated)
 * @return array|false Session info or false if not authenticated
 */
function ensureSessionActive($conn, &$data) {
    // Step 1: Get client_id from any available source
    $client_id = getClientId($conn, $data);
    
    if (!$client_id) {
        error_log("❌ No client_id found - user not authenticated" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        return false;
    }
    
    // Store client_id in session for future use
    $_SESSION['client_id'] = $client_id;
    
    // Step 2: Ensure therapeutic session exists
    return ensureTherapeuticSession($client_id);
}

/**
 * Get client_id from session, cookie, or $data
 */
function getClientId($conn, &$data) {
    // Priority 1: Check $data array
    if (isset($data['client_id']) && $data['client_id']) {
        error_log("✓ Client ID from \$data: " . $data['client_id'] . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        return $data['client_id'];
    }
    
    // Priority 2: Check PHP session
    if (isset($_SESSION['client_id']) && $_SESSION['client_id']) {
        error_log("✓ Client ID from session: " . $_SESSION['client_id'] . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        $data['client_id'] = $_SESSION['client_id'];
        return $_SESSION['client_id'];
    }
    
    // Priority 3: Check cookie
    if (isset($_COOKIE['zuzoo_client_id']) && $_COOKIE['zuzoo_client_id']) {
        error_log("🍪 Client ID from cookie: " . $_COOKIE['zuzoo_client_id'] . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        
        // Load client info from database
        $data = get_clientinfo($conn, $data, $_COOKIE['zuzoo_client_id'], "id");
        
        if (isset($data['client_id']) && $data['client_id']) {
            error_log("✓ Client info loaded from database" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
            return $data['client_id'];
        }
    }
    
    return null;
}

/**
 * Ensure therapeutic session exists (create if needed)
 */
function ensureTherapeuticSession($client_id) {
    // Check if session variables already exist
    if (isset($_SESSION['current_session_id']) && isset($_SESSION['current_session_number'])) {
        error_log("✓ Using existing therapeutic session #" . $_SESSION['current_session_number'] . 
                  " (ID: " . $_SESSION['current_session_id'] . ")" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        
        return [
            'is_new' => false,
            'session_id' => $_SESSION['current_session_id'],
            'session_number' => $_SESSION['current_session_number'],
            'client_id' => $client_id
        ];
    }
    
    // Get or create session
    error_log("🔄 Initializing therapeutic session for client: " . $client_id . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    
    $current_session = getCurrentOrNewSession($client_id);
    
    // Store in session variables
    $_SESSION['current_session_id'] = $current_session['session_id'];
    $_SESSION['current_session_number'] = $current_session['session_number'];
    
    error_log("✅ Session initialized: Session #" . $current_session['session_number'] . 
              " (ID: " . $current_session['session_id'] . 
              ", Status: " . ($current_session['is_new'] ? 'NEW' : 'CONTINUING') . ")" . 
              PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    
    return [
        'is_new' => $current_session['is_new'],
        'session_id' => $current_session['session_id'],
        'session_number' => $current_session['session_number'],
        'client_id' => $client_id
    ];
}

/**
 * Get current active session or create new one
 */
function getCurrentOrNewSession($client_id) {
    global $conn;
    
    // Check for active session
    $sql = "SELECT session_id, session_number, profile_json, session_date
            FROM hdb_client_sessions 
            WHERE client_id = ? 
            AND session_status = 'active'
            ORDER BY session_date DESC 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Continue existing session
        $session = $result->fetch_assoc();
        error_log("↩️  Continuing active session #" . $session['session_number'] . 
                  " from " . $session['session_date'] . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        
        return [
            'session_id' => $session['session_id'],
            'session_number' => $session['session_number'],
            'is_new' => false,
            'profile' => json_decode($session['profile_json'], true) ?: []
        ];
    }
    
    // No active session - create new one
    $session_number = getNextSessionNumber($client_id);
    $session_id = createNewSession($client_id, $session_number);
    
    error_log("🆕 Created new session #" . $session_number . " (ID: " . $session_id . ")" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    
    return [
        'session_id' => $session_id,
        'session_number' => $session_number,
        'is_new' => true,
        'profile' => []
    ];
}

/**
 * Get next session number for client
 */
function getNextSessionNumber($client_id) {
    global $conn;
    
    $sql = "SELECT MAX(session_number) as last_session 
            FROM hdb_client_sessions 
            WHERE client_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $next_session = ($row['last_session'] === null) ? 1 : $row['last_session'] + 1;
    
    error_log("📊 Session count for client $client_id: " . ($next_session - 1) . " completed, starting #$next_session" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    
    return $next_session;
}

/**
 * Create new session record in database
 */
function createNewSession($client_id, $session_number) {
    global $conn;
    
    $sql = "INSERT INTO hdb_client_sessions 
            (client_id, session_number, session_date, session_status, profile_json, created_at) 
            VALUES (?, ?, NOW(), 'active', '{}', NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $client_id, $session_number);
    
    if (!$stmt->execute()) {
        error_log("❌ Failed to create session: " . $stmt->error . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        return false;
    }
    
    return $conn->insert_id;
}

/**
 * Get previous session data (for Session 2+ follow-up questions)
 */
function getPreviousSession($client_id, $session_number) {
    global $conn;
    
    $sql = "SELECT session_id, session_number, profile_json, next_session_questions, session_date
            FROM hdb_client_sessions 
            WHERE client_id = ? 
            AND session_number = ?
            AND session_status = 'completed'
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $client_id, $session_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $session = $result->fetch_assoc();
        
        error_log("📖 Loaded previous session #" . $session_number . " from " . $session['session_date'] . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        
        return [
            'session_id' => $session['session_id'],
            'profile' => json_decode($session['profile_json'], true) ?: [],
            'next_questions' => json_decode($session['next_session_questions'], true) ?: [],
            'session_date' => $session['session_date']
        ];
    }
    
    error_log("⚠️  No previous session #" . $session_number . " found for client " . $client_id . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    return null;
}

/**
 * Update session profile with new Q&A data
 */
function updateSessionProfile($session_id, $profile_data) {
    global $conn;
    
    $profile_json = json_encode($profile_data, JSON_PRETTY_PRINT);
    
    $sql = "UPDATE hdb_client_sessions 
            SET profile_json = ?,
                updated_at = NOW()
            WHERE session_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $profile_json, $session_id);
    
    if (!$stmt->execute()) {
        error_log("❌ Failed to update session profile: " . $stmt->error . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        return false;
    }
    
    error_log("💾 Session profile updated (Session ID: $session_id)" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    return true;
}

/**
 * Get current session profile
 */
function getSessionProfile($session_id) {
    global $conn;
    
    $sql = "SELECT profile_json FROM hdb_client_sessions WHERE session_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return json_decode($row['profile_json'], true) ?: [];
    }
    
    return [];
}

/**
 * Complete current session and prepare for next
 */
function completeSession($session_id, $final_profile = null, $next_session_questions = null) {
    global $conn;
    
    $profile_json = $final_profile ? json_encode($final_profile, JSON_PRETTY_PRINT) : null;
    $questions_json = $next_session_questions ? json_encode($next_session_questions, JSON_PRETTY_PRINT) : null;
    
    $sql = "UPDATE hdb_client_sessions 
            SET session_status = 'completed',
                profile_json = COALESCE(?, profile_json),
                next_session_questions = COALESCE(?, next_session_questions),
                updated_at = NOW()
            WHERE session_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $profile_json, $questions_json, $session_id);
    
    if (!$stmt->execute()) {
        error_log("❌ Failed to complete session: " . $stmt->error . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
        return false;
    }
    
    error_log("✅ Session completed (ID: $session_id)" . PHP_EOL, 3, __DIR__ . "/../a_debug.log");
    
    // Clear session variables so next visit creates new session
    unset($_SESSION['current_session_id']);
    unset($_SESSION['current_session_number']);
    
    return true;
}

/**
 * Get all sessions for a client (history)
 */
function getClientSessionHistory($client_id) {
    global $conn;
    
    $sql = "SELECT session_id, session_number, session_date, session_status 
            FROM hdb_client_sessions 
            WHERE client_id = ? 
            ORDER BY session_number DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>