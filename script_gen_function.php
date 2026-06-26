<?php
// Include this once in your config file

/*
$client_id = 1; // Get from session or request
$current_issue = 'anxiety'; // Get from form

$result = generateHypnotherapyScript($client_id, $current_issue);

if ($result['success']) {
    echo "Script generated successfully!";
    echo "<pre>" . htmlspecialchars($result['script']) . "</pre>";
    echo "Script ID: " . $result['script_id'];
} else {
    echo "Error: " . $result['error'];
}
die;

*/

 
function generateHypnotherapyScript($client_id, $current_issue) {
    // Database credentials (move to config file)
	
	
	error_log(" n came  generateHypnotherapyScriptt  4"  . $client_id. PHP_EOL, 3, __DIR__ . "/a_debug.log");
    $dbhost = "localhost";
    $dbase = "hypnotherapy_db"; 
    $dbuser = "inaamalvi1403"; 
    $dbpass = "AllahuAkbar786"; 

    // API keys (move to environment variables)
    $GEMINI_API_KEY = "AIzaSyD-hdKf1-ASwR3FVooxNSIluA0jPtYjKho";
    $CHATGPT_API_KEY = "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";

    // Connect to database
    $conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);
    if (!$conn) {
        return ['success' => false, 'error' => "Connection failed: " . mysqli_connect_error()];
    }
    mysqli_set_charset($conn, "utf8");

    // Get client's firstname
    $query = "SELECT firstname FROM hdb_clients WHERE id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $client_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $firstname);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (empty($firstname)) {
        mysqli_close($conn);
        return ['success' => false, 'error' => "User not found..."];
    }

    // Build data array
    $data = [
        'user_id' => $client_id,
        'firstname' => $firstname,
        'current_issue' => $current_issue 
    ];

    // Get Q&A pairs
	error_log(" n came  getClientQAData  5"  .$client_id. PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
    $qa_pairs = getClientQAData($conn, $client_id);
    
    // Build prompt
	error_log(" n came  buildHypnotherapyPrompt  5"  .$client_id. PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
    $prompt = buildHypnotherapyPrompt($conn, $data);
    
    // Generate script (using ChatGPT as default)
	error_log(" n came  callChatGPTAPI_1  5"  .$client_id. PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
    $script = callChatGPTAPI_1($prompt, $CHATGPT_API_KEY);
    	error_log(" n after  callChatGPTAPI_1  5"  .$client_id. PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
    // Save to database
  //  $query = "INSERT INTO hdb_scripts (user_id, issue, script_text, duration_minutes) VALUES (?, ?, ?, 12)";
  //  $stmt = mysqli_prepare($conn, $query);
  //  mysqli_stmt_bind_param($stmt, "iss", $client_id, $current_issue, $script);
  //  mysqli_stmt_execute($stmt);
  //  $script_id = mysqli_insert_id($conn);
    
    // Close connection
  //  mysqli_close($conn);
    	error_log(" n came  after insert  5"  .$client_id. PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
    // Return result
    return [
        'success' => true,
        'script' => $script
        
        
    ];
		error_log(" n after return  5"  .$script. PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
	
}

// Helper functions (include these too)
function getClientQAData($conn, $user_id) {
    $qa_pairs = [];
    $query = "SELECT question, user_input, date FROM hdb_client_questions WHERE user_id = ? ORDER BY date DESC";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $question, $user_input, $date);
    while (mysqli_stmt_fetch($stmt)) {
        if (!empty(trim($user_input))) {
            $qa_pairs[] = ['question' => $question, 'answer' => $user_input, 'date' => $date];
        }
    }
    mysqli_stmt_close($stmt);
    return $qa_pairs;
}

function formatQAForPrompt($qa_pairs) {
    $qa_text = "";
    foreach ($qa_pairs as $idx => $qa) {
        $num = $idx + 1;
        $qa_text .= "$num. Question: {$qa['question']}" . PHP_EOL;
        $qa_text .= "   Answer: {$qa['answer']}" . PHP_EOL . PHP_EOL;
    }
    return $qa_text;
}

function buildHypnotherapyPrompt($conn, $data) {
	error_log(" i am here to build prompt for script  "  .print_r($data, true). PHP_EOL, 3, __DIR__ . "/a_debug.log");//die;
  
	
    $qa_pairs = getClientQAData($conn, $data['user_id']);
    
    $prompt = <<<PROMPT
Updated prompt—extra pauses after most sentences are now mandatory.

---

You are a professional hypnotherapist.  
I already have a completed induction & deepening track for this client; **ONLY create the 6-7-minute therapeutic section** that will be dropped in between the deepening and the standard emergence.

**CLIENT INFORMATION**  
- Name: {$data['firstname']}  
- Main issue (in client's own words): {$data['current_issue']}

**INTAKE Q&A (use exact client phrases & sensory language)**  
{formatQAForPrompt($qa_pairs)}
 
**THERAPEUTIC REQUIREMENTS**  
1. Speak directly to {$data['firstname']} in second person, gentle & permissive tone.  
2. Mirror the client's metaphors, sub-modalities, and wording found above.  
3. Weave in these **four themes** naturally:  
   - Sense of healing happening right now  
   - Shifting mind toward health  
   - The power of the subconscious mind to change things quickly  
   - The resources you already have inside to heal and be healthy  
4. Follow the concise 4-step structure:  
   A. Acknowledge & validate their current feeling (use their exact description).  
   B. Transform the limiting sensation into a manageable metaphor/object that can be shrunk, moved, or dissolved.  
   C. Install an anchor for their resource state (memory or feeling they already mentioned).  
   D. Future-pace: walk them through the next typical trigger scene while they use the anchor and watch the metaphor change.  
5. **DO NOT include the "Fast Recovery – Hypnotherapy Support" paragraph.**  
6. Keep total length **6-7 minutes**.  
7. **PAUSE RULE:** insert <break time="1s"/> after **every single sentence** (except when another <break time="Xs"/> is already specified).  
8. Mark **every pause** exactly as <break time="Xs"/> so the text is ready for 11-labs TTS.  
9. Close by reminding {$data['firstname']} that the new choices feel "natural, automatic, and steadily growing stronger every day."

Do **NOT** write an induction, deepening, or emergence—only the therapeutic change-work above.
PROMPT;
    
    return $prompt;
} 

function callChatGPTAPI_1($prompt, $apiKey) {
    $url = "https://api.openai.com/v1/chat/completions";
    $payload = json_encode([
        "model" => "gpt-4o-mini",
        "messages" => [["role" => "user", "content" => $prompt]],
        "max_tokens" => 4096,
        "temperature" => 0.7
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $apiKey]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Add for localhost testing
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return "ERROR: HTTP $httpCode - $response";
    }
    
    $decoded = json_decode($response, true);
    return $decoded['choices'][0]['message']['content'] ?? 'No response generated';
}