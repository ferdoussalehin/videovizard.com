<?php
// check_ffmpeg.php — standalone system ffmpeg checker

$results = [];

// ── 1. which / where ─────────────────────────────────────────────
$which = shell_exec('which ffmpeg 2>&1');
$results['which_ffmpeg'] = trim($which ?: '— not found —');

// ── 2. version string ─────────────────────────────────────────────
$version = shell_exec('ffmpeg -version 2>&1');
$results['ffmpeg_version'] = $version ? trim(strtok($version, "\n")) : '— could not run —';

// ── 3. full path via exec + return code ───────────────────────────
exec('ffmpeg -version', $out, $rc);
$results['return_code'] = $rc;   // 0 = success, non-zero = not found / error
$results['available']   = ($rc === 0);

// ── 4. common install paths check ────────────────────────────────
$paths = [
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/opt/homebrew/bin/ffmpeg',
    '/usr/local/ffmpeg/bin/ffmpeg',
];
$found_paths = [];
foreach ($paths as $p) {
    if (file_exists($p) && is_executable($p)) $found_paths[] = $p;
}
$results['found_at_paths'] = $found_paths ?: ['none of the common paths'];

// ── 5. PHP exec functions enabled? ───────────────────────────────
$disabled = ini_get('disable_functions');
$results['exec_disabled_functions'] = $disabled ?: '(none)';
$results['exec_available']   = function_exists('exec');
$results['shell_exec_available'] = function_exists('shell_exec');

// ── 6. ffprobe (companion tool) ───────────────────────────────────
$ffprobe = shell_exec('ffprobe -version 2>&1');
$results['ffprobe'] = $ffprobe ? trim(strtok($ffprobe, "\n")) : '— not found —';

// ── Output ────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FFmpeg System Check</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 30px; }
h1  { font-size: 18px; font-weight: 700; color: #5fc3ff; margin-bottom: 20px; }
.card { background: #1e293b; border-radius: 10px; padding: 20px; max-width: 700px; }
.row { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid #334155; align-items: flex-start; }
.row:last-child { border-bottom: none; }
.lbl { color: #94a3b8; font-size: 12px; min-width: 220px; flex-shrink: 0; padding-top: 2px; }
.val { font-size: 12px; word-break: break-all; }
.ok  { color: #4ade80; font-weight: 700; }
.err { color: #f87171; font-weight: 700; }
.dim { color: #64748b; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.badge.ok  { background: #14532d; color: #4ade80; }
.badge.err { background: #450a0a; color: #f87171; }
h2 { font-size: 13px; color: #94a3b8; margin: 24px 0 10px; text-transform: uppercase; letter-spacing: .08em; }
</style>
</head>
<body>
<h1>🎬 FFmpeg System Check</h1>

<div class="card">

    <div class="row">
        <span class="lbl">FFmpeg Available</span>
        <span class="val">
            <?php if ($results['available']): ?>
                <span class="badge ok">✓ YES — ffmpeg is installed</span>
            <?php else: ?>
                <span class="badge err">✗ NO — ffmpeg not found</span>
            <?php endif; ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">which ffmpeg</span>
        <span class="val <?= $results['which_ffmpeg'] === '— not found —' ? 'err' : 'ok' ?>">
            <?= htmlspecialchars($results['which_ffmpeg']) ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">Version</span>
        <span class="val <?= str_contains($results['ffmpeg_version'], 'ffmpeg') ? 'ok' : 'err' ?>">
            <?= htmlspecialchars($results['ffmpeg_version']) ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">exec() return code</span>
        <span class="val <?= $results['return_code'] === 0 ? 'ok' : 'err' ?>">
            <?= $results['return_code'] ?> <?= $results['return_code'] === 0 ? '(success)' : '(error / not found)' ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">Common paths checked</span>
        <span class="val">
            <?php foreach ($paths as $p): ?>
                <div>
                    <?php if (file_exists($p) && is_executable($p)): ?>
                        <span class="ok">✓ <?= $p ?></span>
                    <?php else: ?>
                        <span class="dim">✗ <?= $p ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">ffprobe</span>
        <span class="val <?= str_contains($results['ffprobe'], 'ffprobe') ? 'ok' : 'err' ?>">
            <?= htmlspecialchars($results['ffprobe']) ?>
        </span>
    </div>

    <h2>PHP Environment</h2>

    <div class="row">
        <span class="lbl">exec() available</span>
        <span class="val <?= $results['exec_available'] ? 'ok' : 'err' ?>">
            <?= $results['exec_available'] ? '✓ yes' : '✗ no — exec() is disabled' ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">shell_exec() available</span>
        <span class="val <?= $results['shell_exec_available'] ? 'ok' : 'err' ?>">
            <?= $results['shell_exec_available'] ? '✓ yes' : '✗ no — shell_exec() is disabled' ?>
        </span>
    </div>

    <div class="row">
        <span class="lbl">disabled_functions</span>
        <span class="val dim"><?= htmlspecialchars($results['exec_disabled_functions']) ?></span>
    </div>

    <div class="row">
        <span class="lbl">PHP version</span>
        <span class="val"><?= phpversion() ?></span>
    </div>

    <div class="row">
        <span class="lbl">Server OS</span>
        <span class="val"><?= php_uname() ?></span>
    </div>

</div>

<?php if (!$results['available']): ?>
<div style="margin-top:20px;background:#450a0a;border-radius:10px;padding:16px;max-width:700px;font-size:12px;line-height:1.8;color:#fca5a5;">
    <strong>FFmpeg not found.</strong> To install on common systems:<br>
    <strong>Ubuntu/Debian:</strong> <code>sudo apt install ffmpeg</code><br>
    <strong>CentOS/RHEL:</strong> <code>sudo yum install ffmpeg</code> or <code>sudo dnf install ffmpeg</code><br>
    <strong>cPanel shared hosting:</strong> ffmpeg is usually not available — contact your host or upgrade to a VPS.<br>
    <strong>Mac (Homebrew):</strong> <code>brew install ffmpeg</code>
</div>
<?php endif; ?>

</body>
</html>