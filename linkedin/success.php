<?php
require_once 'linkedin_handler.php';

$linkedIn = new LinkedInCredentialHandler('http://localhost:8000');
$accessToken = $linkedIn->getAccessToken();
?>
<!DOCTYPE html>
<html>
<head>
    <title>LinkedIn Authentication Success</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .token { word-break: break-all; background: #f5f5f5; padding: 10px; }
    </style>
</head>
<body>
    <h1 class="success">LinkedIn Authentication Successful!</h1>
    <?php if ($accessToken): ?>
        <h2>Access Token:</h2>
        <div class="token"><?php echo htmlspecialchars($accessToken); ?></div>
    <?php else: ?>
        <p>No access token available.</p>
    <?php endif; ?>
    <p><a href="index.php">Back to Home</a></p>
</body>
</html> 