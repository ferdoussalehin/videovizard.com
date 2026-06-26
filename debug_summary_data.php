<?php
/**
 * FINAL FIXED VERSION - extractBaselineInfo()
 * 
 * Replace your existing function with this one
 */
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
        // Clean the question - remove "Q:3/31 -" style prefixes
        $question_raw = $qa['question'];
        $question = preg_replace('/^.*Q:\d+\/\d+\s*-\s*/i', '', $question_raw);
        $question = preg_replace('/<\/?br>/i', '', $question); // Remove <br> tags
        $question = strtolower(trim($question));
        
        $answer = trim($qa['answer']);
        
        // Skip empty answers
        if (empty($answer)) continue;
        
        // Skip if answer is ONLY a single digit (0-9) - likely just a rating
        if (preg_match('/^\d$/', $answer)) {
            continue;
        }
        
        $all_answers[] = $answer;
        
        // ====================================================================
        // PRIMARY ISSUE
        // ====================================================================
        if (empty($info['issue'])) {
            if (preg_match('/main|primary|biggest|tell me about|describe.*problem|what.*bother|concern|what.*experiencing/i', $question)) {
                $info['issue'] = substr($answer, 0, 150);
            } elseif (strlen($answer) > 20) {
                $info['issue'] = substr($answer, 0, 150);
            }
        }
        
        // ====================================================================
        // DURATION - How long?
        // ====================================================================
        if (preg_match('/how long|duration|when.*start|since when|began|how many (years|months|weeks)|since how/i', $question)) {
            $info['duration'] = $answer;
        }
        
        // ====================================================================
        // TRIGGER - What started it?
        // ====================================================================
        if (preg_match('/trigger|cause|what.*started|what.*began|why.*think|reason|what happened/i', $question)) {
            $info['trigger'] = $answer;
        }
        
        // ====================================================================
        // PATTERN - Constant, waves, episodic?
        // FIXED: Removed the strlen check that was breaking it!
        // ====================================================================
        if (preg_match('/pattern|constant|frequent|episodic|when.*occur|how.*feel|in waves|show up|manifest|since it started/i', $question)) {
            $info['pattern'] = $answer;  // ← FIXED: No length check!
        } 
        // Also check if answer contains pattern keywords
        elseif (preg_match('/constant|always|all the time|every day|episodic|sometimes|situational|only when|waves|comes and goes/i', $answer)) {
            if ($info['pattern'] === 'Not specified') {
                $info['pattern'] = $answer;
            }
        }
        
        // ====================================================================
        // SYMPTOMS - What do you feel/experience?
        // ====================================================================
        if (preg_match('/symptom|feel|experience|describe|notice|physical|emotional|what.*like|show up/i', $question)) {
            $symptoms_text = $answer;
            
            // Split comma-separated symptoms
            if (strpos($symptoms_text, ',') !== false || strpos($symptoms_text, ' and ') !== false) {
                $parts = preg_split('/,|\sand\s/', $symptoms_text);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (strlen($part) > 3 && !in_array($part, $info['symptoms'])) {
                        $info['symptoms'][] = $part;
                    }
                }
            } else {
                if (strlen($symptoms_text) > 3 && !in_array($symptoms_text, $info['symptoms'])) {
                    $info['symptoms'][] = $symptoms_text;
                }
            }
        }
        
        // ====================================================================
        // FREQUENCY - How often?
        // ====================================================================
        if (preg_match('/how often|frequency|times.*(week|day|month)|per (day|week|month)/i', $question)) {
            $info['frequency'] = $answer;
        } 
        // Check answer for frequency patterns
        elseif (preg_match('/\d+.*times.*(day|week|month)|daily|weekly|monthly|hourly|most days|every day/i', $answer)) {
            if ($info['frequency'] === 'Not specified') {
                $info['frequency'] = $answer;
            }
        }
        
        // ====================================================================
        // IMPACT - How does it affect you?
        // ====================================================================
        if (preg_match('/affect|impact|interfere|difficult|problem|trouble|prevent|stop.*from|hard to/i', $question)) {
            $info['impact'] = $answer;
        }
    }
    
    // ====================================================================
    // FALLBACKS - Smart defaults if fields still empty
    // ====================================================================
    
    // If primary issue still empty, use first substantial answer
    if (empty($info['issue']) && !empty($all_answers)) {
        $info['issue'] = substr($all_answers[0], 0, 150);
    }
    
    // If no symptoms but we have multiple answers, use some as symptoms
    if (empty($info['symptoms']) && count($all_answers) >= 2) {
        $info['symptoms'] = array_slice($all_answers, 0, min(3, count($all_answers)));
    }
    
    // Limit symptoms to top 5
    if (count($info['symptoms']) > 5) {
        $info['symptoms'] = array_slice($info['symptoms'], 0, 5);
    }
    
    return $info;
}

// ============================================================================
// TEST WITH YOUR ACTUAL DATA
// ============================================================================

echo "Testing with your actual Duration & Onset data:\n";
echo str_repeat("=", 70) . "\n\n";

$test_data = [
    [
        'question' => 'Q:3/31 - Since how low you have suffering from anxiety? (2 months, 2 years, from childhood, long time)',
        'answer' => '2 years'
    ],
    [
        'question' => 'Q:4/31 -Since it started, how does your anxiety feel? (constant, or in waves)',
        'answer' => 'constant'
    ]
];

$result = extractBaselineInfo($test_data, null);

echo "Extracted Information:\n";
echo str_repeat("-", 70) . "\n";
foreach ($result as $key => $value) {
    if (is_array($value)) {
        echo "$key: " . json_encode($value) . "\n";
    } else {
        echo "$key: $value\n";
    }
}

echo "\n\nExpected Results:\n";
echo str_repeat("-", 70) . "\n";
echo "✓ duration: 2 years\n";
echo "✓ pattern: constant\n";
echo "✓ issue: 2 years (fallback - first answer)\n";
?>