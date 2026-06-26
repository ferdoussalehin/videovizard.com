<?php
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
session_start();
require_once __DIR__ . '/function_store.php';
require_once __DIR__ . '/followup_logic.php';  
//require_once __DIR__ . '/azure_tts_utility.php'; 
//require_once __DIR__ . '/config.php';
//require_once __DIR__ . '/azure_voicegen_test.php';
 function test_script_generation($conn, $client_id) {
    
    echo "<div style='max-width: 900px; margin: 40px auto; padding: 30px; background: #f5f5f5; font-family: Arial;'>";
    echo "<h1 style='color: #2196F3; text-align: center;'>Therapeutic Script Generation Test</h1>";
    echo "<hr style='border: 2px solid #2196F3; margin: 20px 0;'>";
    
    // Generate script
    echo "<p style='color: #666;'>⏳ Generating script for client {$client_id}...</p>";
    
    $script_data = generate_therapeutic_script($conn, $client_id, "Alex");
    
    if (!$script_data) {
        echo "<p style='color: red;'>❌ Failed to generate script. Check logs.</p>";
        echo "</div>";
        return;
    }
    
    // Display metadata
    echo "<div style='background: white; padding: 20px; margin: 20px 0; border-left: 5px solid #4CAF50;'>";
    echo "<h3 style='color: #4CAF50; margin-top: 0;'>✅ Script Generated Successfully</h3>";
    echo "<p><strong>Duration:</strong> {$script_data['duration_estimate']}</p>";
    echo "<p><strong>Categories Addressed:</strong> {$script_data['categories_addressed']}</p>";
    echo "<p><strong>Generated At:</strong> {$script_data['generated_at']}</p>";
    echo "<p><strong>Length:</strong> " . strlen($script_data['script_text']) . " characters</p>";
    echo "</div>";
    
    // Display script
    echo "<div style='background: white; padding: 30px; margin: 20px 0; line-height: 1.8; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>";
    echo "<h2 style='color: #1976D2; border-bottom: 3px solid #2196F3; padding-bottom: 10px;'>📝 Therapeutic Script</h2>";
    echo "<div style='white-space: pre-wrap; font-size: 16px; color: #333;'>";
    echo htmlspecialchars($script_data['script_text']);
    echo "</div>";
    echo "</div>";
    
    // Copy button
    echo "<div style='text-align: center; margin: 30px 0;'>";
    echo "<button onclick='copyScript()' style='background: #4CAF50; color: white; padding: 15px 40px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;'>📋 Copy Script</button>";
    echo "</div>";
    
    echo "<script>
    function copyScript() {
        var scriptText = document.querySelector('div[style*=\"white-space: pre-wrap\"]').textContent;
        navigator.clipboard.writeText(scriptText).then(function() {
            alert('✅ Script copied to clipboard!');
        });
    }
    </script>";
    
    echo "</div>";
}

require_once __DIR__ . '/azure_tts_utility.php';
$azure_apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
//const AUDIO_DIR = 'audio_files';
//const TONY_VOICE = 'en-US-TonyNeural';
//$azure_apiKey    = "3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo"; 

$voice_id = "en-US-TonyNeural"; 

// ============================================
// DATABASE CONNECTION
// ============================================
$dbhost = "localhost";
$dbase = "hypnotherapy_db"; 
$dbuser = "inaamalvi1403"; 
$dbpass = "AllahuAkbar786"; 

$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);

if (!$conn) {
	echo "error 0 .......";
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "details" => mysqli_connect_error()
    ]);
    exit();
}
$logFile = "a_debug.log";

test_script_generation($conn, 1);
die;

//$token = 'YOUR_AZURE_ACCESS_TOKEN'; // The token you retrieved from the authentication service
define('AZURE_REGION', 'canadaeast'); // Replace with your actual region (e.g., 'westus2')
define('AZURE_API_KEY', $azure_apiKey); 

$text_to_speak = "Hello, this is a test of the Azure text to speech service using a specified voice ID.";
$voice_id = 'en-US-TonyNeural'; // **The new, fifth parameter you added**
$output_filename = 'speech_output.mp3';
$region = 'canadaeast';
$text_to_speak = "why not try this para now";
$data =[];
$data['user_id'] = $data['user_id'] ?? 1;
$data['message_type'] = $data['message_type'] ?? '';





//$script_result = generateClientscript($conn, $data, $audio_type);
$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);

if (!$conn) {
	echo "error 0";
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "details" => mysqli_connect_error()
    ]);
    exit();
} 
 $client_id = 1; // Replace with your actual client_id

// echo "going ";die;


echo "<br> this is the result";
echo "<pre>";
print_r($categories);
echo "</pre>";


echo "<br> this is the result";
echo "<pre>";
print_r($all_summaries);
echo "</pre>";
$client_id = 1;
$client_name = "John Walter";;
$categories = get_client_assessment_by_category($conn, $client_id, "session_1");

 echo "going ";//die;
$all_summaries = process_and_save_categories($conn, $client_id, $categories);

