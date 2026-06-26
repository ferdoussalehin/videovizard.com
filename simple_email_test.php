<?php
/**
 * SUPER SIMPLE EMAIL TEST
 * Just upload this ONE file and visit it in your browser
 * NO other files needed!
 */

// ============================================
// CHANGE THESE 3 THINGS:
// ============================================
$BREVO_API_KEY = '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';

// CHOOSE ONE (both are authenticated!):
$SENDER_EMAIL = 'hello@stressreleasor.com';  // Your main domain ✅
// $SENDER_EMAIL = 'hello@updates.stressreleasor.com';  // Brevo subdomain ✅

$TEST_TO_EMAIL = 'inaamalvi1@gmail.com';  // Your email to receive test
// ============================================

/**
 * Simple Brevo email function
 */
function sendSimpleEmail($api_key, $from_email, $to_email, $subject, $message) {
    $url = 'https://api.brevo.com/v3/smtp/email';
    
    $data = [
        'sender' => [
            'email' => $from_email,
            'name' => 'StressReleasor'
        ],
        'to' => [
            [
                'email' => $to_email,
                'name' => 'Test User'
            ]
        ],
        'subject' => $subject,
        'htmlContent' => '<html><body><p>' . nl2br(htmlspecialchars($message)) . '</p></body></html>',
        'textContent' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $api_key
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => ($http_code == 201 || $http_code == 200),
        'http_code' => $http_code,
        'response' => $response
    ];
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Simple Email Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 700px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .box {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 { 
            color: #667eea; 
            margin-top: 0;
        }
        .success { 
            background: #d4edda; 
            color: #155724; 
            padding: 20px; 
            border-radius: 8px; 
            margin: 20px 0;
            border-left: 5px solid #28a745;
        }
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            padding: 20px; 
            border-radius: 8px; 
            margin: 20px 0;
            border-left: 5px solid #dc3545;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 5px solid #ffc107;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            border: 1px solid #ddd;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
        }
        button:hover { 
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .config {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 14px;
        }
        .step {
            background: #f8f9fa;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            border-left: 4px solid #667eea;
        }
        ol { 
            line-height: 2;
        }
        strong {
            color: #667eea;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            color: #e83e8c;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>📧 Super Simple Email Test</h1>
        
        <?php
        // Check if configured
        if ($BREVO_API_KEY == 'YOUR_API_KEY_HERE' || $TEST_TO_EMAIL == 'your-email@gmail.com') {
            ?>
            <div class="error">
                <h3>⚠️ Configuration Required</h3>
                <p>Please edit this file and change these 3 values at the top:</p>
            </div>
            
            <div class="config">
                <strong>Line 9:</strong> $BREVO_API_KEY = 'YOUR_API_KEY_HERE';<br>
                <strong>Line 10:</strong> $SENDER_EMAIL = 'noreply@stressreleasor.com';<br>
                <strong>Line 11:</strong> $TEST_TO_EMAIL = 'your-email@gmail.com';
            </div>
            
            <div class="step">
                <h3>How to get your Brevo API Key:</h3>
                <ol>
                    <li>Go to <a href="https://app.brevo.com" target="_blank">https://app.brevo.com</a></li>
                    <li>Click <strong>SMTP & API</strong> (in left menu)</li>
                    <li>Click <strong>API Keys</strong></li>
                    <li>Copy your API key</li>
                    <li>Paste it in this file on line 9</li>
                </ol>
            </div>
            <?php
        } elseif (isset($_GET['send'])) {
            // SEND THE EMAIL
            echo "<h2>🚀 Sending email...</h2>";
            
            echo '<div class="config">';
            echo '<strong>From:</strong> ' . htmlspecialchars($SENDER_EMAIL) . '<br>';
            echo '<strong>To:</strong> ' . htmlspecialchars($TEST_TO_EMAIL) . '<br>';
            echo '<strong>Time:</strong> ' . date('Y-m-d H:i:s');
            echo '</div>';
            
            $subject = "Test Email from StressReleasor - " . date('H:i:s');
            $message = "Hello!\n\n";
            $message .= "This is a test email from your StressReleasor website.\n\n";
            $message .= "If you're reading this, your Brevo email integration is working correctly! ✅\n\n";
            $message .= "Sent at: " . date('Y-m-d H:i:s') . "\n";
            $message .= "From: " . $SENDER_EMAIL . "\n\n";
            $message .= "Best regards,\n";
            $message .= "StressReleasor Team";
            
            $result = sendSimpleEmail($BREVO_API_KEY, $SENDER_EMAIL, $TEST_TO_EMAIL, $subject, $message);
            
            if ($result['success']) {
                echo '<div class="success">';
                echo '<h2>✅ SUCCESS! Email Sent to Brevo</h2>';
                echo '<p><strong>HTTP Code:</strong> ' . $result['http_code'] . '</p>';
                echo '<p>Brevo accepted your email and is delivering it now.</p>';
                echo '<hr>';
                echo '<h3>📬 Check Your Email:</h3>';
                echo '<ol>';
                echo '<li><strong>Inbox:</strong> ' . htmlspecialchars($TEST_TO_EMAIL) . '</li>';
                echo '<li><strong>Spam/Junk folder</strong> (very common!)</li>';
                echo '<li><strong>Promotions tab</strong> (if using Gmail)</li>';
                echo '<li><strong>Wait 5 minutes</strong> - emails can be delayed</li>';
                echo '</ol>';
                echo '</div>';
                
                echo '<div class="warning">';
                echo '<h3>⚠️ Email Not Arriving?</h3>';
                echo '<p><strong>Most Common Reason:</strong> Your sender email is not verified in Brevo!</p>';
                echo '<ol>';
                echo '<li>Go to <a href="https://app.brevo.com" target="_blank">Brevo Dashboard</a></li>';
                echo '<li>Click <strong>Senders & IP</strong> → <strong>Senders</strong></li>';
                echo '<li>Verify your email: <code>' . htmlspecialchars($SENDER_EMAIL) . '</code></li>';
                echo '<li>Check the email inbox for verification link</li>';
                echo '<li>Wait for "Verified ✓" status</li>';
                echo '</ol>';
                echo '</div>';
                
            } else {
                echo '<div class="error">';
                echo '<h2>❌ Email Failed</h2>';
                echo '<p><strong>HTTP Code:</strong> ' . $result['http_code'] . '</p>';
                
                $response_data = json_decode($result['response'], true);
                
                if ($result['http_code'] == 401) {
                    echo '<p><strong>Error:</strong> Invalid API Key</p>';
                    echo '<p>Your Brevo API key is incorrect or expired.</p>';
                    echo '<p>Get the correct key from: <a href="https://app.brevo.com" target="_blank">Brevo</a> → SMTP & API → API Keys</p>';
                } elseif ($result['http_code'] == 400) {
                    echo '<p><strong>Error:</strong> ' . ($response_data['message'] ?? 'Bad Request') . '</p>';
                    echo '<p>Check that your sender email is verified in Brevo!</p>';
                } else {
                    echo '<p><strong>Error:</strong> ' . ($response_data['message'] ?? 'Unknown error') . '</p>';
                }
                
                echo '</div>';
            }
            
            echo '<h3>📋 Full Response from Brevo:</h3>';
            echo '<pre>' . htmlspecialchars(json_encode(json_decode($result['response'], true), JSON_PRETTY_PRINT)) . '</pre>';
            
            echo '<br><a href="?"><button>← Back to Test Page</button></a>';
            
        } else {
            // SHOW TEST FORM
            ?>
            <p>Everything is configured! Ready to send a test email.</p>
            
            <div class="config">
                <strong>From:</strong> <?php echo htmlspecialchars($SENDER_EMAIL); ?><br>
                <strong>To:</strong> <?php echo htmlspecialchars($TEST_TO_EMAIL); ?><br>
                <strong>API Key:</strong> <?php echo substr($BREVO_API_KEY, 0, 10); ?>...
            </div>
            
            <div class="warning">
                <h3>✅ Before Clicking Send:</h3>
                <ol>
                    <li><strong>Verify your sender email in Brevo!</strong></li>
                    <li>Go to: <a href="https://app.brevo.com" target="_blank">Brevo</a> → Senders & IP → Senders</li>
                    <li>Make sure <code><?php echo htmlspecialchars($SENDER_EMAIL); ?></code> shows "Verified ✓"</li>
                    <li>If not verified, you'll get success but no email!</li>
                </ol>
            </div>
            
            <a href="?send=1"><button>📧 Send Test Email Now</button></a>
            
            <hr style="margin: 30px 0;">
            
            <h3>📊 Your Brevo Status:</h3>
            <ul>
                <li>✅ You have <strong>297 emails</strong> remaining</li>
                <li>✅ Valid until February 9, 2026</li>
                <li>⚠️ Make sure sender email is verified!</li>
            </ul>
            <?php
        }
        ?>
    </div>
</body>
</html>
