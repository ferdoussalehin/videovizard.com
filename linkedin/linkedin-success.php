<?php
require_once 'linkedin_handler.php';

$linkedin = new LinkedInCredentialHandler('http://localhost:8000');
?>
<!DOCTYPE html>
<html>
<head>
    <title>LinkedIn Authentication Success</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .success-message {
            background-color: #dff0d8;
            border: 1px solid #d6e9c6;
            color: #3c763d;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .token-info {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="success-message">
        <h2>LinkedIn Authentication Successful!</h2>
        <p>You have successfully connected your LinkedIn account.</p>
    </div>

    <div class="token-info">
        <h3>Token Information:</h3>
        <?php if ($linkedin->getAccessToken()): ?>
            <p><strong>Access Token:</strong> <?php echo substr($linkedin->getAccessToken(), 0, 10) . '...'; ?></p>
            <p><strong>Token Expires In:</strong> <?php echo $linkedin->getTokenExpiredInTime(); ?> seconds</p>
            <p><strong>User URN:</strong> <?php echo $linkedin->get_user_URN(); ?></p>
        <?php else: ?>
            <p>No access token available.</p>
        <?php endif; ?>
    </div>

    <p><a href="example.php">Go to Example Page</a></p>
</body>
</html> 