<?php
require_once 'linkedin_handler.php';

// Initialize the LinkedIn handler
$linkedIn = new LinkedInCredentialHandler('http://localhost:8000');

// Debug: Check if we have an access token
$accessToken = $linkedIn->getAccessToken();
echo "<h3>Access Token Status:</h3>";
echo "<pre>";
echo "Has Access Token: " . (!empty($accessToken) ? "Yes" : "No") . "\n";
if (!empty($accessToken)) {
    echo "Token: " . substr($accessToken, 0, 20) . "...\n";
} else {
    echo "Token: Not available\n";
    echo "</pre>";
    echo "<h2>Authentication Required</h2>";
    echo "<p>You need to authenticate with LinkedIn before posting content.</p>";
    echo "<p>Please visit <a href='index.php'>the authentication page</a> to connect your LinkedIn account.</p>";
    exit; // Stop execution if no token is available
}
echo "</pre>";

// Debug: Check user info file
$userInfoFile = __DIR__ . '/linkedin_user_info.json';
echo "<h3>User Info File Status:</h3>";
echo "<pre>";
echo "File exists: " . (file_exists($userInfoFile) ? "Yes" : "No") . "\n";
if (file_exists($userInfoFile)) {
    echo "File contents:\n";
    print_r(json_decode(file_get_contents($userInfoFile), true));
}
echo "</pre>";

// Debug: Check user URN
$userURN = $linkedIn->get_user_URN();
echo "<h3>User URN Status:</h3>";
echo "<pre>";
echo "Has User URN: " . (!empty($userURN) ? "Yes" : "No") . "\n";
echo "URN: " . $userURN . "\n";
echo "</pre>";

// Check if we have a URN before attempting to post
if (empty($userURN)) {
    echo "<h2>LinkedIn User ID Not Available</h2>";
    echo "<p>Your LinkedIn user ID (URN) could not be retrieved. This might be due to:</p>";
    echo "<ul>";
    echo "<li>Missing permissions in your LinkedIn application</li>";
    echo "<li>Invalid or expired access token</li>";
    echo "<li>API connectivity issues</li>";
    echo "</ul>";
    echo "<p>Please check the API logs for more details and try re-authenticating.</p>";
    echo "<p><a href='index.php'>Go to authentication page</a></p>";
    exit;
}

// Example content to post
$content = [
    'title' => 'Test Post from PHP Application',
    'description' => 'This is a test post created using the LinkedIn API. You can find this post on your LinkedIn profile feed.',
    'url' => 'https://www.linkedin.com/feed/'  // LinkedIn feed URL
];

// Try to post to LinkedIn
$result = $linkedIn->send_to_linkedIn_feeds($content, 'simple');

// Show detailed result
echo "<h2>Post Attempt Result:</h2>";
echo "<pre>";
if (is_array($result)) {
    print_r($result);
} else {
    echo "Result: " . ($result ? "Success" : "Failure") . "\n";
}
echo "</pre>";

if ($result === true) {
    echo "<h2>Post Successful!</h2>";
    echo "<p>Your post has been published to your LinkedIn profile feed.</p>";
    echo "<p><strong>Note:</strong> This post is set to <strong>PUBLIC</strong> visibility.</p>";
    echo "<p>To view your post:</p>";
    echo "<ol>";
    echo "<li>Go to <a href='https://www.linkedin.com/feed/' target='_blank'>LinkedIn Feed</a></li>";
    echo "<li>Or visit your LinkedIn profile</li>";
    echo "</ol>";
} else {
    echo "<h2>Failed to Post</h2>";
    
    // Show detailed error information
    if (is_array($result) && isset($result['body'])) {
        $errorData = json_decode($result['body'], true);
        echo "<h3>LinkedIn API Error Details:</h3>";
        echo "<pre>";
        print_r($errorData);
        echo "</pre>";
    }
    
    echo "<h3>Possible Issues:</h3>";
    echo "<ol>";
    echo "<li>Authentication Status: " . (!empty($accessToken) ? "Authenticated" : "Not Authenticated") . "</li>";
    echo "<li>User URN Status: " . (!empty($userURN) ? "Available" : "Not Available") . "</li>";
    echo "<li>Response Code: " . (is_array($result) && isset($result['response_code']) ? $result['response_code'] : "Unknown") . "</li>";
    echo "</ol>";
    
    echo "<h3>Next Steps:</h3>";
    echo "<ol>";
    echo "<li>If not authenticated, visit <a href='index.php'>index.php</a> to authenticate</li>";
    echo "<li>If authenticated but URN is missing, try re-authenticating</li>";
    echo "<li>Check if your LinkedIn application has the correct permissions (w_member_social scope)</li>";
    echo "</ol>";
} 