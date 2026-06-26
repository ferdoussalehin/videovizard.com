<?php
session_start();

include 'dbconnect_hdb.php';
include 'rebuttal_system.php';

$client_id = 1; // test client

// Initialize chat history in session
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

// Optional: reset chat
if (isset($_POST['reset'])) {
    $_SESSION['chat_history'] = [];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle new input
$user_input = $_POST['input'] ?? null;

if ($user_input) {
    $data = [
        'client_id' => $client_id,
        'user_input' => $user_input
    ];
    
    // Add user message to chat history FIRST
    $_SESSION['chat_history'][] = [
        'from' => 'user',
        'text' => $user_input
    ];
    
    try {
        // Get system response using your rebuttal system
        $response = handle_offer_flow($conn, $data);
        
        // Debug: Log the response
        error_log("Response received: " . print_r($response, true));
        
        // Handle different response formats
        if (is_array($response)) {
            
            // Check if it's a valid response with question_text
            if (isset($response['question_text']) && !empty($response['question_text'])) {
                
                // Parse buttons if they exist
                $buttons = [];
                
                if (isset($response['button_value']) && !empty($response['button_value'])) {
                    // Try to parse JSON button_value
                    $buttonData = json_decode($response['button_value'], true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && isset($buttonData['button_options'])) {
                        $buttons = $buttonData['button_options'];
                    }
                } elseif (isset($response['button_options']) && is_array($response['button_options'])) {
                    // Direct button_options array
                    $buttons = $response['button_options'];
                }
                
                // Add system message to chat history
                $_SESSION['chat_history'][] = [
                    'from' => 'system',
                    'text' => $response['question_text'],
                    'buttons' => $buttons
                ];
                
            } else {
                // Response exists but no question_text
                $_SESSION['chat_history'][] = [
                    'from' => 'system',
                    'text' => "⚠️ Invalid response format. Missing question_text.",
                    'buttons' => []
                ];
                error_log("Error: Response missing question_text - " . print_r($response, true));
            }
            
        } else {
            // Response is not an array
            $_SESSION['chat_history'][] = [
                'from' => 'system',
                'text' => "⚠️ Invalid response type. Expected array, got " . gettype($response),
                'buttons' => []
            ];
            error_log("Error: Invalid response type - " . print_r($response, true));
        }
        
    } catch (Exception $e) {
        // Catch any exceptions
        $_SESSION['chat_history'][] = [
            'from' => 'system',
            'text' => "❌ Error: " . $e->getMessage(),
            'buttons' => []
        ];
        error_log("Exception in handle_offer_flow: " . $e->getMessage());
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Rebuttal Chat Simulation</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            max-width: 700px; 
            margin: 20px auto; 
            background: #f5f5f5;
            padding: 20px;
        }
        
        h2 {
            color: #333;
            text-align: center;
        }
        
        .chat-box { 
            border: 1px solid #ccc; 
            padding: 15px; 
            height: 500px; 
            overflow-y: auto; 
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .user-msg { 
            color: white;
            background: #0b5394;
            padding: 10px 15px;
            margin: 10px 0;
            border-radius: 18px;
            max-width: 70%;
            margin-left: auto;
            text-align: right;
        }
        
        .system-msg { 
            color: #333;
            background: #e3f2fd;
            padding: 10px 15px;
            margin: 10px 0;
            border-radius: 18px;
            max-width: 70%;
            margin-right: auto;
        }
        
        .button-container {
            margin: 10px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .button { 
            padding: 8px 16px; 
            background: #4caf50; 
            color: white; 
            border: none; 
            cursor: pointer;
            border-radius: 6px;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .button:hover { 
            background: #45a049; 
        }
        
        .input-form {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        
        .input-form input[type="text"] {
            flex: 1;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .input-form button {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        
        .send-btn {
            background: #1976d2;
            color: white;
        }
        
        .send-btn:hover {
            background: #1565c0;
        }
        
        .reset-btn {
            background: #f44336;
            color: white;
        }
        
        .reset-btn:hover {
            background: #d32f2f;
        }
        
        .empty-state {
            text-align: center;
            color: #999;
            padding: 50px 20px;
        }
    </style>
</head>
<body>

<h2>💬 Rebuttal Chat Simulation</h2>

<div class="chat-box" id="chat-box">
    <?php if (empty($_SESSION['chat_history'])): ?>
        <div class="empty-state">
            👋 Welcome! Start chatting to test the rebuttal system.
        </div>
    <?php else: ?>
        <?php foreach ($_SESSION['chat_history'] as $msg): ?>
            <div class="<?= $msg['from'] == 'user' ? 'user-msg' : 'system-msg' ?>">
                <?= nl2br(htmlspecialchars($msg['text'])) ?>
            </div>
            
            <?php if (!empty($msg['buttons'])): ?>
                <div class="button-container">
                    <?php foreach ($msg['buttons'] as $btn): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="input" value="<?= htmlspecialchars($btn['value']) ?>">
                            <button class="button" type="submit"><?= htmlspecialchars($btn['label']) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<form method="post" class="input-form">
    <input type="text" name="input" placeholder="Type your message..." autofocus required>
    <button type="submit" class="send-btn">Send</button>
    <button type="submit" name="reset" value="1" class="reset-btn" 
            onclick="return confirm('Reset chat history?')">Reset</button>
</form>

<script>
// Auto-scroll to bottom
function scrollToBottom() {
    var chatBox = document.getElementById('chat-box');
    chatBox.scrollTop = chatBox.scrollHeight;
}

// Scroll on page load
scrollToBottom();

// Also scroll after any form submission
window.addEventListener('load', function() {
    scrollToBottom();
});
</script>

</body>
</html>