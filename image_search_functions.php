<?php
// image_search_functions.php
// Two-tier media search:
//   Tier 1 — company-specific assets (company_id = current company)
//   Tier 2 — shared stock fallback (company_id = 0 OR admin_id = 0)
// Tier 2 only used when Tier 1 returns fewer than MIN_COMPANY_RESULTS good matches.

// Suppress display_errors — any PHP error must not corrupt the JSON response
@ini_set('display_errors', 0);

if (!defined('MIN_COMPANY_RESULTS')) {
    define('MIN_COMPANY_RESULTS', 3); // if Tier 1 has fewer than this, pad with Tier 2
}

if (!function_exists('cleanTagsForEmbedding')) {
    function cleanTagsForEmbedding(string $tags): string {
        $clean = str_replace('|', ', ', $tags);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return trim($clean);
    }
}

if (!function_exists('getEmbeddingForSearch')) {
    function getEmbeddingForSearch(string $text, string $apiKey): ?array {
        error_log("[image_search] getEmbeddingForSearch: text=" . substr($text, 0, 100));

        if (empty($apiKey)) {
            error_log("[image_search] ERROR: API key is empty");
            return null;
        }

        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'text-embedding-3-large',
                'input' => $text,
            ]),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[image_search] ERROR: API returned $httpCode: " . substr($response, 0, 200));
            return null;
        }

        $data      = json_decode($response, true);
        $embedding = $data['data'][0]['embedding'] ?? null;
        error_log("[image_search] embedding dims=" . ($embedding ? count($embedding) : 'null'));
        return $embedding;
    }
}

if (!function_exists('cosineSimilarityForSearch')) {
    function cosineSimilarityForSearch(array $a, array $b): float {
        if (count($a) !== count($b)) return 0.0;
        $dot = 0.0; $normA = 0.0; $normB = 0.0;
        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $dot   += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA == 0 || $normB == 0) return 0.0;
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}

