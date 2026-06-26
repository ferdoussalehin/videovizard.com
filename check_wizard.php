<?php
$lines = file(__DIR__ . '/wizard_step2.php');
echo "<pre>";
echo "Total lines: " . count($lines) . "\n\n";
echo "=== Lines 1-15 (functions/includes at top) ===\n";
for ($i = 0; $i < 15; $i++) echo ($i+1).": ".htmlspecialchars($lines[$i]);

echo "\n=== All function declarations ===\n";
foreach ($lines as $n => $line) {
    if (preg_match('/^\s*function\s+(\w+)/i', $line, $m)) {
        echo ($n+1).": ".htmlspecialchars(trim($line))."\n";
    }
}

echo "\n=== Action handler (switch/if on action) ===\n";
foreach ($lines as $n => $line) {
    if (stripos($line, '$action') !== false || stripos($line, "case '") !== false || stripos($line, 'create_scenes') !== false) {
        echo ($n+1).": ".htmlspecialchars(trim($line))."\n";
    }
}
echo "</pre>";
