<?php
/**
 * meta/ig_analytics.php
 *
 * Fetches Instagram account analytics via the Instagram Graph API
 * (Instagram Business Login — graph.instagram.com).
 *
 * With instagram_business_basic scope:
 *   GET /{ig-user-id}           → username, followers_count, media_count
 *   GET /{ig-user-id}/media     → posts in last 30d, like_count, comments_count
 *
 * Additionally, with instagram_business_manage_insights scope:
 *   GET /{ig-user-id}/insights  → impressions, reach, interactions, saves,
 *                                  profile_views, website_clicks, shares
 *   GET /{ig-user-id}/insights  → audience_country, audience_gender_age (lifetime)
 *
 * Fields that cannot be fetched are returned as null so the dashboard falls
 * back to its dummy defaults.
 */
declare(strict_types=1);

const IG_GRAPH_BASE = 'https://graph.instagram.com/v22.0';
const IG_CACHE_TTL  = 1800; // 30 minutes
const IG_CACHE_DIR  = __DIR__ . '/ig_analytics_cache';

function ig_an_log(string $msg): void {
    @file_put_contents(__DIR__ . '/../a_errors.log',
        date('[Y-m-d H:i:s] ') . '[ig_analytics] ' . $msg . "\n", FILE_APPEND);
}

function ig_an_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return [$code, is_array($data) ? $data : []];
}

function ig_an_call(string $path, array $params, string $token): array {
    $params['access_token'] = $token;
    $url = IG_GRAPH_BASE . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    [$code, $data] = ig_an_get($url);
    if ($code >= 400 || isset($data['error'])) {
        $msg = $data['error']['message'] ?? ('http ' . $code);
        ig_an_log("call $path failed: $msg");
        return [];
    }
    return $data;
}

/**
 * Pull all available analytics for one IG user. Returns a flat array of metrics.
 * Fields that cannot be fetched are set to null — caller falls back to dummy.
 */