if (!$conn->ping()) {
    error_log("⚠️ Reconnecting before report generation...\n", 3, __DIR__ . "/a_debug.log");
    $conn->close();
    
    $dbhost = "localhost";
    $dbase = "hypnotherapy_db"; 
    $dbuser = "inaamalvi1403"; 
    $dbpass = "AllahuAkbar786"; 
    
    $conn = new mysqli($dbhost, $dbuser, $dbpass, $dbase);
    
    if ($conn->connect_error) {
        error_log("❌ Failed to reconnect\n", 3, __DIR__ . "/a_debug.log");
        die("Connection failed");
    }
    
    error_log("✅ Reconnected successfully\n", 3, __DIR__ . "/a_debug.log");
}
echo "<h2>✅ Processed and saved " . count($all_summaries) . " categories!</h2>";
//$report = get_comprehensive_report_html(&$conn, $client_id, $client_name = "Client");
//$report = get_comprehensive_report_html($conn, $client_id, $client_name);

$report = display_comprehensive_report($conn, $client_id, $client_name);
error_log(print_r($report, true), 3, __DIR__ . "/a_debug.log");	 //die;
// ✅ SAVE REPORT TO DATABASE (optional)


 
if ($report) {
    $report_json = json_encode($report, JSON_UNESCAPED_UNICODE);
     
    $query = "UPDATE hdb_clients SET 
            comprehensive_report = '$report_json',
            report_generated_date = NOW()
            WHERE client_id = '$client_id'";
    
    $retval = mysqli_query($conn, $query);

    if (!$retval) {
		echo "error1";
        return ['error' => "SQL error while fetching topics: " . mysqli_error($conn)];
		echo "error1";
    }

    if (mysqli_num_rows($retval) === 0) {
		echo "error2";
        return ['error' => "No topics found for this user"];
    }
    
    echo "<p style='text-align: center; color: green;'>✅ Report saved to database</p>";
}
$client_id = 1; // Your actual client ID

// Get client name from database first
$sql = "SELECT firstname FROM clients WHERE client_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stmt->bind_result($firstname);
$stmt->fetch();
$stmt->close();

$client_name = $firstname ?? "Client";

echo "<h2>Generating Report for: {$client_name}</h2>";

// Generate and display
$report = display_comprehensive_report($conn, $client_id, $client_name);

//$results = process_all_categories($conn, $categories);
//$conn->close();
//$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);




echo "<br> this is the result";
echo "<pre>";
print_r($categories);
echo "</pre>";





// Show results
echo "<h2>Results:</h2>";
echo "<pre>";
print_r($results);
echo "</pre>";

 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 
 die;
 //*********************************
 $query = "
        SELECT *
        FROM hdb_summary_history   where client_id = '1' 
    ";
	echo "query".$query;
    $retval = mysqli_query($conn, $query);

    if (!$retval) {
		echo "error1";
        return ['error' => "SQL error while fetching topics: " . mysqli_error($conn)];
		echo "error1";
    }

    if (mysqli_num_rows($retval) === 0) {
		echo "error2";
        return ['error' => "No topics found for this user"];
    }


   

	// Read from database and build array
	$row = mysqli_fetch_assoc($retval);
	echo "<br> clinical data".$row['clinical_data'];
	echo "<br> script essential ".$row['script_essentials'];
	echo "<br> html_comment ".$row['summary_contents'];
	

die;







 $query = "
        SELECT *
        FROM chat_history   where client_id = '1' order by id
    ";
	echo "query".$query;
    $retval = mysqli_query($conn, $query);

    if (!$retval) {
		echo "error1";
        return ['error' => "SQL error while fetching topics: " . mysqli_error($conn)];
		echo "error1";
    }

    if (mysqli_num_rows($retval) === 0) {
		echo "error2";
        return ['error' => "No topics found for this user"];
    }


    $chat_history = [];

	// Read from database and build array
	while ($row = mysqli_fetch_assoc($retval)) {
		$chat_history[] = [
			'role' => $row['role'],
			'content' => $row['message']
		];
	}

	// Now you have the array in the format you need
	// You can use it directly:
	$data['chat_history'] = $chat_history;
	$data['topic_id'] = '1';
    $data['firstname']= "inam";
    $data['current_issue'] = "anxiety";   // keep dynamic value
	$audio_type = "FREE";
	
	//$script_result = generateClientscript($conn, $data, $audio_type);
	
	
	
	
	echo " <br><br><br><br>*****************************************************************";
	//echo "<br><br>  the result is ".htmlspecialchars($script_result);
	//$newtext = htmlspecialchars($script_result);
	//echo "new text is ".$newtext;//die;
	//var_dump($script_result);
	
	//chat_data = getLastSessionChatForAI($conn, $client_id);
	// var_dump($chat_history);




/********************************************************************/
//$token = getAzureToken(AZURE_API_KEY, AZURE_REGION);

$token = getAzureToken(AZURE_API_KEY, AZURE_REGION);

if (!$token) {
    header("HTTP/1.1 500 Internal Server Error");
    echo "<h1>Error: Failed to obtain Azure authentication token.</h1>";
    echo "<p>Please check your API key, region, and server logs for details.</p>";
    exit;
}
// Wrap in SSML for Azure TTS



// Get first 500 characters

//$short = substr($newtext, 0, 500);
$short = 'inam, for two years, you ve been experiencing anxiety that shows up as tiredness, gut issue, and high blood pressure, and you feel depressed.<break time="2s"/> And this fills your mind with worries about the future, your job, and marriage.<break time="2s"/> Especially when people are at home, during mostly morning times, these feelings become stronger.<break time="2s"/> Each episode typically lasts about 1 hour.<break time="2s"/>';
echo "<br><br>short is .................................".$short;
$newtext = htmlspecialchars($short);
echo "<br><br>short is .................................".$newtext;

$success = generateSpeech($token, AZURE_REGION,  $voice_id,$short, $output_filename);
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