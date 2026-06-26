<?php
/**
 * meta/fb_analytics.php
 *
 * Fetches Facebook Page analytics from the Graph API for the dashboard.
 *
 * Data sources used:
 *   GET /{page-id}                     → name, category, fan_count, followers_count
 *   GET /{page-id}/published_posts     → posts in last 30 days + attachment types
 *   GET /{page-id}/scheduled_posts     → scheduled post count
 *   GET /{page-id}/videos              → video count + total length
 *   GET /{page-id}/insights/...        → reach, impressions, video views, demographics
 *                                        (only if the token has read_insights scope)
 *
 * Returns the same key set the dashboard uses. Fields that cannot be fetched
 * with the current token's scopes are returned as null so the dashboard can
 * fall back to its dummy defaults.
 */
declare(strict_types=1);

const FB_GRAPH_VERSION = 'v24.0';
const FB_CACHE_TTL     = 1800; // 30 minutes
const FB_CACHE_DIR     = __DIR__ . '/fb_analytics_cache';

function fb_an_log(string $msg): void {
    @file_put_contents(__DIR__ . '/../a_errors.log',
        date('[Y-m-d H:i:s] ') . '[fb_analytics] ' . $msg . "\n", FILE_APPEND);
}

function fb_an_get(string $url): array {
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

function fb_an_graph(string $path, array $params, string $token): array {
    $params['access_token'] = $token;
    $url = 'https://graph.facebook.com/' . FB_GRAPH_VERSION . '/' . ltrim($path, '/')
         . '?' . http_build_query($params);
    [$code, $data] = fb_an_get($url);
    if ($code >= 400 || isset($data['error'])) {
        $msg = $data['error']['message'] ?? ('http ' . $code);
        fb_an_log("graph $path failed: $msg");
        return [];
    }
    return $data;
}

/**
 * Pull all available analytics for one page. Returns a flat array of metrics.
 * Fields that cannot be fetched are set to null — caller falls back to dummy.
 */
function fb_fetch_page_analytics(string $pageId, string $pageToken): array {
    $out = [
        'name'           => null,
        'category'       => null,
        'followers'      => null,
        'reach'          => null,
        'impressions'    => null,
        'video_views'    => null,
        'page_visits'    => null,
        'comments'       => null,
        'shares'         => null,
        'reactions'      => null,
        'eng_rate'       => null,
        'clicks'         => null,
        'link_clicks'    => null,
        'avg_watch'      => null,
        'completion'     => null,
        'posts_month'    => null,
        'scheduled'      => null,
        'best_format'    => null,
        'top_time'       => null,
        'video_count'    => null,
        'video_total_sec'=> null,
        'top_country'    => null,
        'age_top'        => null,
        'gender'         => null,
        'live_fields'    => [],   // which keys are real (vs fallback)
        'fetched_at'     => date('c'),
    ];

    // Page basic info ----------------------------------------------------------
    $page = fb_an_graph($pageId, [
        'fields' => 'id,name,category,fan_count,followers_count',
    ], $pageToken);
    if (!empty($page['id'])) {
        $out['name']     = $page['name']     ?? null;
        $out['category'] = $page['category'] ?? null;
        $followers = $page['followers_count'] ?? $page['fan_count'] ?? null;
        if ($followers !== null) {
            $out['followers']      = (int)$followers;
            $out['live_fields'][]  = 'followers';
        }
    }

    // Posts in last 30 days ----------------------------------------------------
    $since = strtotime('-30 days');
    $posts = fb_an_graph("$pageId/published_posts", [
        'fields' => 'id,created_time,attachments{type,subattachments{type}},'
                  . 'comments.summary(true).limit(0),shares,'
                  . 'reactions.summary(true).limit(0)',
        'since'  => $since,
        'limit'  => 100,
    ], $pageToken);

    if (!empty($posts['data']) && is_array($posts['data'])) {
        $rows = $posts['data'];
        $out['posts_month']   = count($rows);
        $out['live_fields'][] = 'posts_month';

        // Best format — most common attachment type
        $formatCounts = [];
        foreach ($rows as $p) {
            $atts = $p['attachments']['data'] ?? [];
            foreach ($atts as $a) {
                $t = $a['type'] ?? 'status';
                $formatCounts[$t] = ($formatCounts[$t] ?? 0) + 1;
            }
        }
        if ($formatCounts) {
            arsort($formatCounts);
            $top = array_key_first($formatCounts);
            $out['best_format']   = fb_an_format_label($top);
            $out['live_fields'][] = 'best_format';
        }

        // Top time — most-common (day-of-week, hour bucket) from posting cadence
        // Note: this is when the page POSTS most, not when the audience is most active.
        // True best-time-to-post needs read_insights.
        $slotCounts = [];
        foreach ($rows as $p) {
            if (empty($p['created_time'])) continue;
            $ts = strtotime($p['created_time']);
            if (!$ts) continue;
            $slot = date('D ga', $ts); // e.g. "Wed 7pm"
            $slotCounts[$slot] = ($slotCounts[$slot] ?? 0) + 1;
        }
        if ($slotCounts) {
            arsort($slotCounts);
            $out['top_time']      = array_key_first($slotCounts);
            $out['live_fields'][] = 'top_time';
        }

        // Post-level comments / shares / reactions aggregation.
        // shares is a {count} object only when present; comments/reactions come
        // back via .summary(true) regardless. These need pages_read_engagement.
        $cTotal = 0; $sTotal = 0; $rTotal = 0;
        $cSeen  = false; $sSeen = false; $rSeen = false;
        foreach ($rows as $p) {
            if (isset($p['comments']['summary']['total_count'])) {
                $cTotal += (int)$p['comments']['summary']['total_count'];
                $cSeen   = true;
            }
            if (isset($p['shares']['count'])) {
                $sTotal += (int)$p['shares']['count'];
                $sSeen   = true;
            } elseif (array_key_exists('shares', $p)) {
                // shares key present but zero-count is omitted by the API — still counts as seen
                $sSeen = true;
            }
            if (isset($p['reactions']['summary']['total_count'])) {
                $rTotal += (int)$p['reactions']['summary']['total_count'];
                $rSeen   = true;
            }
        }
        if ($cSeen) { $out['comments']  = $cTotal; $out['live_fields'][] = 'comments'; }
        if ($sSeen) { $out['shares']    = $sTotal; $out['live_fields'][] = 'shares'; }
        if ($rSeen) { $out['reactions'] = $rTotal; $out['live_fields'][] = 'reactions'; }
    }

    // Scheduled posts ----------------------------------------------------------
    $sched = fb_an_graph("$pageId/scheduled_posts", [
        'fields' => 'id',
        'limit'  => 100,
    ], $pageToken);
    if (isset($sched['data']) && is_array($sched['data'])) {
        $out['scheduled']     = count($sched['data']);
        $out['live_fields'][] = 'scheduled';
    }

    // Videos -------------------------------------------------------------------
    // Pull video_insights inline so we can aggregate avg watch time and completion
    // in the same round-trip. video_insights requires read_insights on the page token.
    $videos = fb_an_graph("$pageId/videos", [
        'fields' => 'id,created_time,length,'
                  . 'video_insights.metric(total_video_views,'
                  . 'total_video_avg_time_watched,total_video_complete_views_organic)',
        'limit'  => 100,
    ], $pageToken);
    if (!empty($videos['data']) && is_array($videos['data'])) {
        $rows = $videos['data'];
        $out['video_count']    = count($rows);
        $out['video_total_sec']= (int)round(array_sum(array_column($rows, 'length')));
        $out['live_fields'][]  = 'video_count';

        // Aggregate video insights across all videos in the window.
        // total_video_avg_time_watched is in MILLISECONDS per video; we want a
        // simple average across videos (matches the dashboard's "0:42" style).
        $watchSumMs    = 0; $watchCount    = 0;
        $viewsTotal    = 0; $completeTotal = 0;
        foreach ($rows as $v) {
            $vi = $v['video_insights']['data'] ?? [];
            foreach ($vi as $m) {
                $name = $m['name'] ?? '';
                $val  = $m['values'][0]['value'] ?? null;
                if ($val === null) continue;
                switch ($name) {
                    case 'total_video_avg_time_watched':
                        $watchSumMs += (int)$val;
                        $watchCount++;
                        break;
                    case 'total_video_views':
                        $viewsTotal += (int)$val;
                        break;
                    case 'total_video_complete_views_organic':
                        $completeTotal += (int)$val;
                        break;
                }
            }
        }
        if ($watchCount > 0) {
            $avgSec = (int)round(($watchSumMs / $watchCount) / 1000);
            $out['avg_watch']     = sprintf('%d:%02d', intdiv($avgSec, 60), $avgSec % 60);
            $out['live_fields'][] = 'avg_watch';
        }
        if ($viewsTotal > 0) {
            $out['completion']    = (int)round(($completeTotal / $viewsTotal) * 100);
            $out['live_fields'][] = 'completion';
        }
    }

    // ── Insights (only if read_insights granted) ─────────────────────────────
    // The Graph API returns error code 100 ("must be a valid insights metric")
    // or code 200 ("read_insights permission missing") if the token can't read
    // them — fb_an_graph will log and return [], and we just skip.

    $ins = fb_an_graph("$pageId/insights", [
        'metric'      => 'page_impressions,page_impressions_unique,page_post_engagements,'
                       . 'page_video_views,page_views_total,page_consumptions,'
                       . 'page_consumptions_by_consumption_type',
        'period'      => 'day',
        'date_preset' => 'last_30d',
    ], $pageToken);
    if (!empty($ins['data'])) {
        $impressionsSum = 0;
        $engagementsSum = 0;
        foreach ($ins['data'] as $m) {
            $name   = $m['name']   ?? '';
            $values = $m['values'] ?? [];

            // page_consumptions_by_consumption_type returns a map per day, not an int.
            if ($name === 'page_consumptions_by_consumption_type') {
                $linkClicks = 0;
                foreach ($values as $v) {
                    $bucket = $v['value'] ?? [];
                    if (is_array($bucket) && isset($bucket['link clicks'])) {
                        $linkClicks += (int)$bucket['link clicks'];
                    }
                }
                $out['link_clicks']   = $linkClicks;
                $out['live_fields'][] = 'link_clicks';
                continue;
            }

            $sum = 0;
            foreach ($values as $v) $sum += (int)($v['value'] ?? 0);
            switch ($name) {
                case 'page_impressions':
                    $out['impressions']   = $sum; $out['live_fields'][] = 'impressions';
                    $impressionsSum       = $sum; break;
                case 'page_impressions_unique':
                    $out['reach']         = $sum; $out['live_fields'][] = 'reach'; break;
                case 'page_post_engagements':
                    $engagementsSum       = $sum; break;
                case 'page_video_views':
                    $out['video_views']   = $sum; $out['live_fields'][] = 'video_views'; break;
                case 'page_views_total':
                    $out['page_visits']   = $sum; $out['live_fields'][] = 'page_visits'; break;
                case 'page_consumptions':
                    $out['clicks']        = $sum; $out['live_fields'][] = 'clicks'; break;
            }
        }
        // Engagement rate = engagements / impressions × 100, one decimal.
        // Matches how the dashboard renders eng_rate (e.g. 4.2 for 4.2%).
        if ($impressionsSum > 0 && $engagementsSum > 0) {
            $out['eng_rate']      = round(($engagementsSum / $impressionsSum) * 100, 1);
            $out['live_fields'][] = 'eng_rate';
        }
    }

    // Demographics (lifetime) — also requires read_insights
    $demo = fb_an_graph("$pageId/insights", [
        'metric' => 'page_fans_country,page_fans_gender_age',
        'period' => 'lifetime',
    ], $pageToken);
    if (!empty($demo['data'])) {
        foreach ($demo['data'] as $m) {
            $name = $m['name'] ?? '';
            $val  = $m['values'][0]['value'] ?? [];
            if (!is_array($val) || !$val) continue;
            if ($name === 'page_fans_country') {
                arsort($val);
                $iso = array_key_first($val);
                $out['top_country']   = fb_an_country_name((string)$iso);
                $out['live_fields'][] = 'top_country';
            } elseif ($name === 'page_fans_gender_age') {
                // keys look like "F.25-34", "M.18-24", "U.45-54"
                $ageTotals    = [];
                $genderTotals = ['F' => 0, 'M' => 0, 'U' => 0];
                $total        = 0;
                foreach ($val as $k => $count) {
                    $parts = explode('.', (string)$k);
                    $g = $parts[0] ?? 'U';
                    $a = $parts[1] ?? '';
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
                    $m = (int)round(($genderTotals['M'] / $total) * 100);
                    $out['gender']        = $f >= $m ? "{$f}% F" : "{$m}% M";
                    $out['live_fields'][] = 'gender';
                }
            }
        }
    }

    return $out;
}

function fb_an_format_label(string $type): string {
    // Map FB attachment types to friendly labels matching the dashboard tone
    switch ($type) {
        case 'video_inline':
        case 'native_video':         return 'Reels';
        case 'photo':
        case 'cover_photo':
        case 'profile_media':        return 'Photos';
        case 'album':
        case 'multi_share':          return 'Carousel';
        case 'share':
        case 'link':                 return 'Link posts';
        case 'note':
        case 'status':               return 'Text posts';
        case 'event':                return 'Events';
        default:                     return ucfirst(str_replace('_', ' ', $type));
    }
}

function fb_an_country_name(string $iso): string {
    static $map = [
        'US' => 'USA', 'GB' => 'UK', 'CA' => 'Canada', 'AU' => 'Australia',
        'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh',
        'DE' => 'Germany', 'FR' => 'France', 'BR' => 'Brazil',
        'MX' => 'Mexico', 'JP' => 'Japan', 'NG' => 'Nigeria',
    ];
    return $map[$iso] ?? $iso;
}

/**
 * Resolve the FB page (id + token) to use for the current request.
 *
 *   1. If $adminId + $conn are provided, look up the most recently updated
 *      facebook_page_* row in hdb_oauth_tokens for that admin.
 *   2. Otherwise fall back to tokens.json (single-tenant / standalone mode).
 *
 * Returns ['id' => ..., 'access_token' => ..., 'name' => ..., 'source' => 'db|file']
 * or null if no page is connected.
 */
function fb_resolve_page(?int $adminId = null, $conn = null): ?array {
    // ── Preferred: per-admin row in hdb_oauth_tokens ─────────────────────────
    if ($adminId && $conn instanceof \mysqli) {
        $aid = (int)$adminId;
        $q = mysqli_query($conn,
            "SELECT channel_id, channel_name, access_token
             FROM hdb_oauth_tokens
             WHERE admin_id = $aid
               AND platform LIKE 'facebook_page_%'
               AND token_expiry > NOW()
             ORDER BY updated_at DESC
             LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            if (!empty($r['channel_id']) && !empty($r['access_token'])) {
                return [
                    'id'           => (string)$r['channel_id'],
                    'name'         => (string)($r['channel_name'] ?? ''),
                    'access_token' => (string)$r['access_token'],
                    'source'       => 'db',
                ];
            }
        }
    }

    // ── Fallback: tokens.json (no auth context, dev/standalone) ──────────────
    $tokensFile = __DIR__ . '/tokens.json';
    if (!is_file($tokensFile)) return null;
    $tokens = json_decode((string)file_get_contents($tokensFile), true);
    if (!is_array($tokens) || empty($tokens['pages'])) return null;

    $selected = $tokens['selected_page_id'] ?? null;
    $page     = null;
    foreach ($tokens['pages'] as $p) {
        if ($selected && ($p['id'] ?? null) === $selected) { $page = $p; break; }
    }
    if (!$page) $page = $tokens['pages'][0];
    if (empty($page['id']) || empty($page['access_token'])) return null;

    return [
        'id'           => (string)$page['id'],
        'name'         => (string)($page['name'] ?? ''),
        'access_token' => (string)$page['access_token'],
        'source'       => 'file',
    ];
}

/**
 * Cached entry point. Resolves the page for the current admin/session,
 * fetches analytics from the Graph API, caches per page id for 30 min.
 *
 * Returns null if no page is connected for the caller.
 */
function fb_get_dashboard_data(?int $adminId = null, $conn = null, bool $force = false): ?array {
    $page = fb_resolve_page($adminId, $conn);
    if (!$page) return null;

    // Cache key isolates per page so different users never share cached data.
    $cacheKey  = ($adminId ? $adminId . '_' : '') . preg_replace('/[^A-Za-z0-9_-]/', '', $page['id']);
    if (!is_dir(FB_CACHE_DIR)) @mkdir(FB_CACHE_DIR, 0775, true);
    $cacheFile = FB_CACHE_DIR . '/' . $cacheKey . '.json';

    if (!$force && is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        if ($age < FB_CACHE_TTL) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }
    }

    $data = fb_fetch_page_analytics($page['id'], $page['access_token']);
    $data['page_id']     = $page['id'];
    $data['page_source'] = $page['source'];
    @file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $data;
}
