<?php
// Capture ALL output so PHP errors never corrupt JSON responses
ob_start();
ini_set('display_errors', 0);   // errors go to log, never to browser
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

include __DIR__ . "/config.php";
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? null;
if (!$apiKey) { ob_end_clean(); die("No API key found"); }

$folder = "podcast_images";

function logError($msg) {
    file_put_contents(__DIR__ . "/a_errors.log",
        "[" . date("Y-m-d H:i:s") . "] " . print_r($msg, true) . "\n",
        FILE_APPEND
    );
}

// ── analyzeImageWithVision: GPT-4o reads saved image, returns structured JSON ──
// Columns filled: ai_group, ai_subgroup, ai_description, ai_tags,
//                 ai_mood, ai_usecases, natural_language_tags
function analyzeImageWithVision($imagePath, $apiKey, $conn) {

    // Load industry list so GPT matches exactly
    $industries = [];
    $sql_ind = "SELECT id, industry_desc FROM hdb_master_industries ORDER BY industry_desc ASC";
    logError("[Vision] SQL: $sql_ind");
    $ri = mysqli_query($conn, $sql_ind);
    $ind_rows = ($ri !== false && $ri !== null) ? mysqli_num_rows($ri) : 0;
    if ($ri) while ($row = mysqli_fetch_assoc($ri)) $industries[] = $row['industry_desc'];
    logError("[Vision] Industries loaded: $ind_rows rows → " . implode(', ', array_slice($industries, 0, 5)) . (count($industries) > 5 ? '...' : ''));

    // Load niche list (including aliases) so GPT matches exactly
    $niches = [];
    $sql_nic = "SELECT niche_desc, aliases FROM hdb_master_niches ORDER BY niche_desc ASC";
    logError("[Vision] SQL: $sql_nic");
    $rn = mysqli_query($conn, $sql_nic);
    $nic_rows = ($rn !== false && $rn !== null) ? mysqli_num_rows($rn) : 0;
    if ($rn) while ($row = mysqli_fetch_assoc($rn)) {
        $niches[] = $row['niche_desc'];
        if (!empty(trim($row['aliases']))) {
            foreach (array_map('trim', explode(',', $row['aliases'])) as $a) {
                if ($a !== '') $niches[] = $a;
            }
        }
    }
    $niches = array_unique($niches);
    logError("[Vision] Niches loaded: $nic_rows DB rows → " . count($niches) . " values (inc aliases)");

    // Read image and base64-encode
    if (!file_exists($imagePath)) {
        logError("[Vision] ERROR: file not found: $imagePath");
        return null;
    }
    $b64 = base64_encode(file_get_contents($imagePath));
    logError("[Vision] Image base64 size: " . strlen($b64) . " chars | path: $imagePath");

    $ind_list = implode(', ', $industries);
    $nic_list = implode(', ', array_slice($niches, 0, 180)); // cap to avoid token overflow

    $system_prompt = "You are an expert visual content analyst for a marketing video platform.
Your job is to analyse an image and classify it for a media library.
You MUST return ONLY a valid JSON object — no markdown, no explanation, no code fences.

Available industries (match ai_group to ONE of these exactly): {$ind_list}

Available niches (match ai_subgroup to ONE of these exactly, pick the closest match): {$nic_list}

Return this EXACT JSON structure:
{
  \"ai_group\": \"exact industry name from the list above\",
  \"ai_subgroup\": \"exact niche name from the list above\",
  \"ai_description\": \"2-3 sentence factual description of what is happening in the image\",
  \"ai_tags\": \"tag1, tag2, tag3, tag4, tag5, tag6, tag7, tag8\",
  \"ai_mood\": \"one or two words describing mood/atmosphere e.g. professional, energetic, calm, clinical\",
  \"ai_usecases\": \"social media post, brand video, marketing campaign\",
  \"natural_language_tags\": \"action phrase 1|subject phrase 2|environment phrase 3|mood phrase 4|lighting phrase 5|industry phrase 6|niche phrase 7|scene context phrase 8\"
}

Rules for natural_language_tags: pipe-separated descriptive phrases (not single words), searchable and specific.
Example: professional cooking scene|chef chopping vegetables|bright kitchen prep station|cool natural daylight|action food photography|restaurant niche|culinary professional|kitchen action shot";

    $payload = [
        'model'      => 'gpt-4o',
        'max_tokens' => 700,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                [
                    'type'      => 'image_url',
                    'image_url' => ['url' => 'data:image/png;base64,' . $b64, 'detail' => 'low'],
                ],
                [
                    'type' => 'text',
                    'text' => 'Analyse this image and return the JSON classification as instructed.',
                ],
            ],
        ]],
        'system' => $system_prompt,
    ];

    logError("[Vision] Calling GPT-4o | model=gpt-4o | detail=low");
    $_t = microtime(true);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp     = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    $elapsed = round(microtime(true) - $_t, 2);
    logError("[Vision] Response: http=$http | time={$elapsed}s | curl_err='$curl_err'");

    if ($http !== 200) {
        logError("[Vision] ERROR: non-200 body=" . substr($resp, 0, 400));
        return null;
    }

    $data = json_decode($resp, true);
    $raw  = trim($data['choices'][0]['message']['content'] ?? '');
    logError("[Vision] Raw GPT output (first 600): " . substr($raw, 0, 600));

    // Strip markdown code fences if GPT wrapped output
    $raw = preg_replace('/^```json\s*/i', '', $raw);
    $raw = preg_replace('/^```\s*/i',     '', $raw);
    $raw = preg_replace('/```\s*$/i',     '', $raw);
    $raw = trim($raw);

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        logError("[Vision] ERROR: JSON parse failed. Raw: " . substr($raw, 0, 300));
        return null;
    }

    logError("[Vision] Parsed OK | ai_group='" . ($parsed['ai_group'] ?? '') . "' ai_subgroup='" . ($parsed['ai_subgroup'] ?? '') . "'");
    logError("[Vision] nl_tags='" . substr($parsed['natural_language_tags'] ?? '', 0, 200) . "'");
    logError("[Vision] ai_mood='" . ($parsed['ai_mood'] ?? '') . "' ai_tags='" . substr($parsed['ai_tags'] ?? '', 0, 100) . "'");
    return $parsed;
}

