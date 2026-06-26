<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'dbconnect_hdb.php';

$message    = '';
$msgType    = '';
$podcastTitle = '';

// SHOW: fetch title by ID
if (isset($_POST['action']) && $_POST['action'] === 'show') {
    $id = intval($_POST['podcast_id']);
    if ($id > 0) {
        $res = mysqli_query($conn, "SELECT title, niche FROM hdb_podcasts WHERE id = $id AND admin_id = 32 AND company_id = 27");
        if ($res && mysqli_num_rows($res) > 0) {
            $row = mysqli_fetch_assoc($res);
            $podcastTitle    = $row['title'];
            $currentNiche    = $row['niche'];
            $message  = 'Podcast found.';
            $msgType  = 'success';
        } else {
            $message = 'No podcast found with that ID.';
            $msgType = 'error';
        }
    } else {
        $message = 'Please enter a valid podcast ID.';
        $msgType = 'error';
    }
}

// UPDATE: set niche
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $id    = intval($_POST['podcast_id']);
    $niche = mysqli_real_escape_string($conn, trim($_POST['niche'] ?? ''));
    if ($id > 0 && $niche !== '') {
        $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET niche = '$niche' WHERE id = $id AND admin_id = 32 AND company_id = 27");
        if ($ok && mysqli_affected_rows($conn) > 0) {
            $message = 'Niche updated successfully!';
            $msgType = 'success';
            // reload title
            $res = mysqli_query($conn, "SELECT title, niche FROM hdb_podcasts WHERE id = $id AND admin_id = 32 AND company_id = 27");
            if ($res) { $row = mysqli_fetch_assoc($res); $podcastTitle = $row['title']; $currentNiche = $row['niche']; }
        } else {
            $message = 'Update failed or no changes made.';
            $msgType = 'error';
        }
    } else {
        $message = 'Please enter a valid ID and select a niche.';
        $msgType = 'error';
    }
}

$niches = [
    'finance'    => '💰 Finance',
    'realestate' => '🏡 Real Estate',
    'dental'     => '🦷 Dental',
    'beauty'     => '💆 Beauty',
    'optician'   => '👓 Optician',
    'restaurant' => '🍕 Restaurant',
    'legal'      => '⚖️ Legal',
    'fitness'    => '🏋️ Fitness',
    'home'       => '🔧 Home Services',
    'health'     => '🏥 Health',
    'education'  => '🎓 Education',
    'auto'       => '🚗 Auto',
    'travel'     => '✈️ Travel',
];

