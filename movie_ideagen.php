<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'config.php';

$apiUrl = "https://api.openai.com/v1/chat/completions";

$response = "";
$step = "0";
$userInput = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $step      = $_POST['step']       ?? "1";
    $userInput = $_POST['user_input'] ?? "";
}

function validateInput($input) {
    $input = strtolower(trim($input));
    $validIntent = ["video","idea","story","reel","tiktok","content","ad","marketing"];
    $business    = ["gym","fitness","trainer","coach","salon","hair","beauty","restaurant","cafe","real estate","agent","yoga","financial","advisor","boutique","fashion","instructor"];
    $hasIntent   = false;
    foreach ($validIntent as $w) { if (strpos($input, $w) !== false) { $hasIntent = true; break; } }
    $hasBusiness = false;
    foreach ($business as $w)    { if (strpos($input, $w) !== false) { $hasBusiness = true; break; } }
    return ($hasIntent && $hasBusiness) ? "valid" : "invalid";
}

function callAI($apiUrl, $apiKey, $systemPrompt, $userInput) {
    $data = [
        "model"       => "gpt-4o-mini",
        "messages"    => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user",   "content" => $userInput]
        ],
        "temperature" => 0.9
    ];
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST,       true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $result = curl_exec($ch);
    if ($result === false) return "CURL ERROR: " . curl_error($ch);
    curl_close($ch);
    $json = json_decode($result, true);
    if (isset($json['error'])) return "API ERROR: " . $json['error']['message'];
    return $json["choices"][0]["message"]["content"] ?? "Error generating response.";
}

// ── STEP 1 ────────────────────────────────────────────────────
if ($step == "1" && $_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($userInput))) {
        $response = "⚠️ Please enter your business description and what kind of video you want.";
    } else {
        $_SESSION['input'] = $userInput;
        $systemPrompt = "
You are a cinematic story director.
Generate ONLY a story concept for a short cinematic video.
Return:
1. Title
2. Sentiment
3. Hook
4. Character
5. Setting
6. CORE STORY IDEA (1 paragraph cinematic narrative)
DO NOT include scenes or technical video instructions.
";
        $response = callAI($apiUrl, $apiKey, $systemPrompt, $userInput);
        $_SESSION['story'] = $response;
    }
}

// ── STEP 2 ────────────────────────────────────────────────────
if ($step == "2" && $_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_SESSION['story'])) {
        $response = "⚠️ Session expired. Please start over.";
    } else {
        $storyInput   = $_SESSION['story'];
        $systemPrompt = "
You are a cinematic AI video director.
Convert the approved story into a 30–40 second faceless cinematic video.
OUTPUT MUST INCLUDE:
1. Scene breakdown (5–7 scenes)
   - Scene description
   - WAN 2.2 prompt (highly detailed cinematic)
   - Camera movement
   - Lighting style
   - Emotional intent
   - On-screen text
2. AUDIO DESIGN
   - music style
   - tempo
   - emotional purpose
   - sound effects
3. CAPTIONS
   - hook caption
   - mid captions
   - final caption
RULES:
- Must match story exactly
- Must be cinematic and faceless
";
        $response = callAI($apiUrl, $apiKey, $systemPrompt, $storyInput);
        unset($_SESSION['story']);
        unset($_SESSION['input']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cinematic AI System</title>
    <style>
        body { font-family: Arial; background:#111; color:#fff; padding:20px; max-width:900px; margin:0 auto; }
        textarea { width:100%; height:120px; background:#222; color:#fff; border:1px solid #444; padding:10px; border-radius:6px; font-size:14px; }
        button { padding:10px 20px; margin-top:10px; cursor:pointer; background:#3b82f6; color:#fff; border:none; border-radius:6px; font-size:14px; font-weight:bold; }
        button:hover { background:#2563eb; }
        button.green { background:#22c55e; }
        button.green:hover { background:#16a34a; }
        button.gray { background:#555; }
        button.gray:hover { background:#444; }
        .box { background:#222; padding:20px; margin-top:20px; white-space:pre-wrap; border-radius:8px; border:1px solid #333; line-height:1.7; }
        .box h3 { margin-top:0; color:#3b82f6; }
        .error { color:#f87171; background:#2d1515; padding:15px; border-radius:6px; margin-top:15px; }
        .actions { display:flex; gap:10px; margin-top:15px; flex-wrap:wrap; }
    </style>
</head>
<body>

<h2>🎬 AI Cinematic Video Generator</h2>

<!-- STEP 1 FORM — always visible -->
<form method="POST">
    <textarea name="user_input" placeholder="Example: I am a gym instructor in Milton and I want a video idea for seniors fitness"><?php echo htmlspecialchars($userInput); ?></textarea><br>
    <input type="hidden" name="step" value="1">
    <button type="submit">🎬 Generate Story Idea</button>
</form>

<?php if ($step == "1" && $response): ?>

    <?php if (strpos($response, '⚠️') === 0 || strpos($response, 'ERROR') !== false): ?>
        <div class="error"><?php echo nl2br(htmlspecialchars($response)); ?></div>
    <?php else: ?>
        <!-- Story output -->
        <div class="box">
            <h3>📖 Story Idea</h3>
            <?php echo nl2br(htmlspecialchars($response)); ?>
        </div>

        <div class="actions">
            <!-- Approve -->
            <form method="POST">
                <input type="hidden" name="step" value="2">
                <button type="submit" class="green">✅ Approve & Generate Full Video</button>
            </form>

            <!-- Regenerate -->
            <form method="POST">
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="user_input" value="<?php echo htmlspecialchars($_SESSION['input'] ?? $userInput); ?>">
                <button type="submit" class="gray">🔁 Regenerate Story</button>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ($step == "2" && $response): ?>
    <div class="box">
        <h3>🎥 Full Video Plan</h3>
        <?php echo nl2br(htmlspecialchars($response)); ?>
    </div>
    <div class="actions">
        <form method="POST">
            <input type="hidden" name="step" value="1">
            <button type="submit" class="gray">🔄 Start Over</button>
        </form>
    </div>
<?php endif; ?>

</body>
</html>