// ── lookupIndustryNicheIds: resolve ai_group/ai_subgroup to DB integer IDs ───
function lookupIndustryNicheIds($conn, $ai_group, $ai_subgroup) {
    $industry_id = null;
    $niche_id    = null;

    if (!empty($ai_group)) {
        $esc     = mysqli_real_escape_string($conn, $ai_group);
        $sql_ind = "SELECT id FROM hdb_master_industries WHERE industry_desc='$esc' LIMIT 1";
        logError("[IDLookup] SQL: $sql_ind");
        $r = mysqli_fetch_assoc(mysqli_query($conn, $sql_ind));
        $industry_id = $r ? (int)$r['id'] : null;
        logError("[IDLookup] industry_id=" . ($industry_id ?? 'NULL') . " for ai_group='$ai_group'");
    }

    if (!empty($ai_subgroup)) {
        $esc     = mysqli_real_escape_string($conn, $ai_subgroup);
        // Try exact niche_desc match first
        $sql_nic = "SELECT id FROM hdb_master_niches WHERE niche_desc='$esc' LIMIT 1";
        logError("[IDLookup] SQL: $sql_nic");
        $r = mysqli_fetch_assoc(mysqli_query($conn, $sql_nic));
        if ($r) {
            $niche_id = (int)$r['id'];
            logError("[IDLookup] niche_id=$niche_id (exact match)");
        } else {
            // Fallback: check aliases column (comma-separated)
            $sql_alias = "SELECT id FROM hdb_master_niches
                          WHERE FIND_IN_SET('$esc', REPLACE(REPLACE(aliases, ', ', ','), ' ,', ',')) > 0
                          LIMIT 1";
            logError("[IDLookup] Alias SQL: $sql_alias");
            $r2 = mysqli_fetch_assoc(mysqli_query($conn, $sql_alias));
            $niche_id = $r2 ? (int)$r2['id'] : null;
            logError("[IDLookup] niche_id=" . ($niche_id ?? 'NULL') . " (alias match)");
        }
    }

    return ['industry_id' => $industry_id, 'niche_id' => $niche_id];
}

// ── generateEmbedding: text-embedding-3-large for natural_language_tags ───────
function generateEmbedding($text, $apiKey) {
    logError("[Embedding] Generating | text_len=" . strlen($text) . " | model=text-embedding-3-large");
    $_t = microtime(true);

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'text-embedding-3-large',
            'input' => $text,
        ]),
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $elapsed = round(microtime(true) - $_t, 2);
    logError("[Embedding] http=$http | time={$elapsed}s");

    if ($http !== 200) {
        logError("[Embedding] FAILED: " . substr($resp, 0, 200));
        return null;
    }
    $d   = json_decode($resp, true);
    $emb = $d['data'][0]['embedding'] ?? null;
    logError("[Embedding] OK | dims=" . ($emb ? count($emb) : 'NULL'));
    return $emb;
}

