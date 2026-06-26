<?php
session_start();
ini_set('session.gc_maxlifetime', 15552000);  // 180 days in seconds
ini_set('session.cookie_lifetime', 15552000); // 180 days
session_set_cookie_params(15552000);
// Protect page (only logged-in users)
if(!isset($_SESSION['admin_id'])){
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); // change if your login file is named differently
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Main Menu</title>

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

/* Menu Box */

.menu-box{
    background:#fff;
    padding:30px 25px;
    width:100%;
    max-width:360px;
    border-radius:14px;
    box-shadow:0 6px 20px rgba(0,0,0,0.25);
    text-align:center;
}

.menu-box h2{
    margin-top:0;
    color:#5b3790;
}

/* Buttons */

.menu-btn{
    width:100%;
    padding:14px;
    margin:10px 0;
    background:#5b3790;
    border:none;
    color:#fff;
    font-size:16px;
    border-radius:8px;
    cursor:pointer;
}

.menu-btn:hover{
    background:#6f46ad;
}

.logout{
    background:#b83232;
}

.logout:hover{
    background:#d64545;
}

</style>
</head>

<body>

<div class="menu-box">

<h2>🌿 Main Menu</h2>

<p>Welcome, <b><?php echo $_SESSION['user']; ?></b></p> 
<a href="nl_tag_analyzer2.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 8a. Vv - NL tags with industry <button>
</a>
<!-- Option 1 -->
<form action="vizard_browser.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 1. VideoVizard - Social Media idea to posting ..<button>
</a>
</form>

<form action="generate_batch_images.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱2. Vv - DB image generator <button>
</a>
</form>



<form action="media_review.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 3. Vv - Media Review<button>
</a>
</form>

<form action="auto_thumbnail.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 4. Vv - Create thumbnail<button>
</a>
</form>

<form action="scene_inspector.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 5. Vv - Inspect Podcast scenes <button>
</a>
</form>

<form action="test_matcher.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 6. Vv - Match Tags <button>
</a>
</form>
<form action="check_image_datawithfolders.php" method="get">
<a href="check_image_datawithfolders.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 7. Vv - Check DB against folders <button>
</a>
</form>
<form action="auto_thumbnail.php" method="get">
<a href="vizard_browser.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 8. Vv - Thumbnail generator <button>
</a>
</form>



<form action="generate_embeddings.php" method="get">
<a href="generate_embeddings.php" style="text-decoration:none;"> 
    <button class="menu-btn">📱 9. Vv - compare files image data <button>
</a>
</form>
<form action="generate_embeddings.php" method="get">
<a href="p" style="text-decoration:none;"> 
    <button class="menu-btn">📱 10 - clean nl tags from imagedata <button>
</a>





<!-- Option 2 -->
<form action="stress.php" method="get">
<a href="stressreleasor.com/admin_dashboard.php" style="text-decoration:none;">
    <button class="menu-btn">🧘 Manage Stress Releasor</button>
</a>
</form>

<!-- Log Out -->
<form action="logout.php" method="post">
<a href="stressreleasor.com/logout.php" style="text-decoration:none;">
    <button class="menu-btn logout">🚪 Log Out</button>
<a>
</form>

</div>

</body>
</html>