$podcastId   = intval($_POST['podcast_id'] ?? 0);
$selectedNiche = $_POST['niche'] ?? ($currentNiche ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Podcast Niche Updater</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #f5f2ee;
    --surface:   #fdfcfa;
    --border:    #e2ddd8;
    --text:      #2a2520;
    --muted:     #8a8078;
    --accent:    #c17f3e;
    --accent-dk: #a3672c;
    --success-bg:#eef7ee;
    --success-bd:#b3d9b3;
    --success-tx:#2d6a2d;
    --error-bg:  #fdf0f0;
    --error-bd:  #f0bcbc;
    --error-tx:  #8b2020;
    --radius:    12px;
    --shadow:    0 2px 16px rgba(60,40,10,0.08);
  }

  body {
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
  }

  /* subtle grain overlay */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
  }

  .card {
    position: relative;
    z-index: 1;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow);
    width: 100%;
    max-width: 480px;
    padding: 40px 36px 36px;
  }

  .card-header {
    margin-bottom: 32px;
  }

  .card-header h1 {
    font-family: 'DM Serif Display', serif;
    font-size: 26px;
    font-weight: 400;
    color: var(--text);
    line-height: 1.2;
  }

  .card-header p {
    margin-top: 6px;
    font-size: 13px;
    color: var(--muted);
    font-weight: 300;
  }

  .divider {
    height: 1px;
    background: var(--border);
    margin: 0 -36px 28px;
  }

  /* Message */
  .msg {
    border-radius: var(--radius);
    padding: 12px 16px;
    font-size: 13.5px;
    font-weight: 500;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .msg.success { background: var(--success-bg); border: 1px solid var(--success-bd); color: var(--success-tx); }
  .msg.error   { background: var(--error-bg);   border: 1px solid var(--error-bd);   color: var(--error-tx); }

  /* Section: ID lookup */
  .section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 10px;
  }

  .input-row {
    display: flex;
    gap: 10px;
    margin-bottom: 24px;
  }

  input[type="number"] {
    flex: 1;
    height: 44px;
    padding: 0 14px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    outline: none;
    transition: border-color .2s;
    -moz-appearance: textfield;
  }
  input[type="number"]::-webkit-outer-spin-button,
  input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; }
  input[type="number"]:focus { border-color: var(--accent); }

  .btn {
    height: 44px;
    padding: 0 20px;
    border: none;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s, transform .1s;
  }
  .btn:active { transform: scale(.97); }

  .btn-show {
    background: var(--bg);
    border: 1.5px solid var(--border);
    color: var(--text);
  }
  .btn-show:hover { border-color: var(--accent); color: var(--accent); }

  .btn-update {
    width: 100%;
    height: 48px;
    background: var(--accent);
    color: #fff;
    font-size: 14px;
    border-radius: var(--radius);
    margin-top: 8px;
  }
  .btn-update:hover { background: var(--accent-dk); }

  /* Podcast title display */
  .podcast-title-box {
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    margin-bottom: 24px;
    font-size: 14px;
    color: var(--text);
    line-height: 1.5;
  }
  .podcast-title-box .label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 4px;
  }
  .podcast-title-box .title-text {
    font-weight: 500;
  }

  /* Dropdown */
  .select-wrap {
    position: relative;
    margin-bottom: 8px;
  }
  .select-wrap::after {
    content: '▾';
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
    font-size: 13px;
  }
  select {
    width: 100%;
    height: 48px;
    padding: 0 40px 0 14px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    appearance: none;
    outline: none;
    cursor: pointer;
    transition: border-color .2s;
  }
  select:focus { border-color: var(--accent); }

  .footer-note {
    margin-top: 24px;
    font-size: 12px;
    color: var(--muted);
    text-align: center;
  }
</style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <h1>Podcast Niche Updater</h1>
    <p>Look up a podcast by ID, then assign it a niche category.</p>
  </div>
  <div class="divider"></div>

  <?php if ($message): ?>
  <div class="msg <?= $msgType ?>">
    <?= $msgType === 'success' ? '✓' : '✕' ?> <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- STEP 1: Look up podcast -->
  <div class="section-label">Step 1 — Enter Podcast ID</div>
  <form method="POST">
    <div class="input-row">
      <input type="number" name="podcast_id" placeholder="e.g. 1042"
             value="<?= $podcastId ?: '' ?>" required>
      <button type="submit" name="action" value="show" class="btn btn-show">Show</button>
    </div>

    <?php if ($podcastTitle): ?>
    <div class="podcast-title-box">
      <div class="label">Podcast Title</div>
      <div class="title-text"><?= htmlspecialchars($podcastTitle) ?></div>
    </div>

    <!-- STEP 2: Select niche and update -->
    <div class="section-label">Step 2 — Select Niche</div>
    <div class="select-wrap">
      <select name="niche" required>
        <option value="" disabled <?= !$selectedNiche ? 'selected' : '' ?>>Choose a niche…</option>
        <?php foreach ($niches as $val => $label): ?>
        <option value="<?= $val ?>" <?= $selectedNiche === $val ? 'selected' : '' ?>>
          <?= $label ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" name="action" value="update" class="btn btn-update">
      Update Niche
    </button>
    <?php endif; ?>

  </form>

  <div class="footer-note">admin_id 32 · company_id 27 · hdb_podcasts</div>
</div>

</body>
</html>
