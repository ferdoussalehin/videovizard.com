<?php
// Database configuration
include 'dbconnect_hdb.php';

// کنکشن چیک کریں
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// آپ کی ٹیبل سے ڈیٹا حاصل کرنے کی کیوری
$sql = "SELECT id, category, issue, blog_title FROM hdb_blog_pages ORDER BY id ASC";
$result = mysqli_query($conn, $sql);

echo "<h2>فہرستِ مضامین (Blog Titles)</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%; text-align: right; direction: rtl;'>";
echo "<tr style='background-color: #f2f2f2;'>
        <th>آئی ڈی (ID)</th>
        <th>کیٹیگری (Category)</th>
        <th>مسئلہ (Issue)</th>
        <th>عنوان (Blog Title)</th>
      </tr>";

if (mysqli_num_rows($result) > 0) {
    // ہر قطار کا ڈیٹا ڈسپلے کریں
    while($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>" . $row["id"] . "</td>";
        echo "<td>" . $row["category"] . "</td>";
        echo "<td>" . $row["issue"] . "</td>";
        echo "<td>" . $row["blog_title"] . "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='4' style='text-align:center;'>کوئی ڈیٹا نہیں ملا۔</td></tr>";
}

echo "</table>";

// کنکشن بند کریں
mysqli_close($conn);
?>