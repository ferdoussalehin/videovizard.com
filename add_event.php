
<?php
//�
// add_event.php
include 'functionlib.php';
include 'dbconnect.php';

if (isset($_POST['event_date']) && isset($_POST['event_time']) && isset($_POST['event_name'])) {
    $event_date = $_POST['event_date'];
    $event_time = $_POST['event_time'];
    $event_name = $_POST['event_name'];
	$patient_id = $_POST['patient_id'];
	$event_name = $_POST['event_name'];
	$patient_id = $_POST['patient_id'];
	//echo "id. ".$patient_id;die;
    $new_patient_name = $_POST['new_patient_name'];
	if ($patient_id == "" )
	{
		if ($new_patient_name  == "")
		{
			$new_patient_name = "New Patient";
		}
		$todays_date = date('Y-m-d');
		$query = "INSERT INTO  eh_patients (     `phoneno`,      `patient_name`,   `create_date` )
                             VALUES                ( '9999999',     '$new_patient_name',   '$todays_date' )";
		
		if(!mysqli_query($conn,$query))
		{
			 echo("3 description: in s_subject " . mysqli_error($conn)."    ");
			 $updatemsgtype = 99;
			 $updatemsg = "Error in inserting the record";die;

		}
		else
		{
		   $patient_id = mysqli_insert_id($conn);
		   echo "id. ".$patient_id;
		   
		}
	
	}
    $sql = "INSERT INTO eh_calendar (patient_id, event_date, event_time, event_name) VALUES ('$patient_id', '$event_date', '$event_time', '$event_name')";
	
	//echo "sql ".$sql;
    if ($conn->query($sql) === TRUE) {
        echo "Event added successfully!";//die;
    } else {
        echo "Error: " . $conn->error;//die;
    }
}
$conn->close();
?>
