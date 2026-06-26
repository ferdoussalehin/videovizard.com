<?
$finger_array = array('m_one.png','m_two.png','m_three.png','m_four.png','m_five.png','m_six.png','m_seven.png','m_eight.png','m_nine.png','m_ten.png');
define('IMGPATH', 'https://alviafoundation.org/mansak/img/otherimages/');
define('BASEPATH', 'https://alviafoundation.org/mansak/');
//echo "hello ......inam.................test";
$dbhost = "localhost";
$dbase = "alvia_db"; 
//The name of the database;
$dbuser = "alvia_admin"; //The username for the database
$dbpass = "AllahoAkbar786"; // The password for the database
$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase); if(! $conn )   
{      echo "Unable to connect";      die('Could not connect: ' . mysql_error());   

}
$conn->set_charset("utf8");
?>