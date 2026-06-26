<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
$apiKey = '3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo';  // Replace with your actual key
$region = 'canadaeast';

// Get token function
function getAzureToken($apiKey, $region) {
    $ch = curl_init("https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken");
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
    curl_close($ch);
    
    return ($status == 200) ? $token : false;
}

// Generate speech function
function generateSpeech($token, $region, $ssml, $filename) {
    $ch = curl_init("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
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
    curl_close($ch);
    
    // Log API response status for debugging
    if ($status != 200) {
        error_log("Azure TTS API Error. Status: {$status}. Response Length: " . strlen($audio), 0);
    }
    
    if ($status == 200) {
        file_put_contents($filename, $audio);
        return true;
    }
    return false;
}

// Get token
$token = getAzureToken($apiKey, $region);
if (!$token) {
    die("Failed to get token. Check your API key and region.");
}

echo "<h1>Azure TTS Voice, Speed & Emotion Test</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .test-section { margin: 30px 0; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
    .test-section h2 { margin-top: 0; color: #0066cc; }
    audio { margin: 10px 0; width: 100%; max-width: 400px; }
    .label { font-weight: bold; color: #333; }
    .code { background: #f5f5f5; padding: 10px; border-radius: 3px; margin: 10px 0; font-family: monospace; font-size: 12px; }
</style>";

// Updated text for multilingual use (requires translation in each SSML block)
$testTextEN = "Hello, I'm here to help you overcome your anxiety through personalized hypnotherapy sessions. Take a deep breath and relax.";
$testTextAR = "مرحباً، أنا هنا لمساعدتك في التغلب على قلقك من خلال جلسات التنويم المغناطيسي الشخصية. خذ نفساً عميقاً واسترخ.";
$testTextHI = "नमस्ते, मैं यहां व्यक्तिगत सम्मोहन चिकित्सा सत्रों के माध्यम से आपकी चिंता को दूर करने में मदद करने के लिए हूं। गहरी सांस लें और आराम करें।";
$testTextUR = "ہیلو، میں یہاں آپ کی ذاتی ہپنوتھراپی سیشنز کے ذریعے آپ کی پریشانی پر قابو پانے میں مدد کرنے کے لیے ہوں۔ گہری سانس لیں اور آرام کریں۔";
$testTextFR = "Bonjour, je suis là pour vous aider à surmonter votre anxiété grâce à des séances d'hypnothérapie personnalisées. Respirez profondément et détendez-vous.";
$testTextES = "Hola, estoy aquí para ayudarte a superar tu ansiedad a través de sesiones de hipnoterapia personalizadas. Respira hondo y relájate.";
$testTextPA = "ਸਤਿ ਸ਼੍ਰੀ ਅਕਾਲ, ਮੈਂ ਇੱਥੇ ਨਿੱਜੀ ਹਿਪਨੋਥੈਰੇਪੀ ਸੈਸ਼ਨਾਂ ਰਾਹੀਂ ਤੁਹਾਡੀ ਚਿੰਤਾ ਨੂੰ ਦੂਰ ਕਰਨ ਵਿੱਚ ਤੁਹਾਡੀ ਮਦਦ ਕਰਨ ਲਈ ਹਾਂ। ਇੱਕ ਡੂੰਘਾ ਸਾਹ ਲਓ ਅਤੇ ਆਰਾਮ ਕਰੋ।"; // Gurmukhi/Punjabi

// ==========================================
// SECTION 1: DIFFERENT VOICES (English)
// ==========================================
echo "<div class='test-section'>";
echo "<h2>1. Different Voices (Normal Speed)</h2>";

$voices = [
    'en-US-JennyNeural' => 'Jenny - Friendly Female',
    'en-US-GuyNeural' => 'Guy - Casual Male',
    'en-US-AriaNeural' => 'Aria - Professional Female',
    'en-US-DavisNeural' => 'Davis - Professional Male',
    'en-US-JaneNeural' => 'Jane - Warm Female',
    'en-US-JasonNeural' => 'Jason - Confident Male',
    'en-US-SaraNeural' => 'Sara - Empathetic Female',
    'en-US-TonyNeural' => 'Tony - Authoritative Male'
];

foreach ($voices as $voiceName => $description) {
    $filename = "voice_" . str_replace('-', '_', $voiceName) . ".mp3";
    
    $ssml = "<speak version='1.0' xml:lang='en-US'>
        <voice name='{$voiceName}'>
            {$testTextEN}
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 15px 0;'>";
        echo "<span class='label'>{$description} ({$voiceName})</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "</div>";
    }
}
echo "</div>";

// --- Sections 2, 3, 4, 5, 6 (English Tests) Omitted for brevity, assumed working. ---

// ==========================================
// SECTION 2: DIFFERENT SPEEDS
// ==========================================
echo "<div class='test-section'>";
echo "<h2>2. Different Speaking Rates (Jenny Voice)</h2>";

$speeds = [
    'x-slow' => 'Extra Slow (x-slow)',
    'slow' => 'Slow',
    'medium' => 'Normal (medium)',
    'fast' => 'Fast',
    'x-fast' => 'Extra Fast (x-fast)',
    '0.8' => 'Custom 80% (0.8)',
    '1.2' => 'Custom 120% (1.2)',
    '1.5' => 'Custom 150% (1.5)'
];

foreach ($speeds as $rate => $label) {
    $filename = "speed_" . str_replace(['.', '-'], '_', $rate) . ".mp3";
    
    $ssml = "<speak version='1.0' xml:lang='en-US'>
        <voice name='en-US-JennyNeural'>
            <prosody rate='{$rate}'>
                {$testTextEN}
            </prosody>
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 15px 0;'>";
        echo "<span class='label'>{$label}</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "<div class='code'>rate='{$rate}'</div>";
        echo "</div>";
    }
}
echo "</div>";

// ==========================================
// SECTION 3: DIFFERENT EMOTIONS & STYLES
// ==========================================
echo "<div class='test-section'>";
echo "<h2>3. Different Emotions & Speaking Styles (Sara Voice)</h2>";
echo "<p><em>Note: Emotional styles work best with Sara, Aria, Guy, and Jenny voices</em></p>";

$styles = [
    'cheerful' => 'Cheerful - Happy and upbeat',
    'empathetic' => 'Empathetic - Understanding and caring',
    'calm' => 'Calm - Peaceful and soothing',
    'gentle' => 'Gentle - Soft and reassuring',
    'sad' => 'Sad - Melancholic',
    'angry' => 'Angry - Frustrated',
    'fearful' => 'Fearful - Anxious',
    'hopeful' => 'Hopeful - Optimistic'
];

foreach ($styles as $style => $description) {
    $filename = "emotion_" . $style . ".mp3";
    
    $ssml = "<speak version='1.0' xml:lang='en-US'>
        <voice name='en-US-SaraNeural'>
            <mstts:express-as style='{$style}'>
                {$testTextEN}
            </mstts:express-as>
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 15px 0;'>";
        echo "<span class='label'>{$description}</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "<div class='code'>style='{$style}'</div>";
        echo "</div>";
    }
}
echo "</div>";

// ==========================================
// SECTION 4: PITCH VARIATIONS
// ==========================================
echo "<div class='test-section'>";
echo "<h2>4. Different Pitch Levels (Jenny Voice)</h2>";

$pitches = [
    'x-low' => 'Extra Low Pitch',
    'low' => 'Low Pitch',
    'medium' => 'Normal Pitch',
    'high' => 'High Pitch',
    'x-high' => 'Extra High Pitch',
    '+10%' => 'Slightly Higher (+10%)',
    '-10%' => 'Slightly Lower (-10%)'
];

foreach ($pitches as $pitch => $label) {
    $filename = "pitch_" . str_replace(['+', '%', '-'], '_', $pitch) . ".mp3";
    
    $ssml = "<speak version='1.0' xml:lang='en-US'>
        <voice name='en-US-JennyNeural'>
            <prosody pitch='{$pitch}'>
                {$testTextEN}
            </prosody>
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 15px 0;'>";
        echo "<span class='label'>{$label}</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "<div class='code'>pitch='{$pitch}'</div>";
        echo "</div>";
    }
}
echo "</div>";

