<?php
$azure_apiKey    = "3vs0sstQbPry82FdryDffZchAIaIVBDxtQcdSFthWTh1fPikMQEHJQQJ99BLACREanaXJ3w3AAAYACOGtITo";  // Replace with your actual key
$gemini_apiKey   = "AIzaSyD-hdKf1-ASwR3FVooxNSIluA0jPtYjKho";
$eleven_lab_api_key   = "sk_ec6e1134b726e22e5b70e13d0d79b50e1f5a30d4fdcbef8d";
$chatgpt_api_key = "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";
$google_api_key  = "AIzaSyBr8KW5xPwsv16U_C8kbYQzGHy9wys4eu0";


/********************************************************************************/
define('FB_APP_ID', '952268383945804');
define('FB_APP_SECRET', '06510d978bdc1fbd3d11733839c94442');
define('FB_REDIRECT_URI', 'https://videovizard.com/facebook-callback.php');
/********************************************************************************/

$falApiKey = '9df14313-bebe-4e41-bbe8-58bce75e6a56:622f06c8eda041b63af21d87fa87dc4e';
$pexel_key = "bHCX4xLzv0xxVsHeUesP4R2ht6dWOtp6M4R9bvXWhCoshl3yYySCJZMc";

// fal.ai async video — public URL fal.ai POSTs the finished result to.
// Must resolve to the box that runs cron_video_gen.php + the queue DB.
define('FAL_WEBHOOK_URL',   'http://187.124.249.46/videovizard.com/fal_webhook.php');
// Shared secret embedded in the webhook URL (?token=) so fal_webhook.php can
// reject anyone POSTing to the public endpoint who isn't fal.ai's callback.
define('FAL_WEBHOOK_TOKEN', '8328fa7c35bc0a1c2157a8a7a6da0072b97b4efac55bb0aa');
// Defense-in-depth: also verify fal.ai's ED25519 signature (needs libsodium).
// Token alone is sufficient; flip on once verified working in prod.
define('FAL_WEBHOOK_VERIFY_SIGNATURE', false);
/********************************************************************************/

// tiktok_config.php
define('TT_CLIENT_KEY',    'aw7892eebf06uo4y');
define('TT_CLIENT_SECRET', 'Lv3QcoTVlG4alnlqMgzVvxZE30Llkqtm');
define('TT_REDIRECT_URI',  'https://videovizard.com/tiktok_callback.php');
// Full approved scopes — video.upload and video.publish now approved
define('TT_SCOPE', 'user.info.profile,user.info.stats,video.list,video.upload,video.publish');

/********************************************************************************/
define('STRIPE_SECRET_KEY',  'sk_test_51S0ekZ3NpwafsWMFl1VSwhQHgPj9WYqkgGO8uzdPDVkngqBGCfEjCzEkCFNqMnyGzaAVDuBwBC2Xo1J3BikaYlP600bEIG9aZ0');
define('STRIPE_PUBLIC_KEY',  'pk_test_51S0ekZ3NpwafsWMFvsw1NjNvalCEtpMy5U3YWp0TwwvdrHIPlIJXZAj7lBNI2lquKUid8SmgmsK0pQq6ojBByzfi00RLB8RNI3');

  $myApiKey ="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";
   $api_Key ="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";
  
// this is actual key
  $apiKey ="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";
// ============================================
// DATABASE CONNECTION
// ============================================ 
$dbhost = "localhost";
$dbase = "user_hypnotherapy_db2";  
$dbuser = "user_inaamalvi1403"; 
$dbpass = "AllahuAkbar786"; 



$dbuser = "user_inaamalvi1403"; 
$dbpass = "AllahuAkbar786";
$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);

if (!$conn) {
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "details" => mysqli_connect_error()
    ]);
    exit();
}
function getPromoPrice($conn, $service_key) {
    $key = mysqli_real_escape_string($conn, $service_key);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_ai_pricing 
         WHERE service_key = '$key' 
         AND is_active = 1 
         LIMIT 1"));
    return $row ?: null;
}

function calcVideoCost($conn, $scene_count, $scene_seconds, $video_model_key) {
    $model  = getPromoPrice($conn, $video_model_key);
    $flux   = getPromoPrice($conn, 'flux_modal');
    $gpt    = getPromoPrice($conn, 'gpt_scene_plan');
    
    if (!$model) return null;
    
    // API cost depends on billing unit
    if ($model['unit'] === 'per_second') {
        $api_cost = $scene_count * $scene_seconds * $model['our_cost'];
    } elseif ($model['unit'] === 'per_video') {
        $api_cost = $scene_count * $model['our_cost'];
    } else {
        $api_cost = $model['our_cost'];
    }
    
    $api_cost_with_retry = $api_cost * $model['retry_buffer'];
    $image_cost          = $scene_count * ($flux['our_cost'] ?? 0.02);
    $gpt_cost            = ($gpt['our_cost'] ?? 0.005) * 3; // 3 GPT calls
    $total_our_cost      = $api_cost_with_retry + $image_cost + $gpt_cost;
    
    // Get user-facing price from promo_video_ai row
    $package = getPromoPrice($conn, 'promo_video_ai');
    $charge  = $package['our_price'] ?? 12.00;
    
    return [
        'api_cost'       => round($api_cost, 4),
        'api_with_retry' => round($api_cost_with_retry, 4),
        'image_cost'     => round($image_cost, 4),
        'gpt_cost'       => round($gpt_cost, 4),
        'total_our_cost' => round($total_our_cost, 2),
        'charge'         => round($charge, 2),
        'margin'         => round($charge - $total_our_cost, 2),
        'margin_pct'     => round((($charge - $total_our_cost) / $charge) * 100, 1),
        'model_key'      => $video_model_key,
        'model_name'     => $model['service_name'],
    ];
}
?>