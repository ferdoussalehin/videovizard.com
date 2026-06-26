<?php
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
set_time_limit(120);

require 'config.php';
require 'dbconnect_hdb.php';

// ── Auto-create synonym table if not exists ───────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_nl_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(100) NOT NULL,
    type ENUM('subject','verb') NOT NULL DEFAULT 'subject',
    synonyms TEXT NOT NULL DEFAULT '[]',
    hit_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_term_type (term, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helper: get cached synonyms from hdb_nl_subjects ─────────
function getCachedSynonyms($conn, $term, $type) {
    $term = mysqli_real_escape_string($conn, strtolower(trim($term)));
    $type = mysqli_real_escape_string($conn, $type);
    $r = mysqli_query($conn, "SELECT synonyms FROM hdb_nl_subjects WHERE term='$term' AND type='$type' LIMIT 1");
    if ($r && $row = mysqli_fetch_assoc($r)) {
        mysqli_query($conn, "UPDATE hdb_nl_subjects SET hit_count=hit_count+1 WHERE term='$term' AND type='$type'");
        return json_decode($row['synonyms'], true) ?: [];
    }
    return null; // null = not found, [] = found but empty
}

// ── Helper: save/update synonyms to hdb_nl_subjects ──────────
function saveSynonyms($conn, $term, $type, $synonyms) {
    $term = mysqli_real_escape_string($conn, strtolower(trim($term)));
    $type = mysqli_real_escape_string($conn, $type);
    $synjson = mysqli_real_escape_string($conn, json_encode(array_values($synonyms)));
    mysqli_query($conn, "INSERT INTO hdb_nl_subjects (term, type, synonyms) VALUES ('$term','$type','$synjson')
        ON DUPLICATE KEY UPDATE synonyms='$synjson', updated_at=NOW()");
}

// ── Helper: call OpenAI chat ──────────────────────────────────
function callOpenAI($prompt, $apiKey, $isJson = true) {
    $body = [
        'model'       => 'gpt-4o-mini',
        'max_tokens'  => 600,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($isJson) $body['response_format'] = ['type' => 'json_object'];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT    => 30,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($isJson) {
        $text = preg_replace('/^```json\s*/i', '', trim($text));
        $text = preg_replace('/```\s*$/i', '', $text);
        return json_decode(trim($text), true);
    }
    return $text;
}

// ── Helper: word-boundary check on a single string (PHP-side) ──
function termMatchesText($term, $text) {
    return preg_match('/\b' . preg_quote($term, '/') . '\b/i', $text) === 1;
}

// ── Helper: check if ALL groups co-occur in a single text string ──
// OR within each group, AND between groups.
function textMatchesGroups($text, $groups) {
    foreach ($groups as $group) {
        $groupMatched = false;
        foreach ($group as $term) {
            if ($term !== '' && termMatchesText($term, $text)) {
                $groupMatched = true;
                break;
            }
        }
        if (!$groupMatched) return false;
    }
    return true;
}

// ── Helper: search with co-occurrence enforced per field ─────────
// ai_description: all terms must appear in the same sentence block.
// ai_tags: all terms must appear within the SAME single tag string.
// A row matches if EITHER its description OR any one tag entry satisfies all groups.
function searchByGroups($conn, $groups, $label, $displayTerms) {
    $groups = array_values(array_filter($groups, function($g) {
        return !empty(array_filter(array_map('trim', $g)));
    }));
    if (empty($groups)) return ['label' => $label, 'terms' => $displayTerms, 'rows' => [], 'count' => 0];

    // MySQL pre-filter: fetch candidates where first group appears anywhere
    $firstTerms = array_filter(array_map('trim', $groups[0]));
    $candidateClauses = [];
    foreach ($firstTerms as $t) {
        $t = mysqli_real_escape_string($conn, $t);
        if ($t === '') continue;
        $pat = "[[:<:]]" . $t . "[[:>:]]";
        $candidateClauses[] = "ai_description REGEXP '$pat'";
        $candidateClauses[] = "ai_tags REGEXP '$pat'";
    }
    if (empty($candidateClauses)) return ['label' => $label, 'terms' => $displayTerms, 'rows' => [], 'count' => 0];

    $sql = "SELECT id, image_name, media_type, ai_description, ai_tags, natural_language_tags
            FROM hdb_image_data
            WHERE (" . implode(' OR ', $candidateClauses) . ")
            ORDER BY id ASC
            LIMIT 2000";

    $r = mysqli_query($conn, $sql);
    if (!$r) return ['label' => $label, 'terms' => $displayTerms, 'rows' => [], 'count' => 0];

    $rows = [];
    while ($candidate = mysqli_fetch_assoc($r)) {
        $matched = false;

        // Check ai_description — all groups must co-occur in this one string
        $desc = $candidate['ai_description'] ?? '';
        if ($desc !== '' && textMatchesGroups($desc, $groups)) {
            $matched = true;
        }

        // Check ai_tags — all groups must co-occur in a SINGLE tag entry
        if (!$matched && !empty($candidate['ai_tags'])) {
            $tagArr = json_decode($candidate['ai_tags'], true);
            if (is_array($tagArr)) {
                foreach ($tagArr as $tag) {
                    if (textMatchesGroups($tag, $groups)) {
                        $matched = true;
                        break;
                    }
                }
            }
        }

        if ($matched) {
            $rows[] = $candidate;
            if (count($rows) >= 200) break;
        }
    }

    return ['label' => $label, 'terms' => $displayTerms, 'rows' => $rows, 'count' => count($rows)];
}

// ── Helper: primary-only count ────────────────────────────────
function countPrimaryOnly($conn, $psTerms) {
    if (empty($psTerms)) return 0;
    $clauses = [];
    foreach ($psTerms as $t) {
        $t = mysqli_real_escape_string($conn, trim($t));
        if ($t === '') continue;
        $pat = "[[:<:]]" . $t . "[[:>:]]";
        $clauses[] = "ai_description REGEXP '$pat'";
        $clauses[] = "ai_tags REGEXP '$pat'";
    }
    if (empty($clauses)) return 0;
    $r = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_image_data WHERE (" . implode(' OR ', $clauses) . ")");
    if (!$r) return 0;
    $row = mysqli_fetch_assoc($r);
    return (int)($row['cnt'] ?? 0);
}

// ── Load videos ───────────────────────────────────────────────
$videos = [];
// Auto-detect title column in hdb_podcasts
$titleCol = 'id';
$titleCheck = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts LIKE 'title'");
if ($titleCheck && mysqli_num_rows($titleCheck) > 0) {
    $titleCol = 'title';
} else {
    $titleCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts LIKE 'podcast_title'");
    if ($titleCheck2 && mysqli_num_rows($titleCheck2) > 0) $titleCol = 'podcast_title';
}
$vr = mysqli_query($conn, "SELECT id, $titleCol as title FROM hdb_podcasts ORDER BY id DESC LIMIT 100");
if ($vr) while ($row = mysqli_fetch_assoc($vr)) $videos[] = $row;

// ── AJAX: load scenes for a video ────────────────────────────
// ── AJAX: debug scene columns ────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'debug_scenes') {
    header('Content-Type: application/json');
    $vid = (int)($_GET['video_id'] ?? 0);
    $out = [];

    // Show columns of hdb_podcast_stories
    $cols = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories");
    $out['columns'] = [];
    if ($cols) while ($c = mysqli_fetch_assoc($cols)) $out['columns'][] = $c['Field'];

    // Count total rows
    $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_podcast_stories"));
    $out['total_rows'] = $cnt['cnt'];

    // Sample row with this video id on common column names
    foreach (['podcast_id','video_id','parent_id'] as $col) {
        $chk = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_podcast_stories WHERE $col=$vid");
        if ($chk) {
            $row = mysqli_fetch_assoc($chk);
            $out['count_by_'.$col] = $row['cnt'];
        } else {
            $out['count_by_'.$col] = 'column_not_exist';
        }
    }

    // First 3 rows raw
    $sample = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories LIMIT 3");
    $out['sample_rows'] = [];
    if ($sample) while ($r = mysqli_fetch_assoc($sample)) {
        // Only show non-blob columns
        foreach ($r as $k => $v) if (strlen($v) > 200) $r[$k] = substr($v,0,80).'…';
        $out['sample_rows'][] = $r;
    }

    echo json_encode($out, JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'scenes') {
    header('Content-Type: application/json');
    $vid = (int)($_GET['video_id'] ?? 0);
    $scenes = [];
    if ($vid) {
        $sr = mysqli_query($conn, "SELECT id, scene_order, natural_language_tags, text_contents, prompt
            FROM hdb_podcast_stories
            WHERE podcast_id=$vid AND natural_language_tags IS NOT NULL AND natural_language_tags != ''
            ORDER BY scene_order ASC");
        if ($sr) {
            while ($row = mysqli_fetch_assoc($sr)) $scenes[] = $row;
        } else {
            echo json_encode(['error' => mysqli_error($conn)]);
            exit;
        }
    }
    echo json_encode($scenes);
    exit;
}

// ── AJAX: analyze NL tags ─────────────────────────────────────
if (isset($_POST['ajax_analyze'])) {
    ob_start();
    header('Content-Type: application/json');

    $nlInput = trim($_POST['nl_input'] ?? '');
    if (!$nlInput) { echo json_encode(['error' => 'No input provided']); ob_end_flush(); exit; }

    $prompt = 'You are an AI that extracts structured visual search tags from natural language descriptions, optimized for IMAGE SEARCH.
Your task:
From the given input phrase, extract:
1. Primary subject → the VISUALLY DOMINANT entity that someone would search for in an image library (singular, lowercase root noun)
2. Primary subject synonyms → 3–6 visually relevant synonyms or close alternatives
3. Secondary subject → supporting object, place, or context (singular, lowercase)
4. Action verb → the main action performed by the PRIMARY SUBJECT (present tense, root verb)
5. Action synonyms → 5–8 relevant synonyms of the action verb (base form)

CRITICAL RULES for Primary Subject:
- The primary subject must be the MOST VISUALLY INTERESTING / SEARCHABLE entity — what you would type first into Google Images
- NEVER choose a surface, container, or location as primary subject if it only holds or displays the real subject
- Surfaces/containers to NEVER use as primary: table, floor, wall, counter, shelf, plate, bowl, tray, room, background, surface, desk, board
- If the sentence says "X on/in/at [surface]" → the primary subject is X, the surface is secondary
- "table filled with desi dishes" → primary = "dish" (the food), secondary = "table"
- "shoes on the shelf" → primary = "shoe", secondary = "shelf"
- "books on the desk" → primary = "book", secondary = "desk"
- The action MUST be performed by the primary subject
- Normalize nouns to singular (e.g., "dishes" → "dish", "dentures" → "denture")
- PRESERVE compound role/identity words exactly — do NOT strip qualifiers: "businessman" stays "businessman", "sportswoman" stays "sportswoman", "policeman" stays "policeman", "firefighter" stays "firefighter", "schoolgirl" stays "schoolgirl"
- Only reduce to bare noun (e.g., "man", "woman") if there is NO qualifier in the input
- Use simple, visual, concrete words only
- Synonyms must be usable for image search (avoid abstract terms)
- Avoid overly generic synonyms (e.g., "object", "thing", "do")
- If no action exists → return "none" and []
- If no secondary subject exists → return "none"
- If no good synonyms exist → return []

Output format (strict JSON):
{
  "primary_subject": "",
  "primary_subject_synonyms": [],
  "secondary_subject": "",
  "action": "",
  "action_synonyms": []
}
Examples:
Input: "table filled with desi dishes"
Output:
{
  "primary_subject": "dish",
  "primary_subject_synonyms": ["food", "meal", "cuisine", "desi_food", "indian_food"],
  "secondary_subject": "table",
  "action": "none",
  "action_synonyms": []
}
Input: "Dentures on the table"
Output:
{
  "primary_subject": "denture",
  "primary_subject_synonyms": ["false_teeth", "prosthetic_teeth", "dental_plate"],
  "secondary_subject": "table",
  "action": "none",
  "action_synonyms": []
}
Input: "A man walking in the rain"
Output:
{
  "primary_subject": "man",
  "primary_subject_synonyms": ["person", "male", "adult"],
  "secondary_subject": "rain",
  "action": "walk",
  "action_synonyms": ["stroll", "stride", "wander", "pace"]
}
Input: "Kids playing football in park"
Output:
{
  "primary_subject": "kid",
  "primary_subject_synonyms": ["child", "boy", "girl"],
  "secondary_subject": "park",
  "action": "play",
  "action_synonyms": ["run", "kick", "compete", "engage"]
}
Now process this input:
"' . addslashes($nlInput) . '"';

    $parsed = callOpenAI($prompt, $apiKey, true);
    if (!$parsed) { echo json_encode(['error' => 'OpenAI call failed']); ob_end_flush(); exit; }

    $ps  = strtolower(trim($parsed['primary_subject'] ?? ''));
    $ss  = strtolower(trim($parsed['secondary_subject'] ?? 'none'));
    $act = strtolower(trim($parsed['action'] ?? 'none'));
    $psSyns  = array_map('strtolower', array_filter($parsed['primary_subject_synonyms'] ?? []));
    $actSyns = array_map('strtolower', array_filter($parsed['action_synonyms'] ?? []));

    // ── Specificity guard: if AI reduced a compound role to a bare noun,
    // restore the more specific term from the original input.
    $bareNouns = ['man','woman','person','people','boy','girl','child','kid','individual','figure','human'];
    if (in_array($ps, $bareNouns)) {
        // Extract all words from input, find compound role words that contain the bare noun
        $inputWords = preg_split('/[\s\|\,]+/', strtolower($nlInput));
        $rolePatterns = ['man','woman','person','boy','girl','child','worker','maker',
                         'owner','keeper','holder','leader','player','runner','rider',
                         'fighter','teacher','doctor','officer','manager','director'];
        foreach ($inputWords as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (strlen($word) > strlen($ps) && strpos($word, $ps) !== false) {
                // e.g. "businessman" contains "man" — use the full word as primary
                $ps = $word;
                $psSyns = []; // reset synonyms so they get regenerated for specific term
                break;
            }
            // Also catch compound words where bare noun is a suffix component
            foreach ($rolePatterns as $rp) {
                if ($word !== $ps && (str_ends_with($word, $rp) || str_ends_with($word, 'man') || str_ends_with($word, 'woman')) && strlen($word) > 4) {
                    if (strpos($word, $ps) !== false) {
                        $ps = $word;
                        $psSyns = [];
                        break 2;
                    }
                }
            }
        }
    }

    // ── Safety swap: if AI still returned a surface/container as primary,
    // swap with secondary so we always search for the visually meaningful subject.
    $surfaceWords = ['table','floor','wall','counter','shelf','plate','bowl','tray',
                     'room','background','surface','desk','board','countertop','ground',
                     'ceiling','window','door','basket','rack','bin','box'];
    if (in_array($ps, $surfaceWords) && $ss !== 'none' && $ss !== '') {
        // swap primary <-> secondary (synonyms follow primary)
        [$ps, $ss] = [$ss, $ps];
        $psSyns = []; // reset — old synonyms were for the surface, not the real subject
    }

    // Check cache first, then save if new
    if ($ps && $ps !== 'none') {
        $cached = getCachedSynonyms($conn, $ps, 'subject');
        if ($cached === null) {
            saveSynonyms($conn, $ps, 'subject', $psSyns);
        } else if (!empty($cached) && empty($psSyns)) {
            $psSyns = $cached; // use cached if AI returned nothing
        }
    }
    if ($act && $act !== 'none') {
        $cached = getCachedSynonyms($conn, $act, 'verb');
        if ($cached === null) {
            saveSynonyms($conn, $act, 'verb', $actSyns);
        } else if (!empty($cached) && empty($actSyns)) {
            $actSyns = $cached;
        }
    }

    // Build search term sets
    $psTerms  = array_unique(array_filter(array_merge([$ps], $psSyns)));
    $actTerms = array_unique(array_filter(array_merge([$act !== 'none' ? $act : null], $actSyns)));
    $ssTerms  = array_unique(array_filter([$ss !== 'none' ? $ss : null]));

    // ── PRIMARY SUBJECT GATE ─────────────────────────────────
    // Count rows matching primary subject only (exact word, no synonyms) — if zero, stop.
    $primaryOnlyCount = countPrimaryOnly($conn, [$ps]);
    if ($primaryOnlyCount === 0) {
        echo json_encode([
            'primary_subject'          => $ps,
            'primary_subject_synonyms' => $psSyns,
            'secondary_subject'        => $ss,
            'action'                   => $act,
            'action_synonyms'          => $actSyns,
            'not_found'                => true,
            'not_found_terms'          => $psTerms,
        ]);
        ob_end_flush();
        exit;
    }

    $t0 = microtime(true);

    // Synonyms are shown in UI only — searches use exact extracted terms only
    $psExact  = [$ps];                              // just the primary word
    $actExact = ($act !== 'none') ? [$act] : [];    // just the verb
    $ssExact  = ($ss !== 'none')  ? [$ss]  : [];    // just the secondary

    $hasVerb      = !empty($actExact);
    $hasSecondary = !empty($ssExact);

    // Search A: only if verb AND secondary both exist
    if ($hasVerb && $hasSecondary) {
        $searchA = searchByGroups($conn,
            [$psExact, $actExact, $ssExact],
            'A: Primary + Verb + Secondary',
            array_merge($psExact, $actExact, $ssExact)
        );
    } else {
        $missing = [];
        if (!$hasVerb)      $missing[] = 'verb';
        if (!$hasSecondary) $missing[] = 'secondary subject';
        $searchA = ['label' => 'A: Primary + Verb + Secondary', 'terms' => [], 'rows' => [], 'count' => 0, 'skipped' => 'No ' . implode(' or ', $missing) . ' in input'];
    }

    // Search B: only if verb exists
    if ($hasVerb) {
        $searchB = searchByGroups($conn,
            [$psExact, $actExact],
            'B: Primary + Verb',
            array_merge($psExact, $actExact)
        );
    } else {
        $searchB = ['label' => 'B: Primary + Verb', 'terms' => [], 'rows' => [], 'count' => 0, 'skipped' => 'No verb in input'];
    }

    // Search C: only if secondary exists
    if ($hasSecondary) {
        $searchC = searchByGroups($conn,
            [$psExact, $ssExact],
            'C: Primary + Secondary',
            array_merge($psExact, $ssExact)
        );
    } else {
        $searchC = ['label' => 'C: Primary + Secondary', 'terms' => [], 'rows' => [], 'count' => 0, 'skipped' => 'No secondary subject in input'];
    }

    // Stats
    $totalRow   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_image_data"));
    $withDescRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_image_data WHERE ai_description IS NOT NULL AND ai_description != ''"));

    echo json_encode([
        'primary_subject'          => $ps,
        'primary_subject_synonyms' => $psSyns,
        'secondary_subject'        => $ss,
        'action'                   => $act,
        'action_synonyms'          => $actSyns,
        'searchA'                  => $searchA,
        'searchB'                  => $searchB,
        'searchC'                  => $searchC,
        'stats' => [
            'total_assets'     => (int)($totalRow['cnt'] ?? 0),
            'with_description' => (int)($withDescRow['cnt'] ?? 0),
            'primary_only'     => $primaryOnlyCount,
            'primary_terms'    => [$ps],
            'exec_time'        => round(microtime(true) - $t0, 3),
            'countA'           => $searchA['count'],
            'countB'           => $searchB['count'],
            'countC'           => $searchC['count'],
        ],
    ]);
    ob_end_flush();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NL Tag Analyzer</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; max-width: 1400px; margin: 30px auto; padding: 0 20px; background: #f5f5f5; }
h2 { color: #333; margin-bottom: 6px; }
h3 { margin: 0 0 12px; color: #444; font-size: 15px; }

/* Boxes */
.box { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }

/* Form controls */
select { width: 100%; padding: 9px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 10px; }
textarea { width: 100%; height: 80px; font-size: 13px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; resize: vertical; }
button { background: #5c35d4; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; margin-top: 8px; }
button:hover { background: #4a28b8; }
button:disabled { background: #999; cursor: not-allowed; }
label { font-weight: bold; font-size: 13px; display: block; margin-bottom: 4px; color: #444; }

/* Variable display cards */
.var-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.var-card { background: #f3f0ff; border: 1px solid #d8d0f8; border-radius: 8px; padding: 12px 14px; }
.var-card.action-card { background: #fff7ed; border-color: #fed7aa; }
.var-card.secondary-card { background: #f0fdf4; border-color: #bbf7d0; }
.var-label { font-size: 10px; text-transform: uppercase; color: #888; margin-bottom: 4px; letter-spacing: 0.5px; }
.var-value { font-size: 18px; font-weight: bold; color: #3c2080; }
.var-card.action-card .var-value { color: #c2410c; }
.var-card.secondary-card .var-value { color: #166534; }
.syn-list { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px; }
.syn-pill { background: #ede9fc; color: #3c2080; font-size: 11px; padding: 2px 8px; border-radius: 10px; }
.syn-pill.act { background: #ffedd5; color: #c2410c; }
.syn-pill.sec { background: #dcfce7; color: #166534; }

/* Search result blocks */
.search-block { margin-bottom: 24px; }
.search-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding: 10px 14px; border-radius: 6px; }
.search-header.a { background: #ede9fc; border-left: 4px solid #5c35d4; }
.search-header.b { background: #fff7ed; border-left: 4px solid #ea580c; }
.search-header.c { background: #f0fdf4; border-left: 4px solid #16a34a; }
.search-title { font-weight: bold; font-size: 14px; flex: 1; }
.search-count { font-size: 13px; font-weight: bold; padding: 3px 12px; border-radius: 12px; }
.count-badge-a { background: #5c35d4; color: #fff; }
.count-badge-b { background: #ea580c; color: #fff; }
.count-badge-c { background: #16a34a; color: #fff; }
.terms-used { font-size: 11px; color: #666; margin-bottom: 8px; padding: 6px 10px; background: #f9f9f9; border-radius: 4px; }

/* Results table */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: #5c35d4; color: #fff; padding: 10px 12px; text-align: left; font-size: 12px; }
.th-a { background: #5c35d4; }
.th-b { background: #ea580c; }
.th-c { background: #16a34a; }
td { padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: top; }
tr:hover td { background: #fafafa; }

/* Media preview */
.preview-cell { width: 100px; }
.preview-wrap {
    width: 90px;
    height: 160px;
    background: #f0f0f0;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #ddd;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.preview-wrap img,
.preview-wrap video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: pointer;
}
.no-preview { color: #999; font-size: 10px; text-align: center; padding: 8px; }

/* Description cell */
.desc-cell { font-size: 12px; line-height: 1.5; color: #333; max-width: 500px; }
.desc-cell .highlight { background: #fef08a; border-radius: 2px; padding: 0 2px; }
.file-name { font-size: 10px; color: #999; margin-top: 4px; word-break: break-all; }
.media-badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: 600; }
.badge-image { background: #dbeafe; color: #1d4ed8; }
.badge-video { background: #ede9fe; color: #6d28d9; }

/* Tag pills */
.tag-pill { display: inline-block; background: #ede9fc; color: #3c2080; font-size: 10px; padding: 1px 6px; border-radius: 8px; margin: 1px; }

/* Loading spinner */
.spinner { display: inline-block; width: 18px; height: 18px; border: 3px solid #ddd; border-top-color: #5c35d4; border-radius: 50%; animation: spin 0.7s linear infinite; vertical-align: middle; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Lightbox */
.lightbox { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.9); z-index: 1000; justify-content: center; align-items: center; cursor: pointer; }
.lightbox.active { display: flex; }
.lightbox-content { max-width: 90vw; max-height: 90vh; position: relative; background: #000; border-radius: 8px; overflow: hidden; }
.lightbox-close { position: absolute; top: -40px; right: 0; background: rgba(0,0,0,.7); color: #fff; font-size: 28px; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-family: monospace; }
.lightbox-close:hover { background: #f44; }
.lightbox-media { max-width: 90vw; max-height: 90vh; object-fit: contain; }

/* Empty state */
.empty-state { text-align: center; padding: 24px; color: #999; font-size: 13px; }

/* Status messages */
.status-loading { color: #5c35d4; font-size: 13px; margin-top: 10px; }
.err-box { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 16px; font-size: 13px; color: #b91c1c; margin-top: 10px; }

/* Synonym table */
.syn-table th { background: #374151; }
.no-results-row td { text-align: center; color: #999; padding: 20px; }

/* Log section */
.log-section { background: #1e1e1e; color: #d4d4d4; border-radius: 8px; padding: 16px; margin-top: 20px; font-family: 'Courier New', monospace; font-size: 12px; overflow-x: auto; }
.log-section h3 { color: #4ec9b0; margin-bottom: 12px; font-size: 14px; }
.log-line { padding: 6px 0; border-bottom: 1px solid #333; font-family: monospace; }
.log-line.info { color: #9cdcfe; }
.log-line.warning { color: #ce9178; }
.log-line.error { color: #f48771; }
.log-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 14px; }
.log-stat { background: #2d2d2d; padding: 8px 12px; border-radius: 6px; }
.log-stat-label { color: #858585; font-size: 10px; text-transform: uppercase; margin-bottom: 2px; }
.log-stat-value { color: #4ec9b0; font-size: 16px; font-weight: bold; }

/* Cache indicator */
.cache-badge { font-size: 10px; padding: 2px 6px; border-radius: 8px; margin-left: 6px; }
.from-cache { background: #dcfce7; color: #166534; }
.from-ai { background: #dbeafe; color: #1d4ed8; }
</style>
</head>
<body>

<h2>🔍 NL Tag Analyzer</h2>
<p style="color:#666; font-size:13px; margin-top:0;">Select a video + scene, analyze the NL tags, and search the asset library by description.</p>

<!-- ── STEP 1: Video + Scene Picker ─────────────────────────── -->
<div class="box">
    <h3>Step 1 — Pick a Video &amp; Scene</h3>
    <label>Video</label>
    <select id="videoSelect" onchange="loadScenes()">
        <option value="">— select a video —</option>
        <?php foreach ($videos as $v): ?>
        <option value="<?= $v['id'] ?>">[ID <?= $v['id'] ?>] <?= htmlspecialchars(substr($v['title'] ?? 'Untitled', 0, 80)) ?></option>
        <?php endforeach; ?>
    </select>

    <label>Scene</label>
    <select id="sceneSelect" onchange="fillFromScene()" disabled>
        <option value="">— select a scene —</option>
    </select>

    <div id="sceneInfoBox" style="display:none; margin:10px 0;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <!-- NL Tags -->
            <div>
                <label>🏷️ NL Tags <span style="font-weight:normal; color:#888;">(editable)</span></label>
                <textarea id="nlInput" placeholder="e.g. real estate agent showing tablet|couple viewing new home|modern office meeting" style="margin-top:4px;"></textarea>
            </div>
            <!-- Image Prompt -->
            <div>
                <label>🎨 Image Prompt</label>
                <div id="sceneImagePromptText" style="margin-top:4px; padding:8px 12px; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:4px; font-size:13px; color:#78350f; line-height:1.6; min-height:80px;">—</div>
            </div>
        </div>
    </div>

    <!-- Fallback textarea when no scene selected -->
    <div id="manualInputBox">
        <label>NL Tags Input <span style="font-weight:normal; color:#888;">(type manually)</span></label>
        <textarea id="nlInputManual" placeholder="e.g. real estate agent showing tablet|couple viewing new home|modern office meeting"></textarea>
    </div>

    <button id="analyzeBtn" onclick="analyzeNL()">🔍 Analyze Tags &amp; Search Assets</button>
    <span id="loadingMsg" class="status-loading" style="display:none;"><span class="spinner"></span> Calling AI and searching database…</span>
    <div id="analyzeError" class="err-box" style="display:none;"></div>
</div>

<input type="hidden" id="storedImagePrompt" value="">

<!-- ── STEP 2: Extracted Variables ──────────────────────────── -->
<div class="box" id="variablesBox" style="display:none;">
    <h3>Step 2 — Extracted Variables</h3>
    <div id="imagePromptCard" style="display:none; margin-bottom:14px; padding:12px 14px; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:6px;">
        <div style="font-size:10px; text-transform:uppercase; color:#92400e; letter-spacing:0.5px; margin-bottom:5px;">🎨 Scene Image Prompt</div>
        <div id="imagePromptCardText" style="font-size:13px; color:#78350f; line-height:1.6;"></div>
    </div>
    <div class="var-grid">
        <div class="var-card">
            <div class="var-label">Primary Subject</div>
            <div class="var-value" id="vPrimary">—</div>
            <div class="syn-list" id="vPrimarySyns"></div>
        </div>
        <div class="var-card action-card">
            <div class="var-label">Action Verb</div>
            <div class="var-value" id="vAction">—</div>
            <div class="syn-list" id="vActionSyns"></div>
        </div>
        <div class="var-card secondary-card">
            <div class="var-label">Secondary Subject</div>
            <div class="var-value" id="vSecondary">—</div>
        </div>
    </div>
</div>

<!-- ── STEP 3: Search Results ───────────────────────────────── -->
<div id="resultsArea"></div>

<!-- ── Synonym Table ────────────────────────────────────────── -->
<div class="box">
    <h3>📚 Synonym Cache Table (hdb_nl_subjects) <button onclick="loadSynTable()" style="font-size:12px; padding:4px 12px; margin:0 0 0 10px;">↻ Refresh</button></h3>
    <div id="synTableWrap">
        <div class="empty-state">Click Refresh to load cached terms.</div>
    </div>
</div>

<!-- ── Lightbox ─────────────────────────────────────────────── -->
<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <div class="lightbox-close" onclick="closeLightbox()">×</div>
        <div id="lightboxMedia"></div>
    </div>
</div>

<script>
// ── Scene loading ─────────────────────────────────────────────
function loadScenes() {
    var vid = document.getElementById('videoSelect').value;
    var sel = document.getElementById('sceneSelect');
    sel.innerHTML = '<option value="">— loading… —</option>';
    sel.disabled = true;
    if (!vid) { sel.innerHTML = '<option value="">— select a scene —</option>'; return; }

    fetch('nl_tag_analyzer.php?ajax=scenes&video_id=' + vid)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">— select a scene —</option>';
            // Handle error response
            if (!Array.isArray(data)) {
                sel.innerHTML = '<option value="">— error: ' + (data.error || 'unknown') + ' —</option>';
                sel.disabled = false;
                return;
            }
            if (data.length === 0) {
                sel.innerHTML = '<option value="">— no scenes with NL tags found —</option>';
                sel.disabled = false;
                return;
            }
            data.forEach(s => {
                var opt = document.createElement('option');
                opt.value = JSON.stringify(s);
                opt.textContent = '[ID ' + s.id + '] Scene ' + s.scene_order + ' — ' + (s.natural_language_tags || '').substring(0, 70) + '…';
                sel.appendChild(opt);
            });
            sel.disabled = false;
        })
        .catch(err => {
            sel.innerHTML = '<option value="">— failed to load: ' + err + ' —</option>';
            sel.disabled = false;
        });
}

function fillFromScene() {
    var sel = document.getElementById('sceneSelect');
    var val = sel.value;
    if (!val) {
        document.getElementById('sceneInfoBox').style.display = 'none';
        document.getElementById('manualInputBox').style.display = 'block';
        return;
    }
    try {
        var s = JSON.parse(val);
        // Show side-by-side layout
        document.getElementById('sceneInfoBox').style.display = 'block';
        document.getElementById('manualInputBox').style.display = 'none';
        // Fill NL tags
        document.getElementById('nlInput').value = s.natural_language_tags || '';
        // Fill image prompt
        document.getElementById('sceneImagePromptText').textContent = s.prompt && s.prompt.trim() ? s.prompt : '— no image prompt —';
        // Store for variables box
        document.getElementById('storedImagePrompt').value = s.prompt || '';
    } catch(e) {}
}

// ── Analyze ───────────────────────────────────────────────────
function analyzeNL() {
    // Read from whichever input is visible
    var nlEl = document.getElementById('sceneInfoBox').style.display !== 'none'
        ? document.getElementById('nlInput')
        : document.getElementById('nlInputManual');
    var input = nlEl.value.trim();
    if (!input) { alert('Please enter or select NL tags first.'); return; }

    document.getElementById('analyzeBtn').disabled = true;
    document.getElementById('loadingMsg').style.display = 'inline';
    document.getElementById('analyzeError').style.display = 'none';
    document.getElementById('variablesBox').style.display = 'none';
    document.getElementById('resultsArea').innerHTML = '';

    var fd = new FormData();
    fd.append('ajax_analyze', '1');
    fd.append('nl_input', input);

    fetch('nl_tag_analyzer.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('analyzeBtn').disabled = false;
            document.getElementById('loadingMsg').style.display = 'none';

            if (data.error) {
                document.getElementById('analyzeError').textContent = '❌ ' + data.error;
                document.getElementById('analyzeError').style.display = 'block';
                return;
            }

            // Always show extracted variables so user can see what AI found
            renderVariables(data);

            // Primary subject gate — if not found in image data, stop here
            if (data.not_found) {
                var terms = (data.not_found_terms || []).join(', ');
                document.getElementById('resultsArea').innerHTML =
                    '<div class="box"><div style="text-align:center; padding:30px;">' +
                    '<div style="font-size:48px; margin-bottom:12px;">🚫</div>' +
                    '<div style="font-size:17px; font-weight:bold; color:#b91c1c; margin-bottom:8px;">Primary subject not found in image library</div>' +
                    '<div style="font-size:13px; color:#666;">Searched <code>ai_description</code> for: <strong>' + esc(terms) + '</strong></div>' +
                    '<div style="font-size:12px; color:#999; margin-top:8px;">No assets match the primary subject — searches B and C were skipped.</div>' +
                    '</div></div>';
                return;
            }

            renderSearchResults(data);
            renderStats(data);
        })
        .catch(err => {
            document.getElementById('analyzeBtn').disabled = false;
            document.getElementById('loadingMsg').style.display = 'none';
            document.getElementById('analyzeError').textContent = '❌ Request failed: ' + err;
            document.getElementById('analyzeError').style.display = 'block';
        });
}

// ── Render variables ──────────────────────────────────────────
function renderVariables(data) {
    // Show image prompt if we have one stored from the scene picker
    var imgPrompt = document.getElementById('storedImagePrompt').value.trim();
    var card = document.getElementById('imagePromptCard');
    if (imgPrompt) {
        document.getElementById('imagePromptCardText').textContent = imgPrompt;
        card.style.display = 'block';
    } else {
        card.style.display = 'none';
    }

    document.getElementById('vPrimary').textContent = data.primary_subject || '—';
    document.getElementById('vAction').textContent  = data.action || '—';
    document.getElementById('vSecondary').textContent = data.secondary_subject || '—';

    var pSynEl = document.getElementById('vPrimarySyns');
    pSynEl.innerHTML = '';
    (data.primary_subject_synonyms || []).forEach(s => {
        pSynEl.innerHTML += '<span class="syn-pill">' + esc(s) + '</span>';
    });

    var aSynEl = document.getElementById('vActionSyns');
    aSynEl.innerHTML = '';
    (data.action_synonyms || []).forEach(s => {
        aSynEl.innerHTML += '<span class="syn-pill act">' + esc(s) + '</span>';
    });

    document.getElementById('variablesBox').style.display = 'block';
}

// ── Render search result tables ───────────────────────────────
function renderSearchResults(data) {
    var area = document.getElementById('resultsArea');
    area.innerHTML = '';

    var searches = [
        { key: 'searchA', cls: 'a', cntCls: 'count-badge-a', thCls: 'th-a', label: '🔎 Search A — Primary + Verb + Secondary (all synonyms)' },
        { key: 'searchB', cls: 'b', cntCls: 'count-badge-b', thCls: 'th-b', label: '🔎 Search B — Primary + Verb (with synonyms)' },
        { key: 'searchC', cls: 'c', cntCls: 'count-badge-c', thCls: 'th-c', label: '🔎 Search C — Primary + Secondary (with synonyms)' },
    ];

    searches.forEach(s => {
        var res = data[s.key];
        if (!res) return;

        var html = '<div class="box search-block">';
        html += '<div class="search-header ' + s.cls + '">';
        html += '<span class="search-title">' + s.label + '</span>';

        if (res.skipped) {
            html += '<span class="search-count" style="background:#94a3b8; color:#fff;">N/A</span>';
            html += '</div>';
            html += '<div class="empty-state" style="padding:16px; color:#94a3b8; font-style:italic;">⚠️ Skipped — ' + esc(res.skipped) + '</div>';
            html += '</div>';
            area.innerHTML += html;
            return;
        }

        html += '<span class="search-count ' + s.cntCls + '">' + res.count + ' row' + (res.count !== 1 ? 's' : '') + '</span>';
        html += '</div>';

        // Terms used
        if (res.terms && res.terms.length) {
            html += '<div class="terms-used">🏷️ <strong>Terms searched in ai_description:</strong> ' + res.terms.map(t => '<code>' + esc(t) + '</code>').join(', ') + '</div>';
        }

        if (!res.rows || res.rows.length === 0) {
            html += '<div class="empty-state" style="padding:20px; text-align:center; color:#999;">— 0 matching assets found for this combination —</div>';
        } else {
            html += '<table>';
            html += '<thead><tr>';
            html += '<th class="' + s.thCls + '" style="width:40px;">#</th>';
            html += '<th class="' + s.thCls + '" style="width:110px;">Preview</th>';
            html += '<th class="' + s.thCls + '">ai_description &amp; ai_tags</th>';
            html += '<th class="' + s.thCls + '" style="width:80px;">Type</th>';
            html += '<th class="' + s.thCls + '">File</th>';
            html += '</tr></thead><tbody>';

            res.rows.forEach((row, idx) => {
                var mediaType = (row.media_type || '').toLowerCase();
                var baseUrl = mediaType === 'video'
                    ? 'https://videovizard.com/podcast_videos/'
                    : 'https://videovizard.com/podcast_images/';
                var url = baseUrl + row.image_name;
                var safeUrl = esc(url);
                var safeName = esc(row.image_name || '');
                var safeTags = esc(row.ai_description || '');

                var previewHtml;
                if (mediaType === 'video') {
                    previewHtml = '<div class="preview-wrap" data-url="' + safeUrl + '" data-type="video" data-name="' + safeName + '">'
                        + '<video src="' + safeUrl + '" muted preload="metadata" playsinline'
                        + ' onmouseenter="this.play().catch(()=>{})" onmouseleave="this.pause();this.currentTime=0"'
                        + ' onclick="event.stopPropagation();openLightbox(\'' + safeUrl + '\',\'video\',\'' + safeName + '\')"'
                        + ' onerror="this.parentElement.innerHTML=\'<div class=no-preview>🎬 missing</div>\'">'
                        + '</video></div>';
                } else {
                    previewHtml = '<div class="preview-wrap">'
                        + '<img src="' + safeUrl + '" loading="lazy"'
                        + ' onclick="openLightbox(\'' + safeUrl + '\',\'image\',\'' + safeName + '\')"'
                        + ' onerror="this.parentElement.innerHTML=\'<div class=no-preview>🖼️ missing</div>\'">'
                        + '</div>';
                }

                var badgeCls = mediaType === 'video' ? 'badge-video' : 'badge-image';
                var descHtml = highlightTerms(esc(row.ai_description || '—'), res.terms);

                // Parse ai_tags JSON array and render as pills with highlights
                var tagsHtml = '';
                if (row.ai_tags) {
                    try {
                        var tagArr = JSON.parse(row.ai_tags);
                        if (Array.isArray(tagArr) && tagArr.length) {
                            tagsHtml = '<div style="margin-top:8px; padding-top:8px; border-top:1px dashed #eee;">'
                                + '<span style="font-size:10px; color:#888; text-transform:uppercase; letter-spacing:0.5px;">AI Tags</span>'
                                + '<div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:3px;">';
                            tagArr.forEach(function(tag) {
                                tagsHtml += '<span style="background:#f1f5f9; border:1px solid #e2e8f0; color:#475569; font-size:10px; padding:2px 7px; border-radius:8px; line-height:1.6;">'
                                    + highlightTerms(esc(tag), res.terms) + '</span>';
                            });
                            tagsHtml += '</div></div>';
                        }
                    } catch(e) {
                        // ai_tags not valid JSON — skip
                    }
                }

                html += '<tr>';
                html += '<td style="color:#999; font-size:12px;">' + (idx + 1) + '</td>';
                html += '<td class="preview-cell">' + previewHtml + '</td>';
                html += '<td class="desc-cell">' + descHtml + tagsHtml + '</td>';
                html += '<td><span class="media-badge ' + badgeCls + '">' + esc(row.media_type || '—') + '</span></td>';
                html += '<td><div class="file-name">' + safeName + '</div></td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
        }
        html += '</div>';
        area.innerHTML += html;
    });
}

// ── Render stats log ─────────────────────────────────────────
function renderStats(data) {
    var st = data.stats;
    if (!st) return;
    var area = document.getElementById('resultsArea');

    var cntA = st.countA || 0;
    var cntB = st.countB || 0;
    var cntC = st.countC || 0;

    var html = '<div class="log-section">';
    html += '<h3>🔍 SEARCH DETAILS LOG</h3>';

    html += '<div class="log-stats">';
    html += '<div class="log-stat"><div class="log-stat-label">Total Assets in DB</div><div class="log-stat-value">' + (st.total_assets || 0) + '</div></div>';
    html += '<div class="log-stat"><div class="log-stat-label">Assets with ai_description</div><div class="log-stat-value">' + (st.with_description || 0) + '</div></div>';
    html += '<div class="log-stat"><div class="log-stat-label">Primary Subject Only</div><div class="log-stat-value" style="color:#9cdcfe;">' + (st.primary_only || 0) + '</div></div>';
    html += '<div class="log-stat"><div class="log-stat-label">Execution Time</div><div class="log-stat-value">' + (st.exec_time || 0) + 's</div></div>';
    html += '</div>';

    var fmtCount = function(n, skipped) {
        if (skipped) return '<span style="color:#94a3b8; font-size:13px;">N/A</span>';
        return '<span style="color:' + (n > 0 ? '#4ec9b0' : '#f48771') + ';">' + n + '</span>';
    };
    html += '<div class="log-stats">';
    html += '<div class="log-stat"><div class="log-stat-label">Primary + Verb + Secondary</div><div class="log-stat-value">' + fmtCount(cntA, data.searchA && data.searchA.skipped) + '</div></div>';
    html += '<div class="log-stat"><div class="log-stat-label">Primary + Verb</div><div class="log-stat-value">' + fmtCount(cntB, data.searchB && data.searchB.skipped) + '</div></div>';
    html += '<div class="log-stat"><div class="log-stat-label">Primary + Secondary</div><div class="log-stat-value">' + fmtCount(cntC, data.searchC && data.searchC.skipped) + '</div></div>';
    html += '<div class="log-stat"><div class="log-stat-label">Primary Terms Used</div><div class="log-stat-value" style="font-size:11px; color:#9cdcfe;">' + (st.primary_terms || []).join(', ') + '</div></div>';
    html += '</div>';

    html += '<div class="log-line info">📊 <strong>Summary:</strong> Primary subject found in <strong>' + (st.primary_only||0) + '</strong> assets. ';
    if (cntA === 0 && cntB === 0 && cntC === 0) {
        html += 'No combined searches returned rows — try broader synonyms.';
    } else {
        var found = [];
        if (cntA > 0) found.push('A: ' + cntA);
        if (cntB > 0) found.push('B: ' + cntB);
        if (cntC > 0) found.push('C: ' + cntC);
        html += 'Results found in: ' + found.join(' | ');
    }
    html += '</div>';
    html += '</div>';

    area.innerHTML += html;
}

// ── Highlight matched terms in description ────────────────────
function highlightTerms(text, terms) {
    if (!terms || !terms.length) return text;
    terms.forEach(t => {
        if (!t) return;
        var re = new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        text = text.replace(re, '<span class="highlight">$1</span>');
    });
    return text;
}

// ── Synonym table loader ──────────────────────────────────────
function loadSynTable() {
    fetch('nl_tag_analyzer.php?ajax=syn_table')
        .then(r => r.json())
        .then(rows => {
            var wrap = document.getElementById('synTableWrap');
            if (!rows.length) { wrap.innerHTML = '<div class="empty-state">No terms cached yet.</div>'; return; }

            var html = '<table class="syn-table"><thead><tr>';
            html += '<th>Term</th><th>Type</th><th>Synonyms</th><th>Hits</th><th>Added</th>';
            html += '</tr></thead><tbody>';
            rows.forEach(r => {
                var syns = [];
                try { syns = JSON.parse(r.synonyms || '[]'); } catch(e) {}
                var pillCls = r.type === 'verb' ? 'act' : '';
                html += '<tr>';
                html += '<td><strong>' + esc(r.term) + '</strong></td>';
                html += '<td><span class="media-badge ' + (r.type==='verb'?'badge-video':'badge-image') + '">' + esc(r.type) + '</span></td>';
                html += '<td>' + (syns.map(s => '<span class="syn-pill ' + pillCls + '">' + esc(s) + '</span>').join(' ') || '<span style="color:#ccc;">none</span>') + '</td>';
                html += '<td style="text-align:center;">' + (r.hit_count || 0) + '</td>';
                html += '<td style="font-size:11px; color:#999;">' + esc((r.created_at||'').substring(0,10)) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            wrap.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('synTableWrap').innerHTML = '<div class="err-box">Failed to load synonym table.</div>';
        });
}

// ── Lightbox ──────────────────────────────────────────────────
function openLightbox(url, type, name) {
    var lb = document.getElementById('lightbox');
    var mc = document.getElementById('lightboxMedia');
    mc.innerHTML = '';
    if (type === 'video') {
        var v = document.createElement('video');
        v.src = url; v.controls = true; v.autoplay = true;
        v.className = 'lightbox-media';
        v.onerror = () => mc.innerHTML = '<div style="color:#fff;padding:40px;text-align:center;">❌ Video not found</div>';
        mc.appendChild(v);
    } else {
        var img = document.createElement('img');
        img.src = url; img.className = 'lightbox-media'; img.alt = name || '';
        img.onerror = () => mc.innerHTML = '<div style="color:#fff;padding:40px;text-align:center;">🖼️ Image not found</div>';
        mc.appendChild(img);
    }
    lb.classList.add('active');
}
function closeLightbox() {
    var mc = document.getElementById('lightboxMedia');
    var v = mc.querySelector('video');
    if (v) v.pause();
    mc.innerHTML = '';
    document.getElementById('lightbox').classList.remove('active');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

// ── Escape helper ─────────────────────────────────────────────
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Auto-load syn table on page load
loadSynTable();
</script>

<?php
// ── AJAX: load synonym table ──────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'syn_table') {
    header('Content-Type: application/json');
    $rows = [];
    $r = mysqli_query($conn, "SELECT term, type, synonyms, hit_count, created_at FROM hdb_nl_subjects ORDER BY hit_count DESC, created_at DESC LIMIT 200");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode($rows);
    exit;
}
?>
</body>
</html>
