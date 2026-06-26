<?php
$file = __DIR__ . '/wizard_step2.php';
$code = file_get_contents($file);

// Fix the nl_tags — replace full sentence as first tag with better descriptive phrases
$old = "            // Natural language tags for stock media search
            \$nl_tags = implode('|', [
                \$text,
                (\$niche ? \$niche . ' professional' : 'professional'),
                (!empty(\$kws[0]) ? \$kws[0] . ' lifestyle' : 'lifestyle'),
                (!empty(\$kws[1]) ? \$niche . ' ' . \$kws[1] : \$niche . ' concept'),
                'real life ' . (\$niche ?: 'business'),
            ]);";

$new = "            // Natural language tags for stock media search
            // Use short descriptive phrases (not full sentence) for better stock search
            \$nl_parts = [];
            if (\$niche)              \$nl_parts[] = strtolower(\$niche);
            if (!empty(\$kws[0]))     \$nl_parts[] = \$kws[0];
            if (!empty(\$kws[1]))     \$nl_parts[] = \$niche ? strtolower(\$niche) . ' ' . \$kws[1] : \$kws[1];
            if (!empty(\$kws[2]))     \$nl_parts[] = \$kws[0] . ' ' . \$kws[2];
            \$nl_parts[] = \$niche ? strtolower(\$niche) . ' professional' : 'professional';
            \$nl_parts[] = 'real life ' . (\$niche ?: 'business');
            \$nl_tags = implode('|', array_unique(\$nl_parts));";

if (strpos($code, $old) !== false) {
    $code = str_replace($old, $new, $code);
    file_put_contents($file, $code);
    echo "✅ nl_tags patched in wizard_step2.php\n";
} else {
    echo "⚠ Exact match not found — showing current nl_tags block:\n";
    preg_match('/Natural language tags.*?nl_tags\s*=.*?;/s', $code, $m);
    echo htmlspecialchars($m[0] ?? 'not found') . "\n";
}

echo "\nLines 386-400 after patch:\n<pre>";
$lines = file($file);
for ($i = 385; $i <= 400; $i++) {
    echo ($i+1).": ".htmlspecialchars($lines[$i]??'');
}
echo "</pre>";
