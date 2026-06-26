<?php
// media_test.php — upload this to same folder as media_review.php and open in browser
// Each step builds on the previous. Find where it breaks.

echo "<h2>Step 1: PHP is running ✅</h2>";
echo "PHP version: " . PHP_VERSION . "<br>";
echo "Script path: " . __FILE__ . "<br><br>";

echo "<h2>Step 2: Testing dbconnect_hdb.php</h2>";
if (!file_exists(__DIR__ . '/dbconnect_hdb.php')) {
    echo "❌ dbconnect_hdb.php NOT FOUND in " . __DIR__ . "<br>";
} else {
    echo "✅ dbconnect_hdb.php found<br>";
    require_once 'dbconnect_hdb.php';
    if (!isset($conn) || !$conn) {
        echo "❌ \$conn is not set or DB connection failed<br>";
    } else {
        echo "✅ DB connection OK<br>";
    }
}
echo "<br>";

echo "<h2>Step 3: Testing config.php</h2>";
if (!file_exists(__DIR__ . '/config.php')) {
    echo "⚠️ config.php not found (may already be loaded by dbconnect_hdb.php)<br>";
} else {
    echo "✅ config.php found<br>";
    if (empty($chatgpt_api_key)) {
        require_once __DIR__ . '/config.php';
    }
}
if (empty($chatgpt_api_key)) {
    echo "❌ \$chatgpt_api_key is EMPTY after loading config<br>";
} else {
    echo "✅ \$chatgpt_api_key is set: " . substr($chatgpt_api_key, 0, 8) . "...<br>";
}
echo "<br>";

echo "<h2>Step 4: Testing folders</h2>";
echo "podcast_images/ exists: " . (is_dir('podcast_images/') ? '✅ YES' : '❌ NO') . "<br>";
echo "podcast_videos/ exists: " . (is_dir('podcast_videos/') ? '✅ YES' : '❌ NO') . "<br>";
echo "<br>";

echo "<h2>Step 5: Testing cURL</h2>";
if (!function_exists('curl_init')) {
    echo "❌ cURL is NOT available — this is why tag generation fails<br>";
} else {
    echo "✅ cURL is available<br>";
    // Test actual connectivity to OpenAI
    $ch = curl_init('https://api.openai.com/v1/models');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $chatgpt_api_key));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        echo "❌ cURL error connecting to OpenAI: " . $err . "<br>";
    } elseif ($code === 200) {
        echo "✅ OpenAI API reachable, key is valid (HTTP 200)<br>";
    } elseif ($code === 401) {
        echo "❌ OpenAI API key is INVALID (HTTP 401)<br>";
    } else {
        echo "⚠️ OpenAI returned HTTP $code<br>";
        echo "Response: " . htmlspecialchars(substr($res, 0, 300)) . "<br>";
    }
}
echo "<br>";

echo "<h2>Step 6: Testing hdb_image_data table</h2>";
if (isset($conn) && $conn) {
    $res = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_image_data'");
    if ($res && mysqli_num_rows($res) > 0) {
        echo "✅ hdb_image_data table exists<br>";
        $cols = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");
        $colNames = array();
        while ($col = mysqli_fetch_assoc($cols)) { $colNames[] = $col['Field']; }
        echo "Columns: " . implode(', ', $colNames) . "<br>";
        $needed = array('natural_language_tags','embedding','status','media_type','media_type_format','created_at','updated_at');
        foreach ($needed as $n) {
            echo ($n ? (in_array($n, $colNames) ? "✅ " : "❌ MISSING: ") : "") . $n . "<br>";
        }
    } else {
        echo "❌ hdb_image_data table does NOT exist<br>";
    }
} else {
    echo "⚠️ Skipped — no DB connection<br>";
}
echo "<br>";

echo "<h2>Step 7: Scanning podcast_images/</h2>";
if (is_dir('podcast_images/')) {
    $files = scandir('podcast_images/');
    $count = 0;
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, array('jpg','jpeg','png','webp','gif','mp4','webm','mov'))) $count++;
    }
    echo "✅ Found $count media files<br>";
} else {
    echo "❌ podcast_images/ folder not found<br>";
}

echo "<br><h2>✅ All tests complete</h2>";
?>
