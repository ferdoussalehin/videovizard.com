<?php
require_once 'linkedin_handler.php';

// Initialize the handler with your domain
$linkedin = new LinkedInCredentialHandler('http://localhost:8000');

// The credentials are now loaded from environment variables
// No need to call saveCredentials() as that's been replaced with saveRedirectUrl()

// If you need to update the redirect URL, use:
$linkedin->saveRedirectUrl('http://localhost:8000/callback.php');

// Step 1: Check if credentials are properly set
if (!$linkedin->hasValidCredentials()) {
    echo "<div style='color: red; padding: 10px; background-color: #ffeeee; border: 1px solid #ffcccc;'>";
    echo "<strong>Error:</strong> LinkedIn API credentials not set. Please configure the LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET environment variables.";
    echo "</div>";
    echo "<p>See the README.md file for instructions on setting up environment variables.</p>";
    exit;
}

// Step 2: Get the LinkedIn login URL
$loginUrl = $linkedin->getLinkedInLoginLink();
echo "<p>Login URL: <a href='" . htmlspecialchars($loginUrl) . "'>Connect to LinkedIn</a></p>";

// Step 3: Example of sharing content (after authentication)
if ($linkedin->getAccessToken()) {
    echo "<h2>You are authenticated with LinkedIn</h2>";
    
    // Simple text post
    $simpleContent = [
        'title' => 'My Post Title',
        'description' => 'This is a test post description',
        'url' => 'https://example.com/post'
    ];
    
    echo "<h3>Example: Post to LinkedIn</h3>";
    echo "<pre>" . htmlspecialchars(print_r($simpleContent, true)) . "</pre>";
    echo "<p>To actually post this content, uncomment the code in example.php</p>";
    
    // Uncomment to actually post to LinkedIn:
    /*
    $result = $linkedin->send_to_linkedIn_feeds($simpleContent, 'simple');
    echo "<p>Simple post result: " . ($result ? "Success" : "Failed") . "</p>";
    */
    
    // Example with image (commented out)
    /*
    // Advanced post with image
    $advancedContent = [
        'title' => 'My Image Post',
        'description' => 'This is a test post with image',
        'thumbnails' => file_get_contents('test.jpg')  // Make sure this file exists
    ];
    
    $result = $linkedin->send_to_linkedIn_feeds($advancedContent, 'advanced');
    echo "<p>Advanced post result: " . ($result ? "Success" : "Failed") . "</p>";
    */
} else {
    echo "<p>Not authenticated. Please login first.</p>";
}

// Step 4: Example of managing options
$options = [
    'linkedIn_button_showing_status' => true,
    'linkedIn_shared_type' => 'simple'
];
$linkedin->set_linkedIn_manage_option_settings($options);

// Get current settings
$currentSettings = $linkedin->get_linkedIn_manage_option_settings();
echo "<h3>Current Settings:</h3>";
echo "<pre>" . htmlspecialchars(print_r($currentSettings, true)) . "</pre>"; 