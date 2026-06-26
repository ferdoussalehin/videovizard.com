<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing SadTalker API Connection</h2>";

$api_url = 'https://inaamalvi1--sadtalker-app-fastapi-app.modal.run';

// Test 1: Check if API is reachable
echo "<h3>Test 1: API Health Check</h3>";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $api_url . '/health',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode == 200) {
    echo "<p style='color:green'>✓ API is reachable</p>";
    echo "<pre>" . print_r(json_decode($response, true), true) . "</pre>";
} else {
    echo "<p style='color:red'>✗ API returned HTTP $httpCode</p>";
    if ($error) echo "<p>Error: $error</p>";
}

// Test 2: Check if we can access a sample image
echo "<h3>Test 2: Check Image Files</h3>";
$image_path = __DIR__ . '/podcast_images/host_male_2_p1.png';
if (file_exists($image_path)) {
    echo "<p style='color:green'>✓ Image found: $image_path</p>";
    echo "<img src='/podcast_images/host_male_2_p1.png' width='100'><br>";
} else {
    echo "<p style='color:red'>✗ Image not found: $image_path</p>";
}

// Test 3: Show database records
echo "<h3>Test 3: Database Records</h3>";
include 'config.php';

$result = mysqli_query($conn, "SELECT id, podcast_id, status, LEFT(text, 50) as script_preview, image_file FROM hdb_video_gen WHERE status = 'pending' LIMIT 5");
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Podcast ID</th><th>Status</th><th>Script Preview</th><th>Image File</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['podcast_id']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>" . htmlspecialchars($row['script_preview']) . "</td>";
        echo "<td>{$row['image_file']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No pending records found</p>";
}

echo "<h3>Ready to process:</h3>";
echo "<a href='sadtalker_job.php' target='_blank'>Process All Pending Scenes</a><br>";
echo "<a href='sadtalker_job.php?podcast_id=312' target='_blank'>Process Podcast ID 312</a><br>";
echo "<a href='sadtalker_job.php?action=retry' target='_blank'>Retry Failed Scenes</a>";
?>