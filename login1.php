<?php
session_start();

if(isset($_SESSION['user'])){
    header("Location: menu.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>StressReleasor Login</title>

<style>

body{
    margin:0;
    min-height:100vh;
    background: linear-gradient(135deg,#3c225f,#5b3790);
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:Segoe UI,sans-serif;
}

/* Login Card */

.login-box{
    background:#fff;
    padding:25px 22px;
    width:100%;
    max-width:340px;
    border-radius:14px;
    box-shadow:0 6px 20px rgba(0,0,0,0.25);
    text-align:center;
}

.login-box h2{
    margin-top:0;
    color:#5b3790;
}

/* Inputs */

input{
    width:100%;
    padding:12px;
    margin:10px 0;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
}

/* Button */

button{
    width:100%;
    padding:12px;
    background:#5b3790;
    border:none;
    color:#fff;
    font-size:16px;
    border-radius:8px;
    cursor:pointer;
}

button:hover{
    background:#6f46ad;
}

/* Error */

.error{
    color:red;
    margin-bottom:10px;
    font-size:14px;
}

</style>
</head>

<body>

<div class="login-box">

<h2>🌿 StressReleasor</h2>

<?php if(isset($_GET['err'])): ?>
<div class="error">Invalid Login</div>
<?php endif; ?>

<form method="post" action="auth.php">

<input type="text" name="user" placeholder="Username" required>

<input type="password" name="pass" placeholder="Password" required>

<button type="submit">Login</button>

</form>

</div>

</body>
</html>
