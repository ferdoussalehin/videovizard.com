<?php
/**
 * BACKWARD COMPATIBLE sendBrevoEmail
 * Returns string (like your old code) instead of array
 * Drop-in replacement for your existing sendBrevoEmail function
 */

/**
 * Send email via Brevo - Returns STRING for backward compatibility
 * 
 * @param string $to_email Recipient email
 * @param string $to_name Recipient name
 * @param string $subject Email subject
 * @param string $message Email body (HTML or plain text)
 * @return string "HTTP Code: 201 | Response: ..." format
 */
function sendBrevoEmail2($to_email, $to_name, $subject, $message) {
    $api_key = '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';
    $from_email = 'hello@stressreleasor.com';  // Your authenticated sender
    $from_name = 'StressReleasor';
    
    $url = 'https://api.brevo.com/v3/smtp/email';
    
    // Prepare email data
    $data = [
        'sender' => [
            'email' => $from_email,
            'name' => $from_name
        ],
        'to' => [
            [
                'email' => $to_email,
                'name' => $to_name
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $message,
        'textContent' => strip_tags($message)
    ];
    
    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $api_key
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Format response as STRING (backward compatible with your old code)
    if ($curl_error) {
        return "HTTP Code: 0 | cURL Error: " . $curl_error;
    }
    
    // Return in format: "HTTP Code: 201 | Response: {json}"
    return "HTTP Code: " . $http_code . " | Response: " . $response;
}

/**
 * Optional: Version that returns array (for new code)
 */


// Example usage showing the difference:

/*
// OLD STYLE (returns string):
$result = sendBrevoEmail('user@example.com', 'John', 'Subject', 'Body');
if (strpos($result, 'HTTP Code: 201') !== false) {
    echo "Success!";
}

// NEW STYLE (returns array):
$result = sendBrevoEmailArray('user@example.com', 'John', 'Subject', 'Body');
if ($result['success']) {
    echo "Success! Message ID: " . $result['messageId'];
}
*/
?>
