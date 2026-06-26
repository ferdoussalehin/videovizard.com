<?php
$mysqli = new mysqli("localhost", "username", "password", "database");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$month = date('m');
$year = date('Y');
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$events = [];

$result = $mysqli->query("SELECT event_date, event_name FROM events WHERE MONTH(event_date) = $month AND YEAR(event_date) = $year");
while ($row = $result->fetch_assoc()) {
    $events[$row['event_date']] = $row['event_name'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['event_date'], $_POST['event_name'])) {
    $event_date = $_POST['event_date'];
    $event_name = $_POST['event_name'];
    
    $stmt = $mysqli->prepare("INSERT INTO events (event_date, event_name) VALUES (?, ?)");
    $stmt->bind_param("ss", $event_date, $event_name);
    if ($stmt->execute()) {
        echo "<script>alert('Event booked successfully!'); window.location.href='';</script>";
    } else {
        echo "<script>alert('Error: Could not book event.');</script>";
    }
    $stmt->close();
}

$mysqli->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Event Calendar</title>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid black; padding: 10px; text-align: center; }
        .event-day { background-color: #FFD700; cursor: pointer; }
        .empty-day { background-color: #90EE90; cursor: pointer; }
    </style>
    <script>
        function bookEvent(date) {
            let eventName = prompt("Enter event name:");
            if (eventName) {
                document.getElementById('event_date').value = date;
                document.getElementById('event_name').value = eventName;
                document.getElementById('eventForm').submit();
            }
        }
    </script>
</head>
<body>
    <h2>Event Calendar - <?php echo date('F Y'); ?></h2>
    <table>
        <tr>
            <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
        </tr>
        <tr>
            <?php
            $firstDayOfMonth = date('w', strtotime("$year-$month-01"));
            for ($i = 0; $i < $firstDayOfMonth; $i++) {
                echo "<td></td>";
            }
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = "$year-$month-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                $class = isset($events[$date]) ? 'event-day' : 'empty-day';
                $text = isset($events[$date]) ? $events[$date] : 'Book Event';
                echo "<td class='$class' onclick='bookEvent(\"$date\")'>$day<br>$text</td>";
                if ((($day + $firstDayOfMonth) % 7) == 0) {
                    echo "</tr><tr>";
                }
            }
            ?>
        </tr>
    </table>

    <form id="eventForm" method="POST" style="display:none;">
        <input type="hidden" id="event_date" name="event_date">
        <input type="hidden" id="event_name" name="event_name">
    </form>
</body>
</html>