// ==========================================
// SECTION 5: COMBINED EFFECTS
// ==========================================
echo "<div class='test-section'>";
echo "<h2>5. Combined Effects - Hypnotherapy Optimized (English)</h2>";

$combinations = [
    [
        'name' => 'Calm Hypnotherapist (Recommended)',
        'voice' => 'en-US-SaraNeural',
        'style' => 'calm',
        'rate' => '0.9',
        'pitch' => '-5%'
    ],
    [
        'name' => 'Gentle & Slow (Deep Relaxation)',
        'voice' => 'en-US-JennyNeural',
        'style' => 'gentle',
        'rate' => '0.8',
        'pitch' => 'low'
    ],
    [
        'name' => 'Empathetic & Warm',
        'voice' => 'en-US-SaraNeural',
        'style' => 'empathetic',
        'rate' => '0.95',
        'pitch' => 'medium'
    ],
    [
        'name' => 'Cheerful & Encouraging',
        'voice' => 'en-US-AriaNeural',
        'style' => 'cheerful',
        'rate' => '1.0',
        'pitch' => '+5%'
    ],
    [
        'name' => 'Professional & Clear',
        'voice' => 'en-US-AriaNeural',
        'style' => 'professional',
        'rate' => '1.0',
        'pitch' => 'medium'
    ]
];

