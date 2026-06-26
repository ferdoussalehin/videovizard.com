<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');

session_start();
require_once 'dbconnect_hdb.php';
header('Content-Type: application/json');

error_log("[save_tokens] SESSION: " . json_encode($_SESSION) . " POST: " . json_encode($_POST));

if (!isset($_SESSION['admin_id'])) {
    error_log("[save_tokens] ERROR: not logged in");
    echo json_encode(['success' => false, 'error' => 'not logged in']);
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$earned   = (int)($_POST['earned'] ?? 0);
$game     = mysqli_real_escape_string($conn, trim($_POST['game'] ?? ''));

error_log("[save_tokens] admin_id=$admin_id earned=$earned game=$game");

$allowed_games = ['ttt', 'words', 'emoji', 'math', 'grid'];

if ($earned <= 0 || $earned > 10) {
    error_log("[save_tokens] ERROR: invalid earned=$earned");
    echo json_encode(['success' => false, 'error' => 'invalid amount']);
    exit;
}
if (!in_array($game, $allowed_games)) {
    error_log("[save_tokens] ERROR: invalid game=$game");
    echo json_encode(['success' => false, 'error' => 'invalid game']);
    exit;
}

// Upsert per-game row
$sql = "INSERT INTO hdb_game_tokens (admin_id, game, tokens, plays, last_played)
        VALUES ($admin_id, '$game', $earned, 1, NOW())
        ON DUPLICATE KEY UPDATE
            tokens      = tokens + $earned,
            plays       = plays + 1,
            last_played = NOW()";

if (!mysqli_query($conn, $sql)) {
    error_log("[save_tokens] hdb_game_tokens failed: " . mysqli_error($conn));
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}
error_log("[save_tokens] hdb_game_tokens OK");

// Update total on hdb_users
$sql2 = "UPDATE hdb_users SET game_tokens = game_tokens + $earned WHERE id = $admin_id";
if (!mysqli_query($conn, $sql2)) {
    error_log("[save_tokens] hdb_users failed: " . mysqli_error($conn));
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}
error_log("[save_tokens] hdb_users OK");

// Return totals
$r1  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT game_tokens FROM hdb_users WHERE id = $admin_id"));
$r2  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT tokens, plays FROM hdb_game_tokens WHERE admin_id = $admin_id AND game = '$game'"));

$response = [
    'success'     => true,
    'total'       => (int)$r1['game_tokens'],
    'game_tokens' => (int)$r2['tokens'],
    'game_plays'  => (int)$r2['plays'],
];
error_log("[save_tokens] response: " . json_encode($response));
echo json_encode($response);
