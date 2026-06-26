<?php
session_start();
include 'logo_component.php';
include 'dbconnect_hdb.php'; 
include 'translate.php';

$user_logged_in = isset($_SESSION['audio_user_id']) && isset($_SESSION['audio_user_email']);

if (!$user_logged_in && isset($_COOKIE['audio_user_email'])) {
    $cookie_email = mysqli_real_escape_string($conn, $_COOKIE['audio_user_email']);
    $cookie_query = "SELECT id, name, email FROM users_audio WHERE email = '$cookie_email'";
    $cookie_result = mysqli_query($conn, $cookie_query);
    if ($cookie_result && mysqli_num_rows($cookie_result) > 0) {
        $cookie_user = mysqli_fetch_assoc($cookie_result);
        $_SESSION['audio_user_id'] = $cookie_user['id'];
        $_SESSION['audio_user_name'] = $cookie_user['name'];
        $_SESSION['audio_user_email'] = $cookie_user['email'];
        $user_logged_in = true; 
        mysqli_query($conn, "UPDATE users_audio SET last_active = NOW() WHERE id = " . $cookie_user['id']);
    }
}

$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';
$lang_config = [
    'en' => ['prefix' => '', 'dir' => 'ltr'],
    'es' => ['prefix' => 'es_', 'dir' => 'ltr'],
    'fr' => ['prefix' => 'fr_', 'dir' => 'ltr'],
    'ar' => ['prefix' => 'ar_', 'dir' => 'rtl'],
    'ur' => ['prefix' => 'ur_', 'dir' => 'rtl'],
    'hi' => ['prefix' => 'hi_', 'dir' => 'ltr']
];

$audio_prefix = $lang_config[$lang]['prefix'] ?? '';
$text_direction = $lang_config[$lang]['dir'] ?? 'ltr';
$trigger = isset($_GET['trigger']) ? $_GET['trigger'] : 'work';

// Load Translations
$en_file = "translations/stress_trigger_en.json";
$t = [];
if (file_exists($en_file)) {
    $en_json = file_get_contents($en_file);
    $t = json_decode($en_json, true) ?? [];
}
if ($lang !== 'en') {
    $lang_file = "translations/stress_trigger_{$lang}.json";
    if (file_exists($lang_file)) {
        $lang_json = file_get_contents($lang_file);
        $lang_data = json_decode($lang_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($lang_data)) {
            $t = array_merge($t, $lang_data);
        }
    }
}

$triggers = [
    'work' => [
        'title' => $t['work_title'] ?? 'Work Stress',
        'icon' => '💼',
        'description' => $t['work_description'] ?? 'High pressure and deadlines.',
        'symptoms' => $t['work_symptoms'] ?? ['Racing heart', 'Tight shoulders', 'Brain fog'],
        'audio_helps' => $t['work_audio_helps'] ?? 'Rewires your focus and calms the nervous system.',
        'audio_file' => "web_audios/{$audio_prefix}workstress.mp3",
    ],
    'money' => [
        'title' => $t['money_title'] ?? 'Money Stress',
        'icon' => '💰',
        'description' => $t['money_description'] ?? 'Anxiety about bills and future.',
        'symptoms' => $t['money_symptoms'] ?? ['Sleep loss', 'Feeling trapped', 'Irritability'],
        'audio_helps' => $t['money_audio_helps'] ?? 'Shifts your mind from scarcity to solution-oriented calm.',
        'audio_file' => "web_audios/{$audio_prefix}financestress.mp3",
    ],
    'relationships' => [
        'title' => $t['relationships_title'] ?? 'Relationship Stress',
        'icon' => '❤️',
        'description' => $t['relationships_description'] ?? 'Conflict or emotional disconnection.',
        'symptoms' => $t['relationships_symptoms'] ?? ['Chest tightness', 'Overthinking', 'Exhaustion'],
        'audio_helps' => $t['relationships_audio_helps'] ?? 'Promotes emotional regulation and clear communication.',
        'audio_file' => "web_audios/{$audio_prefix}relationshipstress.mp3",
    ]
];

$current = $triggers[$trigger] ?? $triggers['work'];