// ── Load assets from hdb_image_data ──────────────────────────────────────────
// $tier = 'company' → company_id = $company_id (user-uploaded assets)
// $tier = 'stock'   → company_id = 0 OR admin_id = 0 (shared stock)
if (!function_exists('loadAssetVectorsForSearch')) {
    function loadAssetVectorsForSearch(
        $conn,
        $exclude_podcast_id = 0,
        $media_type_filter  = '',
        $include_mine       = false,
        $admin_id           = 0,
        $hard_limit         = 2000,
        $tier               = 'stock',
        $company_id         = 0,
        $ai_group           = ''     // ← industry filter
    ) {
        error_log("[image_search] loadAssetVectors: tier=$tier company=$company_id ai_group=$ai_group exclude=$exclude_podcast_id media=$media_type_filter limit=$hard_limit");

        // ── Exclude files already used in this podcast ────────────────────────
        $exclude_sql = '';
        if ($exclude_podcast_id > 0) {
            $exclude_sql = "AND image_name NOT IN (
                SELECT image_file   FROM hdb_podcast_stories WHERE podcast_id=$exclude_podcast_id AND image_file   != '' AND image_file   IS NOT NULL
                UNION SELECT image_file_1 FROM hdb_podcast_stories WHERE podcast_id=$exclude_podcast_id AND image_file_1 != '' AND image_file_1 IS NOT NULL
                UNION SELECT image_file_2 FROM hdb_podcast_stories WHERE podcast_id=$exclude_podcast_id AND image_file_2 != '' AND image_file_2 IS NOT NULL
                UNION SELECT image_file_3 FROM hdb_podcast_stories WHERE podcast_id=$exclude_podcast_id AND image_file_3 != '' AND image_file_3 IS NOT NULL
                UNION SELECT image_file_4 FROM hdb_podcast_stories WHERE podcast_id=$exclude_podcast_id AND image_file_4 != '' AND image_file_4 IS NOT NULL
            )";
        }

        // ── Media type filter ─────────────────────────────────────────────────
        $media_clause = '';
        if ($media_type_filter === 'video') {
            $media_clause = "AND media_type = 'video'";
        } elseif ($media_type_filter === 'image') {
            $media_clause = "AND (media_type = 'image' OR media_type IS NULL OR media_type = '')";
        }

        // ── Ownership filter — the key change ─────────────────────────────────
        // Check if company_id column exists — auto-add it if missing
        static $_img_has_company_id = null;
        if ($_img_has_company_id === null) {
            $cc = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data LIKE 'company_id'");
            $_img_has_company_id = ($cc && mysqli_num_rows($cc) > 0);
            if (!$_img_has_company_id) {
                // Add the column silently so future calls work
                mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN company_id INT NOT NULL DEFAULT 0");
                $_img_has_company_id = (mysqli_errno($conn) === 0);
                error_log("[image_search] auto-added company_id column to hdb_image_data");
            }
        }

        if ($_img_has_company_id && $tier === 'company' && $company_id > 0) {
            // Tier 1: assets uploaded by this specific company
            $ownership_filter = "AND company_id = $company_id";
        } elseif ($_img_has_company_id) {
            // Tier 2: shared stock (company_id = 0 or admin_id = 0)
            $ownership_filter = "AND (company_id = 0 OR company_id IS NULL OR admin_id = 0 OR admin_id IS NULL)";
        } else {
            // Column doesn't exist yet — fall back to original admin_id = 0 filter
            $ownership_filter = "AND (admin_id = 0 OR admin_id IS NULL)";
        }

        // ── Industry filter (ai_group) ────────────────────────────────────────
        // Narrows the result set to assets tagged for this industry.
        // Limit is reduced to 100 when filtering (typically returns 20-30 rows).
        $ai_group_clause = '';
        if (!empty($ai_group)) {
            $esc_ag = mysqli_real_escape_string($conn, $ai_group);
            $ai_group_clause = "AND ai_group = '$esc_ag'";
            $hard_limit = 100; // small set — no need to load 2000
        }

        $sql = "SELECT id, image_name, natural_language_tags, media_type, embedding, thumbnail
                FROM hdb_image_data
                WHERE embedding IS NOT NULL
                AND embedding != ''
                AND skip_embedding = 0
                $ownership_filter
                $ai_group_clause
                $media_clause
                $exclude_sql
                ORDER BY id ASC
                LIMIT $hard_limit";

        $result = mysqli_query($conn, $sql);
        if (!$result) {
            error_log("[image_search] SQL error: " . mysqli_error($conn));
            return [];
        }

        $assets = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $vec = json_decode($row['embedding'], true);
            if (!is_array($vec) || count($vec) === 0) continue;
            $assets[] = [
                'id'         => $row['id'],
                'image_name' => $row['image_name'],
                'media_type' => $row['media_type'],
                'nl_tags'    => $row['natural_language_tags'],
                'thumbnail'  => $row['thumbnail'],
                'embedding'  => $vec,
                'dims'       => count($vec),
            ];
        }

        error_log("[image_search] loaded " . count($assets) . " assets (tier=$tier company=$company_id)");
        return $assets;
    }
}

if (!function_exists('findMatchingTerms')) {
    function findMatchingTerms(string $query, string $assetTags): array {
        $queryLower = strtolower($query);
        $assetLower = strtolower($assetTags);
        $cleanQuery = preg_replace('/[^a-z0-9\s|,]/i', '', $queryLower);
        $cleanAsset = preg_replace('/[^a-z0-9\s|,]/i', '', $assetLower);
        $queryWords = preg_split('/[\s|,]+/', $cleanQuery);
        $assetWords = preg_split('/[\s|,]+/', $cleanAsset);

        $matches = [];
        foreach ($queryWords as $word) {
            if (strlen($word) >= 4 && in_array($word, $assetWords)) {
                $matches[] = $word;
            }
        }
        $qWords = array_values(array_filter($queryWords, fn($w) => strlen($w) >= 3));
        for ($i = 0; $i < count($qWords) - 1; $i++) {
            $bigram = $qWords[$i] . ' ' . $qWords[$i + 1];
            if (strpos($cleanAsset, $bigram) !== false) $matches[] = $bigram;
            if ($i < count($qWords) - 2) {
                $trigram = $qWords[$i] . ' ' . $qWords[$i + 1] . ' ' . $qWords[$i + 2];
                if (strpos($cleanAsset, $trigram) !== false) $matches[] = $trigram;
            }
        }
        return array_slice(array_unique($matches), 0, 5);
    }
}

