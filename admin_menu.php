<?php
session_start();


//echo "session is ".$_SESSION['admin_id'];die;
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); // Redirect back if not logged in
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | Releasor</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; margin: 0; padding: 20px; }
        .dashboard-container { max-width: 400px; margin: auto; background: white; padding: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { font-size: 14px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .menu-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px; }
        
        /* Small font styling per your preference */
        .btn {
            display: block;
            padding: 10px;
            background: #007bff;
            color: white;
            text-decoration: none;
            text-align: center;
            border-radius: 3px;
            font-size: 11px; 
            font-weight: bold;
            transition: background 0.2s;
        }
        .btn:hover { background: #0056b3; }
        .btn.logout { background: #dc3545; grid-column: span 2; margin-top: 10px; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <h1>Control Panel: <?php echo $_SESSION['level']; ?></h1> 
    
    <div class="menu-grid">
        <a href="admin_dashboard.php" class="btn">Dashboard</a>
        <a href="videomaker.php" class="btn">Video Maker</a>
        <a href="podcast_translator.php" class="btn">Generate Languages</a>
        <a href="settings.php" class="btn">System Settings</a>
        
        <a href="logout.php" class="btn logout">Sign Out</a>
    </div>
</div>

</body>
</html>