// ── insertImageData: INSERT into hdb_image_data then UPDATE with embedding ────
function insertImageData($conn, $apiKey, $filePath, $niche, $vision, $adminId) {

    $ai_group    = trim($vision['ai_group']              ?? $niche ?? '');
    $ai_subgroup = trim($vision['ai_subgroup']           ?? $niche ?? '');
    $ai_desc     = trim($vision['ai_description']        ?? '');
    $ai_tags     = trim($vision['ai_tags']               ?? '');
    $ai_mood     = trim($vision['ai_mood']               ?? '');
    $ai_usecases = trim($vision['ai_usecases']           ?? '');
    $nl_tags     = trim($vision['natural_language_tags'] ?? '');
    $now         = date('Y-m-d H:i:s');
    $today       = date('Y-m-d');
    $file_size   = file_exists($filePath) ? (string)filesize($filePath) : '0';

    logError("[Insert] ai_group='$ai_group' | ai_subgroup='$ai_subgroup' | ai_mood='$ai_mood'");
    logError("[Insert] ai_tags='" . substr($ai_tags, 0, 150) . "'");
    logError("[Insert] nl_tags='" . substr($nl_tags, 0, 200) . "'");
    logError("[Insert] file_path='$filePath' | file_size={$file_size}b | admin_id=$adminId");

    // Resolve industry_id / niche_id
    $ids         = lookupIndustryNicheIds($conn, $ai_group, $ai_subgroup);
    $industry_id = $ids['industry_id'];
    $niche_id    = $ids['niche_id'];
    $ind_sql     = ($industry_id !== null) ? (int)$industry_id : 'NULL';
    $nic_sql     = ($niche_id    !== null) ? (int)$niche_id    : 'NULL';

    // Escape
    $e_img       = mysqli_real_escape_string($conn, $filePath);
    $e_ai_group  = mysqli_real_escape_string($conn, $ai_group);
    $e_ai_sub    = mysqli_real_escape_string($conn, $ai_subgroup);
    $e_ai_desc   = mysqli_real_escape_string($conn, $ai_desc);
    $e_ai_tags   = mysqli_real_escape_string($conn, $ai_tags);
    $e_ai_mood   = mysqli_real_escape_string($conn, $ai_mood);
    $e_ai_uc     = mysqli_real_escape_string($conn, $ai_usecases);
    $e_nl        = mysqli_real_escape_string($conn, $nl_tags);
    $e_niche     = mysqli_real_escape_string($conn, $niche);
    $e_fs        = mysqli_real_escape_string($conn, $file_size);
    $e_desc      = mysqli_real_escape_string($conn, substr($ai_desc, 0, 99));

    $sql = "INSERT INTO hdb_image_data
        (image_hashtags, niches, image_name, image_description, add_by,
         media_type, created_at, description, natural_language_tags,
         status, updated_at, media_format, media_type_format, thumbnail,
         skip_embedding, admin_id, resize_flag, file_size,
         master_industry, niche, tag_flag,
         industry_id, niche_id,
         ai_tags, tagged_at,
         ai_group, ai_subgroup, ai_mood, ai_usecases, ai_description)
        VALUES
        ('', '$e_niche', '$e_img', '$e_ai_desc', $adminId,
         'image', '$today', '$e_desc', '$e_nl',
         'active', '$now', 'png', 'image', '',
         0, $adminId, 0, '$e_fs',
         '$e_ai_group', '$e_ai_sub', 1,
         $ind_sql, $nic_sql,
         '$e_ai_tags', '$now',
         '$e_ai_group', '$e_ai_sub', '$e_ai_mood', '$e_ai_uc', '$e_ai_desc')";

    logError("[Insert] SQL: $sql");

    if (!mysqli_query($conn, $sql)) {
        logError("[Insert] FAILED: " . mysqli_error($conn));
        return null;
    }
    $new_id = (int)mysqli_insert_id($conn);
    logError("[Insert] OK | hdb_image_data id=$new_id | tag_flag=1 set");

    // Generate and store embedding immediately
    if (!empty($nl_tags)) {
        $emb = generateEmbedding($nl_tags, $apiKey);
        if ($emb) {
            $emb_json = mysqli_real_escape_string($conn, json_encode($emb));
            $sql_emb  = "UPDATE hdb_image_data
                         SET embedding='$emb_json', skip_embedding=1, updated_at='$now'
                         WHERE id=$new_id";
            logError("[Embedding] SQL: UPDATE hdb_image_data SET embedding=[" . count($emb) . " dims], skip_embedding=1 WHERE id=$new_id");
            if (mysqli_query($conn, $sql_emb)) {
                logError("[Embedding] Stored OK | id=$new_id");
            } else {
                logError("[Embedding] UPDATE FAILED: " . mysqli_error($conn));
            }
        } else {
            logError("[Embedding] Skipped — embedding generation returned null");
        }
    } else {
        logError("[Embedding] Skipped — nl_tags is empty");
    }

    return $new_id;
}

// ── Enhanced system prompt — cinematic, no yellow, live-photo feel ─────────────
function buildSystemPrompt($niche) {
    $nicheNote = '';

    // Therapy / coaching niche
    if (preg_match('/hypno|therap|counsel|anxiety|stress|depress|mental|wellness|coach|nlp|mindful/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: This is a {$niche} niche. Every prompt MUST include real people — a therapist, coach, or client. No empty rooms, no objects only, no abstract imagery.";
    }

    // Food niche
    if (preg_match('/food|restaurant|burger|pasta|kebab|cuisine|kitchen|chef/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: Food scenes MUST show action — cooking, cutting, plating, serving. No static food-only images.";
    }

    // Fitness niche
    if (preg_match('/fitness|gym|workout|training/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: Fitness scenes MUST show physical motion — lifting, running, stretching. No idle poses.";
    }

    // Beauty niche
    if (preg_match('/salon|beauty|hair|makeup|spa/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: Beauty scenes MUST show interaction — styling, applying, treating. No empty chairs or tools-only shots.";
    }

    // Medical niche
    if (preg_match('/clinic|medical|doctor|dentist|health/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: Medical scenes MUST include doctor-patient interaction. No empty rooms or equipment-only visuals.";
    }

    // Real estate niche
    if (preg_match('/real estate|property|realtor|home/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: Real estate scenes MUST include agent and client interaction — walkthrough, discussion, or handover.";
    }

    // Retail niche
    if (preg_match('/retail|shop|store|checkout|customer/i', $niche)) {
        $nicheNote .= "\nIMPORTANT: Retail scenes MUST include customer interaction — browsing, checkout, or packaging.";
    }

    return "You are a professional video art director creating photorealistic DSLR photography prompts.
Output ONLY the enhanced prompt text — no explanation, no JSON, no preamble.

STRICT COLOR & LIGHTING RULES — apply to every prompt:
- Always use cool-neutral daylight lighting (5500K–6500K), never warm/yellow/orange tones
- Always include: soft natural daylight, color accurate, vibrant true-to-life colors, no warm cast
- Always end the prompt with: Shot on Sony A7R, 35mm lens, shallow depth of field, photorealistic, no yellow cast, crisp clean tones
- Never use words like: golden, warm, cozy, amber, candlelight, incandescent, glowing

PEOPLE & APPEARANCE RULES — apply when people are in the scene:
- Target market is Canada, USA and Europe — people must look North American or Northern European
- Use descriptors: fair skin, light complexion, Caucasian, Canadian, American or British
- Hair: blonde, brown, auburn, light brown, chestnut — not black hair unless specified
- Features: defined jawline, light eyes (blue, green, grey, hazel) or brown eyes with fair skin
- Style: clean, professional, modern Western wardrobe — business casual or smart casual
- Age range: 28–55 unless scene calls for something different
- People must look engaged, calm, hopeful or professional — never distressed, crying or in pain
- NEVER generate faces that appear Southeast Asian, East Asian, South Asian, Middle Eastern or Latin American unless the niche specifically calls for it

REALISM RULES:
- This must look like a real photograph taken on location, not a studio shot and not AI-generated
- Authentic environment, natural composition, candid feel
- No perfect symmetry, no overly clean setups — real life imperfection
- Documentary-style visual storytelling
- Prefer off-center composition with negative space for camera movement
- Include foreground elements (blurred) to create depth for parallax
- Ensure clear subject-background separation
- Frame scenes mid-action, not static posing{$nicheNote}";
}
// ── GPT enhancement ────────────────────────────────────────────────────────────
function callOpenAI($prompt, $niche, $apiKey) {
    $system = buildSystemPrompt($niche);
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "model"       => "gpt-4o-mini",
            "messages"    => [
                ["role" => "system", "content" => $system],
                ["role" => "user",   "content" => "Scene text: \"{$prompt}\"\nNiche: {$niche}\n\nWrite one enhanced photorealistic DSLR photography prompt for this scene. Return only the prompt text."]
            ],
            "temperature" => 0.7,
            "max_tokens"  => 300,
        ])
    ]);
    return curl_exec($ch);
}

// ── Generate image via OpenAI ──────────────────────────────────────────────────
function generateImage($prompt, $apiKey) {
    $ch = curl_init("https://api.openai.com/v1/images/generations");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "model"  => "gpt-image-1",
            "prompt" => $prompt,
            "size"   => "1024x1536"
        ])
    ]);
    $res  = curl_exec($ch);
    $json = json_decode($res, true);
    return $json['data'][0]['b64_json'] ?? null;
}

