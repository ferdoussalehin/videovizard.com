<?php
session_start();

if(isset($_SESSION['user'])){
    header("Location: menu.php");
    exit;
}





session_start();
// Database configuration

// Set session to last for 30 days (30 days * 24 hours * 60 mins * 60 secs) 
$timeout = 30 * 24 * 60 * 60; 
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout);

$current_id = session_id();

include 'dbconnect_hdb.php';



 
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Escape inputs to prevent basic SQL injection
    $user = mysqli_real_escape_string($conn, $_POST['user_name']);
    $pass = mysqli_real_escape_string($conn, $_POST['password']);
   

    // Standard procedural query
    $sql = "SELECT * FROM hdb_users 
            WHERE user_name = '$user' 
            AND password = '$pass' 
          
            LIMIT 1";
	
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) === 1) {
		$row = mysqli_fetch_assoc($result);

		// 1. First, create the new session identity
		session_regenerate_id(true);
		
		// 2. NOW grab the fresh ID that was just created
		$current_id = session_id(); 
		   
		// 3. Set your data
		$_SESSION['admin_id']   = $row['id'];
		$_SESSION['level']      = $row['level_name'];
		$_SESSION['client_id']  = $row['client_id'];
		$_SESSION['created_at'] = time();

		// 4. Send the fresh ID to the browser cookie
		setcookie(session_name(), $current_id, time() + (30 * 24 * 60 * 60), "/");
//echo "gonig";die;
//echo "going";die;
		header("Location: vidora_home.php");
		exit();
	}
	else
	{
			echo "failed".$sql;die;
	}
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

<form method="post" action="">

<input type="text" name="user_name" placeholder="Username" required>

<input type="text" name="password" placeholder="Password" required>

<button type="submit">Login</button>

</form>

</div>

</body>
</html>
