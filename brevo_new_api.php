<?php

function sendSimpleEmail($from_email, $to_email, $subject, $message) 
{
    $url = 'https://api.brevo.com/v3/smtp/email';
    $api_key = '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';
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

/**
 * Send formatted HTML email via Brevo API (for Inner Strength Quiz)
 * 
 * @param string $toEmail Recipient email address
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlContent Full HTML email content
 * @return array ['success' => bool, 'messageId' => string|null, 'error' => string|null]
 */
function sendFormattedEmail($toEmail, $toName, $subject, $htmlContent) {
    
    $url = 'https://api.brevo.com/v3/smtp/email';
    $api_key = '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';
    
    // Validate inputs
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'error' => 'Invalid email address'
        ];
    }
    
    if (empty($subject)) {
        return [
            'success' => false,
            'error' => 'Subject is required'
        ];
    }
    
    if (empty($htmlContent)) {
        return [
            'success' => false,
            'error' => 'Email content is required'
        ];
    }
    
    // Prepare email data
    $data = [
        'sender' => [
            'name' => 'Inam Alvi - StressReleasor',
            'email' => 'no-reply@stressreleasor.com' // Change this if not verified!
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $toName
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent
    ];
    
    // Initialize cURL
    $ch = curl_init($url);
    
    if ($ch === false) {
        return [
            'success' => false,
            'error' => 'Failed to initialize cURL'
        ];
    }
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'api-key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    // Log for debugging
    error_log("=== STRENGTH QUIZ EMAIL ===");
    error_log("To: $toEmail ($toName)");
    error_log("Subject: $subject");
    error_log("HTTP Code: $httpCode");
    error_log("Response: $response");
    if ($curlError) {
        error_log("cURL Error: $curlError");
    }
    error_log("===========================");
    
    // Handle cURL errors
    if ($curlError) {
        return [
            'success' => false,
            'error' => 'CURL Error: ' . $curlError
        ];
    }
    
    // Handle HTTP errors
    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMsg = "HTTP $httpCode";
        
        if ($response) {
            $responseData = json_decode($response, true);
            if (isset($responseData['message'])) {
                $errorMsg .= ": " . $responseData['message'];
            } elseif (isset($responseData['code'])) {
                $errorMsg .= " - Code: " . $responseData['code'];
            } else {
                $errorMsg .= ": " . substr($response, 0, 200);
            }
        }
        
        return [
            'success' => false,
            'error' => $errorMsg
        ];
    }
    
    // Success!
    $responseData = json_decode($response, true);
    
    return [
        'success' => true,
        'messageId' => $responseData['messageId'] ?? null,
        'response' => $response
    ];
}

/**
 * Legacy function for backward compatibility
 */
function sendBrevoEmail($toEmail, $toName, $subject, $htmlContent) {
    return sendFormattedEmail($toEmail, $toName, $subject, $htmlContent);
}

?>