include 'dbconnect_hdb.php';
if ($conn->connect_error) die("DB Error");

// ── AJAX: generate one job ─────────────────────────────────────────────────────
if (isset($_POST['generate']) && isset($_POST['job_id'])) {
    ob_end_clean(); // discard any buffered output (stray whitespace, warnings)
    ob_start();     // fresh buffer so we control exactly what gets sent
    header('Content-Type: application/json');
    $id = (int)$_POST['job_id'];

    $res = $conn->query("SELECT * FROM image_jobs WHERE id=$id LIMIT 1");
    if ($res->num_rows == 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Job not found']);
        exit;
    }
    $row    = $res->fetch_assoc();
    $prompt = $row['prompt'];
    $niche  = $row['niche'];

    // Enhance
    $t_enhance_start = microtime(true);
    $enhance  = json_decode(callOpenAI($prompt, $niche, $apiKey), true);
    $enhanced = $enhance['choices'][0]['message']['content'] ?? $prompt;
    $t_enhance = round(microtime(true) - $t_enhance_start, 2);

    // Generate
    $t_gen_start = microtime(true);
    $image = generateImage($enhanced, $apiKey);
    $t_gen = round(microtime(true) - $t_gen_start, 2);

    if (!$image) {
        logError("Image failed ID $id");
        $conn->query("UPDATE image_jobs SET status='failed' WHERE id=$id");
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Image generation failed']);
        exit;
    }

    // Save to podcast_images folder
    $file = $folder . "/img_" . time() . "_$id.png";
    if (!is_dir($folder)) mkdir($folder, 0755, true);
    file_put_contents($file, base64_decode($image));
    logError("[Generate] Image saved: $file | job_id=$id | niche=$niche");
    $conn->query("UPDATE image_jobs SET status='done', image_path='$file' WHERE id=$id");

    // ── Step 5: Vision analysis + hdb_image_data insert ─────────────────
    $t_vision_start = microtime(true);
    logError("[Generate] Starting vision analysis for job_id=$id file=$file");

    $admin_id_for_insert = (int)($row['admin_id'] ?? 0);
    $vision_result = analyzeImageWithVision($file, $apiKey, $conn);
    $t_vision = round(microtime(true) - $t_vision_start, 2);

    $image_data_id = null;
    if ($vision_result) {
        logError("[Generate] Vision OK in {$t_vision}s — inserting hdb_image_data row");
        $image_data_id = insertImageData($conn, $apiKey, $file, $niche, $vision_result, $admin_id_for_insert);
        logError("[Generate] hdb_image_data insert result: id=" . ($image_data_id ?? 'FAILED'));
    } else {
        logError("[Generate] Vision analysis FAILED for job_id=$id — hdb_image_data row NOT inserted");
    }

    $json_out = json_encode([
        'success'        => true,
        'file'           => $file,
        'enhanced'       => $enhanced,
        'original'       => $prompt,
        'niche'          => $niche,
        'id'             => $id,
        't_enhance'      => $t_enhance,
        't_gen'          => $t_gen,
        't_vision'       => $t_vision ?? 0,
        't_total'        => round($t_enhance + $t_gen + ($t_vision ?? 0), 2),
        'image_data_id'  => $image_data_id,
        'ai_group'       => $vision_result['ai_group']    ?? '',
        'ai_subgroup'    => $vision_result['ai_subgroup'] ?? '',
        'ai_mood'        => $vision_result['ai_mood']     ?? '',
    ]);
    ob_end_clean(); // discard anything that snuck in during vision/embedding
    echo $json_out;
    exit;
}

// ── AJAX: get next pending job — ATOMIC (safe for parallel workers) ────────────
if (isset($_POST['next_job'])) {
    ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    // Use MySQL advisory lock so parallel workers can't grab the same row
    $conn->query("SELECT GET_LOCK('next_image_job', 3)");

    $res = $conn->query(
        "SELECT id, prompt, niche FROM image_jobs
         WHERE status='pending' ORDER BY id ASC LIMIT 1 FOR UPDATE"
    );

    if (!$res || $res->num_rows == 0) {
        $conn->query("SELECT RELEASE_LOCK('next_image_job')");
        echo json_encode(['done' => true]);
        exit;
    }

    $row = $res->fetch_assoc();
    $id  = (int)$row['id'];
    $conn->query("UPDATE image_jobs SET status='processing' WHERE id=$id AND status='pending'");
    $affected = $conn->affected_rows;

    $conn->query("SELECT RELEASE_LOCK('next_image_job')");

    // If another worker beat us to it (affected_rows=0), return done so caller retries
    if ($affected === 0) {
        echo json_encode(['done' => false, 'retry' => true]);
        exit;
    }

    echo json_encode(['done' => false, 'id' => $row['id'], 'prompt' => $row['prompt'], 'niche' => $row['niche']]);
    exit;
}