function ig_fetch_user_analytics(string $userId, string $token, ?int $adminId = null, $conn = null): array {
    $out = [
        'username'    => null,
        'followers'   => null,
        'reach'       => null,
        'impressions' => null,
        'video_views' => null,
        'page_visits' => null,
        'comments'    => null,
        'shares'      => null,
        'saves'       => null,
        'eng_rate'    => null,
        'link_clicks' => null,
        'avg_watch'   => null,
        'completion'  => null,
        'posts_month' => null,
        'scheduled'   => null,
        'best_format' => null,
        'top_time'    => null,
        'top_country' => null,
        'age_top'     => null,
        'gender'      => null,
        'live_fields' => [],
        'fetched_at'  => date('c'),
    ];

    // Profile ----------------------------------------------------------------
    $profile = ig_an_call($userId, [
        'fields' => 'username,name,followers_count,media_count',
    ], $token);
    if (!empty($profile['id'])) {
        $out['username'] = $profile['username'] ?? null;
        if (isset($profile['followers_count'])) {
            $out['followers']     = (int)$profile['followers_count'];
            $out['live_fields'][] = 'followers';
        }
    }

    // Media (last 30 days) ---------------------------------------------------
    $since  = strtotime('-30 days');
    $media  = ig_an_call("$userId/media", [
        'fields' => 'id,timestamp,media_type,like_count,comments_count',
        'since'  => $since,
        'limit'  => 100,
    ], $token);

    $likeTotal = 0;
    if (!empty($media['data']) && is_array($media['data'])) {
        // Filter to last 30 days in case API doesn't honour `since` strictly
        $rows = array_values(array_filter($media['data'], function ($m) use ($since) {
            return !empty($m['timestamp']) && strtotime($m['timestamp']) >= $since;
        }));

        if ($rows) {
            $out['posts_month']   = count($rows);
            $out['live_fields'][] = 'posts_month';

            // Best format from media_type
            $typeCounts = [];
            foreach ($rows as $m) {
                $t = $m['media_type'] ?? 'IMAGE';
                $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
            }
            arsort($typeCounts);
            $out['best_format']   = ig_an_format_label((string)array_key_first($typeCounts));
            $out['live_fields'][] = 'best_format';

            // Top posting time
            $slotCounts = [];
            foreach ($rows as $m) {
                if (empty($m['timestamp'])) continue;
                $slot = date('D ga', (int)strtotime($m['timestamp']));
                $slotCounts[$slot] = ($slotCounts[$slot] ?? 0) + 1;
            }
            if ($slotCounts) {
                arsort($slotCounts);
                $out['top_time']      = array_key_first($slotCounts);
                $out['live_fields'][] = 'top_time';
            }

            // Comments and likes aggregation (basic scope — no insights needed)
            $commentTotal = array_sum(array_column($rows, 'comments_count'));
            $likeTotal    = array_sum(array_column($rows, 'like_count'));
            $out['comments']      = $commentTotal;
            $out['live_fields'][] = 'comments';

            // Engagement rate from post data (fallback when insights unavailable)
            if (!empty($out['followers']) && ($likeTotal + $commentTotal) > 0) {
                $out['eng_rate']      = round(($likeTotal + $commentTotal) / $out['followers'] * 100, 1);
                $out['live_fields'][] = 'eng_rate';
            }
        }
    }

    // Account insights (requires instagram_business_manage_insights) ---------
    $until = time();
    $ins   = ig_an_call("$userId/insights", [
        'metric' => 'impressions,reach,total_interactions,profile_views,'
                  . 'website_clicks,likes,comments,shares,saves',
        'period' => 'day',
        'since'  => $since,
        'until'  => $until,
    ], $token);

    if (!empty($ins['data'])) {
        $totalImpressions  = 0;
        $totalInteractions = 0;
        foreach ($ins['data'] as $m) {
            $name   = $m['name']   ?? '';
            $values = $m['values'] ?? [];
            $sum    = 0;
            foreach ($values as $v) $sum += (int)($v['value'] ?? 0);
            switch ($name) {
                case 'impressions':
                    $out['impressions']   = $sum;
                    $out['live_fields'][] = 'impressions';
                    $totalImpressions     = $sum;
                    break;
                case 'reach':
                    $out['reach']         = $sum;
                    $out['live_fields'][] = 'reach';
                    break;
                case 'total_interactions':
                    $totalInteractions    = $sum;
                    break;
                case 'profile_views':
                    $out['page_visits']   = $sum;
                    $out['live_fields'][] = 'page_visits';
                    break;
                case 'website_clicks':
                    $out['link_clicks']   = $sum;
                    $out['live_fields'][] = 'link_clicks';
                    break;
                case 'shares':
                    $out['shares']        = $sum;
                    $out['live_fields'][] = 'shares';
                    break;
                case 'saves':
                    $out['saves']         = $sum;
                    $out['live_fields'][] = 'saves';
                    break;
            }
        }
        // Override engagement rate with impression-based calc when available
        if ($totalImpressions > 0 && $totalInteractions > 0) {
            $out['eng_rate']      = round(($totalInteractions / $totalImpressions) * 100, 1);
            // eng_rate already in live_fields from post-data fallback; add only if not present
            if (!in_array('eng_rate', $out['live_fields'])) {
                $out['live_fields'][] = 'eng_rate';
            }
        }
    }

    // Audience demographics (requires instagram_business_manage_insights) ----
    $demo = ig_an_call("$userId/insights", [
        'metric' => 'audience_country,audience_gender_age',
        'period' => 'lifetime',
    ], $token);
    if (!empty($demo['data'])) {
        foreach ($demo['data'] as $m) {
            $name = $m['name'] ?? '';
            $val  = $m['values'][0]['value'] ?? $m['value'] ?? [];
            if (!is_array($val) || !$val) continue;
            if ($name === 'audience_country') {
                arsort($val);
                $iso = array_key_first($val);
                $out['top_country']   = ig_an_country_name((string)$iso);
                $out['live_fields'][] = 'top_country';
            } elseif ($name === 'audience_gender_age') {
                // Keys like "F.25-34", "M.18-24"
                $ageTotals    = [];
                $genderTotals = ['F' => 0, 'M' => 0, 'U' => 0];
                $total        = 0;
                foreach ($val as $k => $count) {
                    $parts = explode('.', (string)$k);
                    $g     = $parts[0] ?? 'U';
                    $a     = $parts[1] ?? '';
                    $ageTotals[$a]    = ($ageTotals[$a] ?? 0) + (int)$count;
                    $genderTotals[$g] = ($genderTotals[$g] ?? 0) + (int)$count;
                    $total           += (int)$count;
                }
                if ($ageTotals) {
                    arsort($ageTotals);
                    $out['age_top']       = (string)array_key_first($ageTotals);
                    $out['live_fields'][] = 'age_top';
                }
                if ($total > 0) {
                    $f = (int)round(($genderTotals['F'] / $total) * 100);
                    $mv = (int)round(($genderTotals['M'] / $total) * 100);
                    $out['gender']        = $f >= $mv ? "{$f}% F" : "{$mv}% M";
                    $out['live_fields'][] = 'gender';
                }
            }
        }
    }

    // Scheduled posts count from local DB ------------------------------------
    if ($adminId && $conn instanceof mysqli) {
        $aid = (int)$adminId;
        $q   = mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM hdb_podcasts
             WHERE admin_id = $aid
               AND video_status = 'SCHEDULED'
               AND (instagram_status IS NULL OR instagram_status != 'posted')");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $out['scheduled']     = (int)$r['cnt'];
            $out['live_fields'][] = 'scheduled';
        }
    }

    return $out;
}