if (!function_exists('extractDomainAnchors')) {
    function extractDomainAnchors(string $query): array {
        static $GENERIC_WORDS = [
            'calm','relaxed','relaxing','peaceful','quiet','serene','comfortable',
            'happy','sad','focused','gentle','soft','warm','bright','dark',
            'person','man','woman','people','client','patient','professional',
            'hands','face','eyes','body','head','background',
            'room','chair','table','desk','floor','wall','light','window',
            'indoor','outdoor','interior','setting','environment','space',
            'sitting','standing','lying','looking','smiling','holding',
            'session','scene','moment','shot','view','image','photo','video',
            'professional','natural','real','authentic','modern','clean',
            'close','wide','high','white','gray','grey','black','blue',
        ];
        $lower  = strtolower($query);
        $clean  = preg_replace('/[^a-z0-9\s|,]/i', '', $lower);
        $words  = preg_split('/[\s|,]+/', $clean);
        $words  = array_filter($words, fn($w) => strlen($w) >= 4);
        $anchors = [];
        foreach ($words as $w) {
            if (!in_array($w, $GENERIC_WORDS)) $anchors[] = $w;
        }
        return array_unique($anchors);
    }
}

if (!function_exists('assetSharesDomainAnchor')) {
    function assetSharesDomainAnchor(array $anchors, string $assetTags): bool {
        if (empty($anchors)) return true;
        $assetLower = strtolower($assetTags);
        foreach ($anchors as $anchor) {
            if (strpos($assetLower, $anchor) !== false) return true;
        }
        return false;
    }
}