foreach ($combinations as $index => $combo) {
    $filename = "combo_" . ($index + 1) . ".mp3";
    
    $ssml = "<speak version='1.0' xml:lang='en-US'>
        <voice name='{$combo['voice']}'>
            <mstts:express-as style='{$combo['style']}'>
                <prosody rate='{$combo['rate']}' pitch='{$combo['pitch']}'>
                    {$testTextEN}
                </prosody>
            </mstts:express-as>
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0066cc;'>";
        echo "<span class='label' style='font-size: 16px;'>{$combo['name']}</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "<div class='code'>";
        echo "Voice: {$combo['voice']}<br>";
        echo "Style: {$combo['style']}<br>";
        echo "Rate: {$combo['rate']}<br>";
        echo "Pitch: {$combo['pitch']}";
        echo "</div>";
        echo "</div>";
    }
}
echo "</div>";

// ==========================================
// SECTION 6: PAUSES & EMPHASIS
// ==========================================
echo "<div class='test-section'>";
echo "<h2>6. Pauses & Emphasis (Hypnotherapy Techniques)</h2>";

$pauseText = "Take a deep breath... <break time='2s'/> and relax. <break time='1s'/> Feel the tension... <break time='1s'/> melting away.";
$emphasisText = "You are <emphasis level='strong'>safe</emphasis>. You are <emphasis level='strong'>calm</emphasis>. You are <emphasis level='strong'>in control</emphasis>.";

$techniques = [
    [
        'name' => 'Strategic Pauses',
        'text' => $pauseText
    ],
    [
        'name' => 'Strong Emphasis',
        'text' => $emphasisText
    ],
    [
        'name' => 'Combined - Pauses + Emphasis',
        'text' => "Take a moment... <break time='1500ms'/> to focus on your <emphasis level='strong'>breathing</emphasis>. <break time='2s'/> In... <break time='1s'/> and out."
    ]
];

foreach ($techniques as $index => $technique) {
    $filename = "technique_" . ($index + 1) . ".mp3";
    
    $ssml = "<speak version='1.0' xml:lang='en-US'>
        <voice name='en-US-SaraNeural'>
            <mstts:express-as style='calm'>
                <prosody rate='0.85'>
                    {$technique['text']}
                </prosody>
            </mstts:express-as>
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 15px 0;'>";
        echo "<span class='label'>{$technique['name']}</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "<div class='code'>" . htmlspecialchars($technique['text']) . "</div>";
        echo "</div>";
    }
}
echo "</div>";


// ==========================================
// SECTION 7: MULTILINGUAL HYPNOTHERAPY VOICES (The missing/incomplete part)
// ==========================================
echo "<div class='test-section' style='border-color: #ff6600;'>";
echo "<h2>7. Multilingual Hypnotherapy Voices (Male & Female) 🌍</h2>";
echo "<p><em>Applying rate='0.9' and pitch='-5%' for a calming effect. Using 'calm' style where available.</em></p>";

