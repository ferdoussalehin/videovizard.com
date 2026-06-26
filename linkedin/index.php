<?php
require_once 'linkedin_handler.php';

// Initialize the LinkedIn handler
$linkedIn = new LinkedInCredentialHandler('http://localhost:8000');

// Update the redirect URL if needed
$linkedIn->saveRedirectUrl('http://localhost:8000/callback.php');

// Check if we need to redirect to LinkedIn
$redirectToLinkedIn = isset($_GET['connect']) && $_GET['connect'] == '1';

if ($redirectToLinkedIn && $linkedIn->hasValidCredentials()) {
    // Get the LinkedIn login URL and redirect
    header('Location: ' . $linkedIn->getLinkedInLoginLink());
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>LinkedIn Integration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 4px;
        }
        .button {
            display: inline-block;
            background-color: #0077b5;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 0;
        }
        .button:hover {
            background-color: #005582;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>LinkedIn Integration</h1>
        
        <?php if (!$linkedIn->hasValidCredentials()): ?>
            <div class="error">
                <h2>LinkedIn API Credentials Missing</h2>
                <p>Please set up your LinkedIn API credentials in environment variables:</p>
                <ul>
                    <li>LINKEDIN_CLIENT_ID</li>
                    <li>LINKEDIN_CLIENT_SECRET</li>
                </ul>
                <p>See the README.md file for instructions.</p>
            </div>
        <?php elseif ($linkedIn->getAccessToken()): ?>
            <div class="status">
                <h2>Connected to LinkedIn</h2>
                <p>You are already authenticated with LinkedIn.</p>
                <a href="example.php" class="button">Try Posting Content</a>
            </div>
        <?php else: ?>
            <div class="status">
                <h2>Connect to LinkedIn</h2>
                <p>Click the button below to connect your LinkedIn account:</p>
                <a href="?connect=1" class="button">Connect with LinkedIn</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html> 