// ── Score a set of assets against a query vector ──────────────────────────────
// Returns array of scored results above $min_score, sorted desc.
if (!function_exists('scoreAssets')) {
    function scoreAssets(
        array  $assets,
        array  $queryVector,
        int    $queryDims,
        array  $domainAnchors,
        float  $min_score,
        int    $limit,
        string $tier_label = ''
    ): array {
        $scored = [];
        foreach ($assets as $asset) {
            if ($asset['dims'] !== $queryDims) continue;

            $score = cosineSimilarityForSearch($queryVector, $asset['embedding']);
            if ($score < $min_score) continue;

            $matchedTerms = [];
            if (!empty($asset['nl_tags'])) {
                $matchedTerms = findMatchingTerms(
                    implode(', ', $domainAnchors),
                    $asset['nl_tags']
                );
            }

            $hasDomainMatch = assetSharesDomainAnchor($domainAnchors, $asset['nl_tags'] ?? '');
            if (!$hasDomainMatch && !empty($domainAnchors)) {
                $score *= 0.55;
                error_log("[image_search] domain penalty ($tier_label): " . $asset['image_name']);
            }

            if ($score < $min_score) continue;

            $scored[] = [
                'id'             => $asset['id'],
                'filename'       => $asset['image_name'],
                'media_type'     => $asset['media_type'],
                'nl_tags'        => $asset['nl_tags'],
                'thumbnail'      => $asset['thumbnail'],
                'score'          => round($score, 4),
                'score_pct'      => round($score * 100, 1),
                'matched_terms'  => $matchedTerms,
                'domain_matched' => $hasDomainMatch,
                'tier'           => $tier_label,
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }
}

// ── Main search function — two-tier ───────────────────────────────────────────
if (!function_exists('searchAssets')) {
    function searchAssets(
        $conn,
        $query,
        $apiKey,
        $exclude_podcast_id = 0,
        $media_type_filter  = '',
        $include_mine       = false,
        $admin_id           = 0,
        $limit              = 30,
        $min_score          = 0.20,
        $company_id         = 0
    ) {
        error_log("[image_search] searchAssets: query=" . substr($query, 0, 100)
            . " company=$company_id podcast=$exclude_podcast_id limit=$limit min_score=$min_score media=$media_type_filter");

        if (empty($query)) return [];

        // ── Look up ai_group from hdb_podcasts using podcast_id ───────────────
        // This narrows the asset pool to the same industry — faster than loading 2000 rows.
        // Falls back to no filter if ai_group is empty or not set on the podcast.
        $ai_group = '';
        if ($exclude_podcast_id > 0) {
            $pgr = mysqli_query($conn, "SELECT ai_group FROM hdb_podcasts WHERE id=$exclude_podcast_id LIMIT 1");
            if ($pgr && $pgrow = mysqli_fetch_assoc($pgr)) {
                $ai_group = trim($pgrow['ai_group'] ?? '');
            }
        }
        error_log("[image_search] ai_group=" . ($ai_group ?: 'none (unfiltered)'));

        $cleanQuery  = cleanTagsForEmbedding($query);
        $queryVector = getEmbeddingForSearch($cleanQuery, $apiKey);
        if (!$queryVector) {
            error_log("[image_search] failed to get query embedding");
            return [];
        }

        $queryDims     = count($queryVector);
        $domainAnchors = extractDomainAnchors($cleanQuery);
        error_log("[image_search] domain anchors: " . implode(', ', $domainAnchors));

        $results = [];

        // ── TIER 1: Company-specific assets ──────────────────────────────────
        if ($company_id > 0) {
            $companyAssets = loadAssetVectorsForSearch(
                $conn, $exclude_podcast_id, $media_type_filter,
                $include_mine, $admin_id, 500, 'company', $company_id, $ai_group
            );
            if (!empty($companyAssets)) {
                $companyResults = scoreAssets(
                    $companyAssets, $queryVector, $queryDims,
                    $domainAnchors, $min_score, $limit, 'company'
                );
                error_log("[image_search] Tier 1 (company=$company_id): " . count($companyResults) . " results");
                $results = $companyResults;
            }
        }

        // ── TIER 2: Shared stock ──────────────────────────────────────────────
        $tier1_count = count($results);
        $need        = $limit - $tier1_count;

        if ($need > 0 && $tier1_count < MIN_COMPANY_RESULTS) {
            $tier1_files = array_column($results, 'filename');

            // Try with ai_group filter first (20-30 rows, fast)
            $stockAssets = loadAssetVectorsForSearch(
                $conn, $exclude_podcast_id, $media_type_filter,
                $include_mine, $admin_id, 2000, 'stock', 0, $ai_group
            );
            error_log("[image_search] Tier 2 ai_group filtered: " . count($stockAssets) . " assets");

            // Fallback: unfiltered if ai_group returned nothing
            if (empty($stockAssets) && !empty($ai_group)) {
                error_log("[image_search] ai_group=0 results — falling back to unfiltered stock");
                $stockAssets = loadAssetVectorsForSearch(
                    $conn, $exclude_podcast_id, $media_type_filter,
                    $include_mine, $admin_id, 2000, 'stock', 0, ''
                );
                error_log("[image_search] Tier 2 unfiltered: " . count($stockAssets) . " assets");
            }

            if (!empty($stockAssets)) {
                $stockAssets = array_filter($stockAssets,
                    fn($a) => !in_array($a['image_name'], $tier1_files)
                );
                $stockResults = scoreAssets(
                    array_values($stockAssets), $queryVector, $queryDims,
                    $domainAnchors, $min_score, $need, 'stock'
                );
                error_log("[image_search] Tier 2 scored: " . count($stockResults) . " results");
                $results = array_merge($results, $stockResults);
            }
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        error_log("[image_search] final: " . count($results)
            . " tier1=$tier1_count"
            . " top_score=" . ($results[0]['score'] ?? 0)
            . " top_tier=" . ($results[0]['tier'] ?? '?')
            . " ai_group=" . ($ai_group ?: 'unfiltered'));

        return $results;
    }
}
