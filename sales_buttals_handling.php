<?php
/**
 * SIMPLE SALES REBUTTAL HANDLER
 * 
 * You handle buttons in your frontend.
 * This just gives you the right response text.
 * 
 * USAGE:
 * 
 * $response_text = handle_sales_rebuttal($conn, $client_id, $user_input);
 * 
 * $data = [
 *     "question_text" => $response_text,
 *     "show_buttons" => true,
 *     "button_options" => [
 *         ["label" => "💎 6-Session Program – $199", "value" => "package_6"],
 *         ["label" => "🧘 Single Session – $49", "value" => "single_session"]
 *     ]
 * ];
 */

include_once 'function_store.php';

/**
 * Main rebuttal handler - returns full response with buttons
 */
function handle_sales_rebuttal($conn, $client_id, $user_input) {
    
    // Detect objection type
    $objection_type = detect_objection_type($user_input);
    
    error_log("🔵 Sales Rebuttal - Input: '{$user_input}' → Type: {$objection_type}\n", 3, __DIR__ . "/a_debug.log");
    
    // Try FAQ database first
    $faq = search_sales_faq($conn, $user_input);
    
    $response_text = '';
    
    if ($faq['matched']) {
        error_log("✅ FAQ Match: {$faq['category']} (Score: {$faq['confidence']})\n", 3, __DIR__ . "/a_debug.log");
        $response_text = $faq['response'] . "\n\nWould you like to continue with the program?";
    } else {
        // Fallback to AI
        error_log("🤖 Using AI fallback for objection: {$objection_type}\n", 3, __DIR__ . "/a_debug.log");
        $response_text = get_ai_rebuttal($user_input, $objection_type);
    }
    
    // ✅ RETURN FULL RESPONSE WITH BUTTONS
    return [
        'question_text' => $response_text,
        'button_value' => '{"show_buttons":true,"button_options":[{"label":"💎 $199 Package","value":"package_199"},{"label":"🧘 $49 Virtual Session","value":"session_49"}]}'
    ];
}

/**
 * Detect what type of objection this is
 */
function detect_objection_type($input) {
    $input = strtolower($input);
    
    // Price objections
    if (preg_match('/afford|expensive|money|cost|price|budget|cheap|jobless|broke/', $input)) {
        return 'price';
    }
    
    // Safety concerns
    if (preg_match('/danger|safe|control|mind|harm|scary|afraid|risk|side effect/', $input)) {
        return 'safety';
    }
    
    // Effectiveness doubts
    if (preg_match('/work|guarantee|result|fail|effective|proof|evidence|scam|fake/', $input)) {
        return 'doubt';
    }
    
    // Delay tactics
    if (preg_match('/think|later|not now|maybe|tomorrow|decide|time|wait/', $input)) {
        return 'delay';
    }
     
    // Medical eligibility
    if (preg_match('/medicine|drug|doctor|bipolar|schizo|mania|medication|therapy|psychiatrist/', $input)) {
        return 'eligibility';
    }
    
    // Difference between options
    if (preg_match('/difference|compare|versus|vs|which|better|choose/', $input)) {
        return 'comparison';
    }
    
    return 'general';
}

/**
 * Search FAQ database for matching response
 */
function search_sales_faq($conn, $user_input) {
    
    $user_input_lower = strtolower(trim($user_input));
    
    $sql = "SELECT id, question_pattern, category, answer, keywords 
            FROM post_audio_faqs 
            WHERE is_active = 1 
            ORDER BY display_order ASC";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        error_log("❌ FAQ query failed: " . $conn->error . "\n", 3, __DIR__ . "/a_debug.log");
        return ['matched' => false];
    }
    
    $best_match = null;
    $best_score = 0;
    
    while ($row = $result->fetch_assoc()) {
        $score = 0;
        
        // Parse keywords
        $keywords = array_map('trim', explode(',', strtolower($row['keywords'])));
        
        // Score keyword matches
        foreach ($keywords as $keyword) {
            if (stripos($user_input_lower, $keyword) !== false) {
                $score += 2;
            }
        }
        
        // Score question pattern match
        if (stripos($user_input_lower, strtolower($row['question_pattern'])) !== false) {
            $score += 10;
        }
        
        // Score partial word matches
        $question_words = explode(' ', strtolower($row['question_pattern']));
        foreach ($question_words as $word) {
            if (strlen($word) > 3 && stripos($user_input_lower, $word) !== false) {
                $score += 1;
            }
        }
        
        // Track best match
        if ($score > $best_score) {
            $best_score = $score;
            $best_match = $row;
        }
    }
    
    // Minimum confidence threshold
    if ($best_score >= 3) {
        return [
            'matched' => true,
            'response' => $best_match['answer'],
            'category' => $best_match['category'],
            'confidence' => $best_score
        ];
    }
    
    return ['matched' => false];
}