$multilingual_voices = [
    // ARABIC (Egypt) - ar-EG
    'ar-EG-SalmaNeural' => ['lang' => 'ar-EG', 'desc' => 'Arabic (Egypt) - Salma (F)', 'text' => $testTextAR, 'style' => 'calm'],
    'ar-EG-ShakirNeural' => ['lang' => 'ar-EG', 'desc' => 'Arabic (Egypt) - Shakir (M)', 'text' => $testTextAR, 'style' => 'default'], 
    
    // HINDI (India) - hi-IN 
    'hi-IN-SwaraNeural' => ['lang' => 'hi-IN', 'desc' => 'Hindi (India) - Swara (F)', 'text' => $testTextHI, 'style' => 'default'],
    'hi-IN-MadhurNeural' => ['lang' => 'hi-IN', 'desc' => 'Hindi (India) - Madhur (M)', 'text' => $testTextHI, 'style' => 'default'],
    
    // PUNJABI (India) - pa-IN
    'pa-IN-JasleenNeural' => ['lang' => 'pa-IN', 'desc' => 'Punjabi (Gurmukhi) - Jasleen (F)', 'text' => $testTextPA, 'style' => 'default'], 
    
    // URDU (Pakistan) - ur-PK
    'ur-PK-UzmaNeural' => ['lang' => 'ur-PK', 'desc' => 'Urdu (Pakistan) - Uzma (F)', 'text' => $testTextUR, 'style' => 'default'],
    'ur-PK-AsadNeural' => ['lang' => 'ur-PK', 'desc' => 'Urdu (Pakistan) - Asad (M)', 'text' => $testTextUR, 'style' => 'default'],
    
    // FRENCH (France) - fr-FR
    'fr-FR-DeniseNeural' => ['lang' => 'fr-FR', 'desc' => 'French (France) - Denise (F)', 'text' => $testTextFR, 'style' => 'calm'],
    'fr-FR-HenriNeural' => ['lang' => 'fr-FR', 'desc' => 'French (France) - Henri (M)', 'text' => $testTextFR, 'style' => 'default'], 
    
    // SPANISH (Spain) - es-ES
    'es-ES-ElviraNeural' => ['lang' => 'es-ES', 'desc' => 'Spanish (Spain) - Elvira (F)', 'text' => $testTextES, 'style' => 'calm'],
    'es-ES-AlvaroNeural' => ['lang' => 'es-ES', 'desc' => 'Spanish (Spain) - Alvaro (M)', 'text' => $testTextES, 'style' => 'default']
];

foreach ($multilingual_voices as $voiceName => $details) {
    $filename_base = str_replace(['-', ':', ' '], '_', $voiceName);
    $filename = "multi_{$filename_base}.mp3";
    $rate = '0.9';
    $pitch = '-5%';
    
    // Use mstts:express-as style tag only if a style is explicitly set (e.g., 'calm')
    $style_tag_start = ($details['style'] !== 'default') ? "<mstts:express-as style='{$details['style']}'>" : '';
    $style_tag_end = ($details['style'] !== 'default') ? "</mstts:express-as>" : '';
    
    // Construct the SSML
    $ssml = "<speak version='1.0' xml:lang='{$details['lang']}'>
        <voice name='{$voiceName}'>
            {$style_tag_start}
                <prosody rate='{$rate}' pitch='{$pitch}'>
                    {$details['text']}
                </prosody>
            {$style_tag_end}
        </voice>
    </speak>";
    
    if (generateSpeech($token, $region, $ssml, $filename)) {
        echo "<div style='margin: 20px 0; padding: 15px; background: #fff5e8; border-left: 4px solid #ff6600;'>";
        echo "<span class='label' style='font-size: 16px;'>{$details['desc']} ({$details['lang']})</span><br>";
        echo "<audio controls><source src='{$filename}' type='audio/mpeg'></audio>";
        echo "<div class='code'>";
        echo "Voice: {$voiceName}<br>";
        echo "Style: " . ($details['style'] !== 'default' ? $details['style'] : 'Custom Prosody Only') . "<br>";
        echo "Rate: {$rate}, Pitch: {$pitch}";
        echo "</div>";
        echo "</div>";
    }
}
echo "</div>"; // Closes Section 7


echo "<div style='margin-top: 40px; padding: 20px; background: #e8f5e9; border-radius: 5px;'>";
echo "<h3>✅ Testing Complete!</h3>";
echo "<p>All audio files, including the multilingual examples, should now be generated in your script's directory.</p>";
echo "</div>";
?>