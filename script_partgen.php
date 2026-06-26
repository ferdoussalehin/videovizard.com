<?php
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
session_start();
require_once __DIR__ . '/function_store.php';
//require_once __DIR__ . '/data_handler.php';  
require_once __DIR__ . '/azure_tts_utility.php'; 
//require_once __DIR__ . '/config.php';

 
//require_once __DIR__ . '/azure_tts_utility.php';
$azure_apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
const AUDIO_DIR = 'audio_files';
const TONY_VOICE = 'en-US-TonyNeural';
//$azure_apiKey    = "3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo"; 

$voice_id = "en-US-TonyNeural"; 

//$token = 'YOUR_AZURE_ACCESS_TOKEN'; // The token you retrieved from the authentication service
define('AZURE_REGION', 'canadaeast'); // Replace with your actual region (e.g., 'westus2')
define('AZURE_API_KEY', $azure_apiKey); 

$text_to_speak = "Hello, this is a test of the Azure text to speech service using a specified voice ID.";
$voice_id = 'en-US-TonyNeural'; // **The new, fifth parameter you added**
$output_filename = 'speech_output.mp3';
$region = 'canadaeast';
$text_to_speak = "why not try this para now";

/********************************************************************/
//$token = getAzureToken(AZURE_API_KEY, AZURE_REGION);
$token = getAzureToken(AZURE_API_KEY, AZURE_REGION);

if (!$token) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "<h1>Error: Failed to obtain Azure authentication token.</h1>";
    echo "<p>Please check your API key, region, and server logs for details.</p>";
    exit;
}


$success = generateSpeech($token, AZURE_REGION, $text_to_speak, $voice_id, $output_filename);
//$success = generateSpeech($token, AZURE_REGION, $text_to_speak, $output_filename);

// 4. Output Result
if ($success) {
    echo "<h1>Success!</h1>";
    echo "<p>Text converted to speech and saved to: <strong>$output_filename</strong></p>";
    // Optional: Provide a download/play link
    echo '<audio controls><source src="' . $output_filename . '" type="audio/wav">Your browser does not support the audio element.</audio>';
} else {
    // This will trigger if the speech API fails, even if the token worked.
    header("HTTP/1.1 500 Internal Server Error");
    echo "<h1>Error: Failed to generate speech audio.</h1>";
    echo "<p>See server logs for the specific Azure API error message.</p>";
}

die;



function convertDotsToBreaks($text) {
    // IMPORTANT: Replace longest sequences first
    $patterns = [
        '/\.{6}/' => '<break time="6s"/>',
        '/\.{5}/' => '<break time="5s"/>',
        '/\.{4}/' => '<break time="4s"/>',
        '/\.{3}/' => '<break time="3s"/>',
        '/\.{2}/' => '<break time="2s"/>',
        '/\./'    => '<break time="1s"/>',
    ];

    foreach ($patterns as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
    }

    return $text;
}

$dbhost = "localhost";
$dbase = "hypnotherapy_db"; 
$dbuser = "inaamalvi1403"; 
$dbpass = "AllahuAkbar786"; 

$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);








	$base_prompt = get_prompt($conn, "script_part_prompt");
	$preferred_language = "urdu";
	$prompt = str_replace('{language}', $preferred_language, $base_prompt);
	
	//$prompt2 = get_prompt($conn, "script_part_prompt2");
	//echo "promt 2".$prompt2;//die;
	
 $query = "
        SELECT *
        FROM hdb_script_parts where description <> '' order by seqno
    ";
	echo "query".$query;
    $retval = mysqli_query($conn, $query);

    if (!$retval) {
        return ['error' => "SQL error while fetching topics: " . mysqli_error($conn)];
    }

    if (mysqli_num_rows($retval) === 0) {
        return ['error' => "No topics found for this user"];
    }


    while ($row = mysqli_fetch_assoc($retval)) {

		$title = strtolower($row['title']);
		if ($row['title'] <> NULL)
		{
			 echo "<br> title>".$title;
			echo "<br> description ".$row['description']."<br><br>";
			  

			
			$prompt = str_replace('{text_to_convert}', $row['description'], $prompt);
			//echo "this is prompt ".$prompt;die;
			$result = Call_ChatGPT_IssueDetection($prompt, $row['description']);
			$inam = $result['response'];
			  
			  //$withBreaks = convertDotsToBreaks($inam); 
			  $withBreaks = str_replace('.....', '<break time="5s"/>', $inam);
			  $withBreaks = str_replace('....', '<break time="4s"/>', $withBreaks);
			  $withBreaks = str_replace('...', '<break time="3s"/>', $withBreaks);
			  $withBreaks = str_replace('..', '<break time="2s"/>', $withBreaks);
			  $withBreaks = str_replace('.', '<break time="1s"/>', $withBreaks);
			  $withBreaks = str_replace('…', '<break time="3s"/>', $withBreaks);
			  $withBreaks = str_replace('—', '<break time="2s"/>', $withBreaks);
			  
			  
			  
			echo "<br><br>  the result is ".htmlspecialchars($withBreaks);
			die;	
			
			
		}
       
	};
?>