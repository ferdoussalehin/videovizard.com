<?php
include 'config.php';

$OPENAI_API_KEY  = $apiKey;
$EMBEDDING_MODEL = 'text-embedding-3-large';

function removeTag($raw, $searchInput) {
    $tag = trim($searchInput, " \t\n\r\0\x0B|");
    $tag = trim($tag);
    if ($tag === '') return $raw;

    $parts    = explode('|', $raw);
    $filtered = array_filter($parts, function($part) use ($tag) {
        return strcasecmp(trim($part), $tag) !== 0; 
    });

    $cleaned = array_values(array_filter(array_map('trim', $filtered), fn($p) => $p !== ''));
    return implode('|', $cleaned);
}

function normalizeRaw($raw) {
    $parts = explode('|', $raw);
    $parts = array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));
    return implode('|', $parts);
}

function cleanTags($raw) {
    return normalizeRaw($raw);
}

function generateEmbedding($text, $apiKey, $model) {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'input' => $text])
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'message' => 'cURL error: ' . $err];
    $data = json_decode($response, true);
    if (!isset($data['data'][0]['embedding'])) {
        return ['success' => false, 'message' => $data['error']['message'] ?? 'Unknown API error'];
    }
    return ['success' => true, 'embedding' => $data['data'][0]['embedding']];
}

$message        = '';
$message_type   = '';
$search_tag     = trim($_GET['search_tag'] ?? $_POST['search_tag'] ?? '');
$search_results = [];
$action         = $_POST['action'] ?? '';
$process_log    = [];