if ($user_logged_in) {
    $user_id = (int)$_SESSION['audio_user_id'];
    $track_query = "INSERT INTO audio_listens (user_id, trigger_type) VALUES ($user_id, '$trigger')";
    mysqli_query($conn, $track_query);
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $text_direction; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $current['title']; ?> | StressReleasor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #667eea; --secondary: #764ba2; --bg: #f8f9ff; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #333; line-height: 1.6; direction: <?php echo $text_direction; ?>; }
        .container { max-width: 1100px; margin: 0 auto; padding: 0 20px; }

        /* Navigation */
        .navbar { background: white; padding: 15px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .nav-content { display: flex; justify-content: space-between; align-items: center; }
        
        /* Header Section */
        .header-hero { padding: 60px 0; text-align: center; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; margin-bottom: 40px; }
        .trigger-icon-large { font-size: 64px; margin-bottom: 10px; }
        .trigger-title { font-size: 42px; font-weight: 800; }

        /* Layout Grid */
        .main-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px; margin-bottom: 60px; }

        /* Left Column: Info */
        .info-card { background: white; padding: 30px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); height: fit-content; }
        .section-label { color: var(--primary); font-weight: 700; text-transform: uppercase; font-size: 13px; letter-spacing: 1px; margin-bottom: 10px; display: block; }
        .symptoms-list { list-style: none; margin: 20px 0; }
        .symptoms-list li { padding: 10px 0; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; }
        .symptoms-list li::before { content: "●"; color: var(--primary); margin-right: 10px; font-size: 12px; }

        /* Right Column: Audio Options */
        .audio-container { display: flex; flex-direction: column; gap: 20px; }
        
        .audio-card { background: white; border-radius: 24px; padding: 30px; position: relative; border: 2px solid transparent; transition: 0.3s; }
        .audio-card.quick { border-color: #e2e8f0; background: #fafbff; }
        .audio-card.deep { border-color: var(--secondary); box-shadow: 0 10px 30px rgba(118, 75, 162, 0.1); }
        
        .badge { position: absolute; top: -12px; right: 20px; background: var(--secondary); color: white; padding: 4px 12px; border-radius: 50px; font-size: 11px; font-weight: 700; }
        .audio-card h3 { font-size: 22px; font-weight: 800; margin-bottom: 8px; }
        .audio-card p { font-size: 15px; color: #666; margin-bottom: 20px; }

        audio { width: 100%; height: 45px; }

        /* CTA Section */
        .cta-footer { background: white; padding: 40px; border-radius: 24px; text-align: center; margin-bottom: 60px; }
        .btn-main { display: inline-block; background: var(--primary); color: white; padding: 15px 40px; border-radius: 12px; text-decoration: none; font-weight: 700; transition: 0.3s; }
        .btn-main:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4); }

        /* Gate Form */
        .gate-card { max-width: 500px; margin: 60px auto; background: white; padding: 40px; border-radius: 24px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .form-input { width: 100%; padding: 15px; margin: 10px 0; border: 2px solid #eee; border-radius: 10px; }

        @media (max-width: 850px) { .main-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="container nav-content">
        <?php echo logo('nav', 'index.php'); ?>
        <a href="index.php" style="text-decoration:none; color:var(--primary); font-weight:700;">← Back</a>
    </div>
</nav>

<?php if (!$user_logged_in): ?>
    <section class="container">
        <div class="gate-card">
            <h2>Unlock Your Session</h2>
            <p>Tell us where to save your progress.</p>
            <form method="POST">
                <input type="text" name="user_name" class="form-input" placeholder="Your Name" required>
                <input type="email" name="user_email" class="form-input" placeholder="Your Email" required>
                <button type="submit" class="btn-main" style="width:100%; border:none; margin-top:10px;">Get Instant Access</button>
            </form>
        </div>
    </section>
<?php else: ?>
    <header class="header-hero">
        <div class="container">
            <div class="trigger-icon-large"><?php echo $current['icon']; ?></div>
            <h1 class="trigger-title"><?php echo $current['title']; ?></h1>
            <p style="opacity:0.9;">Welcome back, <?php echo htmlspecialchars($_SESSION['audio_user_name']); ?>. Let's clear this stress.</p>
        </div>
    </header>

    <main class="container">
        <div class="main-grid">
            <aside class="info-card">
                <span class="section-label">Common Symptoms</span>
                <ul class="symptoms-list">
                    <?php foreach($current['symptoms'] as $s): ?>
                        <li><?php echo $s; ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="section-label">How this helps</span>
                <p style="font-size:15px;"><?php echo $current['audio_helps']; ?></p>
            </aside>

            <section class="audio-container">
                <div class="audio-card quick">
                    <span class="badge" style="background:#4a5568;">Fast Track</span>
                    <h3>2-Minute Pattern Interrupt</h3>
                    <p>Too busy to talk? Use this 120-second breathing "reset" to stop the physical spiral of <?php echo $current['title']; ?> immediately.</p>
                    <audio controls>
                        <source src="web_audios/<?php echo $audio_prefix; ?>quick_<?php echo $trigger; ?>.mp3" type="audio/mpeg">
                    </audio>
                </div>

                <div class="audio-card deep">
                    <span class="badge">Most Effective</span>
                    <h3>20-Minute Deep Transformation</h3>
                    <p>The clinical approach. This session uses advanced hypnotherapy to target the <b>source</b> of your triggers. Best for long-term relief.</p>
                    <audio controls id="mainAudio">
                        <source src="<?php echo $current['audio_file']; ?>" type="audio/mpeg">
                    </audio>
                    <p style="font-size:12px; color:#999; margin-top:10px;">💡 Recommended: Use headphones in a quiet space.</p>
                </div>
            </section>
        </div>

        <div class="cta-footer">
            <h3>Need a deeper, personalized session?</h3>
            <p style="margin-bottom:20px; color:#666;">Our interactive AI chatbot can build a custom session specifically for your unique situation.</p>
            <a href="chatbot_landing.php" class="btn-main">Start Deep Intake Form</a>
        </div>
    </main>
<?php endif; ?>

<footer style="padding:40px 0; text-align:center; color:#999; border-top:1px solid #eee;">
    <p>© 2026 StressReleasor | Personal Transformation System</p>
</footer>

<script>
    // Minimal tracking script
    const audio = document.getElementById('mainAudio');
    if(audio) {
        audio.onplay = () => { console.log('Deep session started'); };
    }
</script>
</body>
</html>