function ig_an_format_label(string $type): string {
    switch (strtoupper($type)) {
        case 'REELS':          return 'Reels';
        case 'VIDEO':          return 'Videos';
        case 'IMAGE':          return 'Photos';
        case 'CAROUSEL_ALBUM': return 'Carousel';
        default:               return ucfirst(strtolower(str_replace('_', ' ', $type)));
    }
}

function ig_an_country_name(string $iso): string {
    static $map = [
        'US' => 'USA', 'GB' => 'UK', 'CA' => 'Canada', 'AU' => 'Australia',
        'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh',
        'DE' => 'Germany', 'FR' => 'France', 'BR' => 'Brazil',
        'MX' => 'Mexico', 'JP' => 'Japan', 'NG' => 'Nigeria',
    ];
    return $map[$iso] ?? $iso;
}

/**
 * Resolve the Instagram user (id + token) for the current request.
 * Looks up hdb_oauth_tokens for platform='instagram' row for this admin.
 * Returns null if no account is connected or token is expired.
 */
function ig_resolve_user(?int $adminId = null, $conn = null): ?array {
    if ($adminId && $conn instanceof mysqli) {
        $aid = (int)$adminId;
        $q   = mysqli_query($conn,
            "SELECT channel_id, channel_name, access_token
             FROM hdb_oauth_tokens
             WHERE admin_id = $aid
               AND platform = 'instagram'
               AND token_expiry > NOW()
             ORDER BY updated_at DESC
             LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            if (!empty($r['channel_id']) && !empty($r['access_token'])) {
                return [
                    'id'           => (string)$r['channel_id'],
                    'username'     => (string)($r['channel_name'] ?? ''),
                    'access_token' => (string)$r['access_token'],
                    'source'       => 'db',
                ];
            }
        }
    }
    return null;
}

/**
 * Cached entry point. Resolves the IG user for the current admin/session,
 * fetches analytics, caches per user-id for 30 min.
 *
 * Returns null if no Instagram account is connected for the caller.
 */
function ig_get_dashboard_data(?int $adminId = null, $conn = null, bool $force = false): ?array {
    $user = ig_resolve_user($adminId, $conn);
    if (!$user) return null;

    $cacheKey  = ($adminId ? $adminId . '_' : '') . preg_replace('/[^A-Za-z0-9_-]/', '', $user['id']);
    if (!is_dir(IG_CACHE_DIR)) @mkdir(IG_CACHE_DIR, 0775, true);
    $cacheFile = IG_CACHE_DIR . '/' . $cacheKey . '.json';

    if (!$force && is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        if ($age < IG_CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
    }

    $data = ig_fetch_user_analytics($user['id'], $user['access_token'], $adminId, $conn);
    $data['ig_user_id'] = $user['id'];
    $data['ig_username'] = $user['username'];
    $data['ig_source']   = $user['source'];
    @file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $data;
}
