<?php
//�
include 'functionlib.php';
include 'dbconnect.php';



if (isset($_POST['event_date'])) {
    $event_id = $_POST['event_date'];
	echo "id is ".$event_id;
    $sql = "SELECT * FROM eh_calendar WHERE id = '$event_id'";
    $result = $conn->query($sql);

    echo "<h3>Events on " . $event_date . "</h3>";
    echo "<table border='1'><tr><th>Event Name</th><th>Event Time</th><th>Action</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>" . $row['event_name'] . "</td>
                <td><input type='time' value='" . $row['event_time'] . "' id='time_" . $row['id'] . "'></td>
                <td><button onclick='updateEvent(" . $row['id'] . ")'>Update</button></td>
              </tr>";
    }
    echo "</table>";
}
$conn->close();