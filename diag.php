<?php
/**
 * COMPLETE REGENERATION SCRIPT
 * Customized for your actual questions and categories
 */

include 'dbconnect_hdb.php';

// ============================================================================
// CUSTOMIZED EXTRACTION FUNCTION
// ============================================================================

function extractBaselineInfo($qa_data, $baseline_rating = null) {
    $info = [
        'rating' => $baseline_rating ?? 5,
        'issue' => '',
        'duration' => 'Not specified',
        'trigger' => 'Not specified',
        'pattern' => 'Not specified',
        'symptoms' => [],
        'frequency' => 'Not specified',
        'impact' => 'Not specified'
    ];
    
    $all_answers = [];
    
    foreach ($qa_data as $qa) {
        $question_raw = $qa['question'];
        $question = preg_replace('/^.*Q:\d+\/\d+\s*-\s*/i', '', $question_raw);
        $question = preg_replace('/<\/?br>/i', '', $question);
        $question = strtolower(trim($question));
        
        $answer = trim($qa['answer']);
        
        if (empty($answer)) continue;
        if (preg_match('/^\d$/', $answer)) continue;
        
        $all_answers[] = $answer;
        
        // DURATION - Q3
        if (preg_match('/since how|how long|when.*start|began/i', $question)) {
            $info['duration'] = $answer;
            continue;
        }
        
        // PATTERN - Q4
        if (preg_match('/how does.*feel|constant.*waves/i', $question)) {
            $info['pattern'] = $answer;
            continue;
        }
        
        // FREQUENCY - Q5
        if (preg_match('/how often.*experience|during.*typical week/i', $question)) {
            $info['frequency'] = $answer;
            continue;
        }
        
        // PHYSICAL SYMPTOMS - Q7, Q8
        if (preg_match('/physical sensations|physical.*notice|body.*reaction|body.*feel/i', $question)) {
            $symptoms_text = $answer;
            if (strpos($symptoms_text, ',') !== false) {
                $parts = array_map('trim', explode(',', $symptoms_text));
                foreach ($parts as $part) {
                    if (strlen($part) > 3) {
                        $info['symptoms'][] = $part;
                    }
                }
            } else {
                if (strlen($symptoms_text) > 3) {
                    $info['symptoms'][] = $symptoms_text;
                }
            }
            continue;
        }
        
        // EMOTIONAL STATE - Q9
        if (preg_match('/emotions.*strongest|emotions.*feel/i', $question)) {
            if (strlen($answer) > 3 && !in_array($answer, $info['symptoms'])) {
                $info['symptoms'][] = $answer;
            }
            continue;
        }
        
        // NEGATIVE THOUGHTS - Q10
        if (preg_match('/thoughts.*worries|thoughts.*repeat/i', $question)) {
            $info['trigger'] = $answer;
            continue;
        }
        
        // TRIGGERS - Q12
        if (preg_match('/situations.*trigger|events.*trigger/i', $question)) {
            $info['trigger'] = $answer;
            continue;
        }
        
        // BEHAVIORAL PATTERNS - Q13
        if (preg_match('/how.*react|how.*respond/i', $question)) {
            if (strlen($answer) > 3 && !in_array($answer, $info['symptoms'])) {
                $info['symptoms'][] = $answer;
            }
            continue;
        }
        
        // AVOIDANCE - Q14
        if (preg_match('/avoid|stay away/i', $question)) {
            if (strlen($answer) > 3 && !in_array($answer, $info['symptoms'])) {
                $info['symptoms'][] = $answer;
            }
            continue;
        }
        
        // IMPACT ON DAILY LIFE - Q15, Q16
        if (preg_match('/affected.*daily|impact.*daily|interfere/i', $question)) {
            $info['impact'] = $answer;
            continue;
        }
        
        // ENERGY
        if (preg_match('/energy|fatigue|tired/i', $question)) {
            if ($info['impact'] === 'Not specified') {
                $info['impact'] = $answer;
            }
            continue;
        }
        
        // SLEEP - Q17
        if (preg_match('/sleep/i', $question)) {
            if (strlen($answer) > 3 && !in_array($answer, $info['symptoms'])) {
                $info['symptoms'][] = $answer;
            }
            continue;
        }
        
        // STRESS - Q19
        if (preg_match('/stresses.*pressure|current stress/i', $question)) {
            if ($info['trigger'] === 'Not specified') {
                $info['trigger'] = $answer;
            }
            continue;
        }
        
        // COPING - Q22
        if (preg_match('/currently do|cope with/i', $question)) {
            if (strlen($answer) > 3) {
                $info['symptoms'][] = $answer;
            }
            continue;
        }
    }
    
    // FALLBACKS
    if (empty($info['issue']) && !empty($all_answers)) {
        $info['issue'] = substr($all_answers[0], 0, 150);
    }
    
    if (count($info['symptoms']) > 5) {
        $info['symptoms'] = array_slice($info['symptoms'], 0, 5);
    }
    
    return $info;
}

