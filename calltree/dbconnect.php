<?php
// ── CallMind AI — Database Connection ────────────────────────
// Include this file at the top of every PHP page that needs DB.

define('DB_HOST', 'localhost');
define('DB_USER', 'inaamalvi_calltree');       // ← change this
define('DB_PASS', 'AllahuAkbar786!');   // ← change this
define('DB_NAME', 'calltree_db');        // ← change this
define('DB_PORT', 3306);

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if (!$conn) {
    // In production, log and show a friendly error — don't expose details
    error_log('DB connection failed: ' . mysqli_connect_error());
    http_response_code(503);
    die(json_encode(['error' => 'Service temporarily unavailable.']));
}

mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+00:00'");
