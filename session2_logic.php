<?php

function handle_session2_flow($conn, $client_id, $user_input = null) {

    //$status = get_client_status($conn, $client_id);
	$status - "free_audio_delivered";
    // STEP 1: Welcome
    if ($status === 'free_audio_delivered') {
        update_status($conn, $client_id, 'session_2_feedback');
        $minutes = getClientListeningMinutes($conn, $client_id);

        return [
            'message' => "Welcome back 🌱 I noticed you listened to your audio for about {$minutes} minutes. How was your experience?"
        ];
    }

    // STEP 2: Feedback
    if ($status === 'session_2_feedback') {
        save_feedback($conn, $client_id, $user_input);
        update_status($conn, $client_id, 'session_2_assessment');

        return [
            'message' => "Thank you for sharing. I’ll now ask you a few follow-up questions to understand your progress."
        ];
    }

    // STEP 3: Assessment handled by your existing engine
    if ($status === 'session_2_assessment') {
        return getQuestionData_new($conn, [
            'client_id' => $client_id,
            'session_name' => 'session_2'
        ]);
    }

    // STEP 4: Offer Chat
    if ($status === 'offer_presented' || $status === 'offer_chat_open') {
        update_status($conn, $client_id, 'offer_chat_open');
        return handle_offer_objection($conn, $client_id, $user_input);
    }

    return ['message' => "⚠️ Sorry, something went wrong. Please try again."];
}
