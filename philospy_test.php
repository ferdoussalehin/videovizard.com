<?php
session_start();
include 'dbconnect_hdb.php';

// Initialize or retrieve from session
if (!isset($_SESSION['phil_client_id'])) {
    $_SESSION['phil_client_id'] = 1; // Default test client
}
if (!isset($_SESSION['phil_assessment_id'])) {
    $_SESSION['phil_assessment_id'] = time(); // Unique assessment ID
}
if (!isset($_SESSION['phil_current_question'])) {
    $_SESSION['phil_current_question'] = 1;
}

$client_id = $_SESSION['phil_client_id'];
$assessment_id = $_SESSION['phil_assessment_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Save answer
    if (isset($_POST['save_answer'])) {
        $question_id = intval($_POST['question_id']);
        $selected_option = mysqli_real_escape_string($conn, $_POST['selected_option']);
        $score = intval($_POST['score']);
        $selected_text = mysqli_real_escape_string($conn, $_POST['selected_text']);
        $philosophy_principle = mysqli_real_escape_string($conn, $_POST['philosophy_principle']);
        $question_number = intval($_POST['question_number']);
        
        // Save to database
        $query = "INSERT INTO philosophy_responses 
                  (client_id, assessment_id, question_id, question_number, philosophy_principle, 
                   selected_option, selected_text, score) 
                  VALUES ($client_id, $assessment_id, $question_id, $question_number, 
                          '$philosophy_principle', '$selected_option', '$selected_text', $score)
                  ON DUPLICATE KEY UPDATE 
                  selected_option = '$selected_option',
                  selected_text = '$selected_text',
                  score = $score";
        
        mysqli_query($conn, $query);
        
        // Move to next question
        $_SESSION['phil_current_question']++;
        
        // If completed all 20 questions
        if ($_SESSION['phil_current_question'] > 20) {
            header("Location: philospy_test.php?view=complete");
            exit();
        }
         
        header("Location: philospy_test.php");
        exit();
    }
    
    // Go back
    if (isset($_POST['go_back'])) {
        $_SESSION['phil_current_question']--;
        if ($_SESSION['phil_current_question'] < 1) {
            $_SESSION['phil_current_question'] = 1;
        }
        header("Location: philospy_test.php");
        exit();
    }
    
    // Reset assessment
    if (isset($_POST['reset'])) {
        unset($_SESSION['phil_client_id']);
        unset($_SESSION['phil_assessment_id']);
        unset($_SESSION['phil_current_question']);
        header("Location: philospy_test.php");
        exit();
    }
}

// Get current question
$current_q = $_SESSION['phil_current_question'];

// Fetch question from database
$query = "SELECT * FROM philosophy_questions WHERE question_number = $current_q";
$result = mysqli_query($conn, $query);
$question = mysqli_fetch_assoc($result);

