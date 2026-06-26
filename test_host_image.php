<?php
/**
 * Voice Selection Tool - Host & Guest Voice Assignment
 * Reads from hdb_voices where voice_source = 'openai' AND lang_code = 'en'
 */

// Include database connection
require_once 'dbconnect_hdb.php';

// Initialize variables
$voices = [];
$host_selected_id = null;
$guest_selected_id = null;
$host_voice_data = null;
$guest_voice_data = null;
$error_message = null;
$success_message = null;

// Fetch voices from database
$query = "SELECT id, lang_code, voice_key, voice_name, gender, sample_voice, voice_source 
          FROM hdb_voices 
          WHERE voice_source = 'openai' AND lang_code = 'en' 
          ORDER BY voice_name ASC";

$result = mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $voices[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process'])) {
    $host_selected_id = isset($_POST['host_voice']) ? (int)$_POST['host_voice'] : 0;
    $guest_selected_id = isset($_POST['guest_voice']) ? (int)$_POST['guest_voice'] : 0;
    
    // Validation
    if ($host_selected_id <= 0) {
        $error_message = "Please select a Host voice.";
    } elseif ($guest_selected_id <= 0) {
        $error_message = "Please select a Guest voice.";
    } elseif ($host_selected_id === $guest_selected_id) {
        $error_message = "Validation Error: Host and Guest cannot have the same voice ID. Please choose different voices.";
    } else {
        // Fetch host voice details
        $host_query = "SELECT id, voice_name, voice_key, gender, voice_source FROM hdb_voices WHERE id = " . $host_selected_id;
        $host_result = mysqli_query($conn, $host_query);
        if ($host_result && mysqli_num_rows($host_result) > 0) {
            $host_voice_data = mysqli_fetch_assoc($host_result);
			$host_voice_data['guest_image'] = 'ali';
        }
        
        // Fetch guest voice details
        $guest_query = "SELECT id, voice_name, voice_key, gender, voice_source FROM hdb_voices WHERE id = " . $guest_selected_id;
        $guest_result = mysqli_query($conn, $guest_query);
        if ($guest_result && mysqli_num_rows($guest_result) > 0) {
            $guest_voice_data = mysqli_fetch_assoc($guest_result);
			$guest_voice_data['guest_image'] = 'inam';
			//var_dump ($guest_voice_data);
        }
		
		// now we have the  voice id, gender and host 
		
		//  read hdb_hostguest_images
		$images_male = getImages_array($conn, 'male');
		$images_female = getImages_array($conn, 'female');
		//var_dump ($images_male);
		// we want to get host name for host 
		$prev_name = "";
		if ($host_voice_data['gender'] == 'male')
	 	{
			$host_imagename = find_host_imagename($prev_name, $images_male);
		}
		else
		{
			$host_imagename = find_host_imagename($prev_name, $images_female);
		}
		echo "<br>.. host name ".$host_imagename;
		
		
		$prev_name = $host_imagename;
		if ($guest_voice_data['gender'] == 'male')
	 	{
			$guest_imagename = find_host_imagename($prev_name, $images_male);
		}
		else
		{
			$guest_imagename = find_host_imagename($prev_name, $images_female);
		}
		echo "<br>...guest  name ".$guest_imagename;
		
		
		
		//var_dump ($images_male);
        //var_dump ($images_female);
        if ($host_voice_data && $guest_voice_data) {
            $success_message = "Voices successfully assigned!";
        } else {
            $error_message = "Selected voice(s) could not be found in the database.";
        }
    }
}

function find_host_imagename($prev_name, $images_array, $max_attempts = 20) {
    // Check if array is empty
	
    if (empty($images_array)) {
		echo "came to findhos111t";die;
        return null;
    }
    
    // If only one image and it matches the previous name, cannot select different one
    if (count($images_array) == 1 && $images_array[0]['image_name'] == $prev_name) {
		echo "came to findhost222";die;
        return null;
    }
    
    // Try random selection up to max_attempts times
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        // Randomly select an index
        $random_index = array_rand($images_array);
        $selected_image = $images_array[$random_index];
        
        // Check if selected image name is different from previous
        if ($selected_image['image_name'] != $prev_name) {
			//echo "matched.....................".$selected_image['image_name'];//die;
			$hostname = $selected_image['image_name'];
            return $hostname;
        }
    }
}
function getImages_array($conn, $gender) {
    $images = [];
    //echo " i am here";
    // Prepare and execute query 
    $query = "SELECT * from hdb_hostguest_images WHERE gender = '$gender' ORDER BY id ASC";
    //echo "query......".$query;
	$result = mysqli_query($conn, $query);
    
    // Check if query was successful
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $images[] = $row;
        }
    }
	else
	{
			echo "error........";
	}
    
    return $images;
}

