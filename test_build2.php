<?php
session_start();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
define('WIZARD_INCLUDED', true);
include __DIR__ . '/dbconnect_hdb.php';

$podcast_id = (int)($_GET['podcast_id'] ?? 13);

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE id = $podcast_id LIMIT 1"));

echo "<pre>";
echo "=== PODCAST $podcast_id ===\n";
echo "title:       " . $row['title'] . "\n";
echo "host_voice:  " . $row['host_voice'] . "\n";
echo "voice_rate:  " . $row['voice_rate'] . "\n";
echo "video_media: " . $row['video_media'] . "\n";
echo "lang_code:   " . $row['lang_code'] . "\n";
echo "script_text: " . strlen($row['script_text']??'') . " chars\n\n";

function callWizard(array $post) {
    $prev = $_POST; $_POST = $_REQUEST = $post;
    ob_start();
    include __DIR__ . '/wizard_step2.php';
    $out = ob_get_clean();
    $_POST = $_REQUEST = $prev;
    $ps = strpos($out,'{'); $pa = strpos($out,'[');
    if ($ps===false) $ps=PHP_INT_MAX; if ($pa===false) $pa=PHP_INT_MAX;
    $start = min($ps,$pa);
    if ($start!==PHP_INT_MAX && $start>0) $out = substr($out,$start);
    return $out;
}

// Step 1: create scenes
echo "=== STEP 1: create_scenes_from_podcast ===\n";
$out = callWizard([
    'action'=>'create_scenes_from_podcast','podcast_id'=>$podcast_id,
    'host_voice'=>$row['host_voice']?:'openai:alloy',
    'guest_voice'=>$row['guest_voice']?:$row['host_voice']?:'openai:alloy',
    'rate'=>$row['voice_rate']?:1.0,'lang_code'=>$row['lang_code']?:'en',
]);
$s2 = json_decode($out, true);
echo "Output: " . htmlspecialchars(substr($out,0,300)) . "\n";
echo "Decoded success: " . ($s2['success']??'null') . "\n";
echo "Scene count: " . ($s2['scene_count']??'null') . "\n\n";

if (empty($s2['success'])) { echo "STOPPED: scene creation failed\n</pre>"; exit; }

// Step 2: get scenes
echo "=== STEP 2: get_scenes ===\n";
$out = callWizard(['action'=>'get_scenes','podcast_id'=>$podcast_id]);
echo "Raw output (first 500): " . htmlspecialchars(substr($out,0,500)) . "\n";
$scenes = json_decode($out, true) ?? [];
echo "Scenes loaded: " . count($scenes) . "\n\n";

if (!count($scenes)) { echo "STOPPED: no scenes returned\n</pre>"; exit; }

// Step 3: first audio only
echo "=== STEP 3: generate_scene_audio (scene 1 only) ===\n";
$scene = $scenes[0];
echo "Scene ID: " . $scene['id'] . "\n";
echo "Text: " . substr($scene['text_contents']??'',0,100) . "\n";
echo "natural_language_tags: " . ($scene['natural_language_tags']??'EMPTY') . "\n";
echo "hashtags: " . ($scene['hashtags']??'EMPTY') . "\n\n";

$txt = trim(preg_replace('/<break[^>]*>/i','',$scene['text_contents']??''));
$out = callWizard([
    'action'=>'generate_scene_audio','scene_id'=>$scene['id'],
    'podcast_id'=>$podcast_id,'seq_no'=>1,
    'lang_code'=>$row['lang_code']?:'en',
    'voice_id'=>$row['host_voice']?:'openai:alloy',
    'rate'=>$row['voice_rate']?:1.0,'text'=>$txt,
]);
echo "Audio output: " . htmlspecialchars(substr($out,0,500)) . "\n\n";

// Step 4: search media for scene 1
echo "=== STEP 4: search_images for scene 1 ===\n";
$nl = array_filter(array_map('trim',explode('|',$scene['natural_language_tags']??'')));
$queries = count($nl) ? array_values($nl) : [explode(',',$scene['hashtags']??'')[0]??''];
echo "Search queries: " . implode(' | ', $queries) . "\n";
foreach ($queries as $q) {
    if (!trim($q)) continue;
    $out = callWizard(['action'=>'search_images','hashtags'=>$q]);
    $pa = strpos($out,'['); if ($pa!==false && $pa>0) $out = substr($out,$pa);
    $found = json_decode($out,true) ?? [];
    echo "Query '$q' → " . count($found) . " results\n";
    if (count($found)) {
        echo "First result: " . $found[0]['filename'] . " (type: ".($found[0]['type']??'?').")\n";
        break;
    }
}

echo "\n=== DONE ===\n";
echo "</pre>";