// ============================================================================
// CUSTOMIZED NARRATIVE FUNCTION
// ============================================================================

function generateBaselineNarrative_NoAI($category_name, $baseline_info) {
    $rating = $baseline_info['rating'];
    $duration = $baseline_info['duration'];
    $pattern = $baseline_info['pattern'];
    $trigger = $baseline_info['trigger'];
    $symptoms = $baseline_info['symptoms'];
    $frequency = $baseline_info['frequency'];
    $impact = $baseline_info['impact'];
    
    $narrative = "";
    
    // Severity context
    if ($rating >= 8) {
        $severity_text = "You reported severe difficulties";
    } elseif ($rating >= 6) {
        $severity_text = "You reported moderate to significant challenges";
    } elseif ($rating >= 4) {
        $severity_text = "You reported noticeable difficulties";
    } else {
        $severity_text = "You reported mild concerns";
    }
    
    // Category-specific templates
    switch ($category_name) {
        case 'Duration & Onset':
            if ($duration !== 'Not specified') {
                $narrative = "You reported experiencing anxiety for {$duration}.";
            } else {
                $narrative = "{$severity_text} with anxiety duration.";
            }
            if ($pattern !== 'Not specified') {
                $narrative .= " The pattern has been {$pattern}.";
            }
            break;
            
        case 'Primary Symptom':
            if ($frequency !== 'Not specified') {
                $narrative = "You experience anxiety symptoms {$frequency}.";
            } else {
                $narrative = "{$severity_text} with primary anxiety symptoms.";
            }
            if ($rating > 0) {
                $narrative .= " You rated the intensity as {$rating}/10.";
            }
            break;
            
        case 'Physical Symptoms':
            if (!empty($symptoms)) {
                $symptom_list = implode(', ', array_slice($symptoms, 0, 3));
                $narrative = "You experience physical symptoms including {$symptom_list}.";
            } else {
                $narrative = "{$severity_text} with physical symptoms of anxiety.";
            }
            break;
            
        case 'Emotional State':
            if (!empty($symptoms)) {
                $emotion_list = implode(', ', array_slice($symptoms, 0, 3));
                $narrative = "The strongest emotions you experience are {$emotion_list}.";
            } else {
                $narrative = "{$severity_text} in your emotional well-being.";
            }
            break;
            
        case 'Negative Thoughts':
            if ($trigger !== 'Not specified') {
                $narrative = "You notice repeating thoughts such as {$trigger}.";
            } else {
                $narrative = "{$severity_text} with negative thought patterns.";
            }
            break;
            
        case 'Triggers':
            if ($trigger !== 'Not specified') {
                $narrative = "Situations that trigger your anxiety include {$trigger}.";
            } else {
                $narrative = "{$severity_text} related to identifying triggers.";
            }
            break;
            
        case 'Behavioral Patterns':
            if (!empty($symptoms)) {
                $behavior = $symptoms[0];
                $narrative = "When anxiety shows up, you typically {$behavior}.";
            } else {
                $narrative = "{$severity_text} with behavioral responses to anxiety.";
            }
            break;
            
        case 'Avoidance Patterns':
            if (!empty($symptoms)) {
                $avoidance_list = implode(', ', array_slice($symptoms, 0, 3));
                $narrative = "You tend to avoid {$avoidance_list} due to anxiety.";
            } else {
                $narrative = "{$severity_text} with avoidance behaviors.";
            }
            break;
            
        case 'Daily Functioning':
            if ($impact !== 'Not specified') {
                $narrative = "Anxiety has affected your daily life through {$impact}.";
            } else {
                $narrative = "{$severity_text} with daily functioning and activities.";
            }
            break;
            
        case 'Sleep Quality':
            if (!empty($symptoms)) {
                $sleep_issue = $symptoms[0];
                $narrative = "Anxiety affects your sleep by causing {$sleep_issue}.";
            } else {
                $narrative = "{$severity_text} with sleep quality.";
            }
            break;
            
        case 'Relationships & Support':
            if ($rating > 0) {
                if ($rating >= 7) {
                    $narrative = "You feel well supported by people around you (rated {$rating}/10).";
                } elseif ($rating >= 4) {
                    $narrative = "You feel moderately supported by people around you (rated {$rating}/10).";
                } else {
                    $narrative = "You feel limited support from people around you (rated {$rating}/10).";
                }
            } else {
                $narrative = "{$severity_text} in the area of relationships and support.";
            }
            break;
            
        case 'Stress Load':
            if ($trigger !== 'Not specified') {
                $narrative = "Current stresses contributing to your anxiety include {$trigger}.";
            } else {
                $narrative = "{$severity_text} with current life stresses.";
            }
            break;
            
        case 'Coping Strategies':
            if (!empty($symptoms)) {
                $coping_text = implode(', ', array_slice($symptoms, 0, 3));
                $narrative = "You currently cope with anxiety by using {$coping_text}.";
            } else {
                $narrative = "You discussed your current coping approaches.";
            }
            break;
            
        case 'Panic Attacks':
            if ($frequency !== 'Not specified') {
                $narrative = "You experience panic attacks {$frequency}.";
            } else {
                $narrative = "{$severity_text} related to panic attacks.";
            }
            break;
            
        case 'Fear & Phobias':
            if ($trigger !== 'Not specified' && $trigger !== 'no') {
                $narrative = "You have specific fears or phobias related to {$trigger}.";
            } elseif ($trigger === 'no') {
                $narrative = "You reported no specific fears or phobias.";
            } else {
                $narrative = "{$severity_text} related to specific fears.";
            }
            break;
            
        case 'Focus & Concentration':
            if ($impact !== 'Not specified') {
                $narrative = "Anxiety affects your concentration through {$impact}.";
            } else {
                $narrative = "{$severity_text} with focus and concentration.";
            }
            break;
            
        default:
            $narrative = "{$severity_text} in the {$category_name} area.";
            if ($duration !== 'Not specified') {
                $narrative .= " This has been present for {$duration}.";
            }
            break;
    }
    
    return $narrative;
}