if (!$question) {
    die("Question not found. Make sure you've inserted all 20 questions into philosophy_questions table.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Philosophy Assessment - Question <?php echo $current_q; ?> of 20</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #667eea; font-size: 28px; margin-bottom: 10px; }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;
        }
        .progress-text { text-align: center; color: #666; font-size: 14px; margin-top: 10px; }
        .question-box { background: #f8f9ff; padding: 30px; border-radius: 15px; margin: 30px 0; }
        .scenario {
            background: #fff;
            padding: 20px;
            border-left: 4px solid #667eea;
            border-radius: 8px;
            margin-bottom: 20px;
            font-style: italic;
            color: #555;
        }
        .question-text { font-size: 20px; font-weight: 600; color: #333; margin-bottom: 30px; }
        .options { display: flex; flex-direction: column; gap: 15px; }
        .option-button {
            background: white;
            border: 3px solid #e0e0e0;
            padding: 20px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: left;
        }
        .option-button:hover {
            border-color: #667eea;
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        }
        .option-label {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .option-text { color: #333; font-size: 16px; line-height: 1.6; }
        .navigation { display: flex; justify-content: space-between; margin-top: 30px; }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-back { background: #e0e0e0; color: #333; }
        .btn-back:hover { background: #d0d0d0; }
        .btn-reset { background: #ff6b6b; color: white; }
        .btn-reset:hover { background: #ff5252; }
        .complete-screen { text-align: center; padding: 40px; }
        .complete-screen h2 { color: #667eea; font-size: 32px; margin-bottom: 20px; }
        .complete-screen p { color: #666; font-size: 18px; margin-bottom: 30px; }
        .report-buttons { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .btn-report {
            padding: 15px 40px;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .btn-wisdom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-wisdom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        .btn-combined {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .btn-combined:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 87, 108, 0.4);
        }
        .instruction {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .instruction strong { color: #856404; }
        .debug-info {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>

<?php if (isset($_GET['view']) && $_GET['view'] === 'complete'): ?>
    
    <div class="container">
        <div class="complete-screen">
            <h2>🌟 Assessment Complete! 🌟</h2>
            <p>You've successfully completed all 20 wisdom questions.<br>Your inner wisdom is ready to be revealed.</p>
            
            <div class="debug-info">
                Client ID: <?php echo $client_id; ?> | Assessment ID: <?php echo $assessment_id; ?>
            </div>
            
            <div class="report-buttons">
                <form method="post" action="generate_wisdom_report.php" style="display: inline;">
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                    <input type="hidden" name="assessment_id" value="<?php echo $assessment_id; ?>">
                    <button type="submit" class="btn-report btn-wisdom">
                        📊 View Wisdom Report Only
                    </button>
                </form>
                
                <form method="post" action="generate_combined_report.php" style="display: inline;">
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                    <input type="hidden" name="assessment_id" value="<?php echo $assessment_id; ?>">
                    <button type="submit" class="btn-report btn-combined">
                        🎯 View Combined Report<br>(Archetype + Wisdom)
                    </button>
                </form>
            </div>
            
            <form method="post" style="margin-top: 30px;">
                <button type="submit" name="reset" class="btn btn-reset">
                    🔄 Start New Assessment
                </button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="container">
        <div class="header">
            <h1>🌟 Discover Your Inner Wisdom 🌟</h1>
            <p style="color: #666; margin-top: 10px;">
                Imagine you're 2 years from now — healed, thriving, successful.<br>
                A friend comes to you for advice. What wisdom would you share?
            </p>
        </div>

        <div class="debug-info">
            Client ID: <?php echo $client_id; ?> | Assessment ID: <?php echo $assessment_id; ?> | Question: <?php echo $current_q; ?>/20
        </div>
        
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo ($current_q / 20) * 100; ?>%"></div>
        </div>
        <div class="progress-text">
            Question <?php echo $current_q; ?> of 20 (<?php echo round(($current_q / 20) * 100); ?>% complete)
        </div>
        
        <?php if ($current_q === 1): ?>
        <div class="instruction">
            <strong>Instructions:</strong> Read each scenario and choose the advice that feels most true to your future successful self.
        </div>
        <?php endif; ?>
        
        <div class="question-box">
            <div class="scenario">
                <?php echo htmlspecialchars($question['question_context']); ?>
            </div>
            
            <div class="question-text">
                <?php echo htmlspecialchars($question['question_text']); ?>
            </div>
            
            <form method="post" id="answerForm">
                <input type="hidden" name="save_answer" value="1">
                <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                <input type="hidden" name="question_number" value="<?php echo $question['question_number']; ?>">
                <input type="hidden" name="philosophy_principle" value="<?php echo htmlspecialchars($question['philosophy_principle']); ?>">
                
                <div class="options">
                    <button type="button" class="option-button" onclick="selectOption('A', <?php echo $question['option_a_score']; ?>, <?php echo htmlspecialchars(json_encode($question['option_a_text']), ENT_QUOTES); ?>)">
                        <div class="option-label">Option A</div>
                        <div class="option-text"><?php echo htmlspecialchars($question['option_a_text']); ?></div>
                    </button>
                    
                    <button type="button" class="option-button" onclick="selectOption('B', <?php echo $question['option_b_score']; ?>, <?php echo htmlspecialchars(json_encode($question['option_b_text']), ENT_QUOTES); ?>)">
                        <div class="option-label">Option B</div>
                        <div class="option-text"><?php echo htmlspecialchars($question['option_b_text']); ?></div>
                    </button>
                    
                    <button type="button" class="option-button" onclick="selectOption('C', <?php echo $question['option_c_score']; ?>, <?php echo htmlspecialchars(json_encode($question['option_c_text']), ENT_QUOTES); ?>)">
                        <div class="option-label">Option C</div>
                        <div class="option-text"><?php echo htmlspecialchars($question['option_c_text']); ?></div>
                    </button>
                </div>
                
                <input type="hidden" name="selected_option" id="selected_option">
                <input type="hidden" name="score" id="score">
                <input type="hidden" name="selected_text" id="selected_text">
            </form>
        </div>
        
        <div class="navigation">
            <?php if ($current_q > 1): ?>
            <form method="post" style="display: inline;">
                <button type="submit" name="go_back" class="btn btn-back">← Previous</button>
            </form>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            
            <form method="post" style="display: inline;">
                <button type="submit" name="reset" class="btn btn-reset">🔄 Reset</button>
            </form>
        </div>
    </div>

<?php endif; ?>

<script>
function selectOption(option, score, text) {
    document.getElementById('selected_option').value = option;
    document.getElementById('score').value = score;
    document.getElementById('selected_text').value = text;
    document.getElementById('answerForm').submit();
}
</script>

</body>
</html>