<?php
require_once 'azure_tts_utility.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$host = 'localhost';
$dbname = 'hypnotherapy_db';
$username = 'inaamalvi1403';
$password = 'AllahuAkbar786';


try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$message = '';
$audioResult = null;
$questions = [];

// Fetch all questions from database where audio_msg_gen = 'yes'
try {
    $stmt = $pdo->query("SELECT id, action_type, question_text_audio FROM hdb_questions WHERE audio_msg_gen = 'yes' ORDER BY id");
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Show count
    if (empty($questions)) {
        $message = '⚠️ No questions found with audio_msg_gen = "yes".';
    }
} catch(PDOException $e) {
    $message = '❌ Failed to load questions: ' . $e->getMessage();
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = $_POST['text'];
    $voice = $_POST['voice'];
    $rate = (float)$_POST['rate'];
    $pitch = $_POST['pitch'];
    $style = $_POST['style'];
    $filename = $_POST['filename'];
    $directory = $_POST['directory'];
    
    // Auto-add pauses if not already present
    if (isset($_POST['auto_pauses']) && $_POST['auto_pauses'] === 'yes') {
        $text = addAutomaticPauses($text);
    }
    
    // Use your existing function with directory and filename parameters
    $result = generateAzureSpeech($text, $filename, $directory, $voice, $rate, $pitch, $style);
    
    if ($result['success']) {
        $message = '✅ Audio generated successfully!';
        $audioResult = $result;
        
        // If this is a question from audio_messages directory, update the database
        if ($directory === 'audio_messages') {
            try {
                // Build the audio file path (directory/filename.mp3 in lowercase)
                $audioFilePath = $directory . '/' . strtolower($filename) . '.mp3';
                
                // Log what we're trying to update
                error_log("=== DATABASE UPDATE DEBUG ===");
                error_log("Directory: " . $directory);
                error_log("Filename: " . $filename);
                error_log("Audio File Path: " . $audioFilePath);
                error_log("Action Type to match: " . $filename);
                
                // Update the database - correct column name is question_text_url
                $updateStmt = $pdo->prepare("UPDATE hdb_questions SET audio_msg_gen = 'done', question_text_url = :audio_url WHERE action_type = :action_type");
                $updateStmt->execute([
                    'audio_url' => $audioFilePath,
                    'action_type' => $filename
                ]);
                
                $rowsAffected = $updateStmt->rowCount();
                error_log("Rows affected: " . $rowsAffected);
                
                if ($rowsAffected > 0) {
                    $message .= ' | Database updated: audio_msg_gen = "done" and question_text_url saved';
                } else {
                    $message .= ' | Warning: No database rows updated (action_type may not match)';
                    error_log("WARNING: No rows updated for action_type: " . $filename);
                }
            } catch(PDOException $e) {
                error_log("Database update error: " . $e->getMessage());
                $message .= ' | Database update failed: ' . $e->getMessage();
            }
        }
    } else {
        $message = '❌ ' . $result['error'];
    }
}

/**
 * Automatically add pauses to text based on punctuation
 */
function addAutomaticPauses($text) {
    // Skip if already has break tags
    if (strpos($text, '<break') !== false) {
        return $text;
    }
    
    // Add pauses after periods (end of sentences)
    $text = preg_replace('/\.\s+/', '.<break time="800ms"/> ', $text);
    
    // Add pauses after question marks
    $text = preg_replace('/\?\s+/', '?<break time="900ms"/> ', $text);
    
    // Add pauses after exclamation marks
    $text = preg_replace('/!\s+/', '!<break time="900ms"/> ', $text);
    
    // Add shorter pauses after commas
    $text = preg_replace('/,\s+/', ',<break time="400ms"/> ', $text);
    
    // Add pauses after closing paragraph tags
    $text = preg_replace('/<\/p>\s*/', '</p><break time="1s"/> ', $text);
    
    return $text;
}