// ============================================================================
// REGENERATE ALL SUMMARIES
// ============================================================================

echo "Starting regeneration with customized functions...\n\n";

$sql = "SELECT DISTINCT question_category 
        FROM hdb_client_questions 
        WHERE client_id = 1 AND session_name = 'session_1'
        ORDER BY question_category";

$result = $conn->query($sql);

$total = 0;
$success = 0;
$failed = 0;

while ($row = $result->fetch_assoc()) {
    $category = $row['question_category'];
    $total++;
    
    echo "\n[{$total}] Processing: {$category}\n";
    echo str_repeat("-", 70) . "\n";
    
    // Get Q&A
    $qa_sql = "SELECT question_text, user_input, current_rating
               FROM hdb_client_questions
               WHERE client_id = 1
               AND question_category = '" . $conn->real_escape_string($category) . "'
               AND session_name = 'session_1'
               ORDER BY created_at ASC";
    
    $qa_result = $conn->query($qa_sql);
    
    $qa_data = [];
    $baseline_rating = null;
    
    while ($qa = $qa_result->fetch_assoc()) {
        $qa_data[] = [
            'question' => $qa['question_text'],
            'answer' => $qa['user_input']
        ];
        
        if ($qa['current_rating'] && !$baseline_rating) {
            $baseline_rating = (int)$qa['current_rating'];
        }
    }
    
    echo "  Q&A pairs: " . count($qa_data) . "\n";
    
    if (empty($qa_data)) {
        echo "  ❌ No Q&A data\n";
        $failed++;
        continue;
    }
    
    // Extract
    $baseline = extractBaselineInfo($qa_data, $baseline_rating);
    
    // Generate narrative
    $narrative = generateBaselineNarrative_NoAI($category, $baseline);
    
    echo "  Narrative: " . substr($narrative, 0, 80) . "...\n";
    
    // Create summary
    $summary = [
        'baseline' => [
            'severity_rating' => $baseline['rating'],
            'narrative_summary' => $narrative,
            'primary_issue' => $baseline['issue'],
            'duration' => $baseline['duration'],
            'trigger' => $baseline['trigger'],
            'pattern' => $baseline['pattern'],
            'symptoms' => $baseline['symptoms'],
            'frequency' => $baseline['frequency'],
            'impact' => $baseline['impact']
        ],
        'current_status' => 'baseline',
        'total_improvement' => 0,
        'last_session_improvement' => 0,
        'last_updated' => date('Y-m-d H:i:s'),
        'session_count' => 1,
        'improvements_notes' => [],
        'category_name' => $category,
        'created_date' => date('Y-m-d H:i:s')
    ];
    
    $json = $conn->real_escape_string(json_encode($summary, JSON_UNESCAPED_UNICODE));
    
    // Save
    $save_sql = "INSERT INTO client_assessment_summaries 
                 (client_id, category_name, summary_data, created_at, updated_at)
                 VALUES (1, '{$conn->real_escape_string($category)}', '{$json}', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE 
                 summary_data = VALUES(summary_data),
                 updated_at = NOW()";
    
    if ($conn->query($save_sql)) {
        echo "  ✅ Saved successfully\n";
        $success++;
    } else {
        echo "  ❌ Save failed: " . $conn->error . "\n";
        $failed++;
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "REGENERATION COMPLETE\n"; 
echo str_repeat("=", 70) . "\n";
echo "Total categories: {$total}\n";
echo "Successfully regenerated: {$success}\n";
echo "Failed: {$failed}\n";

$conn->close();
?>