// ACTION: Clean all
if ($action === 'clean_all' && $search_tag) {
    $bareTag = trim($search_tag, " \t\n\r\0\x0B|");
    $escaped = mysqli_real_escape_string($conn, $bareTag);

    $q = mysqli_query($conn,
        "SELECT id, natural_language_tags FROM hdb_image_data
         WHERE natural_language_tags LIKE '%$escaped%'
         LIMIT 50"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;

    $success_count = 0;
    $fail_count    = 0;

    foreach ($rows as $row) {
        $id     = $row['id'];
        $before = $row['natural_language_tags'];
        $after  = removeTag($before, $search_tag);
        $normalBefore = normalizeRaw($before);

        // Skip if tag wasn't actually an exact segment
        if ($after === $normalBefore) {
            $process_log[] = ['id' => $id, 'status' => 'skip', 'msg' => 'Tag not found as exact segment', 'after' => $before];
            continue;
        }
        if (empty(trim($after))) {
            $process_log[] = ['id' => $id, 'status' => 'skip', 'msg' => 'Empty after removing tag', 'after' => ''];
            continue;
        }

        $result = generateEmbedding($after, $OPENAI_API_KEY, $EMBEDDING_MODEL);
        if (!$result['success']) {
            $process_log[] = ['id' => $id, 'status' => 'error', 'msg' => 'API Error: ' . $result['message'], 'after' => $after];
            $fail_count++;
            continue;
        }

        $embeddingJson = json_encode($result['embedding']);
        $tagsEsc = mysqli_real_escape_string($conn, $after);
        $embEsc  = mysqli_real_escape_string($conn, $embeddingJson);

        mysqli_query($conn,
            "UPDATE hdb_image_data
             SET natural_language_tags = '$tagsEsc',
                 embedding = '$embEsc',
                 updated_at = '" . date('Y-m-d H:i:s') . "'
             WHERE id = $id"
        );

        $process_log[] = [
            'id'     => $id,
            'status' => 'done',
            'before' => $before,
            'after'  => $after,
            'dims'   => count($result['embedding']),
            'msg'    => 'Cleaned, embedded, saved'
        ];
        $success_count++;
    }

    $message      = "✅ Done! $success_count rows cleaned & embedded. $fail_count failed.";
    $message_type = $fail_count > 0 ? 'info' : 'success';
}

// SEARCH
if ($search_tag && $action !== 'clean_all') {
    $bareTag = trim($search_tag, " \t\n\r\0\x0B|");
    $escaped = mysqli_real_escape_string($conn, $bareTag);

    $q = mysqli_query($conn,
        "SELECT id, image_name, natural_language_tags, embedding
         FROM hdb_image_data
         WHERE natural_language_tags LIKE '%$escaped%'
         LIMIT 50"
    );
    while ($r = mysqli_fetch_assoc($q)) {
        $r['after']       = removeTag($r['natural_language_tags'], $search_tag);
        $normalBefore     = normalizeRaw($r['natural_language_tags']);
        // Only include rows where the tag is actually an exact segment match
        $r['will_change'] = ($r['after'] !== $normalBefore);
        if ($r['will_change']) {
            $search_results[] = $r;
        }
    }
}

$total     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data"))['c'];
$has_embed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data WHERE embedding IS NOT NULL AND embedding != ''"))['c'];
$no_embed  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data WHERE (embedding IS NULL OR embedding = '') AND skip_embedding=0 AND natural_language_tags IS NOT NULL AND natural_language_tags != ''"))['c'];
$exact_match_count = count($search_results);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Embedding Generator</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#f0f4ff; color:#1e293b; padding:40px 20px; font-size:16px; }
.container { max-width:1060px; margin:0 auto; }
h1 { font-size:32px; font-weight:800; color:#1e293b; margin-bottom:6px; }
.subtitle { color:#64748b; font-size:16px; margin-bottom:32px; }
.stats { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:32px; }
.stat { background:#fff; border:2px solid #e2e8f0; border-radius:16px; padding:24px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.stat-num { font-size:40px; font-weight:800; color:#3b82f6; }
.stat-num.green { color:#16a34a; }
.stat-num.red   { color:#dc2626; }
.stat-label { font-size:14px; color:#64748b; margin-top:6px; font-weight:600; }
.card { background:#fff; border:2px solid #e2e8f0; border-radius:16px; padding:28px; margin-bottom:24px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.card h2 { font-size:20px; font-weight:700; color:#1e293b; margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid #f1f5f9; }
.hint-box { background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:10px; padding:12px 16px; margin-bottom:18px; font-size:14px; color:#1e40af; line-height:1.6; }
.hint-box code { background:#dbeafe; border-radius:4px; padding:1px 6px; font-family:monospace; font-weight:700; }
.search-row { display:flex; gap:12px; align-items:center; }
.search-input { flex:1; background:#f8fafc; border:2px solid #e2e8f0; border-radius:10px; padding:12px 18px; color:#1e293b; font-size:17px; font-weight:500; font-family:monospace; }
.search-input:focus { outline:none; border-color:#3b82f6; }
.btn { padding:12px 28px; border-radius:10px; border:none; font-size:16px; font-weight:700; cursor:pointer; transition:all .2s; box-shadow:0 2px 6px rgba(0,0,0,.1); display:inline-block; text-decoration:none; }
.btn:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,.15); }
.btn-blue  { background:#3b82f6; color:#fff; }
.btn-red   { background:#dc2626; color:#fff; }
.btn-gray  { background:#e2e8f0; color:#475569; }
.clean-all-bar { background:#fffbeb; border:2px solid #fbbf24; border-radius:12px; padding:16px 20px; margin-top:20px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.clean-all-bar .info { font-size:15px; font-weight:600; color:#92400e; }
.clean-all-bar .info span { color:#dc2626; font-weight:800; }
.result-row { border:2px solid #e2e8f0; border-radius:12px; padding:18px; margin-top:16px; }
.result-row-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px; }
.row-id-badge { background:#eff6ff; color:#1d4ed8; border-radius:8px; padding:4px 12px; font-size:14px; font-weight:700; }
.badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:13px; font-weight:700; }
.badge-yes { background:#dcfce7; color:#16a34a; }
.badge-no  { background:#fee2e2; color:#dc2626; }
.compare { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.compare-box { border-radius:10px; padding:16px; border:2px solid; }
.compare-box.before { background:#fff5f5; border-color:#fca5a5; }
.compare-box.after  { background:#f0fdf4; border-color:#86efac; }
.compare-label { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; margin-bottom:10px; }
.compare-label.before { color:#dc2626; }
.compare-label.after  { color:#16a34a; }
.compare-text { font-size:14px; color:#334155; line-height:1.9; word-break:break-word; font-family:monospace; }
.removed-tag { background:#fee2e2; color:#dc2626; text-decoration:line-through; border-radius:4px; padding:1px 6px; font-weight:700; }
.pipe-sep { color:#94a3b8; }
.msg { border-radius:12px; padding:16px 20px; margin-bottom:24px; font-size:16px; font-weight:600; border:2px solid; }
.msg-success { background:#f0fdf4; border-color:#86efac; color:#15803d; }
.msg-info    { background:#fffbeb; border-color:#fbbf24; color:#92400e; }
.log-table { width:100%; border-collapse:collapse; margin-top:8px; }
.log-table th { text-align:left; font-size:13px; font-weight:700; color:#64748b; padding:8px 12px; border-bottom:2px solid #e2e8f0; text-transform:uppercase; }
.log-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; vertical-align:top; font-family:monospace; }
.log-table td:first-child { font-family:sans-serif; font-weight:700; }
.status-done  { color:#16a34a; font-weight:700; font-family:sans-serif; }
.status-error { color:#dc2626; font-weight:700; font-family:sans-serif; }
.status-skip  { color:#94a3b8; font-weight:700; font-family:sans-serif; }
.no-results { color:#94a3b8; font-size:16px; text-align:center; padding:24px; }
.footer-info { font-size:13px; color:#94a3b8; margin-top:16px; text-align:center; }
</style>
</head>
<body>
<div class="container">

<h1>🧠 Embedding Generator</h1>
<p class="subtitle">Search a tag → See only exact matches → Clean All + Regenerate Embeddings + Save</p>

<div class="stats">
    <div class="stat"><div class="stat-num"><?= $total ?></div><div class="stat-label">Total Records</div></div>
    <div class="stat"><div class="stat-num green"><?= $has_embed ?></div><div class="stat-label">✅ Has Embedding</div></div>
    <div class="stat"><div class="stat-num red"><?= $no_embed ?></div><div class="stat-label">⚠ Needs Embedding</div></div>
</div>

<?php if ($message): ?>
<div class="msg msg-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card">
    <h2>🔍 Search & Remove Tag</h2>

    <div class="hint-box">
        <strong>How to use:</strong> Type the exact tag text. Only rows where it appears as a complete segment will be shown.<br>
        Example: <code>outdoor setting</code> will NOT match <em>modern outdoor setting</em> — only an exact <em>outdoor setting</em> segment.<br>
        Results shown are <strong>only rows that will actually change</strong> — no more false matches!
    </div>

    <form method="GET">
        <div class="search-row">
            <input type="text" name="search_tag" class="search-input"
                   value="<?= htmlspecialchars($search_tag) ?>"
                   placeholder="e.g. outdoor setting   or   modern indoor setting">
            <button type="submit" class="btn btn-blue">Search</button>
            <?php if ($search_tag): ?>
            <a href="?" class="btn btn-gray">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($search_tag && empty($search_results) && $action !== 'clean_all'): ?>
        <p class="no-results" style="margin-top:20px;">No rows found where "<strong><?= htmlspecialchars($search_tag) ?></strong>" is an exact tag segment.</p>

    <?php elseif (!empty($search_results)): ?>

    <div class="clean-all-bar">
        <div class="info">
            <span><?= $exact_match_count ?></span> rows have exact tag
            "<span><?= htmlspecialchars($search_tag) ?></span>" — all will be cleaned and embeddings regenerated.
        </div>
        <form method="POST">
            <input type="hidden" name="search_tag" value="<?= htmlspecialchars($search_tag) ?>">
            <input type="hidden" name="action" value="clean_all">
            <button type="submit" class="btn btn-red"
                    onclick="return confirm('Remove tag from <?= $exact_match_count ?> rows and regenerate embeddings?\n\nThis cannot be undone.')">
                🧹 Clean <?= $exact_match_count ?> Rows + Regenerate + Save
            </button>
        </form>
    </div>

    <?php foreach ($search_results as $r): ?>
    <div class="result-row">
        <div class="result-row-header">
            <span class="row-id-badge">Row #<?= $r['id'] ?></span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:13px;color:#64748b;font-family:monospace;"><?= htmlspecialchars(substr($r['image_name'] ?? '', 0, 50)) ?></span>
                <?php if (!empty($r['embedding'])): ?>
                    <span class="badge badge-yes">✅ Will regen embedding</span>
                <?php else: ?>
                    <span class="badge badge-no">❌ Will generate new embedding</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="compare">
            <div class="compare-box before">
                <div class="compare-label before">⬅ BEFORE</div>
                <div class="compare-text"><?php
                    $bareTag  = trim($search_tag, " \t\n\r\0\x0B|");
                    $parts    = explode('|', $r['natural_language_tags']);
                    $rendered = [];
                    foreach ($parts as $p) {
                        $trimmed = trim($p);
                        if (strcasecmp($trimmed, $bareTag) === 0) {
                            $rendered[] = '<span class="removed-tag">' . htmlspecialchars($trimmed) . '</span>';
                        } else {
                            $rendered[] = htmlspecialchars($trimmed);
                        }
                    }
                    echo implode('<span class="pipe-sep">|</span>', $rendered);
                ?></div>
            </div>
            <div class="compare-box after">
                <div class="compare-label after">➡ AFTER (tag removed)</div>
                <div class="compare-text"><?= htmlspecialchars($r['after']) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($process_log)): ?>
<div class="card">
    <h2>📋 Processing Results</h2>
    <table class="log-table">
        <thead>
            <tr><th>Row ID</th><th>Status</th><th>Result</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php foreach ($process_log as $log): ?>
        <tr>
            <td>#<?= $log['id'] ?></td>
            <td class="status-<?= $log['status'] ?>">
                <?= $log['status'] === 'done' ? '✅ Saved' : ($log['status'] === 'error' ? '❌ Error' : '⏭ Skipped') ?>
            </td>
            <td><?= htmlspecialchars(substr($log['after'] ?? '', 0, 120)) ?><?= strlen($log['after'] ?? '') > 120 ? '…' : '' ?></td>
            <td style="font-family:sans-serif;color:#64748b;"><?= htmlspecialchars($log['msg']) ?><?= isset($log['dims']) ? ' (' . $log['dims'] . ' dims)' : '' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="footer-info">
    generate_embeddings.php &nbsp;·&nbsp; <?= $EMBEDDING_MODEL ?> &nbsp;·&nbsp; 3072 dimensions
</div>
</div>
</body>
</html>