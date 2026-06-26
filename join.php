<?php
session_start();
include 'dbconnect_hdb.php';
$trigger = $_GET['trigger'] ?? 'work';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = mysqli_real_escape_string($conn, $_POST['user_name']);
    $email = mysqli_real_escape_string($conn, $_POST['user_email']);

    // Check or Insert User
    $check = mysqli_query($conn, "SELECT id FROM users_audio WHERE email = '$email'");
    if (mysqli_num_rows($check) > 0) {
        $user = mysqli_fetch_assoc($check);
        $user_id = $user['id'];
    } else {
        mysqli_query($conn, "INSERT INTO users_audio (name, email) VALUES ('$name', '$email')");
        $user_id = mysqli_insert_id($conn);
    }

    $_SESSION['audio_user_id'] = $user_id;
    $_SESSION['audio_user_name'] = $name;
    $_SESSION['audio_user_email'] = $email;

    // Set cookie for 30 days
    setcookie('audio_user_email', $email, time() + (86400 * 30), "/");

    header("Location: play_audio.php?trigger=$trigger");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Unlock Your Session</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #667eea; color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .form-card { background: white; color: #333; padding: 40px; border-radius: 30px; width: 90%; max-width: 400px; text-align: center; }
        input { width: 100%; padding: 15px; margin: 10px 0; border: 2px solid #eee; border-radius: 12px; font-size: 16px; }
        button { width: 100%; padding: 15px; background: #667eea; color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 18px; }
    </style>
</head>
<body>
    <div class="form-card">
        <h2>Great Choice.</h2>
        <p>Enter your details to unlock your targeted session for <strong><?php echo ucfirst($trigger); ?> Stress</strong>.</p>
        <form method="POST">
            <input type="text" name="user_name" placeholder="First Name" required>
            <input type="email" name="user_email" placeholder="Email Address" required>
            <button type="submit">Unlock My Audio →</button>
        </form>
    </div>
</body>
</html>