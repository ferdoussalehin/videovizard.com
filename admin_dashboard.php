<?php
session_start();
include 'dbconnect_hdb.php';

// 1. Handle Search Input
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
// Define the date threshold
$seven_days_ago = date('Y-m-d', strtotime('-7 days'));

// 1. Total New Clients (Last 7 Days)
$count_new_q = "SELECT COUNT(*) as total FROM hdb_clients WHERE STR_TO_DATE(created_at, '%Y-%m-%d') >= '$seven_days_ago'";
$count_new_res = mysqli_query($conn, $count_new_q);
$total_new = mysqli_fetch_assoc($count_new_res)['total'];

// 2. Total Active Clients (Prior to 7 days)
$count_active_q = "SELECT COUNT(*) as total FROM hdb_clients WHERE STR_TO_DATE(created_at, '%Y-%m-%d') < '$seven_days_ago'";
$count_active_res = mysqli_query($conn, $count_active_q);
$total_active = mysqli_fetch_assoc($count_active_res)['total'];
// 2. Build Search Query
$where_clause = "";
if (!empty($search)) {
    $where_clause = " WHERE u.name LIKE '%$search%' OR u.email LIKE '%$search%' ";
}
// --- Group 1: New Signups (Last 7 Days) ---
// Since created_at is a string, we compare it using MySQL date functions
$seven_days_ago = date('Y-m-d', strtotime('-7 days'));
$new_clients_query = "SELECT firstname, lastname, email_id, session_name, client_status, created_at 
                      FROM hdb_clients 
                      WHERE STR_TO_DATE(created_at, '%Y-%m-%d') >= '$seven_days_ago'
                      ORDER BY created_at DESC";
$new_clients_result = mysqli_query($conn, $new_clients_query);

// --- Group 2: Active Clients (The rest) ---
$active_clients_query = "SELECT firstname, lastname, email_id, session_name, client_status, updated_at 
                         FROM hdb_clients 
                         WHERE STR_TO_DATE(created_at, '%Y-%m-%d') < '$seven_days_ago'
                         ORDER BY updated_at DESC";
$active_clients_result = mysqli_query($conn, $active_clients_query);
// 3. Fetch Users with Stats
$query = "SELECT 
            u.id, u.name, u.email, u.last_active, u.created_at,
            COUNT(t.id) as total_sessions,
            IFNULL(SUM(t.duration_seconds), 0) as total_seconds
          FROM users_audio u
          LEFT JOIN audio_tracking t ON u.id = t.user_id
          $where_clause
          GROUP BY u.id
          ORDER BY u.last_active DESC";

$result = mysqli_query($conn, $query);

// --- Group 3: Quiz Takers ---
$quiz_query = "SELECT user_name, user_age, user_profession, email, converted_to_paid, created_at 
               FROM strength_quiz_results 
               ORDER BY created_at DESC 
               LIMIT 10"; // Adjust limit as needed
$quiz_result = mysqli_query($conn, $quiz_query);

