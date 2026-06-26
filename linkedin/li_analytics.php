<?php
/**
 * linkedin/li_analytics.php
 *
 * Fetches LinkedIn member analytics for the dashboard.
 *
 * LinkedIn's standard OAuth tier has very limited analytics access.
 * What we can fetch:
 *
 *   With openid + profile scopes (always available):
 *     GET /v2/userinfo       → name, sub (already stored in DB)
 *
 *   With r_1st_connections_size scope:
 *     GET /v2/networkSizes/{personUrn}?edgeType=ConnectionsOf
 *                            → first-degree connection count (used as followers)
 *
 *   Local DB (hdb_podcasts):
 *     posts_month            → posts with linkedin_status set in last 30 days
 *     scheduled              → posts in SCHEDULED state not yet posted to LinkedIn
 *
 * Deeper metrics (impressions, reach, video views, demographics) require the
 * LinkedIn Marketing Developer Program — returned as null so the dashboard
 * falls back to its dummy defaults.
 */
declare(strict_types=1);

const LI_API_BASE  = 'https://api.linkedin.com';
const LI_CACHE_TTL = 1800; // 30 minutes
const LI_CACHE_DIR = __DIR__ . '/../meta/li_analytics_cache';

function li_an_log(string $msg): void {
    @file_put_contents(__DIR__ . '/../a_errors.log',
        date('[Y-m-d H:i:s] ') . '[li_analytics] ' . $msg . "\n", FILE_APPEND);
}

function li_an_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'X-Restli-Protocol-Version: 2.0.0',
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return [$code, is_array($data) ? $data : []];
}

/**
 * Pull all available analytics for a LinkedIn member. Returns a flat array.
 * Fields that cannot be fetched are null — caller falls back to dummy defaults.
 */
function li_fetch_member_analytics(string $memberId, string $memberName, string $token, ?int $adminId = null, $conn = null): array {
    $out = [
        'member_name'  => $memberName,
        'followers'    => null,
        'reach'        => null,
        'impressions'  => null,
        'video_views'  => null,
        'page_visits'  => null,
        'comments'     => null,
        'shares'       => null,
        'saves'        => null,
        'eng_rate'     => null,
        'link_clicks'  => null,
        'avg_watch'    => null,
        'completion'   => null,
        'posts_month'  => null,
        'scheduled'    => null,
        'best_format'  => null,
        'top_time'     => null,
        'top_country'  => null,
        'age_top'      => null,
        'gender'       => null,
        'live_fields'  => [],
        'fetched_at'   => date('c'),
    ];

    // Connection count — proxy for "followers" on personal profiles ----------
    // Requires r_1st_connections_size scope. Gracefully skipped if not granted.
    $personUrn = 'urn:li:person:' . $memberId;
    $netUrl    = LI_API_BASE . '/v2/networkSizes/' . urlencode($personUrn)
               . '?edgeType=ConnectionsOf';
    [$code, $net] = li_an_get($netUrl, $token);
    if ($code === 200 && isset($net['firstDegreeSize'])) {
        $out['followers']     = (int)$net['firstDegreeSize'];
        $out['live_fields'][] = 'followers';
    } elseif ($code !== 200) {
        li_an_log("networkSizes http $code for $memberId");
    }

    // Local DB counts — no API call needed -----------------------------------
    if ($adminId && $conn instanceof mysqli) {
        $aid = (int)$adminId;

        // Posts sent to LinkedIn in the last 30 days
        $q = mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM hdb_podcasts
             WHERE admin_id = $aid
               AND linkedin_status IS NOT NULL
               AND linkedin_status != ''
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $out['posts_month']   = (int)$r['cnt'];
            $out['live_fields'][] = 'posts_month';
        }

        // Posts scheduled but not yet posted to LinkedIn
        $q2 = mysqli_query($conn,
            "SELECT COUNT(*) AS cnt FROM hdb_podcasts
             WHERE admin_id = $aid
               AND video_status = 'SCHEDULED'
               AND (linkedin_status IS NULL OR linkedin_status != 'posted')");
        if ($q2 && ($r2 = mysqli_fetch_assoc($q2))) {
            $out['scheduled']     = (int)$r2['cnt'];
            $out['live_fields'][] = 'scheduled';
        }
    }

    return $out;
}

/**
 * Resolve the LinkedIn member (id + token + name) for the current request.
 * Returns null if no account is connected or token is expired.
 */
function li_resolve_member(?int $adminId = null, $conn = null): ?array {
    if ($adminId && $conn instanceof mysqli) {
        $aid = (int)$adminId;
        $q   = mysqli_query($conn,
            "SELECT channel_id, channel_name, access_token
             FROM hdb_oauth_tokens
             WHERE admin_id = $aid
               AND platform = 'linkedin'
               AND token_expiry > NOW()
             ORDER BY updated_at DESC
             LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            if (!empty($r['channel_id']) && !empty($r['access_token'])) {
                return [
                    'id'           => (string)$r['channel_id'],
                    'name'         => (string)($r['channel_name'] ?? ''),
                    'access_token' => (string)$r['access_token'],
                ];
            }
        }
    }
    return null;
}

/**
 * Cached entry point. Resolves the member for the current admin/session,
 * fetches analytics, caches per member-id for 30 min.
 *
 * Returns null if no LinkedIn account is connected for the caller.
 */
function li_get_dashboard_data(?int $adminId = null, $conn = null, bool $force = false): ?array {
    $member = li_resolve_member($adminId, $conn);
    if (!$member) return null;

    $cacheKey  = ($adminId ? $adminId . '_' : '') . preg_replace('/[^A-Za-z0-9_-]/', '', $member['id']);
    if (!is_dir(LI_CACHE_DIR)) @mkdir(LI_CACHE_DIR, 0775, true);
    $cacheFile = LI_CACHE_DIR . '/' . $cacheKey . '.json';

    if (!$force && is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        if ($age < LI_CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
    }

    $data = li_fetch_member_analytics(
        $member['id'], $member['name'], $member['access_token'], $adminId, $conn
    );
    $data['li_member_id'] = $member['id'];
    $data['li_name']      = $member['name'];
    @file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $data;
}
