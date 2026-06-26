<?php
$error = $_GET['error'] ?? 'unknown';
$errorMessages = [
    'no_code' => 'No authorization code received from LinkedIn.',
    'invalid_state' => 'Invalid state parameter. Possible CSRF attack.',
    'access_denied' => 'Access was denied by the user.',
    'unknown' => 'An unknown error occurred.'
];
$message = $errorMessages[$error] ?? $errorMessages['unknown'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>LinkedIn Authentication Error</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .error-message {
            background-color: #f2dede;
            border: 1px solid #ebccd1;
            color: #a94442;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="error-message">
        <h2>LinkedIn Authentication Error</h2>
        <p><?php echo htmlspecialchars($message); ?></p>
    </div>

    <p><a href="index.php">Try Again</a></p>
</body>
</html> 