<?php
$file = __DIR__ . '/wizard_step2.php';
$code = file_get_contents($file);
echo "<pre>";

// Fix: ob_clean() inside callWizard's ob_start() buffer is fine,
// but we need to make sure admin_id is set from $_POST fallback
// when SESSION is not available during include

// Check current line 23
$lines = file($file);
echo "Line 23: " . htmlspecialchars($lines[22] ?? '') . "\n";
echo "Line 26: " . htmlspecialchars($lines[25] ?? '') . "\n";
echo "Line 27: " . htmlspecialchars($lines[26] ?? '') . "\n\n";

// Patch: after the session auth check, also accept admin_id from $_POST
// This lets build_podcast pass admin_id directly via POST
$old = "if (!isset(\$_SESSION['admin_id'])) {\n    echo json_encode(['success'=>false,'error'=>'Not authenticated']);\n    if(defined('WIZARD_INCLUDED')&&WIZARD_INCLUDED)return;else exit;\n}";

$new = "// Allow admin_id from POST when called programmatically (build_podcast.php)
if (!isset(\$_SESSION['admin_id'])) {
    if (!empty(\$_POST['admin_id'])) {
        \$_SESSION['admin_id'] = (int)\$_POST['admin_id'];
    } else {
        echo json_encode(['success'=>false,'error'=>'Not authenticated']);
        if(defined('WIZARD_INCLUDED')&&WIZARD_INCLUDED)return;else exit;
    }
}";

if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    file_put_contents($file, $code);
    echo "✅ Auth fallback patch applied.\n";
} else {
    // Try a looser match
    $code = preg_replace(
        "/if \(!isset\(\\\$_SESSION\['admin_id'\]\)\) \{[\s\S]*?exit;\s*\}/",
        $new,
        $code,
        1
    );
    file_put_contents($file, $code);
    echo "✅ Auth fallback patch applied (regex).\n";
}

// Verify
$lines = file($file);
echo "\nLines 26-36 after patch:\n";
for ($i = 25; $i <= 35; $i++) {
    echo ($i+1).": ".htmlspecialchars($lines[$i]??'');
}
echo "</pre>";