/**
 * Get AI-generated rebuttal response
 */
function get_ai_rebuttal($user_input, $objection_type) {
    
    $prompt = "
You are a calm, ethical hypnotherapy consultant at 247 Online Therapy.

CONTEXT:
• Client completed a free AI hypnotherapy session
• They experienced positive results
• Now considering: 6-Session Program ($199) or Single Session ($49)
• 6-Session includes: 4 AI sessions + 2 live sessions with certified therapist

CLIENT OBJECTION:
\"{$user_input}\"

OBJECTION TYPE: {$objection_type}

YOUR RESPONSE RULES:
1. Acknowledge their concern warmly (no dismissing)
2. Normalize the hesitation (\"Many people feel this way...\")
3. Address the specific objection briefly
4. Remind them of their positive progress
5. Gently invite them to continue
6. NO pressure, NO manipulation
7. NO guarantees
8. Keep it under 90 words
9. End with a gentle question

TONE: Warm, understanding, professional, ethical

Be conversational and human. Don't sound like a sales pitch.
";
    
    $ai_response = callChatGPT_inam($prompt);
    
    // Extract text from response
    if (is_array($ai_response) && isset($ai_response['question_text'])) {
        return $ai_response['question_text'];
    }
    
    if (is_string($ai_response)) {
        return $ai_response;
    }
    
    // Fallback response if AI fails
    return get_fallback_rebuttal($objection_type);
}

/**
 * Fallback responses if AI fails
 */
function get_fallback_rebuttal($objection_type) {
    
    $fallbacks = [
        'price' => "I completely understand budget concerns. Many of our clients felt the same way initially. What helped them decide was seeing the value: the 6-session program is actually $434 worth of services for $199 - that's a $235 savings. Plus, you've already experienced positive change from the free session. Would investing in your well-being make sense at this price?",
        
        'safety' => "Safety is absolutely the top priority. Clinical hypnotherapy is evidence-based and completely safe - you remain in full control at all times. There are no drugs, no side effects. You've already experienced it in your free session and felt good, right? That's exactly how the paid sessions work. Does that help ease your concerns?",
        
        'doubt' => "I hear you. It's natural to wonder if it will work. Here's what we know: you've already experienced positive change from just one free session. That's proof your mind is responding. The 6-session program builds on that momentum with personalized sessions plus live guidance from a certified therapist. What specific results are you hoping for?",
        
        'delay' => "I completely understand wanting to think it over. That's wise. Here's something to consider though: you've already started the healing process with your free session. Momentum matters in therapy - the sooner you continue, the better the results. What's holding you back from taking the next step today?",
        
        'eligibility' => "Great question. Hypnotherapy complements medical treatment - it doesn't replace it. If you're on medication or seeing a doctor, that's fine. Hypnotherapy works alongside traditional treatment to help with stress, anxiety, sleep, and other symptoms. It's not medical advice. Does that clarify things?",
        
        'comparison' => "Good question! The single session ($49) gives you one AI-personalized audio. The 6-session program ($199) gives you 4 AI sessions PLUS 2 live sessions with me, a certified therapist. That's $434 value for $199. The live sessions let me personally assess your progress and fine-tune the approach. Which sounds like a better fit for your goals?",
        
        'general' => "I understand your concern. Many people hesitate before starting, and that's completely normal. What matters is that you've already experienced positive change from your free session - that shows your mind is ready to heal. The paid program builds on that progress with personalized guidance. What would help you feel more confident about continuing?"
    ];
    
    return $fallbacks[$objection_type] ?? $fallbacks['general'];
}

/**
 * Helper: Get client data for personalization (optional - use if you want)
 */
function get_client_progress_data($conn, $client_id) {
    
    $sql = "SELECT 
                c.firstname,
                c.topic_name,
                c.issue_description,
                c.session_stage
            FROM hdb_clients c
            WHERE c.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    return $data;
}


function is_sales_context($conn, $client_id) {
    // Check if client stage indicates sales conversation
    $stmt = $conn->prepare("SELECT session_stage FROM hdb_clients WHERE id = ?");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $stmt->bind_result($stage);
    $stmt->fetch();
    $stmt->close();
    
    return in_array($stage, ['offer_presented', 'offer_questions']);
}
?>