// Close connection
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host & Guest Voice Selector</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }
        body {
            background: #f0f2f5;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
        }
        .container {
            max-width: 800px;
            width: 100%;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .voice-group {
            margin-bottom: 25px;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #555;
        }
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .stats {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin: 20px 0;
        }
        button:hover {
            background: #0056b3;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 6px;
            margin: 15px 0;
            border: 1px solid #f5c6cb;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 6px;
            margin: 15px 0;
            border: 1px solid #c3e6cb;
        }
        .result-panel {
            background: #e9ecef;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .result-panel h3 {
            margin: 0 0 15px 0;
            color: #333;
        }
        .voice-detail {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .host-box, .guest-box {
            flex: 1;
            background: white;
            padding: 15px;
            border-radius: 6px;
        }
        .host-box strong, .guest-box strong {
            display: block;
            color: #007bff;
            margin-bottom: 10px;
        }
        .voice-name {
            font-size: 18px;
            font-weight: bold;
            margin: 10px 0;
        }
        .voice-meta {
            font-size: 12px;
            color: #666;
            margin-top: 8px;
        }
        .warning {
            font-size: 12px;
            color: #dc3545;
            margin-top: 5px;
        }
        footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🎙️ Voice Selection Studio</h1>
    <div class="subtitle">Assign Host and Guest voices from OpenAI English voices</div>
    
    <div class="stats">
        📊 Available voices: <strong><?php echo count($voices); ?></strong> | 
        Source: <strong>OpenAI</strong> | 
        Language: <strong>English (en)</strong>
    </div>
    
    <?php if (empty($voices)): ?>
        <div class="error">
            No voices found with voice_source = 'openai' AND lang_code = 'en'
        </div>
    <?php else: ?>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" id="voiceForm">
            <div class="voice-group">
                <label>🎤 Host Voice</label>
                <select name="host_voice" id="hostVoice">
                    <option value="">-- Select a voice --</option>
                    <?php foreach ($voices as $voice): ?>
                        <option value="<?php echo $voice['id']; ?>" 
                                <?php echo ($host_selected_id == $voice['id']) ? 'selected' : ''; ?>>
                            <?php 
                          
                            echo $voice['voice_name'];
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="hostWarning" class="warning"></div>
            </div>
            
            <div class="voice-group">
                <label>🎧 Guest Voice</label>
                <select name="guest_voice" id="guestVoice">
                    <option value="">-- Select a voice --</option>
                    <?php foreach ($voices as $voice): ?>
                        <option value="<?php echo $voice['id']; ?>" 
                                <?php echo ($guest_selected_id == $voice['id']) ? 'selected' : ''; ?>>
                            <?php 
                            $genderIcon = ($voice['gender'] == 'female') ? '👩' : (($voice['gender'] == 'male') ? '👨' : '🧑');
                            echo $genderIcon . ' ' . htmlspecialchars($voice['voice_name']) . ' (' . htmlspecialchars($voice['voice_key']) . ')';
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="guestWarning" class="warning"></div>
            </div>
            
            <button type="submit" name="process">✨ Process Selection ✨</button>
        </form>
        
        <?php if ($host_voice_data && $guest_voice_data): ?>
            <div class="result-panel">
                <h3>📋 Selected Voices</h3>
                <div class="voice-detail">
                    <div class="host-box">
                        <strong>🎙️ HOST VOICE</strong>
                        <div class="voice-name"><?php echo htmlspecialchars($host_voice_data['voice_name']); ?></div>
                        <div class="voice-meta">
                            ID: <?php echo $host_voice_data['id']; ?><br>
                            Key: <?php echo htmlspecialchars($host_voice_data['voice_key']); ?><br>
                            Gender: <?php echo ucfirst($host_voice_data['gender']); ?><br>
                            Source: <?php echo strtoupper($host_voice_data['voice_source']); ?>
                        </div>
                    </div>
                    <div class="guest-box">
                        <strong>🎙️ GUEST VOICE</strong>
                        <div class="voice-name"><?php echo htmlspecialchars($guest_voice_data['voice_name']); ?></div>
                        <div class="voice-meta">
                            ID: <?php echo $guest_voice_data['id']; ?><br>
                            Key: <?php echo htmlspecialchars($guest_voice_data['voice_key']); ?><br>
                            Gender: <?php echo ucfirst($guest_voice_data['gender']); ?><br>
                            Source: <?php echo strtoupper($guest_voice_data['voice_source']); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    <?php endif; ?>
    
    <footer>
        ✅ Validation: Same voice ID cannot be selected for Host and Guest<br>
        📀 Data source: hdb_voices WHERE voice_source = 'openai' AND lang_code = 'en'
    </footer>
</div>

<script>
// Client-side validation
document.getElementById('voiceForm').addEventListener('submit', function(e) {
    var host = document.getElementById('hostVoice').value;
    var guest = document.getElementById('guestVoice').value;
    var hostWarning = document.getElementById('hostWarning');
    var guestWarning = document.getElementById('guestWarning');
    
    hostWarning.innerHTML = '';
    guestWarning.innerHTML = '';
    
    if (!host) {
        e.preventDefault();
        hostWarning.innerHTML = '⚠️ Please select a host voice';
        return false;
    }
    
    if (!guest) {
        e.preventDefault();
        guestWarning.innerHTML = '⚠️ Please select a guest voice';
        return false;
    }
    
    if (host === guest) {
        e.preventDefault();
        hostWarning.innerHTML = '⚠️ Cannot use same voice as Guest';
        guestWarning.innerHTML = '⚠️ Cannot use same voice as Host';
        alert('Error: Host and Guest cannot have the same voice ID');
        return false;
    }
    
    return true;
});

// Real-time warning
var hostSelect = document.getElementById('hostVoice');
var guestSelect = document.getElementById('guestVoice');
var hostWarning = document.getElementById('hostWarning');
var guestWarning = document.getElementById('guestWarning');

function checkSameVoice() {
    if (hostSelect.value && guestSelect.value && hostSelect.value === guestSelect.value) {
        hostWarning.innerHTML = '⚠️ Same voice as Guest';
        guestWarning.innerHTML = '⚠️ Same voice as Host';
    } else {
        if (hostWarning.innerHTML === '⚠️ Same voice as Guest') hostWarning.innerHTML = '';
        if (guestWarning.innerHTML === '⚠️ Same voice as Host') guestWarning.innerHTML = '';
    }
}

hostSelect.addEventListener('change', checkSameVoice);
guestSelect.addEventListener('change', checkSameVoice);
</script>
</body>
</html>