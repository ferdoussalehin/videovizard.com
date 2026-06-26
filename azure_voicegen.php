<?php
// Set custom error log in current directory
ini_set('error_log', __DIR__ . '/audio_generator_log.txt');
ini_set('log_errors', 1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';
$region = 'canadaeast';

// Create audio_files directory if it doesn't exist
if (!file_exists('audio_files')) {
    mkdir('audio_files', 0777, true);
}
// Get token function with detailed error logging
function getAzureToken($apiKey, $region) {
    $url = "https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Ocp-Apim-Subscription-Key: ' . $apiKey,
        'Content-Length: 0'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $token = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Log token request details
    error_log("=== TOKEN REQUEST ===");
    error_log("URL: " . $url);
    error_log("HTTP Status: " . $status);
    error_log("Token Length: " . strlen($token));
    if ($error) error_log("cURL Error: " . $error);
    if ($status != 200) error_log("Token Response: " . substr($token, 0, 500));
    
    return ($status == 200) ? $token : false;
}

// Generate speech function with detailed error logging
function generateSpeech($token, $region, $voice, $text, $filename, $rate = '0.9', $pitch = 'medium', $style = 'calm', $lang = 'en-US') {
    // Build SSML based on voice capabilities
    $voicesWithStyle = ['en-US-SaraNeural', 'en-US-AriaNeural', 'en-US-GuyNeural', 'en-US-JennyNeural'];
    
    if (in_array($voice, $voicesWithStyle)) {
        $ssml = "<speak version='1.0' xml:lang='{$lang}' xmlns:mstts='https://www.w3.org/2001/mstts'>
            <voice name='{$voice}'>
                <mstts:express-as style='{$style}'>
                    <prosody rate='{$rate}' pitch='{$pitch}'>
                        {$text}
                    </prosody>
                </mstts:express-as>
            </voice>
        </speak>";
    } else {
        $ssml = "<speak version='1.0' xml:lang='{$lang}'>
            <voice name='{$voice}'>
                <prosody rate='{$rate}' pitch='{$pitch}'>
                    {$text}
                </prosody>
            </voice>
        </speak>";
    }
    
    $url = "https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $ssml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/ssml+xml',
        'X-Microsoft-OutputFormat: audio-16khz-128kbitrate-mono-mp3',
        'User-Agent: PHPTest'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $audio = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Log speech generation details
    error_log("=== SPEECH GENERATION ===");
    error_log("URL: " . $url);
    error_log("Voice: " . $voice);
    error_log("HTTP Status: " . $status);
    error_log("Response Length: " . strlen($audio));
    if ($error) error_log("cURL Error: " . $error);
    if ($status != 200) {
        error_log("Error Response: " . substr($audio, 0, 1000));
        return false;
    }
    
    if ($status == 200 && strlen($audio) > 1000) {
        file_put_contents($filename, $audio);
        error_log("Audio saved successfully: " . $filename);
        return true;
    }
    
    error_log("Audio generation failed - Status: {$status}, Length: " . strlen($audio));
    return false;
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $voice = $_POST['voice'];
    $text = $_POST['text'];
    $filename = $_POST['filename'];
    $rate = $_POST['rate'];
    $pitch = $_POST['pitch'];
    $style = $_POST['style'];
    $lang = $_POST['lang'];
    
    // Clean filename
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
    if (empty($filename)) {
        $filename = 'audio_' . time();
    }
    
    $filepath = 'blog_audios/' . $filename . '.mp3';
    
    error_log("=== NEW AUDIO GENERATION REQUEST ===");
    error_log("Voice: " . $voice);
    error_log("Filename: " . $filepath);
    error_log("Text Length: " . strlen($text));
    
    // Get token
    $token = getAzureToken($apiKey, $region);
    
    if (!$token) {
        $message = '❌ Failed to get Azure token. Check your API key and region.';
        $messageType = 'error';
        error_log("ERROR: Token acquisition failed");
    } else {
        error_log("Token acquired successfully");
        
        if (generateSpeech($token, $region, $voice, $text, $filepath, $rate, $pitch, $style, $lang)) {
            $message = "✅ Audio generated successfully! Saved as: {$filename}.mp3";
            $messageType = 'success';
        } else {
            $message = '❌ Failed to generate audio. Check the server error log for details.';
            $messageType = 'error';
            error_log("ERROR: Speech generation failed");
        }
    }
}

// Available voices - EXPANDED WITH MULTILINGUAL HYPNOTHERAPY VOICES
$voices = [
    '⭐ Best for Hypnotherapy (US English)' => [
        'en-US-SaraNeural' => 'Sara - Empathetic, Warm Female (TOP CHOICE)',
        'en-US-JennyNeural' => 'Jenny - Soft, Gentle Female',
        'en-US-DavisNeural' => 'Davis - Deep, Soothing Male',
        'en-US-GuyNeural' => 'Guy - Calm, Steady Male',
        'en-US-NancyNeural' => 'Nancy - Mature, Reassuring Female',
        'en-US-AmberNeural' => 'Amber - Warm, Natural Female',
    ],
    'Professional & Clear (US)' => [
        'en-US-AriaNeural' => 'Aria - Professional Female',
        'en-US-JasonNeural' => 'Jason - Confident Male',
        'en-US-TonyNeural' => 'Tony - Authoritative Male',
        'en-US-MichelleNeural' => 'Michelle - Professional Female',
        'en-US-MonicaNeural' => 'Monica - Clear Female',
        'en-US-ChristopherNeural' => 'Christopher - Professional Male',
        'en-US-EricNeural' => 'Eric - Clear Male',
        'en-US-JacobNeural' => 'Jacob - Natural Male',
		'en-US-JennyMultilingualNeural' => 'New  - Try this',
		
		
    ],
    'Friendly & Warm (US)' => [
        'en-US-JaneNeural' => 'Jane - Warm Female',
        'en-US-AnaNeural' => 'Ana - Cheerful Female',
        'en-US-AshleyNeural' => 'Ashley - Young, Friendly Female',
        'en-US-BrandonNeural' => 'Brandon - Casual Male',
        'en-US-CoraNeural' => 'Cora - Upbeat Female',
        'en-US-RogerNeural' => 'Roger - Friendly Male',
        'en-US-SteffanNeural' => 'Steffan - Energetic Male',
    ],
    '🇬🇧 UK English (Sophisticated)' => [
        'en-GB-SoniaNeural' => 'Sonia - Warm British Female',
        'en-GB-LibbyNeural' => 'Libby - Soft British Female',
        'en-GB-RyanNeural' => 'Ryan - Clear British Male',
        'en-GB-BellaNeural' => 'Bella - Gentle British Female',
        'en-GB-ElliotNeural' => 'Elliot - Professional British Male',
        'en-GB-MaisieNeural' => 'Maisie - Warm British Female',
        'en-GB-OliverNeural' => 'Oliver - Mature British Male',
        'en-GB-OliviaNeural' => 'Olivia - Professional British Female',
        'en-GB-ThomasNeural' => 'Thomas - Clear British Male',
    ],
    '🇦🇺 Australian English (Relaxed)' => [
        'en-AU-NatashaNeural' => 'Natasha - Warm Australian Female',
        'en-AU-WilliamNeural' => 'William - Natural Australian Male',
        'en-AU-AnnetteNeural' => 'Annette - Professional Australian Female',
        'en-AU-FreyaNeural' => 'Freya - Natural Australian Female',
        'en-AU-DarrenNeural' => 'Darren - Relaxed Australian Male',
        'en-AU-DuncanNeural' => 'Duncan - Mature Australian Male',
        'en-AU-KenNeural' => 'Ken - Friendly Australian Male',
        'en-AU-NeilNeural' => 'Neil - Clear Australian Male',
        'en-AU-TimNeural' => 'Tim - Natural Australian Male',
    ],
    '🇨🇦 Canadian English' => [
        'en-CA-ClaraNeural' => 'Clara - Warm Canadian Female',
        'en-CA-LiamNeural' => 'Liam - Natural Canadian Male',
    ],
    '🇮🇪 Irish English' => [
        'en-IE-EmilyNeural' => 'Emily - Soft Irish Female',
        'en-IE-ConnorNeural' => 'Connor - Friendly Irish Male',
    ],
    '🇪🇸 Spanish (Spain) - Hypnotherapy' => [
        'es-ES-ElviraNeural' => 'Elvira - Warm, Soothing Female ⭐',
        'es-ES-AbrilNeural' => 'Abril - Gentle, Calm Female',
        'es-ES-AlvaroNeural' => 'Alvaro - Deep, Reassuring Male ⭐',
        'es-ES-ArnauNeural' => 'Arnau - Soft, Calming Male',
        'es-ES-EstrellaNeural' => 'Estrella - Mature, Comforting Female',
        'es-ES-IreneNeural' => 'Irene - Professional, Warm Female',
        'es-ES-LaiaNeural' => 'Laia - Gentle Female',
        'es-ES-TeoNeural' => 'Teo - Calm, Steady Male',
    ],
    '🇲🇽 Spanish (Mexico) - Hypnotherapy' => [
        'es-MX-DaliaNeural' => 'Dalia - Warm Mexican Female ⭐',
        'es-MX-JorgeNeural' => 'Jorge - Deep, Soothing Mexican Male ⭐',
        'es-MX-CandelaNeural' => 'Candela - Gentle, Calm Female',
        'es-MX-CecilioNeural' => 'Cecilio - Reassuring Male',
        'es-MX-LibertoNeural' => 'Liberto - Professional Male',
        'es-MX-LucianoNeural' => 'Luciano - Calm Male',
        'es-MX-MarinaNeural' => 'Marina - Soft Female',
        'es-MX-NuriaNeural' => 'Nuria - Warm Female',
    ],
    '🇫🇷 French (France) - Hypnotherapy' => [
        'fr-FR-DeniseNeural' => 'Denise - Warm, Soothing Female ⭐',
        'fr-FR-HenriNeural' => 'Henri - Deep, Calming Male ⭐',
        'fr-FR-BrigitteNeural' => 'Brigitte - Mature, Reassuring Female',
        'fr-FR-CelesteNeural' => 'Celeste - Gentle, Soft Female',
        'fr-FR-ClaudeNeural' => 'Claude - Professional Male',
        'fr-FR-CoralieNeural' => 'Coralie - Natural Female',
        'fr-FR-JacquelineNeural' => 'Jacqueline - Warm, Comforting Female',
        'fr-FR-JeromeNeural' => 'Jerome - Calm, Steady Male',
        'fr-FR-JosephineNeural' => 'Josephine - Elegant Female',
        'fr-FR-MauriceNeural' => 'Maurice - Deep, Soothing Male',
        'fr-FR-YvesNeural' => 'Yves - Professional, Calm Male',
        'fr-FR-YvetteNeural' => 'Yvette - Mature Female',
    ],
    '🇨🇦 French (Canada) - Hypnotherapy' => [
        'fr-CA-SylvieNeural' => 'Sylvie - Warm Canadian French Female ⭐',
        'fr-CA-JeanNeural' => 'Jean - Calm Canadian French Male ⭐',
        'fr-CA-AntoineNeural' => 'Antoine - Deep Male',
        'fr-CA-ThierryNeural' => 'Thierry - Soothing Male',
    ],
    '🇮🇳 Hindi (India) - Hypnotherapy' => [
        'hi-IN-SwaraNeural' => 'Swara - Warm, Soothing Female ⭐',
        'hi-IN-MadhurNeural' => 'Madhur - Deep, Calming Male ⭐',
        'hi-IN-KavyaNeural' => 'Kavya - Gentle, Soft Female',
        'hi-IN-AarohiNeural' => 'Aarohi - Natural Female',
        'hi-IN-RehaanNeural' => 'Rehaan - Reassuring Male',
    ],
    '🇵🇰 Urdu (Pakistan) - Hypnotherapy' => [
        'ur-PK-UzmaNeural' => 'Uzma - Warm, Soothing Female ⭐',
        'ur-PK-AsadNeural' => 'Asad - Deep, Calming Male ⭐',
        'ur-PK-SalmanNeural' => 'Salman - Gentle Male',
        'ur-PK-GulNeural' => 'Gul - Soft, Reassuring Female',
    ],
    '🇮🇳 Urdu (India) - Hypnotherapy' => [
        'ur-IN-GulNeural' => 'Gul - Warm Indian Urdu Female',
        'ur-IN-SalmanNeural' => 'Salman - Calm Indian Urdu Male',
    ],
    '🇸🇦 Arabic (Saudi Arabia) - Hypnotherapy' => [
        'ar-SA-ZariyahNeural' => 'Zariyah - Warm, Soothing Female ⭐',
        'ar-SA-HamedNeural' => 'Hamed - Deep, Calming Male ⭐',
    ],
    '🇪🇬 Arabic (Egypt) - Hypnotherapy' => [
        'ar-EG-SalmaNeural' => 'Salma - Warm Egyptian Female ⭐',
        'ar-EG-ShakirNeural' => 'Shakir - Deep Egyptian Male ⭐',
    ],
    '🇦🇪 Arabic (UAE) - Hypnotherapy' => [
        'ar-AE-FatimaNeural' => 'Fatima - Warm UAE Female',
        'ar-AE-HamdanNeural' => 'Hamdan - Calm UAE Male',
    ],
    '🇯🇴 Arabic (Jordan) - Hypnotherapy' => [
        'ar-JO-SanaNeural' => 'Sana - Gentle Jordanian Female',
        'ar-JO-TaimNeural' => 'Taim - Calm Jordanian Male',
    ],
    '🇲🇦 Arabic (Morocco) - Hypnotherapy' => [
        'ar-MA-MounaNeural' => 'Mouna - Warm Moroccan Female',
        'ar-MA-JamalNeural' => 'Jamal - Soothing Moroccan Male',
    ],
];

// Language codes mapping
$languageCodes = [
    'en-US-' => 'en-US',
    'en-GB-' => 'en-GB',
    'en-AU-' => 'en-AU',
    'en-CA-' => 'en-CA',
    'en-IE-' => 'en-IE',
    'es-ES-' => 'es-ES',
    'es-MX-' => 'es-MX',
    'fr-FR-' => 'fr-FR',
    'fr-CA-' => 'fr-CA',
    'hi-IN-' => 'hi-IN',
    'ur-PK-' => 'ur-PK',
    'ur-IN-' => 'ur-IN',
    'ar-SA-' => 'ar-SA',
    'ar-EG-' => 'ar-EG',
    'ar-AE-' => 'ar-AE',
    'ar-JO-' => 'ar-JO',
    'ar-MA-' => 'ar-MA',
];

// Auto-detect language from voice
$selectedLang = 'en-US';
if (isset($_POST['voice'])) {
    foreach ($languageCodes as $prefix => $code) {
        if (strpos($_POST['voice'], $prefix) === 0) {
            $selectedLang = $code;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zuzoo Multilingual Audio Generator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        h1 {
            color: #667eea;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        select, input, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        select:focus, input:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .new-badge {
            background: #ff4081;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 8px;
        }
        
        .sample-texts {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .sample-btn {
            padding: 6px 12px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .sample-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        audio {
            width: 100%;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎙️ Zuzoo Multilingual Audio Generator</h1>
        <p class="subtitle">Generate hypnotherapy audio in 8 languages with 100+ premium voices</p>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo $message; ?>
                <?php if ($messageType === 'success' && isset($filepath)): ?>
                    <br><br>
                    <audio controls>
                        <source src="<?php echo $filepath; ?>?t=<?php echo time(); ?>" type="audio/mpeg">
                    </audio>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>🌍 NEW MULTILINGUAL SUPPORT!</strong><br>
            <strong>English:</strong> Sara, Davis, Jenny, Guy<br>
            <strong>Spanish:</strong> Elvira, Alvaro, Dalia, Jorge<br>
            <strong>French:</strong> Denise, Henri, Sylvie, Jean<br>
            <strong>Hindi:</strong> Swara, Madhur<br>
            <strong>Urdu:</strong> Uzma, Asad<br>
            <strong>Arabic:</strong> Zariyah, Hamed, Salma, Shakir
        </div>
        
        <form method="POST">
            <input type="hidden" name="lang" value="<?php echo $selectedLang; ?>">
            
            <div class="form-group">
                <label>Voice (100+ options in 8 languages) <span class="new-badge">NEW LANGUAGES</span>:</label>
                <select name="voice" required onchange="updateLanguage(this.value)">
                    <?php foreach ($voices as $category => $voiceList): ?>
                        <optgroup label="<?php echo $category; ?>">
                            <?php foreach ($voiceList as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo (isset($_POST['voice']) && $_POST['voice'] === $value) ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Text to Speak:</label>
                <div class="sample-texts">
                    <span class="sample-btn" onclick="setSample('intro')">Intro</span>
                    <span class="sample-btn" onclick="setSample('relaxation')">Relaxation</span>
                    <span class="sample-btn" onclick="setSample('breathing')">Breathing</span>
                    <span class="sample-btn" onclick="setSample('affirmation')">Affirmation</span>
                </div>
                <textarea name="text" id="textInput" required><?php echo isset($_POST['text']) ? htmlspecialchars($_POST['text']) : 'Hello, I am Zuzoo, your AI hypnotherapy assistant. Take a deep breath and relax. Feel yourself becoming calmer with each breath.'; ?></textarea>
            </div>
            
            <div class="settings-grid">
                <div class="form-group">
                    <label>Speed:</label>
                    <select name="rate">
                        <option value="0.8">Very Slow (0.8)</option>
                        <option value="0.85">Slow (0.85)</option>
                        <option value="0.9" selected>Calm (0.9) ⭐</option>
                        <option value="0.95">Slightly Slow</option>
                        <option value="1.0">Normal (1.0)</option>
                        <option value="1.1">Slightly Fast</option>
                        <option value="1.2">Fast (1.2)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Pitch:</label>
                    <select name="pitch">
                        <option value="x-low">Extra Low</option>
                        <option value="low">Low</option>
                        <option value="-5%">Slightly Low (-5%)</option>
                        <option value="medium" selected>Normal ⭐</option>
                        <option value="+5%">Slightly High (+5%)</option>
                        <option value="high">High</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Style:</label>
                    <select name="style">
                        <option value="calm" selected>Calm ⭐</option>
                        <option value="gentle">Gentle</option>
                        <option value="empathetic">Empathetic</option>
                        <option value="cheerful">Cheerful</option>
                        <option value="hopeful">Hopeful</option>
                        <option value="friendly">Friendly</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Filename (without .mp3):</label>
                <input type="text" name="filename" placeholder="e.g., intro_session_1" value="<?php echo isset($_POST['filename']) ? htmlspecialchars($_POST['filename']) : 'zuzoo_' . date('YmdHis'); ?>">
                <small style="color: #666;">Will be saved in blog_audios/ folder</small>
            </div>
            
            <button type="submit" name="generate" class="btn">
                🎵 Generate Audio
            </button>
        </form>
    </div>
    
    <script>
        const samples = {
            intro: "Hello, I am Zuzoo, your AI hypnotherapy assistant. I am here to help you feel more relaxed and at peace. Together, we will work on overcoming your challenges.",
            relaxation: "Take a deep breath and relax. Feel yourself becoming calmer with each breath. You are safe and in control. Let go of any tension in your body.",
            breathing: "Breathe in slowly through your nose for four counts. Hold for four counts. Now exhale slowly through your mouth for six counts. Feel the tension leaving your body with each breath.",
            affirmation: "You are calm. You are confident. You are in control of your thoughts and feelings. Every day, you are becoming more at peace with yourself. You deserve happiness and inner peace."
        };
        
        function setSample(type) {
            document.getElementById('textInput').value = samples[type];
        }
        
        function updateLanguage(voice) {
            const langCodes = {
                'en-US-': 'en-US',
                'en-GB-': 'en-GB',
                'en-AU-': 'en-AU',
                'en-CA-': 'en-CA',
                'en-IE-': 'en-IE',
                'es-ES-': 'es-ES',
                'es-MX-': 'es-MX',
                'fr-FR-': 'fr-FR',
                'fr-CA-': 'fr-CA',
                'hi-IN-': 'hi-IN',
                'ur-PK-': 'ur-PK',
                'ur-IN-': 'ur-IN',
                'ar-SA-': 'ar-SA',
                'ar-EG-': 'ar-EG',
                'ar-AE-': 'ar-AE',
                'ar-JO-': 'ar-JO',
                'ar-MA-': 'ar-MA',
            };
            
            for (let prefix in langCodes) {
                if (voice.startsWith(prefix)) {
                    document.querySelector('input[name="lang"]').value = langCodes[prefix];
                    break;
                }
            }
        }
    </script>
</body>
</html>