// Voice Options - Organized by use case
$voices = [
    '🌙 Best for Hypnotic Scripts (Deep Trance)' => [
        'en-US-SaraNeural' => 'Sara - Warm, Empathetic Female (TOP CHOICE)',
        'en-US-JennyNeural' => 'Jenny - Gentle, Soothing Female',
        'en-US-DavisNeural' => 'Davis - Deep, Calming Male',
        'en-US-GuyNeural' => 'Guy - Steady, Therapeutic Male',
        'en-GB-SoniaNeural' => 'Sonia - Warm British Female',
        'en-GB-RyanNeural' => 'Ryan - Smooth British Male',
    ],
    '💬 Best for Session Explanations (Clear & Professional)' => [
        'en-US-AriaNeural' => 'Aria - Clear, Professional Female',
        'en-US-AndrewNeural' => 'Andrew - Confident, Articulate Male',
        'en-US-BrianNeural' => 'Brian - Professional, Clear Male',
        'en-US-EmmaNeural' => 'Emma - Natural, Friendly Female',
        'en-US-MichelleNeural' => 'Michelle - Professional Female',
        'en-US-RogerNeural' => 'Roger - Friendly, Clear Male',
    ],
    '🎯 Versatile (Questions & Instructions)' => [
        'en-US-JasonNeural' => 'Jason - Confident, Versatile Male',
        'en-US-NancyNeural' => 'Nancy - Mature, Reassuring Female',
        'en-US-TonyNeural' => 'Tony - Authoritative Male',
        'en-US-AshleyNeural' => 'Ashley - Young, Friendly Female',
    ],
    '🌍 International Accents' => [
        'en-GB-LibbyNeural' => 'Libby - Soft British Female',
        'en-GB-ThomasNeural' => 'Thomas - Clear British Male',
        'en-AU-NatashaNeural' => 'Natasha - Australian Female',
        'en-AU-WilliamNeural' => 'William - Australian Male',
        'en-IE-EmilyNeural' => 'Emily - Irish Female',
        'en-IE-ConnorNeural' => 'Connor - Irish Male',
    ],
    '⚡ Dynamic & Engaging (Motivational Content)' => [
        'en-US-AdamNeural' => 'Adam - Energetic, Motivational Male',
        'en-US-AnaNeural' => 'Ana - Cheerful, Upbeat Female',
        'en-US-ChristopherNeural' => 'Christopher - Dynamic Male',
        'en-US-CoraNeural' => 'Cora - Energetic Female',
        'en-US-SteffanNeural' => 'Steffan - Enthusiastic Male',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zuzoo TTS Generator</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        h1 {
            color: #667eea;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        select, input, textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
        }
        
        select:focus, input:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }
        
        .settings {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .btn:hover {
            opacity: 0.9;
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        audio {
            width: 100%;
            margin-top: 15px;
        }
        
        .quick-fill {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            padding: 5px 10px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .quick-btn:hover {
            background: #667eea;
            color: white;
        }
        
        .info {
            background: #e7f3ff;
            border-left: 4px solid #667eea;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎙️ Zuzoo TTS Generator</h1>
        
        <div class="info">
            💡 <strong>Voice Selection Guide:</strong><br>
            🌙 <strong>Hypnotic Scripts:</strong> Sara, Jenny, Davis, Guy (slow, calming)<br>
            💬 <strong>Explanations:</strong> Aria, Andrew, Brian, Emma (clear, professional)<br>
            ⚡ <strong>Motivational:</strong> Adam, Ana, Christopher (energetic, dynamic)<br>
            <small style="display: block; margin-top: 8px;">📊 Loaded <?php echo count($questions); ?> questions from database</small>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($audioResult && $audioResult['success']): ?>
            <audio controls autoplay>
                <source src="<?php echo $audioResult['audio_url']; ?>?v=<?php echo time(); ?>" type="audio/mpeg">
            </audio>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                Saved as: <?php echo $audioResult['filename']; ?>
            </p>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Directory:</label>
                <select name="directory" required>
                    <option value="audio_messages" <?php echo (!isset($_POST['directory']) || $_POST['directory'] === 'audio_messages') ? 'selected' : ''; ?>>audio_messages (Questions)</option>
                    <option value="audio_scripts" <?php echo (isset($_POST['directory']) && $_POST['directory'] === 'audio_scripts') ? 'selected' : ''; ?>>audio_scripts (Therapeutic Scripts)</option>
                    <option value="audio_custom" <?php echo (isset($_POST['directory']) && $_POST['directory'] === 'audio_custom') ? 'selected' : ''; ?>>audio_custom (Custom Audio)</option>
                </select>
                <small style="color: #666;">Select where to save the audio file</small>
            </div>
            
            <div class="form-group">
                <label>Filename (without .mp3):</label>
                <input type="text" name="filename" value="<?php echo isset($_POST['filename']) ? htmlspecialchars($_POST['filename']) : 'custom_' . date('YmdHis'); ?>" required>
                <small style="color: #666;">Will be saved as: [directory]/[filename in lowercase].mp3</small>
            </div>
            
            <div class="form-group">
                <label>Voice:</label>
                <select name="voice" required>
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
                <label>Text (supports SSML break tags):</label>
                <div class="quick-fill">
                    <button type="button" class="quick-btn" onclick="setText('intro')">Intro</button>
                    <button type="button" class="quick-btn" onclick="setText('relax')">Relaxation</button>
                    <button type="button" class="quick-btn" onclick="setText('breath')">Breathing</button>
                    <button type="button" class="quick-btn" onclick="setText('fullscript')">Full Script</button>
                    <button type="button" class="quick-btn" onclick="setText('explanation')">Explanation</button>
                    <button type="button" class="quick-btn" onclick="setText('motivational')">Motivational</button>
                    <button type="button" class="quick-btn" onclick="showQuestions()">Load Questions</button>
                </div>
                <textarea name="text" id="textArea" required><?php echo isset($_POST['text']) ? htmlspecialchars($_POST['text']) : 'Welcome to your personalized hypnotherapy session. Take a deep breath and relax.'; ?></textarea>
            </div>
            
            <div class="settings">
                <div class="form-group">
                    <label>Speed:</label>
                    <select name="rate">
                        <option value="0.8" <?php echo (isset($_POST['rate']) && $_POST['rate'] === '0.8') ? 'selected' : ''; ?>>Very Slow (0.8)</option>
                        <option value="0.9" <?php echo (!isset($_POST['rate']) || $_POST['rate'] === '0.9') ? 'selected' : ''; ?>>Slow (0.9)</option>
                        <option value="1.0" <?php echo (isset($_POST['rate']) && $_POST['rate'] === '1.0') ? 'selected' : ''; ?>>Normal (1.0)</option>
                        <option value="1.1" <?php echo (isset($_POST['rate']) && $_POST['rate'] === '1.1') ? 'selected' : ''; ?>>Fast (1.1)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Pitch:</label>
                    <select name="pitch">
                        <option value="low" <?php echo (isset($_POST['pitch']) && $_POST['pitch'] === 'low') ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo (!isset($_POST['pitch']) || $_POST['pitch'] === 'medium') ? 'selected' : ''; ?>>Normal</option>
                        <option value="high" <?php echo (isset($_POST['pitch']) && $_POST['pitch'] === 'high') ? 'selected' : ''; ?>>High</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Style:</label>
                    <select name="style">
                        <option value="calm" <?php echo (!isset($_POST['style']) || $_POST['style'] === 'calm') ? 'selected' : ''; ?>>Calm</option>
                        <option value="gentle" <?php echo (isset($_POST['style']) && $_POST['style'] === 'gentle') ? 'selected' : ''; ?>>Gentle</option>
                        <option value="empathetic" <?php echo (isset($_POST['style']) && $_POST['style'] === 'empathetic') ? 'selected' : ''; ?>>Empathetic</option>
                        <option value="friendly" <?php echo (isset($_POST['style']) && $_POST['style'] === 'friendly') ? 'selected' : ''; ?>>Friendly</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 15px;">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" name="auto_pauses" value="yes" <?php echo (!isset($_POST['auto_pauses']) || $_POST['auto_pauses'] === 'yes') ? 'checked' : ''; ?> style="width: auto; margin-right: 8px;">
                    <span>Auto-add pauses (adds natural breaks after punctuation)</span>
                </label>
                <small style="color: #666; margin-left: 24px;">When enabled, adds: 800ms after periods, 400ms after commas, 900ms after questions</small>
            </div>
            
            <button type="submit" class="btn">Generate Audio</button>
        </form>
        
        <!-- Questions Modal -->
        <div id="questionsModal" style="display: none; margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px; border: 2px solid #667eea;">
            <h3 style="color: #667eea; margin-bottom: 15px;">Select Questions</h3>
            
            <div style="margin-bottom: 15px;">
                <label>
                    <input type="checkbox" id="selectAllQuestions" onchange="toggleAllQuestions(this)">
                    <strong>Select All</strong>
                </label>
            </div>
            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 15px;">
                <?php 
                if (empty($questions)) {
                    echo '<p style="padding: 20px; text-align: center; color: #999;">No questions found in database with audio_msg_gen = "yes"</p>';
                } else {
                    foreach ($questions as $q): 
                ?>
                        <label style="display: block; padding: 8px; margin-bottom: 5px; background: white; border-radius: 5px; cursor: pointer;">
                            <input type="checkbox" class="question-checkbox" 
                                   value="<?php echo htmlspecialchars($q['question_text_audio']); ?>" 
                                   data-action-type="<?php echo htmlspecialchars($q['action_type']); ?>">
                            <strong><?php echo htmlspecialchars($q['action_type']); ?>:</strong> 
                            <?php echo htmlspecialchars(substr(strip_tags($q['question_text_audio']), 0, 100)); ?>...
                        </label>
                    <?php 
                    endforeach;
                }
                ?>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn" onclick="loadSelectedQuestions()">Load Selected to Text Area</button>
                <button type="button" class="btn" style="background: #666;" onclick="closeQuestions()">Cancel</button>
            </div>
        </div>
    </div>
    
    <script>
        const samples = {
            intro: "Welcome to your personalized hypnotherapy session.<break time='2s'/> I am here to guide you to a place of peace and relaxation.<break time='1.5s'/> Let's begin your journey together.",
            
            relax: "Take a deep breath<break time='2s'/> and allow yourself to relax.<break time='3s'/> With each breath,<break time='1s'/> feel tension leaving your body.<break time='2s'/> You are safe<break time='1s'/> and in control.<break time='2s'/> Let go of any worries.",
            
            breath: "Breathe in slowly<break time='1s'/> for four counts.<break time='4s'/> Hold for four.<break time='4s'/> Now exhale<break time='1s'/> for six counts.<break time='6s'/> Feel yourself becoming calmer<break time='2s'/> with each breath you take.",
            
            fullscript: "Welcome to your stress relief session.<break time='3s'/> Find a comfortable position,<break time='2s'/> and when you're ready,<break time='1s'/> gently close your eyes.<break time='4s'/> Take a deep breath in through your nose.<break time='5s'/> And slowly exhale through your mouth.<break time='6s'/> Good.<break time='2s'/> Now with each breath,<break time='1s'/> you're becoming more and more relaxed.<break time='4s'/> Feel your shoulders dropping.<break time='3s'/> Your jaw relaxing.<break time='3s'/> Any tension in your body<break time='1s'/> simply melting away.<break time='5s'/> You are safe here.<break time='2s'/> You are in control.<break time='3s'/> And with each passing moment,<break time='1s'/> you feel more at peace.<break time='4s'/> More calm.<break time='3s'/> More centered.<break time='5s'/> When you're ready,<break time='2s'/> you can slowly open your eyes,<break time='1s'/> feeling refreshed and renewed.",
            
            explanation: "Hello and welcome.<break time='1s'/> In this session, we'll be working together to address your concerns and help you achieve your goals.<break time='1.5s'/> I'll guide you through a series of questions to better understand your needs,<break time='1s'/> followed by a personalized hypnotherapy session.<break time='1.5s'/> This process is completely safe and you remain in control throughout.<break time='1s'/> Are you ready to begin?",
            
            motivational: "You have the power within you to make positive changes!<break time='1.5s'/> Every day is a new opportunity to grow stronger,<break time='1s'/> to become more confident,<break time='1s'/> and to achieve your goals.<break time='2s'/> Believe in yourself!<break time='1s'/> You are capable of amazing things.<break time='1.5s'/> Let's work together to unlock your full potential!"
        };
        
        function setText(type) {
            document.getElementById('textArea').value = samples[type];
        }
        
        function showQuestions() {
            document.getElementById('questionsModal').style.display = 'block';
        }
        
        function closeQuestions() {
            document.getElementById('questionsModal').style.display = 'none';
        }
        
        function toggleAllQuestions(checkbox) {
            const checkboxes = document.querySelectorAll('.question-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
        }
        
        function loadSelectedQuestions() {
            const checkboxes = document.querySelectorAll('.question-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one question');
                return;
            }
            
            // If only one question selected, load it directly with its action_type as filename
            if (checkboxes.length === 1) {
                const cb = checkboxes[0];
                const actionType = cb.getAttribute('data-action-type');
                const questionText = cb.value;
                
                // Load question text into main textarea
                document.getElementById('textArea').value = questionText;
                
                // Set directory to audio_messages for questions
                document.querySelector('select[name="directory"]').value = 'audio_messages';
                
                // Set filename to action_type
                document.querySelector('input[name="filename"]').value = actionType;
            } else {
                // Multiple questions - combine them
                let combinedText = '';
                let firstActionType = '';
                
                checkboxes.forEach((cb, index) => {
                    if (index === 0) {
                        firstActionType = cb.getAttribute('data-action-type');
                    }
                    combinedText += cb.value + '\n\n';
                });
                
                document.getElementById('textArea').value = combinedText.trim();
                document.querySelector('select[name="directory"]').value = 'audio_messages';
                document.querySelector('input[name="filename"]').value = firstActionType + '_combined';
            }
            
            closeQuestions();
        }
    </script>
</body>
</html>