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



// Path to the image file
$imagePath = __DIR__ . '/test.jpg';

// Check if image exists
if (!file_exists($imagePath)) {
    echo "<h2>Image Not Found</h2>";
    echo "<p>The image file 'test.jpg' does not exist in the current directory.</p>";
    exit;
}

// First, register the image upload with LinkedIn
try {
    echo "<h3>Step 1: Registering image with LinkedIn...</h3>";
    $registerResult = $linkedIn->registerImageUrl();
    echo "<pre>";
    print_r($registerResult);
    echo "</pre>";
    
    if (!isset($registerResult['uploadedUrl']) || !isset($registerResult['assets'])) {
        echo "<h2>Failed to register image upload</h2>";
        exit;
    }
    
    // Upload the image to the provided URL
    echo "<h3>Step 2: Uploading image to LinkedIn...</h3>";
    $imageContent = file_get_contents($imagePath);
    $uploadResult = $linkedIn->uploadImage(['thumbnails' => $imageContent], $registerResult['uploadedUrl']);
    echo "<pre>";
    print_r($uploadResult);
    echo "</pre>";
    
    if ($uploadResult !== true) {
        echo "<h2>Failed to upload image</h2>";
        exit;
    }
    
    // Now create a post with the uploaded image
    echo "<h3>Step 3: Creating post with the uploaded image...</h3>";
    
    // Example content to post
    $content = [
        'title' => 'Test Post with Image from PHP Application',
        'description' => 'This is a test post with an image created using the LinkedIn API.',
        'asset' => $registerResult['assets']
    ];
    
    // Create a share content with the image
    $result = $linkedIn->send_to_linkedIn_feeds_with_image($content, 'image');
    
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
        echo "<p>Your post with image has been published to your LinkedIn profile feed.</p>";
    } else {
        echo "<h2>Failed to Post</h2>";
        echo "<p>Check the LinkedIn API logs for more details.</p>";
    }
    
} catch (Exception $e) {
    echo "<h2>Error</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
} 