// ── AJAX: queue counts ─────────────────────────────────────────────────────────
if (isset($_POST['queue_counts'])) {
    ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $pending = (int)$conn->query("SELECT COUNT(*) c FROM image_jobs WHERE status='pending'")->fetch_assoc()['c'];
    $done    = (int)$conn->query("SELECT COUNT(*) c FROM image_jobs WHERE status='done'")->fetch_assoc()['c'];
    $failed  = (int)$conn->query("SELECT COUNT(*) c FROM image_jobs WHERE status='failed'")->fetch_assoc()['c'];
    echo json_encode(compact('pending','done','failed'));
    exit;
}

// ── Page load: get counts ──────────────────────────────────────────────────────
$pendingCount = (int)$conn->query("SELECT COUNT(*) c FROM image_jobs WHERE status='pending'")->fetch_assoc()['c'];
$doneCount    = (int)$conn->query("SELECT COUNT(*) c FROM image_jobs WHERE status='done'")->fetch_assoc()['c'];
$failedCount  = (int)$conn->query("SELECT COUNT(*) c FROM image_jobs WHERE status='failed'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Batch Image Generator</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0f1923; color: #e2e8f0; min-height: 100vh; padding: 24px 16px;
}
.wrap { max-width: 980px; margin: 0 auto; }