// Count total quiz takers for a new stat card
$count_quiz_q = "SELECT COUNT(*) as total FROM strength_quiz_results";
$count_quiz_res = mysqli_query($conn, $count_quiz_q);
$total_quiz_takers = mysqli_fetch_assoc($count_quiz_res)['total'];


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Listener Dashboard | StressReleasor</title>
    <style>
        :root { --primary: #667eea; --dark: #1a1a2e; --light: #f4f7f6; }
        body { font-family: 'Inter', sans-serif; background: var(--light); padding: 20px; color: #333; }
        .container { max-width: 1100px; margin: 0 auto; }
        
        /* Search Bar Styles */
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .search-box { display: flex; gap: 10px; }
        .search-box input { padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; width: 300px; font-size: 14px; }
        .btn { padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        
        /* Table Styles */
        .stats-card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9ff; text-align: left; padding: 15px; border-bottom: 2px solid #eee; color: #666; text-transform: uppercase; font-size: 12px; }
        td { padding: 15px; border-bottom: 1px solid #eee; font-size: 14px; }
        tr:hover { background: #fcfcff; }
        
        .badge { padding: 4px 8px; background: #e0e7ff; color: #4338ca; border-radius: 4px; font-weight: 600; }
        .time-badge { background: #ecfdf5; color: #059669; }
        .no-results { padding: 40px; text-align: center; color: #999; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-actions">
        <h1>Audio Listeners</h1>
        
        <form class="search-box" method="GET">
            <input type="text" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if(!empty($search)): ?>
                <a href="admin_dashboard.php" class="btn" style="background:#eee; color:#666;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="stats-card">
        <table>
            <thead>
                <tr>
                    <th>User Details</th>
                    <th>Engagement</th>
                    <th>Total Time</th>
                    <th>Joined</th>
                    <th>Last Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if(mysqli_num_rows($result) > 0): ?>
                    <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($row['name']); ?></strong><br>
                            <span style="color:#888; font-size:12px;"><?php echo htmlspecialchars($row['email']); ?></span>
                        </td>
                        <td><span class="badge"><?php echo $row['total_sessions']; ?> Sessions</span></td>
                        <td>
                            <span class="badge time-badge">
                                <?php echo ($row['total_seconds'] > 0) ? round($row['total_seconds'] / 60, 1) . ' mins' : '0 mins'; ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
                        <td>
                            <?php 
                                $last = strtotime($row['last_active']);
                                echo (time() - $last < 3600) ? '<strong>Just now</strong>' : date('M j, g:i a', $last);
                            ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="no-results">No listeners found matching your search.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="container" style="margin-bottom: 20px;">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        
        <div style="background: #10b981; color: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2);">
            <div style="font-size: 14px; text-transform: uppercase; opacity: 0.9; font-weight: 600;">New Clients (7 Days)</div>
            <div style="font-size: 36px; font-weight: 800; margin-top: 5px;">
                <?php echo $total_new; ?>
            </div>
            <div style="font-size: 12px; margin-top: 10px;">Recent signups requiring attention</div>
        </div>

        <div style="background: #667eea; color: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);">
            <div style="font-size: 14px; text-transform: uppercase; opacity: 0.9; font-weight: 600;">Total Active Clients</div>
            <div style="font-size: 36px; font-weight: 800; margin-top: 5px;">
                <?php echo $total_active; ?>
            </div>
            <div style="font-size: 12px; margin-top: 10px;">Long-term clients in the database</div>
        </div>

    </div>
	
	
	
	<div style="background: #f59e0b; color: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);">
    <div style="font-size: 14px; text-transform: uppercase; opacity: 0.9; font-weight: 600;">Quiz Leads</div>
    <div style="font-size: 36px; font-weight: 800; margin-top: 5px;">
        <?php echo $total_quiz_takers; ?>
    </div>
    <div style="font-size: 12px; margin-top: 10px;">Potential clients from Strength Quiz</div>
</div>
</div>
<div class="container" style="margin-top: 50px;">
    <h2>Clinical Client Overview</h2>

    <div class="stats-card" style="border-left: 5px solid #10b981; margin-bottom: 30px;">
        <div style="padding: 15px; background: #f0fdf4; font-weight: bold; color: #065f46;">
            🌟 New Clients (Joined Last 7 Days)
        </div>
        <table>
            <thead>
                <tr>
                    <th>Client Name</th>
                    <th>Session Type</th>
                    <th>Status</th>
                    <th>Joined On</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($new_clients_result)): ?>
                <tr>
                    <td><strong><?php echo $row['firstname'] . " " . $row['lastname']; ?></strong><br>
                        <small><?php echo $row['email_id']; ?></small>
                    </td>
                    <td><span class="badge" style="background:#f3f4f6; color:#374151;"><?php echo $row['session_name']; ?></span></td>
                    <td><span class="badge" style="background:#d1fae5; color:#065f46;"><?php echo $row['client_status']; ?></span></td>
                    <td><?php echo $row['created_at']; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div class="stats-card" style="border-left: 5px solid var(--primary);">
        <div style="padding: 15px; background: #f8f9ff; font-weight: bold; color: var(--primary);">
            👥 Active Clients
        </div>
        <table>
            <thead>
                <tr>
                    <th>Client Name</th>
                    <th>Session Type</th>
                    <th>Status</th>
                    <th>Last Activity</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($active_clients_result)): ?>
                <tr>
                    <td><strong><?php echo $row['firstname'] . " " . $row['lastname']; ?></strong></td>
                    <td><?php echo $row['session_name']; ?></td>
                    <td><span class="badge"><?php echo $row['client_status']; ?></span></td>
                    <td><?php echo $row['updated_at']; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="container" style="margin-top: 50px; margin-bottom: 50px;">
    <h2>Strength Quiz Leads</h2>
    <div class="stats-card" style="border-left: 5px solid #f59e0b;">
        <div style="padding: 15px; background: #fffbeb; font-weight: bold; color: #b45309;">
            🧠 Recent Quiz Participants
        </div>
        <table>
            <thead>
                <tr>
                    <th>Participant</th>
                    <th>Profession & Age</th>
                    <th>Status</th>
                    <th>Taken On</th>
                </tr>
            </thead>
            <tbody>
                <?php while($quiz = mysqli_fetch_assoc($quiz_result)): ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($quiz['user_name']); ?></strong><br>
                        <small><?php echo htmlspecialchars($quiz['email']); ?></small>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($quiz['user_profession']); ?> 
                        <span class="badge" style="background:#eee; color:#666; font-size:10px;">
                            Age: <?php echo htmlspecialchars($quiz['user_age']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if($quiz['converted_to_paid']): ?>
                            <span class="badge" style="background:#d1fae5; color:#065f46;">Paid Client</span>
                        <?php else: ?>
                            <span class="badge" style="background:#fee2e2; color:#991b1b;">Lead</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M j, Y', strtotime($quiz['created_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>