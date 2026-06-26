<?php
$error = isset($_GET['error']) ? $_GET['error'] : 'Unknown error';
?>
<!DOCTYPE html>
<html>
<head>
    <title>LinkedIn Authentication Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1 class="error">LinkedIn Authentication Error</h1>
    <p>Error: <?php echo htmlspecialchars($error); ?></p>
    <p><a href="index.php">Try Again</a></p>
</body>
</html> 