/* ── Header ── */
.page-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 14px; margin-bottom: 24px;
    padding-bottom: 20px; border-bottom: 1px solid #1e3a5c;
}
.page-header h1 { font-size: 22px; font-weight: 800; color: #5fd1ff; }
.page-header h1 span { color: #fff; }
.queue-stats { display: flex; gap: 10px; flex-wrap: wrap; }
.stat-pill {
    padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700;
    display: flex; align-items: center; gap: 6px;
}
.stat-pill.pending { background: #1e3a5c; color: #5fd1ff; }
.stat-pill.done    { background: #0d3321; color: #34d399; }
.stat-pill.failed  { background: #3b0f0f; color: #f87171; }

/* ── Controls ── */
.controls { display: flex; gap: 12px; align-items: center; margin-bottom: 24px; flex-wrap: wrap; }
.btn {
    padding: 12px 28px; border-radius: 10px; border: none; font-size: 14px;
    font-weight: 700; cursor: pointer; transition: all .15s; letter-spacing: .02em;
    display: flex; align-items: center; gap: 8px;
}
.btn-start { background: linear-gradient(135deg,#10b981,#059669); color: #fff; }
.btn-start:hover:not(:disabled) { box-shadow: 0 4px 16px rgba(16,185,129,.4); transform: translateY(-1px); }
.btn-stop  { background: linear-gradient(135deg,#ef4444,#dc2626); color: #fff; }
.btn-stop:hover:not(:disabled)  { box-shadow: 0 4px 16px rgba(239,68,68,.4);  transform: translateY(-1px); }
.btn:disabled { opacity: .4; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
.status-badge {
    padding: 8px 18px; border-radius: 20px; font-size: 12px; font-weight: 700;
    display: flex; align-items: center; gap: 8px;
}
.status-idle    { background: #1a2a3a; color: #64748b; }
.status-running { background: #0d3321; color: #34d399; }
.status-stopped { background: #3b1a0a; color: #fb923c; }
.status-done    { background: #1a1a3a; color: #818cf8; }

/* ── Timer bar ── */
.timer-bar {
    background: #13232f; border: 1px solid #1e3a5c; border-radius: 14px;
    padding: 16px 20px; margin-bottom: 20px;
    display: flex; gap: 0; flex-wrap: wrap; align-items: stretch;
}
.timer-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 0 20px; flex: 1; min-width: 100px;
    border-right: 1px solid #1e3a5c;
}
.timer-item:first-child { padding-left: 0; }
.timer-item:last-child  { border-right: none; }
.timer-label { font-size: 10px; font-weight: 700; color: #4a6580; text-transform: uppercase; letter-spacing: .08em; }
.timer-value { font-size: 20px; font-weight: 800; font-family: monospace; font-variant-numeric: tabular-nums; color: #5fd1ff; }
.timer-value.green  { color: #34d399; }
.timer-value.yellow { color: #fbbf24; }
.timer-value.muted  { color: #94a3b8; font-size: 16px; }

/* ── Progress ── */
.progress-wrap { margin-bottom: 16px; }
.progress-track { height: 6px; background: #1e3a5c; border-radius: 6px; overflow: hidden; }
.progress-fill  { height: 100%; background: linear-gradient(90deg,#3b82f6,#5fd1ff); border-radius: 6px; transition: width .4s ease; width: 0%; }
.progress-label { font-size: 11px; color: #4a6580; margin-top: 6px; display: flex; justify-content: space-between; }

/* ── Steps ── */
.steps { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.step-pill {
    padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 700;
    border: 1.5px solid #1e3a5c; color: #4a6580; background: #0f1923; transition: all .2s;
}
.step-pill.active { border-color: #5fd1ff; color: #5fd1ff; background: #0f2a44; }
.step-pill.done   { border-color: #34d399; color: #34d399; background: #0d3321; }
.step-pill.error  { border-color: #f87171; color: #f87171; background: #3b0f0f; }

/* ── Job card ── */
.job-card {
    background: #13232f; border: 1px solid #1e3a5c; border-radius: 14px;
    padding: 20px; margin-bottom: 20px; display: none;
}
.job-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
.job-id-badge    { font-size: 11px; font-weight: 700; color: #5fd1ff;  background: #0f2a44; padding: 4px 12px; border-radius: 20px; }
.job-niche-badge { font-size: 11px; font-weight: 700; color: #a78bfa; background: #1e1040; padding: 4px 12px; border-radius: 20px; }
.prompt-box { background: #0f1923; border: 1px solid #1e3a5c; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; }
.prompt-box:last-child { margin-bottom: 0; }
.plabel { font-size: 10px; font-weight: 700; color: #4a6580; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
.ptext  { font-size: 12px; color: #94a3b8; line-height: 1.6; }
.prompt-box.enhanced .ptext { color: #e2e8f0; }

/* ── Results ── */
.results-header { font-size: 12px; font-weight: 700; color: #4a6580; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 14px; }
.results-grid   { display: grid; grid-template-columns: repeat(auto-fill,minmax(160px,1fr)); gap: 12px; margin-bottom: 24px; }
.result-card    { background: #13232f; border: 1px solid #1e3a5c; border-radius: 12px; overflow: hidden; animation: popIn .3s ease; }
@keyframes popIn { from { opacity:0; transform:scale(.95); } to { opacity:1; transform:scale(1); } }
.result-card img { width: 100%; aspect-ratio: 9/16; object-fit: cover; display: block; }
.result-card-footer { padding: 7px 10px; display: flex; justify-content: space-between; align-items: center; font-size: 10px; }
.result-card-footer .rid   { font-weight: 700; color: #5fd1ff; }
.result-card-footer .rtime { color: #34d399; }

/* ── Log ── */
.log-section { margin-top: 20px; }
.log-header  { font-size: 11px; font-weight: 700; color: #4a6580; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 8px; }
.log-wrap    { background: #0a1520; border: 1px solid #1e3a5c; border-radius: 12px; padding: 14px; max-height: 220px; overflow-y: auto; }
.log-line    { font-size: 11px; font-family: monospace; padding: 2px 0; line-height: 1.6; }
.log-line.info    { color: #7dd3fc; }
.log-line.success { color: #86efac; }
.log-line.warning { color: #fde68a; }
.log-line.error   { color: #fca5a5; }

/* ── Concurrency control ── */
.concurrency-wrap { display: flex; flex-direction: column; gap: 4px; }
.conc-label { font-size: 10px; font-weight: 700; color: #4a6580; text-transform: uppercase; letter-spacing: .08em; }
.conc-row   { display: flex; align-items: center; gap: 8px; }
.conc-btn   {
    width: 28px; height: 28px; border-radius: 8px; border: 1.5px solid #1e3a5c;
    background: #13232f; color: #5fd1ff; font-size: 16px; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all .15s; line-height: 1;
}
.conc-btn:hover { background: #1e3a5c; }
.conc-val { font-size: 18px; font-weight: 800; color: #fff; min-width: 24px; text-align: center; font-family: monospace; }

/* ── Worker cards ── */
.workers-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.worker-card {
    flex: 1; min-width: 160px; max-width: 240px;
    background: #13232f; border: 1.5px solid #1e3a5c; border-radius: 12px;
    padding: 12px 14px; transition: border-color .2s;
}
.worker-card.active  { border-color: #3b82f6; }
.worker-card.done    { border-color: #34d399; }
.worker-card.error   { border-color: #ef4444; }
.worker-card.idle    { border-color: #1e3a5c; opacity: .5; }
.worker-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.worker-name   { font-size: 11px; font-weight: 700; color: #5fd1ff; }
.worker-state  { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
.ws-idle    { background: #1a2a3a; color: #4a6580; }
.ws-fetching { background: #0f2a44; color: #5fd1ff; }
.ws-enhancing { background: #1e1040; color: #a78bfa; }
.ws-generating { background: #1a2a0a; color: #fbbf24; }
.ws-saving { background: #0d3321; color: #34d399; }
.ws-done   { background: #0d3321; color: #34d399; }
.ws-error  { background: #3b0f0f; color: #f87171; }
.worker-job  { font-size: 10px; color: #4a6580; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.worker-timer { font-size: 13px; font-weight: 800; font-family: monospace; color: #fff; }
.worker-bar  { height: 3px; background: #1e3a5c; border-radius: 3px; overflow: hidden; margin-top: 8px; }
.worker-bar-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg,#3b82f6,#5fd1ff); transition: width .3s; width: 0%; }
</style>
</head>
<body>
<div class="wrap">

    <!-- Header -->
    <div class="page-header">
        <h1>🎨 <span>Batch</span> Image Generator</h1>
        <div class="queue-stats">
            <div class="stat-pill pending">⏳ <span id="countPending"><?= $pendingCount ?></span> Pending</div>
            <div class="stat-pill done">✅ <span id="countDone"><?= $doneCount ?></span> Done</div>
            <div class="stat-pill failed">❌ <span id="countFailed"><?= $failedCount ?></span> Failed</div>
        </div>
    </div>

    <!-- Controls -->
    <div class="controls">
        <button class="btn btn-start" id="btnStart" onclick="startPool()">
            ▶ Start Processing
        </button>
        <button class="btn btn-stop" id="btnStop" onclick="stopPool()" disabled>
            ⏹ Stop
        </button>
        <div class="concurrency-wrap">
            <label class="conc-label">Parallel Workers</label>
            <div class="conc-row">
                <button class="conc-btn" onclick="changeWorkers(-1)">−</button>
                <span class="conc-val" id="concVal">2</span>
                <button class="conc-btn" onclick="changeWorkers(1)">+</button>
            </div>
        </div>
        <div class="status-badge status-idle" id="statusBadge">● Idle</div>
    </div>

    <!-- Worker status cards -->
    <div class="workers-row" id="workersRow"></div>

    <!-- Timer bar -->
    <div class="timer-bar">
        <div class="timer-item">
            <div class="timer-label">Session Time</div>
            <div class="timer-value" id="timerSession">00:00</div>
        </div>
        <div class="timer-item">
            <div class="timer-label">Enhancement</div>
            <div class="timer-value green" id="timerEnhance">—</div>
        </div>
        <div class="timer-item">
            <div class="timer-label">Generation</div>
            <div class="timer-value yellow" id="timerGen">—</div>
        </div>
        <div class="timer-item">
            <div class="timer-label">Last Job Total</div>
            <div class="timer-value" id="timerTotal">—</div>
        </div>
        <div class="timer-item">
            <div class="timer-label">Jobs Done</div>
            <div class="timer-value muted" id="timerJobsDone">0</div>
        </div>
        <div class="timer-item">
            <div class="timer-label">Avg Per Job</div>
            <div class="timer-value muted" id="timerAvg">—</div>
        </div>
    </div>

    <!-- Progress -->
    <div class="progress-wrap">
        <div class="progress-track">
            <div class="progress-fill" id="progressFill"></div>
        </div>
        <div class="progress-label">
            <span id="progressLabel">Ready to start</span>
            <span id="progressPct">0%</span>
        </div>
    </div>

    <!-- Step pills -->
    <div class="steps">
        <div class="step-pill" id="step0">1. Fetch Job</div>
        <div class="step-pill" id="step1">2. Enhance Prompt</div>
        <div class="step-pill" id="step2">3. Generate Image</div>
        <div class="step-pill" id="step3">4. Save</div>
    </div>

    <!-- Current job -->
    <div class="job-card" id="jobCard">
        <div class="job-card-header">
            <span class="job-id-badge" id="jobIdBadge">Job #—</span>
            <span class="job-niche-badge" id="jobNicheBadge">—</span>
        </div>
        <div class="prompt-box">
            <div class="plabel">Original Prompt</div>
            <div class="ptext" id="jobOrigPrompt">—</div>
        </div>
        <div class="prompt-box enhanced" id="enhancedBox" style="display:none;">
            <div class="plabel">✨ Enhanced Prompt</div>
            <div class="ptext" id="jobEnhPrompt">—</div>
        </div>
    </div>

    <!-- Results this session -->
    <div id="resultsSection" style="display:none;">
        <div class="results-header">Generated This Session</div>
        <div class="results-grid" id="resultsGrid"></div>
    </div>

    <!-- Log -->
    <div class="log-section">
        <div class="log-header">Activity Log</div>
        <div class="log-wrap" id="logWrap"></div>
    </div>

</div>
<script>
// ── State ─────────────────────────────────────────────────────────────────────
let running      = false;
let sessionStart = null;
let sessionTimer = null;
let jobsDone     = 0;
let totalJobTime = 0;
let workerCount  = 2;      // default parallel workers
const MAX_WORKERS = 5;
const MIN_WORKERS = 1;

// ── Logging ───────────────────────────────────────────────────────────────────
function log(msg, type='info') {
    const wrap = document.getElementById('logWrap');
    const d = document.createElement('div');
    d.className = 'log-line ' + type;
    d.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
    wrap.appendChild(d);
    wrap.scrollTop = wrap.scrollHeight;
}

// ── Concurrency control ───────────────────────────────────────────────────────
function changeWorkers(delta) {
    if (running) return; // don't change while running
    workerCount = Math.max(MIN_WORKERS, Math.min(MAX_WORKERS, workerCount + delta));
    document.getElementById('concVal').textContent = workerCount;
}

// ── Worker card UI ────────────────────────────────────────────────────────────
function buildWorkerCards(n) {
    const row = document.getElementById('workersRow');
    row.innerHTML = '';
    for (let i = 0; i < n; i++) {
        row.innerHTML += `
        <div class="worker-card idle" id="wcard${i}">
            <div class="worker-header">
                <span class="worker-name">Worker ${i+1}</span>
                <span class="worker-state ws-idle" id="wstate${i}">Idle</span>
            </div>
            <div class="worker-job" id="wjob${i}">—</div>
            <div class="worker-timer" id="wtimer${i}">—</div>
            <div class="worker-bar"><div class="worker-bar-fill" id="wbar${i}"></div></div>
        </div>`;
    }
}

function wUpdate(i, state, stateLabel, job, timerText, barPct, cardClass) {
    const card = document.getElementById('wcard'+i);
    if (!card) return;
    card.className = 'worker-card ' + (cardClass||'active');
    document.getElementById('wstate'+i).className = 'worker-state ws-'+state;
    document.getElementById('wstate'+i).textContent = stateLabel;
    if (job      !== null) document.getElementById('wjob'+i).textContent   = job;
    if (timerText!== null) document.getElementById('wtimer'+i).textContent = timerText;
    document.getElementById('wbar'+i).style.width = barPct + '%';
}

// ── Timers ────────────────────────────────────────────────────────────────────
function startSessionTimer() {
    sessionStart = Date.now();
    sessionTimer = setInterval(() => {
        const s = Math.floor((Date.now() - sessionStart) / 1000);
        document.getElementById('timerSession').textContent =
            String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0');
    }, 1000);
}
function stopSessionTimer() { clearInterval(sessionTimer); sessionTimer = null; }

function elapsed(startMs) { return ((Date.now() - startMs) / 1000).toFixed(1); }

// ── Update global stats ───────────────────────────────────────────────────────
function updateStats(d) {
    jobsDone++;
    totalJobTime += parseFloat(d.t_total);
    document.getElementById('timerEnhance').textContent  = d.t_enhance + 's';
    document.getElementById('timerGen').textContent      = d.t_gen + 's';
    document.getElementById('timerTotal').textContent    = d.t_total + 's';
    document.getElementById('timerJobsDone').textContent = jobsDone;
    document.getElementById('timerAvg').textContent      = (totalJobTime/jobsDone).toFixed(1) + 's';
}

async function updateCounts() {
    const fd = new FormData(); fd.append('queue_counts','1');
    const d  = await fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).catch(()=>({}));
    if (d.pending !== undefined) {
        document.getElementById('countPending').textContent = d.pending;
        document.getElementById('countDone').textContent    = d.done;
        document.getElementById('countFailed').textContent  = d.failed;
    }
}

// ── Add result card ───────────────────────────────────────────────────────────
function addResultCard(d) {
    document.getElementById('resultsSection').style.display = 'block';
    const card = document.createElement('div');
    card.className = 'result-card';
    card.innerHTML = '<img src="' + d.file + '" loading="lazy" onerror="this.style.display=\'none\'">'
        + '<div class="result-card-footer">'
        + '<span class="rid">#' + d.id + '</span>'
        + '<span class="rtime">' + d.t_total + 's</span>'
        + '</div>';
    document.getElementById('resultsGrid').prepend(card);
}

// ── Single worker loop ────────────────────────────────────────────────────────
// Each worker runs independently; they all hit next_job which is now atomic.
async function workerLoop(workerIdx) {
    log(`Worker ${workerIdx+1} started`, 'info');

    while (running) {
        const jobStart = Date.now();

        // ── Step: Fetch ──────────────────────────────────────────────────────
        wUpdate(workerIdx, 'fetching', 'Fetching…', '—', '—', 10, 'active');

        let next;
        try {
            const fd = new FormData(); fd.append('next_job','1');
            next = await fetch(location.href,{method:'POST',body:fd}).then(r=>r.json());
        } catch(e) {
            log(`W${workerIdx+1} fetch error: ${e.message}`, 'error');
            await new Promise(r => setTimeout(r, 2000));
            continue;
        }

        // Queue empty — worker exits
        if (next.done) {
            log(`W${workerIdx+1} — queue empty, stopping`, 'success');
            wUpdate(workerIdx, 'done', 'Done', 'Queue empty', '—', 100, 'done');
            break;
        }

        // Race condition retry (another worker grabbed it first)
        if (next.retry) {
            await new Promise(r => setTimeout(r, 300));
            continue;
        }

        const jobLabel = 'Job #' + next.id;
        log(`W${workerIdx+1} → ${jobLabel} | ${next.niche||'—'}`, 'info');
        wUpdate(workerIdx, 'enhancing', 'Enhancing…', jobLabel + ' · ' + (next.niche||'—'), '~', 25, 'active');

        // ── Step: Generate (enhance + generate happen server-side) ───────────
        wUpdate(workerIdx, 'generating', 'Generating…', jobLabel, elapsed(jobStart)+'s', 50, 'active');

        let d;
        try {
            const fd1 = new FormData();
            fd1.append('generate','1');
            fd1.append('job_id', next.id);
            d = await fetch(location.href,{method:'POST',body:fd1}).then(r=>r.json());
        } catch(e) {
            log(`W${workerIdx+1} ${jobLabel} error: ${e.message}`, 'error');
            wUpdate(workerIdx, 'error', 'Error', jobLabel, '—', 0, 'error');
            await new Promise(r => setTimeout(r, 2000));
            continue;
        }

        if (!d.success) {
            log(`W${workerIdx+1} ${jobLabel} failed: ${d.error||'Unknown'}`, 'error');
            wUpdate(workerIdx, 'error', 'Failed', jobLabel, '—', 0, 'error');
            await updateCounts();
            await new Promise(r => setTimeout(r, 1000));
            continue;
        }

        // ── Step: Done ───────────────────────────────────────────────────────
        wUpdate(workerIdx, 'done', 'Done ✓', jobLabel, d.t_total+'s total', 100, 'done');
        log(`W${workerIdx+1} ✅ ${jobLabel} | enhance:${d.t_enhance}s gen:${d.t_gen}s total:${d.t_total}s`, 'success');

        updateStats(d);
        addResultCard(d);
        await updateCounts();

        // Small pause before grabbing next job
        await new Promise(r => setTimeout(r, 500));
    }

    log(`Worker ${workerIdx+1} finished`, 'info');
    wUpdate(workerIdx, 'idle', 'Idle', '—', '—', 0, 'idle');
}

// ── Pool: start N workers in parallel ─────────────────────────────────────────
async function startPool() {
    if (running) return;
    running  = true;
    jobsDone = 0; totalJobTime = 0;

    document.getElementById('btnStart').disabled = true;
    document.getElementById('btnStop').disabled  = false;

    // Lock concurrency slider while running
    document.querySelectorAll('.conc-btn').forEach(b => b.disabled = true);

    setStatus('running', 'Running · ' + workerCount + ' workers');
    startSessionTimer();
    buildWorkerCards(workerCount);
    log(`▶ Starting ${workerCount} parallel worker${workerCount>1?'s':''}`, 'info');

    // Launch all workers simultaneously — Promise.all waits for ALL to finish
    const workers = [];
    for (let i = 0; i < workerCount; i++) {
        workers.push(workerLoop(i));
    }

    await Promise.all(workers);

    // All workers done (queue empty or stopped)
    if (running) stopPool(true);
}

// ── Stop pool ─────────────────────────────────────────────────────────────────
function stopPool(natural=false) {
    running = false;
    stopSessionTimer();
    document.getElementById('btnStart').disabled = false;
    document.getElementById('btnStop').disabled  = true;
    document.querySelectorAll('.conc-btn').forEach(b => b.disabled = false);
    setStatus(natural ? 'done' : 'stopped', natural ? 'Completed' : 'Stopped');
    if (!natural) log('⏹ Processing stopped by user', 'warning');
    else          log('🎉 All jobs complete!', 'success');
}

function setStatus(state, text) {
    const el = document.getElementById('statusBadge');
    el.className = 'status-badge status-' + state;
    el.textContent = '● ' + text;
}
</script>
</body>
</html>
