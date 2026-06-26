<?php
// Database Setup Checker for Community Appeals
// Run this file to verify your database is properly set up

include 'dbconnect_hdb.php';

echo "<h2>Database Connection Test</h2>";

// Test connection
if (!$conn) {
    echo "<p style='color: red;'>❌ Database connection FAILED: " . mysqli_connect_error() . "</p>";
    exit;
} else {
    echo "<p style='color: green;'>✓ Database connection successful</p>";
}

// Check if table exists
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'community_appeals'");
if (mysqli_num_rows($table_check) == 0) {
    echo "<p style='color: red;'>❌ Table 'community_appeals' does NOT exist</p>";
    echo "<p>Please run the SQL from community_appeals_table.sql file to create the table.</p>";
    echo "<h3>Quick Create Table SQL:</h3>";
    echo "<textarea style='width: 100%; height: 300px; font-family: monospace;'>";
    echo "CREATE TABLE IF NOT EXISTS `community_appeals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `age` int(3) NOT NULL,
  `email` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `country` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `issue` varchar(100) NOT NULL,
  `appeal_text` text NOT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `submitted_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `sponsored_at` datetime DEFAULT NULL,
  `donor_id` int(11) DEFAULT NULL,
  `thank_you_message` text DEFAULT NULL,
  `thank_you_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `status` (`status`),
  KEY `submitted_at` (`submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
    echo "</textarea>";
    echo "<p>Copy the SQL above and run it in phpMyAdmin or your MySQL client.</p>";
} else {
    echo "<p style='color: green;'>✓ Table 'community_appeals' exists</p>";
    
    // Check table structure
    $structure = mysqli_query($conn, "DESCRIBE community_appeals");
    echo "<h3>Table Structure:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = mysqli_fetch_assoc($structure)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if there are any records
    $count_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM community_appeals");
    $count = mysqli_fetch_assoc($count_result);
    echo "<p>Current records in table: <strong>" . $count['count'] . "</strong></p>";
}

// Test insert capability 
echo "<h3>Test Insert Query:</h3>";
$test_query = "INSERT INTO community_appeals 
    (name, age, email, city, country, category, issue, appeal_text, status, submitted_at) 
    VALUES ('Test User', 25, 'test@example.com', 'Test City', 'Test Country', 
            'anxiety', 'General anxiety', 'This is a test appeal text that is over 100 characters long to meet the minimum requirement for the appeal submission form.', 'pending', NOW())";

echo "<textarea style='width: 100%; height: 150px; font-family: monospace;'>";
echo $test_query;
echo "</textarea>";

echo "<p><strong>Note:</strong> This is a test query. Don't run it unless you want test data in your database.</p>";

echo "<hr>";
echo "<h3>Status Summary:</h3>";
if ($conn && mysqli_num_rows($table_check) > 0) {
    echo "<p style='color: green; font-size: 18px; font-weight: bold;'>✓ Database is ready! You can now use the community appeal form.</p>";
} else {
    echo "<p style='color: red; font-size: 18px; font-weight: bold;'>❌ Setup incomplete. Please create the table first.</p>";
}

mysqli_close($conn);
?>
