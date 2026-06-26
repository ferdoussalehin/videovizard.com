<?php
session_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';
$from_videomaker = isset($_POST['secret_key']) && $_POST['secret_key'] === $SECRET_KEY;
if (!$from_videomaker && !isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

include 'dbconnect_hdb.php';

$admin_id = $from_videomaker ? (int)($_POST['admin_id'] ?? 0) : (int)$_SESSION['admin_id'];
$ROOT_DIR  = __DIR__;
$FFMPEG    = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '/usr/bin/ffmpeg');
$AUDIO_DIR = $ROOT_DIR . '/podcast_audios';

// ── Site base URL — used for downloading media files ─────────────
// Change this to your actual domain if auto-detection fails
define('SITE_BASE_URL', 'https://videovizard.com/');

// Some upload paths in the DB have been saved with the domain name baked into
// image_folder (e.g. "videovizard.com/user_media/..."), which doubles up when
// appended to $ROOT_DIR (which already ends in the domain). Strip it defensively.
//
// Separately — and this is the one that was actually breaking most scenes —
// some writers (the podcast-generation pipeline) save image_folder WITHOUT
// the 'user_media/' prefix at all, e.g. "user_id_34_company_id_29/podcast_images",
// while the real file lives on disk/on the web server at
// "user_media/user_id_34_company_id_29/podcast_images/...". videomaker.php's
// own upload code always saves WITH the prefix, so this script was building
// both the local path ($ROOT_DIR/$folder/$file) and the download URL
// ($base_url.$folder.'/'.$file) without 'user_media/' for those rows — a
// flat top-level folder like podcast_videos/podcast_images was never broken
// (it really does live at the root), only the per-user nested ones 404'd.
// This mirrors vv_resolve_media_folder() in videomaker.php so both files
// agree on the same folder for the same DB value.
function clean_folder(string $folder): string {
    $folder = trim($folder, '/');
    $host = parse_url(SITE_BASE_URL, PHP_URL_HOST) ?: '';
    if ($host && stripos($folder, $host . '/') === 0) {
        $folder = substr($folder, strlen($host) + 1);
    } elseif ($host && $folder === $host) {
        $folder = '';
    }
    if ($folder !== '' && preg_match('#^(user|admin)_id_\d+_company_id_\d+(/|$)#', $folder) && strpos($folder, 'user_media/') !== 0) {
        $folder = 'user_media/' . $folder;
    }
    return $folder;
}

// Build a URL that is actually reachable from this request (e.g. the raw IP
// the front-end calls this script on), rather than SITE_BASE_URL, which may
// be a domain that isn't routed to this server (e.g. parked elsewhere).
function reachable_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? parse_url(SITE_BASE_URL, PHP_URL_HOST);
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $dir;
}

// ── Canvas / video dimensions ─────────────────────────────────
define('PREVIEW_W', 360);
define('PREVIEW_H', 640);
define('VIDEO_W',  1080);
define('VIDEO_H',  1920);
define('SCALE',       3);

// ── Fixed per-scene duration (seconds) ────────────────────────────
// Rule: every scene is exactly this long — image or video, narrated or
// silent. Narration shorter than this gets padded with silence;
// narration longer than this gets cut off. This keeps every clip going
// into the concat list (and therefore the music mix, which times off
// the stitched video's own length) at one predictable duration instead
// of stretching/shrinking per scene based on narration length.
define('SCENE_DURATION_SEC', 5);


$FONT_CANDIDATES = [
    'Arial'           => ['/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', '/usr/share/fonts/truetype/freefont/FreeSans.ttf'],
    'Helvetica'       => ['/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'],
    'Verdana'         => ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
    'Georgia'         => ['/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf'],
    'Courier New'     => ['/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf'],
    'Times New Roman' => ['/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf'],
    'Impact'          => [
        '/usr/share/fonts/truetype/msttcorefonts/Impact.ttf',
        '/usr/share/fonts/truetype/impact/Impact.ttf',
        '/usr/share/fonts/TTF/Impact.ttf',
        $ROOT_DIR . '/fonts/Impact.ttf',
        '/usr/share/fonts/truetype/lato/Lato-Black.ttf',
    ],
    'DejaVu Sans'     => ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
    'Inter'           => ['/usr/share/fonts/truetype/inter/Inter-Regular.ttf', $ROOT_DIR . '/fonts/Inter-Regular.ttf'],
    'Poppins'         => ['/usr/share/fonts/truetype/poppins/Poppins-Regular.ttf', $ROOT_DIR . '/fonts/Poppins-Regular.ttf'],
    'Montserrat'      => ['/usr/share/fonts/truetype/montserrat/Montserrat-Regular.ttf', $ROOT_DIR . '/fonts/Montserrat-Regular.ttf'],
    'Oswald'          => ['/usr/share/fonts/truetype/oswald/Oswald-Regular.ttf', $ROOT_DIR . '/fonts/Oswald-Regular.ttf'],
    'Raleway'         => ['/usr/share/fonts/truetype/raleway/Raleway-Regular.ttf', $ROOT_DIR . '/fonts/Raleway-Regular.ttf'],
    'Bebas Neue'      => ['/usr/share/fonts/truetype/bebas-neue/BebasNeue-Regular.ttf', $ROOT_DIR . '/fonts/BebasNeue-Regular.ttf'],
    'Roboto'          => ['/usr/share/fonts/truetype/roboto/Roboto-Regular.ttf', $ROOT_DIR . '/fonts/Roboto-Regular.ttf'],
    'Lato'            => ['/usr/share/fonts/truetype/lato/Lato-Regular.ttf', $ROOT_DIR . '/fonts/Lato-Regular.ttf'],
    'Open Sans'       => ['/usr/share/fonts/truetype/open-sans/OpenSans-Regular.ttf', $ROOT_DIR . '/fonts/OpenSans-Regular.ttf'],
    'Nunito'          => ['/usr/share/fonts/truetype/nunito/Nunito-Regular.ttf', $ROOT_DIR . '/fonts/Nunito-Regular.ttf'],
    'Dancing Script'  => ['/usr/share/fonts/truetype/dancing-script/DancingScript-Regular.ttf', $ROOT_DIR . '/fonts/DancingScript-Regular.ttf'],
    'Pacifico'        => ['/usr/share/fonts/truetype/pacifico/Pacifico-Regular.ttf', $ROOT_DIR . '/fonts/Pacifico-Regular.ttf'],
    'NotoNastaliqUrdu'=> [$ROOT_DIR . '/fonts/NotoNastaliqUrdu-Regular.ttf'],
    'AttariQuraanWord'=> [$ROOT_DIR . '/fonts/AttariQuraanWord.ttf'],
];
$FONT_BOLD_CANDIDATES = [
    'Arial'       => ['/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'],
    'Helvetica'   => ['/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'],
    'Impact'      => ['/usr/share/fonts/truetype/msttcorefonts/Impact.ttf', $ROOT_DIR . '/fonts/Impact.ttf'],
    'Poppins'     => ['/usr/share/fonts/truetype/poppins/Poppins-Bold.ttf', $ROOT_DIR . '/fonts/Poppins-Bold.ttf'],
    'Montserrat'  => ['/usr/share/fonts/truetype/montserrat/Montserrat-Bold.ttf', $ROOT_DIR . '/fonts/Montserrat-Bold.ttf'],
    'Roboto'      => ['/usr/share/fonts/truetype/roboto/Roboto-Bold.ttf', $ROOT_DIR . '/fonts/Roboto-Bold.ttf'],
    'Lato'        => ['/usr/share/fonts/truetype/lato/Lato-Bold.ttf', $ROOT_DIR . '/fonts/Lato-Bold.ttf'],
    'Inter'       => ['/usr/share/fonts/truetype/inter/Inter-Bold.ttf', $ROOT_DIR . '/fonts/Inter-Bold.ttf'],
    'Open Sans'   => ['/usr/share/fonts/truetype/open-sans/OpenSans-Bold.ttf', $ROOT_DIR . '/fonts/OpenSans-Bold.ttf'],
];
$FONT_FALLBACK = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

function resolve_candidates(array $candidates, string $fallback): string {
    foreach ($candidates as $path) {
        if ($path && file_exists($path)) return $path;
    }
    return $fallback;
}
$FONT_MAP = [];
foreach ($FONT_CANDIDATES as $name => $paths) {
    $FONT_MAP[$name] = resolve_candidates($paths, $FONT_FALLBACK);
}
$FONT_BOLD_MAP = [];
foreach ($FONT_BOLD_CANDIDATES as $name => $paths) {
    $FONT_BOLD_MAP[$name] = resolve_candidates($paths, $FONT_FALLBACK);
}

function resolve_font(array $cap, array $font_map, array $bold_map, string $fallback): string {
    $family    = trim($cap['fontfamily'] ?? 'Arial');
    $is_bold   = in_array(strtolower(trim($cap['fontweight'] ?? '')), ['bold','700','800','900']);
    $is_italic = strtolower(trim($cap['fontstyle'] ?? '')) === 'italic';

    $variant = function(string $base, bool $bold, bool $italic): string {
        $dir  = dirname($base);
        $name = basename($base, '.ttf');
        $bare = preg_replace('/-?(Regular|Bold|Italic|BoldItalic|Oblique|BoldOblique|Medium|Light|Black|Heavy)$/i', '', $name);
        if ($bold && $italic) $suffixes = ['-BoldItalic', '-Bold', '-Italic', '-Regular', ''];
        elseif ($bold)        $suffixes = ['-Bold', '-Black', '-Heavy', '-Regular', ''];
        elseif ($italic)      $suffixes = ['-Italic', '-Oblique', '-Regular', ''];
        else                  $suffixes = ['-Regular', ''];
        foreach ($suffixes as $sfx) {
            $try = $dir . '/' . $bare . $sfx . '.ttf';
            if (file_exists($try)) return $try;
        }
        return $base;
    };

    if ($is_bold && isset($bold_map[$family])) {
        $path = $variant($bold_map[$family], $is_bold, $is_italic);
        if (file_exists($path)) return $path;
    }
    if (isset($font_map[$family])) {
        $path = $variant($font_map[$family], $is_bold, $is_italic);
        if (file_exists($path)) return $path;
        if (file_exists($font_map[$family])) return $font_map[$family];
    }

    $clean = preg_replace('/[^a-zA-Z0-9]/', '', $family);
    if ($clean) {
        if ($is_bold && $is_italic) $want = ['BoldItalic','Bold','Italic','Regular'];
        elseif ($is_bold)           $want = ['Bold','Black','Heavy','Regular'];
        elseif ($is_italic)         $want = ['Italic','Oblique','Regular'];
        else                        $want = ['Regular'];
        foreach ($want as $sfx) {
            foreach (["/usr/share/fonts/truetype/*/", "/usr/share/fonts/truetype/*/*/", "/usr/local/share/fonts/*/"] as $dir) {
                foreach (["$dir{$clean}-{$sfx}.ttf", "$dir{$clean}{$sfx}.ttf"] as $pat) {
                    $hits = glob($pat) ?: [];
                    if (!empty($hits)) return $hits[0];
                }
            }
        }
        foreach (["/usr/share/fonts/truetype/*/", "/usr/share/fonts/truetype/*/*/"] as $dir) {
            $hits = glob("{$dir}{$clean}*.ttf") ?: [];
            if (!empty($hits)) return $hits[0];
        }
    }

    $safe = preg_replace('/[^a-zA-Z0-9]/', '', $family);
    if ($safe && function_exists('shell_exec')) {
        $fc = @shell_exec('fc-list : file family 2>/dev/null') ?: '';
        foreach (explode("\n", $fc) as $line) {
            if (stripos($line, $family) !== false) {
                $path = trim(substr($line, 0, (int)strpos($line . ':', ':')));
                if ($path && file_exists($path)) return $path;
            }
        }
    }

    return $fallback;
}

function ffmpeg_color(string $hex, float $opacity = 1.0): string {
    $hex = strtoupper(ltrim(trim($hex), '#'));
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) !== 6) $hex = 'FFFFFF';
    if ($opacity >= 1.0) return '#' . $hex;
    $aa = str_pad(strtoupper(dechex((int)round((1.0 - $opacity) * 255))), 2, '0', STR_PAD_LEFT);
    return '#' . $hex . $aa;
}

function ffmpeg_escape_text(string $text): string {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace("'",  "\u{2019}", $text);
    $text = str_replace(':',  '\\:',  $text);
    $text = str_replace('[',  '\\[',  $text);
    $text = str_replace(']',  '\\]',  $text);
    $text = str_replace('=',  '\\=',  $text);
    $text = str_replace('%',  '\\%',  $text);
    return $text;
}

function wrap_text(string $text, int $box_width_px, int $fontsize_px, float $char_w_mult = 0.55): array {
    $avg_char_w = $fontsize_px * $char_w_mult;
    $max_chars  = max(1, (int)floor($box_width_px / $avg_char_w));
    $lines = [];
    foreach (explode("\n", $text) as $para) {
        $para = trim($para);
        if ($para === '') { $lines[] = ''; continue; }
        $wrapped = wordwrap($para, $max_chars, "\n", true);
        foreach (explode("\n", $wrapped) as $ln) {
            $lines[] = $ln;
        }
    }
    return $lines;
}

function build_caption_filters(array $cap, array $font_map, array $bold_map, string $fallback): array {
    $text = trim($cap['text_content'] ?? '');
    // NOTE: do NOT return early here — border/bg must draw even if text is empty

    $scale = SCALE;  // 3

    $is_bold   = in_array(strtolower(trim($cap['fontweight'] ?? '')), ['bold','700','800','900']);
    $is_italic = strtolower(trim($cap['fontstyle'] ?? '')) === 'italic';
    $font = resolve_font($cap, $font_map, $bold_map, $fallback);

    $fs_preview = max(8, (int)($cap['fontsize'] ?? 22));
    $fs         = $fs_preview * $scale;

    $px = (int)($cap['position_x'] ?? 50)  * $scale;
    $py = (int)($cap['position_y'] ?? 400) * $scale;
    $bw = max(100, (int)($cap['width'] ?? 320)) * $scale;

    $px = max(0, min($px, VIDEO_W - $bw));

    $lh_mult = (float)($cap['line_height'] ?? 1.25);
    if ($lh_mult < 0.8 || $lh_mult > 4.0) $lh_mult = 1.25;
    $lh = (int)round($fs * $lh_mult);

    $pad = max(6, (int)round($fs * 0.12));

    $char_w_mult = $is_bold ? 0.63 : 0.55;
    $text_area_w = max(20, $bw - $pad * 2);
    $lines = $text !== '' ? wrap_text($text, $text_area_w, $fs, $char_w_mult) : [];

    // box height: use wrapped line count, or 1 line minimum for border-only boxes
    $bh = count($lines) > 0 ? count($lines) * $lh + $pad * 2 : $lh + $pad * 2;

    if ($py + $bh > VIDEO_H) $py = max(0, VIDEO_H - $bh);

    $color        = ffmpeg_color($cap['fontcolor'] ?? '#ffffff', 1.0);
    $talign       = strtolower(trim($cap['text_align'] ?? 'center'));

    $bg_on        = ((int)($cap['bg_enabled'] ?? 1)) === 1;
    $bg_color_hex = $cap['bg_color'] ?? '#000000';
    $bg_opacity   = max(0.0, min(1.0, (float)($cap['bg_opacity'] ?? 0.7)));

    $stroke_on    = ((int)($cap['stroke_enabled']  ?? 0)) === 1
                 || ((int)($cap['outline_enabled'] ?? 0)) === 1;
    $stroke_color = ffmpeg_color($cap['stroke_color'] ?? $cap['outline_color'] ?? '#000000', 1.0);
    $stroke_w     = max(0, (int)($cap['stroke_width'] ?? $cap['outline_width'] ?? 0)) * $scale;

    $text_effect = strtolower(trim($cap['text_effects'] ?? ''));
    $shadow_on   = in_array($text_effect, ['shadow', '3d']);
    $shadow_x    = ($text_effect === '3d') ? 3 * $scale : 2 * $scale;
    $shadow_y    = ($text_effect === '3d') ? 3 * $scale : 2 * $scale;

    $underline    = ((int)($cap['underline'] ?? 0)) === 1;
    $ul_thickness = max(1, (int)round($fs * 0.06));
    $ul_gap       = max(1, (int)round($fs * 0.08));

    $filters = [];

    // ── 1a. drawbox — background fill ─────────────────────────
    if ($bg_on) {
        $hex_clean     = '#' . strtoupper(str_pad(ltrim(trim($bg_color_hex), '#'), 6, '0'));
        $box_color_str = $hex_clean . '@' . number_format($bg_opacity, 2);
        $filters[] = sprintf(
            'drawbox=x=%d:y=%d:w=%d:h=%d:color=%s:t=fill',
            $px, $py, $bw, $bh, $box_color_str
        );
    }

    // ── 1b. drawbox — caption box border ──────────────────────
    $border_thickness = (int)($cap['caption_box_border_thickness'] ?? 0);
    $border_color_raw = trim($cap['caption_box_border_color'] ?? '');
    if ($border_thickness > 0 && $border_color_raw !== '') {
        $border_color_clean = '#' . strtoupper(str_pad(ltrim($border_color_raw, '#'), 6, '0'));
        $filters[] = sprintf(
            'drawbox=x=%d:y=%d:w=%d:h=%d:color=%s:t=%d',
            $px, $py, $bw, $bh,
            $border_color_clean,
            $border_thickness * $scale
        );
    }

    // ── exit here if no text (bg + border already queued above) ─
    if ($text === '') return $filters;

    // ── 2. drawtext (+ optional underline drawbox) per line ────
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;

        $safe_line = ffmpeg_escape_text($line);

        $ty = $py + $pad + $i * $lh;

        switch ($talign) {
            case 'left':
                $tx_expr = (string)($px + $pad);
                break;
            case 'right':
                $tx_expr = ($px + $bw - $pad) . '-text_w';
                break;
            default:
                $tx_expr = ($px + $pad) . '+((' . $text_area_w . '-text_w)/2)';
                break;
        }

        $auto_stroke    = !$bg_on && !$stroke_on;
        $eff_stroke_w   = $stroke_on ? $stroke_w : ($auto_stroke ? max(3, (int)round($fs * 0.04)) : 0);
        $eff_stroke_col = $stroke_on ? $stroke_color : '#000000';

        $parts = [
            'text='      . "'" . $safe_line . "'",
            'fontfile='  . str_replace(['\\', "'", ':', '[', ']', '='], ['\\\\', "\u{2019}", '\\:', '\\[', '\\]', '\\='], $font),
            'fontsize='  . $fs,
            'fontcolor=' . $color,
            'x='         . $tx_expr,
            'y='         . $ty,
            'box=0',
        ];

        if ($eff_stroke_w > 0) {
            $parts[] = 'borderw='     . $eff_stroke_w;
            $parts[] = 'bordercolor=' . $eff_stroke_col;
        }

        if ($shadow_on) {
            $parts[] = 'shadowx='     . $shadow_x;
            $parts[] = 'shadowy='     . $shadow_y;
            $parts[] = 'shadowcolor=0x000000CC';
        }

        $filters[] = 'drawtext=' . implode(':', $parts);

        if ($underline) {
            $ul_y = $ty + $fs + $ul_gap;
            $ul_x = $px + $pad;
            $ul_w = $text_area_w;
            $filters[] = sprintf(
                'drawbox=x=%d:y=%d:w=%d:h=%d:color=%s:t=fill',
                $ul_x, $ul_y, $ul_w, $ul_thickness,
                '#' . strtoupper(str_pad(ltrim(trim($cap['fontcolor'] ?? '#ffffff'), '#'), 6, '0')) . '@1.00'
            );
        }
    }

    return $filters;
}

function load_scene_captions(mysqli $conn, int $podcast_id, int $story_id): array {
    $result = mysqli_query($conn,
        "SELECT * FROM hdb_captions
         WHERE podcast_id = $podcast_id
           AND story_id   = $story_id
           AND is_visible  = 1
           AND media_type != 'image'
           AND (
               (text_content IS NOT NULL AND text_content != '')
               OR caption_box_border_thickness > 0
               OR bg_enabled = 1
           )
         ORDER BY z_index ASC, id ASC"
    );
    $rows = [];
    while ($r = mysqli_fetch_assoc($result)) $rows[] = $r;
    return $rows;
}

function build_vf_filter(array $captions, array $font_map, array $bold_map, string $fallback): string {
    $base = 'scale=' . VIDEO_W . ':' . VIDEO_H . ':force_original_aspect_ratio=decrease,'
          . 'pad='   . VIDEO_W . ':' . VIDEO_H . ':(ow-iw)/2:(oh-ih)/2:black,setsar=1';

    $all_filters = [];
    foreach ($captions as $cap) {
        foreach (build_caption_filters($cap, $font_map, $bold_map, $fallback) as $f) {
            $all_filters[] = $f;
        }
    }

    if (empty($all_filters)) return $base;
    return $base . ',' . implode(',', $all_filters);
}

function download_file(string $url, string $dest, int $timeout = 300): bool {
    if (function_exists('curl_init')) {
        $fp = fopen($dest, 'wb');
        if (!$fp) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_USERAGENT       => 'VideoVizard-Stitch/1.0',
            CURLOPT_SSL_VERIFYPEER  => false,
        ]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($http_code !== 200 || $error || !file_exists($dest) || filesize($dest) < 1000) {
            @unlink($dest);
            return false;
        }
        return true;
    }
    // Fallback to file_get_contents for small files only
    $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => true]]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data || strlen($data) < 1000) return false;
    file_put_contents($dest, $data);
    return true;
}

// ══════════════════════════════════════════════════════════════
// HELPER: get audio duration in seconds via ffprobe
// ══════════════════════════════════════════════════════════════
function get_audio_duration(string $ffmpeg, string $audio_path): float {
    $ffprobe = str_replace('ffmpeg', 'ffprobe', $ffmpeg);
    if (!file_exists($ffprobe)) $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?: '/usr/bin/ffprobe');
    $cmd = escapeshellcmd($ffprobe)
         . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
         . escapeshellarg($audio_path)
         . ' 2>/dev/null';
    $dur = (float)trim(shell_exec($cmd) ?: '0');
    return $dur > 0.1 ? $dur : 5.0;
}

// ══════════════════════════════════════════════════════════════
// HELPER: convert image to MP4 with Ken Burns pan/zoom effect
// ══════════════════════════════════════════════════════════════
function image_to_video(
    string $ffmpeg,
    string $image_path,
    ?string $audio_path,
    float $duration,
    string $out_path,
    array $captions,
    array $font_map,
    array $bold_map,
    string $font_fallback,
    int $scene_index = 0
): array {
    // Rule: every scene is a fixed SCENE_DURATION_SEC long, narrated or
    // not — $duration is passed in already set to that. No extra
    // hang-time is tacked on for narration; narration that's shorter
    // gets padded with silence below, narration that's longer gets cut
    // off, same as the video-scene branch.
    $has_audio = ($audio_path && file_exists($audio_path));

    $fps    = 25;
    $frames = (int)ceil($duration * $fps);
    $W      = VIDEO_W;   // 1080
    $H      = VIDEO_H;   // 1920

    // ── Ken Burns variants ────────────────────────────────────────────────────
    // zoompan expressions must NOT contain single quotes — they are passed
    // inside a shell-escaped -vf string. Use arithmetic only, no if()/min()/max()
    // in the z/x/y expressions — instead use the on/d form which is pure math.
    //
    // WHY THE OLD "scale=8000:-1,zoompan=...s=1080x1920" APPROACH STRETCHED:
    // zoompan always samples a region shaped like its INPUT's aspect ratio
    // (here, the source photo's own ratio, e.g. 16:9) — z/x/y only change the
    // SIZE of that sample, never its shape. The final s=1080x1920 then
    // force-resizes that 16:9-shaped sample into a 9:16 box, which is a
    // non-uniform stretch by definition, no matter how big the pre-scale is.
    //
    // FIX: crop to the TARGET aspect ratio first (panning happens in this
    // crop's x-offset), so by the time zoompan/the final resize runs, the
    // shape already matches 1080:1920 and nothing gets squished — only
    // scaled down uniformly. This is also why it was slow: scaling every
    // frame up to 8000px wide (~36MP) before doing anything else was ~17x
    // more pixels than the 1080x1920 (~2MP) output needed.
    //
    //   ZB       = scaled canvas height (px) — fixed regardless of source
    //              photo's own resolution/aspect, gives zoom headroom only
    //   crop_w   = ZB * (W/H) — the 9:16-shaped slice width at that height
    //   in_w     = crop filter's input width (post-scale, varies by source
    //              photo's original aspect ratio — that's fine, it's only
    //              used to compute how far the pan can travel)
    //   n / $f   = current frame / total frame count, for linear panning
    //   iw/ih    = post-crop width/height inside zoompan (now == crop_w/ZB,
    //              i.e. already 9:16 — so zoompan's zoom is a clean scale)
    $ZB     = 2400; // tall scale target — big enough for crisp zoom, small
                     // enough to render fast (vs. the old 8000px-wide canvas)
    $crop_w = (int)round($ZB * $W / $H); // e.g. 2400 * 1080/1920 = 1350

    $pan_l2r  = "(in_w-{$crop_w})*n/{$frames}";
    $pan_r2l  = "(in_w-{$crop_w})*({$frames}-n)/{$frames}";
    $pan_none = "(in_w-{$crop_w})/2";

    $z_in_slow  = "min(zoom+0.0006\\,1.10)";
    $z_in_fast  = "min(zoom+0.0010\\,1.12)";
    $z_out      = "1.12-0.0010*on";
    $xy_centre  = "x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2)";
    $zp_base    = "s={$W}x{$H}:fps={$fps}:d={$frames}";
    $pre        = "scale=-2:{$ZB}"; // tall scale, aspect preserved, no stretch

    $variants = [
        // 0: pan left → right, zooming in slowly
        // NOTE: crop's x/y expressions are already re-evaluated every frame
        // when they reference 'n' — the explicit ":eval=frame" suffix used
        // to be here but isn't a valid crop option on this ffmpeg build
        // (6.1.1): it threw "Error applying option 'eval' to filter 'crop':
        // Option not found", which silently killed every panning variant
        // (0-3) at the filtergraph-init stage — exactly the scenes that
        // landed on those variants (scene_index % 6 < 4) were getting
        // dropped from the final stitch with zero error surfaced to the UI.
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_l2r}:y=0,zoompan=z={$z_in_slow}:{$xy_centre}:{$zp_base}",
        // 1: pan right → left, zooming in slowly
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_r2l}:y=0,zoompan=z={$z_in_slow}:{$xy_centre}:{$zp_base}",
        // 2: pan left → right, zooming in fast
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_l2r}:y=0,zoompan=z={$z_in_fast}:{$xy_centre}:{$zp_base}",
        // 3: pan right → left, zooming in fast
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_r2l}:y=0,zoompan=z={$z_in_fast}:{$xy_centre}:{$zp_base}",
        // 4: centred, slow zoom in (no pan)
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_none}:y=0,zoompan=z={$z_in_slow}:{$xy_centre}:{$zp_base}",
        // 5: centred, zoom out (no pan)
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_none}:y=0,zoompan=z={$z_out}:{$xy_centre}:{$zp_base}",
    ];
    $ken_burns = $variants[$scene_index % count($variants)];

    // ── Build caption filters on top ─────────────────────────────────────────
    $cap_filters = [];
    foreach ($captions as $cap) {
        foreach (build_caption_filters($cap, $font_map, $bold_map, $font_fallback) as $f) {
            $cap_filters[] = $f;
        }
    }
    // Append setsar so pixel aspect ratio is correct after zoompan
    $vf = $ken_burns . ',setsar=1' . ($cap_filters ? ',' . implode(',', $cap_filters) : '');

    // ── Write a temp script file to avoid shell quoting issues ───────────────
    // Pass the -vf value via a shell variable substitution to avoid
    // single-quote conflicts with zoompan expressions.
    $script = sys_get_temp_dir() . '/kb_' . getmypid() . '_' . $scene_index . '.sh';
    $t      = number_format($duration, 3, '.', '');

    if ($audio_path && file_exists($audio_path)) {
        $script_body = "#!/bin/sh
"
            . escapeshellcmd($ffmpeg) . " -y"
            . " -loop 1 -framerate {$fps} -i " . escapeshellarg($image_path)
            . " -i " . escapeshellarg($audio_path)
            . " -vf " . escapeshellarg($vf)
            // Pad narration with silence up to the full fixed scene
            // duration if it's shorter; -t below cuts it off if it's
            // longer. Either way the scene always comes out at exactly
            // SCENE_DURATION_SEC.
            . " -af " . escapeshellarg("apad=whole_dur={$t}")
            . " -t {$t}"
            . " -c:v libx264 -preset fast -crf 26 -pix_fmt yuv420p"
            . " -c:a aac -b:a 128k -ar 44100 -ac 2"
            . " -movflags +faststart"
            . " " . escapeshellarg($out_path)
            . " 2>&1
";
    } else {
        // No narration for this scene. Still attach a silent AAC track
        // (instead of -an) so every clip in the concat list — image or
        // video, narrated or not — has an identical stream layout
        // (1 video + 1 audio). The final concat step uses "-c copy",
        // which is unreliable/lossy when segments don't all have the
        // same streams — a silent-audio scene next to a narrated one
        // can knock audio out of sync for everything after it.
        $script_body = "#!/bin/sh
"
            . escapeshellcmd($ffmpeg) . " -y"
            . " -loop 1 -framerate {$fps} -i " . escapeshellarg($image_path)
            . " -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100"
            . " -vf " . escapeshellarg($vf)
            . " -map 0:v:0 -map 1:a:0"
            . " -t {$t}"
            . " -c:v libx264 -preset fast -crf 26 -pix_fmt yuv420p"
            . " -c:a aac -b:a 128k -ar 44100 -ac 2"
            . " -shortest"
            . " -movflags +faststart"
            . " " . escapeshellarg($out_path)
            . " 2>&1
";
    }

    file_put_contents($script, $script_body);
    chmod($script, 0755);

    $out = []; $rc = 0;
    exec('/bin/sh ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
    @unlink($script);

    return ['exit' => $rc, 'output' => $out, 'cmd' => $script_body];
}

// ══════════════════════════════════════════════════════════════
// AJAX: debug
// ══════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'debug') {
    while (ob_get_level()) ob_end_clean();
    error_reporting(0); ini_set('display_errors', '0');
    header('Content-Type: application/json');
    $pid = (int)($_POST['podcast_id'] ?? 0);
    $sq  = mysqli_query($conn,
        "SELECT s.seq_no, s.id AS story_id, s.image_file, s.image_folder, s.audio_file
         FROM hdb_podcast_stories s
         WHERE s.podcast_id=$pid
         ORDER BY s.seq_no ASC, s.id ASC");
    $files = [];
    while ($s = mysqli_fetch_assoc($sq)) {
        $vfile  = trim($s['image_file'] ?? '');
        $folder = clean_folder($s['image_folder'] ?? 'podcast_videos');
        $vabs   = $ROOT_DIR . '/' . $folder . '/' . $vfile;
        $afile  = trim($s['audio_file'] ?? '');
        $aabs   = $afile ? $AUDIO_DIR . '/' . $afile : null;
        $captions = load_scene_captions($conn, $pid, (int)$s['story_id']);
        $files[] = [
            'seq'          => $s['seq_no'],
            'story_id'     => $s['story_id'],
            'video_file'   => $vfile,
            'video_abs'    => $vabs,
            'video_exists' => file_exists($vabs),
            'audio_file'   => $afile,
            'audio_abs'    => $aabs,
            'audio_exists' => $aabs ? file_exists($aabs) : false,
            'caption_count'=> count($captions),
            'captions'     => array_map(fn($c) => [
                'id'                         => $c['id'],
                'type'                       => $c['caption_type'],
                'name'                       => $c['caption_name'],
                'text'                       => mb_substr($c['text_content'] ?? '', 0, 60),
                'x'                          => $c['position_x'],
                'y'                          => $c['position_y'],
                'font'                       => $c['fontfamily'],
                'size'                       => $c['fontsize'],
                'color'                      => $c['fontcolor'],
                'bg_enabled'                 => $c['bg_enabled'],
                'bg_color'                   => $c['bg_color'],
                'bg_opacity'                 => $c['bg_opacity'],
                'fontweight'                 => $c['fontweight'],
                'fontstyle'                  => $c['fontstyle'],
                'border_thickness'           => $c['caption_box_border_thickness'],
                'border_color'               => $c['caption_box_border_color'],
                'resolved_font'              => resolve_font($c, $FONT_MAP, $FONT_BOLD_MAP, $FONT_FALLBACK),
                'font_exists'                => file_exists(resolve_font($c, $FONT_MAP, $FONT_BOLD_MAP, $FONT_FALLBACK)),
            ], $captions),
        ];
    }
    $sample_vf = '';
    if (!empty($files)) {
        $first_story = (int)($files[0]['story_id'] ?? 0);
        $all_caps    = load_scene_captions($conn, $pid, $first_story);
        $sample_vf   = build_vf_filter($all_caps, $FONT_MAP, $FONT_BOLD_MAP, $FONT_FALLBACK);
    }
    $fc_raw  = shell_exec('fc-list : file family 2>/dev/null') ?: '';
    $fc_list = [];
    foreach (explode("\n", trim($fc_raw)) as $ln) {
        $ln = trim($ln);
        if ($ln) $fc_list[] = $ln;
    }
    $ttf_files = array_merge(
        glob('/usr/share/fonts/truetype/*.ttf')    ?: [],
        glob('/usr/share/fonts/truetype/*/*.ttf')  ?: [],
        glob('/usr/share/fonts/truetype/*/*/*.ttf') ?: [],
        glob('/usr/local/share/fonts/*.ttf')       ?: [],
        glob('/usr/local/share/fonts/*/*.ttf')     ?: []
    );
    $pod_dbg = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT music_file, music_folder, music_volume, voice_volume FROM hdb_podcasts WHERE id=$pid LIMIT 1"));
    echo json_encode([
        'root_dir'             => $ROOT_DIR,
        'audio_dir'            => $AUDIO_DIR,
        'ffmpeg'               => $FFMPEG,
        'ffmpeg_found'         => file_exists($FFMPEG),
        'exec_enabled'         => function_exists('exec'),
        'preview_canvas'       => PREVIEW_W . 'x' . PREVIEW_H,
        'output_video'         => VIDEO_W   . 'x' . VIDEO_H,
        'scale_factor'         => SCALE,
        'font_fallback'        => $FONT_FALLBACK,
        'font_fallback_exists' => file_exists($FONT_FALLBACK),
        'music_file'           => $pod_dbg['music_file']   ?? 'NOT SET',
        'music_folder'         => $pod_dbg['music_folder'] ?? 'NOT SET',
        'music_volume'         => $pod_dbg['music_volume'] ?? 'NOT SET',
        'voice_volume'         => $pod_dbg['voice_volume'] ?? 'NOT SET',
        'music_local_path'     => $ROOT_DIR . '/podcast_music/' . basename($pod_dbg['music_file'] ?? ''),
        'music_local_exists'   => file_exists($ROOT_DIR . '/podcast_music/' . basename($pod_dbg['music_file'] ?? '')),
        'sample_vf_scene1'     => $sample_vf,
        'fc_list_count'        => count($fc_list),
        'fc_list'              => $fc_list,
        'ttf_files_found'      => $ttf_files,
        'files'                => $files,
    ], JSON_PRETTY_PRINT);
    exit;
}

// ══════════════════════════════════════════════════════════════
// AJAX: get_scenes
// ══════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'get_scenes') {
    while (ob_get_level()) ob_end_clean();
    error_reporting(0); ini_set('display_errors', '0');
    header('Content-Type: application/json');
    $pid = (int)($_POST['podcast_id'] ?? 0);
    if (!$pid) { echo json_encode(['success' => false, 'error' => 'No podcast_id']); exit; }

    $chk = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title FROM hdb_podcasts WHERE id=$pid AND admin_id=$admin_id LIMIT 1"));
    if (!$chk) { echo json_encode(['success' => false, 'error' => 'Podcast not found']); exit; }

    $sq = mysqli_query($conn,
        "SELECT id, seq_no, image_file, image_folder, audio_file FROM hdb_podcast_stories
         WHERE podcast_id=$pid
         ORDER BY seq_no ASC, id ASC");
    $scenes = [];
    while ($s = mysqli_fetch_assoc($sq)) {
        $pid_int = $pid;
        $sid     = (int)$s['id'];
        $caps    = load_scene_captions($conn, $pid_int, $sid);
        $s['caption_count'] = count($caps);
        $scenes[] = $s;
    }

    if (!$scenes) { echo json_encode(['success' => false, 'error' => 'No scenes found']); exit; }
    echo json_encode(['success' => true, 'scenes' => $scenes, 'title' => $chk['title']]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// AJAX: start_stitch
// ══════════════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'start_stitch') {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    error_reporting(0);
    ini_set('display_errors', '0');
    header('Content-Type: application/json');
    set_time_limit(1800);  // 30 min for large UHD downloads
    ini_set('memory_limit', '512M');

    $pid = (int)($_POST['podcast_id'] ?? 0);
    if (!$pid) { echo json_encode(['success' => false, 'error' => 'No podcast_id']); exit; }

    $chk = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_podcasts WHERE id=$pid AND admin_id=$admin_id LIMIT 1"));
    if (!$chk) { echo json_encode(['success' => false, 'error' => 'Podcast not found']); exit; }

    try {

    // ── Always use the configured site URL for downloading media ─────────────
    $base_url = SITE_BASE_URL;
    $music_file = $music_folder = '';
    $music_volume = 0.30; $voice_volume = 1.00;
    $pod_q = mysqli_query($conn,
        "SELECT music_file, music_folder,
                IFNULL(music_volume, 0.30) AS music_volume,
                IFNULL(voice_volume, 1.00) AS voice_volume
         FROM hdb_podcasts WHERE id=$pid LIMIT 1");
    if ($pod_q && $pod = mysqli_fetch_assoc($pod_q)) {
        $music_file   = trim($pod['music_file']   ?? '');
        $music_folder = trim($pod['music_folder'] ?? '');
        $music_volume = (float)($pod['music_volume'] ?? 0.30);
        $voice_volume = (float)($pod['voice_volume'] ?? 1.00);
        // Normalize: if stored as percentage (0-100) convert to decimal (0.0-1.0)
        if ($music_volume > 2.0) $music_volume = $music_volume / 100.0;
        if ($voice_volume > 2.0) $voice_volume = $voice_volume / 100.0;
    } else {
        // Columns may not exist yet — try basic query
        $pod_q2 = mysqli_query($conn,
            "SELECT music_file, music_folder FROM hdb_podcasts WHERE id=$pid LIMIT 1");
        if ($pod_q2 && $pod2 = mysqli_fetch_assoc($pod_q2)) {
            $music_file   = trim($pod2['music_file']   ?? '');
            $music_folder = trim($pod2['music_folder'] ?? '');
        }
    }

    // Resolve music absolute path — download from web server if not local
    $music_abs  = '';
    $music_tmp  = ''; // temp downloaded music file to clean up later
    if ($music_file) {
        if ($music_folder && $music_folder !== 'podcast_music') {
            $try1 = $ROOT_DIR . '/user_media/' . rtrim($music_folder, '/') . '/' . basename($music_file);
            $try2 = $ROOT_DIR . '/' . rtrim($music_folder, '/') . '/' . basename($music_file);
            $music_abs = file_exists($try1) ? $try1 : (file_exists($try2) ? $try2 : '');
            // Remote URL for personal folder
            $music_remote = $base_url . 'user_media/' . rtrim($music_folder, '/') . '/' . basename($music_file);
        } else {
            $try = $ROOT_DIR . '/podcast_music/' . basename($music_file);
            $music_abs = file_exists($try) ? $try : '';
            $music_remote = $base_url . 'podcast_music/' . basename($music_file);
        }

        // Not found locally — download it
        if (!$music_abs && $base_url !== '/') {
            $ffmpeg_logs[] = "Downloading music: $music_remote";
            $music_tmp = sys_get_temp_dir() . '/music_' . $pid . '_' . basename($music_file);
            if (download_file($music_remote, $music_tmp, 120)) {
                $music_abs = $music_tmp;
                $ffmpeg_logs[] = "  → Music downloaded: " . round(filesize($music_tmp)/1048576, 2) . " MB";
            } else {
                @unlink($music_tmp);
                $music_tmp = '';
                $ffmpeg_logs[] = "  → FAILED to download music: $music_remote";
            }
        }
    }

    $sq = mysqli_query($conn,
        "SELECT id AS story_id, seq_no, image_file, image_folder, audio_file, duration
         FROM hdb_podcast_stories
         WHERE podcast_id=$pid
         ORDER BY seq_no ASC, id ASC");

    $tmp_videos = []; // track downloaded/generated files to clean up after
    $skip_log   = []; // track WHY each scene was excluded, for diagnosing empty-scenes failures

    $scenes = [];
    while ($s = mysqli_fetch_assoc($sq)) {
        $vfile    = trim($s['image_file'] ?? '');
        $folder   = clean_folder($s['image_folder'] ?? 'podcast_images');
        $vabs     = $ROOT_DIR . '/' . $folder . '/' . $vfile;
        $afile    = trim($s['audio_file'] ?? '');
        $aabs     = $afile ? $AUDIO_DIR . '/' . basename($afile) : null;
        $story_id = (int)$s['story_id'];

        if (!$vfile) {
            $skip_log[] = "Scene {$s['seq_no']} (story {$story_id}): SKIPPED — image_file column is empty";
            continue;
        }

        $is_video = (bool)preg_match('/\.mp4$/i', $vfile);
        $is_image = (bool)preg_match('/\.(jpg|jpeg|png|webp|gif|bmp|tiff?|heic|avif)$/i', $vfile);

        if (!$is_video && !$is_image) {
            $skip_log[] = "Scene {$s['seq_no']} (story {$story_id}): SKIPPED — unrecognized file extension on '$vfile'";
            continue;
        }

        // ── Download audio if missing locally ─────────────────
        if ($afile && $aabs && !file_exists($aabs)) {
            $remote_aud = $base_url . 'podcast_audios/' . basename($afile);
            download_file($remote_aud, $aabs, 60);
        }
        $aabs_ok = ($aabs && file_exists($aabs)) ? $aabs : null;

        // ── For image scenes: resolve the best available source file ──────────
        // Images are stored as thumbnails in podcast_thumbnails/ after upload.
        // For Ken Burns we need a full-size image. Try to resolve the original:
        //   1. File exists as-is (already full size, e.g. from podcast_images/)
        //   2. Strip _thumb suffix → look in user_media/, the scene's OWN
        //      image_folder, then the legacy flat podcast_images/ fallback
        //   3. Download original from web server — same folder priority
        //   4. Fall back to the thumbnail itself (still works for Ken Burns)
        if ($is_image && !file_exists($vabs)) {
            $resolved = false;

            // Try stripping _thumb to find the original
            $orig_file = preg_replace('/_thumb(\.[^.]+)$/', '$1', $vfile);
            if ($orig_file !== $vfile) {
                // Look for original in user_media folders (any user) — broad safety net
                foreach (glob($ROOT_DIR . '/user_media/*/' . $orig_file) ?: [] as $candidate) {
                    if (file_exists($candidate) && filesize($candidate) > 5000) {
                        $vabs    = $candidate;
                        $vfile   = $orig_file;
                        $resolved = true;
                        $ffmpeg_logs[] = "Scene {$s['seq_no']} [IMAGE] resolved original: $vabs";
                        break;
                    }
                }
                // Try the scene's OWN folder (image_folder is per-scene and
                // may be a nested user_media/... path, not always the flat
                // podcast_images/ — this was the actual bug: it was never
                // tried at all here before).
                if (!$resolved) {
                    $try = $ROOT_DIR . '/' . $folder . '/' . $orig_file;
                    if (file_exists($try) && filesize($try) > 5000) {
                        $vabs    = $try;
                        $vfile   = $orig_file;
                        $resolved = true;
                        $ffmpeg_logs[] = "Scene {$s['seq_no']} [IMAGE] resolved from scene folder ($folder): $vabs";
                    }
                }
                // Legacy flat fallback for older rows that really do live at the root
                if (!$resolved) {
                    $try = $ROOT_DIR . '/podcast_images/' . $orig_file;
                    if (file_exists($try) && filesize($try) > 5000) {
                        $vabs    = $try;
                        $vfile   = $orig_file;
                        $resolved = true;
                        $ffmpeg_logs[] = "Scene {$s['seq_no']} [IMAGE] resolved from podcast_images: $vabs";
                    }
                }
            }

            // Not found locally — download from web server
            if (!$resolved && $base_url !== '/') {
                // Try the scene's own folder FIRST (the actually-correct
                // one — combining image_folder + image_file/orig_file is
                // what was missing), THEN the legacy flat folders/thumbnail
                // as a fallback for older rows.
                $candidates_to_try = [];
                if ($orig_file !== $vfile) {
                    $candidates_to_try[] = [$base_url . $folder . '/' . $orig_file,     $orig_file];
                    $candidates_to_try[] = [$base_url . 'podcast_images/' . $orig_file, $orig_file];
                    $candidates_to_try[] = [$base_url . $folder . '/' . $vfile,         $vfile];
                    $candidates_to_try[] = [$base_url . 'podcast_thumbnails/' . $vfile, $vfile];
                } else {
                    $candidates_to_try[] = [$base_url . $folder . '/' . $vfile, $vfile];
                }
                foreach ($candidates_to_try as [$remote_url, $fname]) {
                    $tmp_dest = sys_get_temp_dir() . '/dl_' . $pid . '_' . $story_id . '_' . basename($fname);
                    $ffmpeg_logs[] = "Scene {$s['seq_no']} [IMAGE] downloading: $remote_url";
                    if (download_file($remote_url, $tmp_dest, 120)) {
                        $vabs = $tmp_dest;
                        $tmp_videos[] = $tmp_dest;
                        $ffmpeg_logs[] = "  -> Downloaded: " . round(filesize($tmp_dest)/1048576, 2) . " MB";
                        $resolved = true;
                        break;
                    } else {
                        @unlink($tmp_dest);
                        $ffmpeg_logs[] = "  -> FAILED: $remote_url";
                    }
                }
            }

            if (!$resolved) {
                $msg = "Scene {$s['seq_no']} (story {$story_id}) [IMAGE] SKIPPED — could not resolve image file '$vfile' (tried: $vabs)";
                $ffmpeg_logs[] = $msg;
                $skip_log[]    = $msg;
                continue;
            }
        }

        // ── For video scenes: download if not local ───────────────────────────
        if ($is_video && !file_exists($vabs) && $base_url !== '/') {
            $remote_url = $base_url . $folder . '/' . $vfile;
            $tmp_dest   = sys_get_temp_dir() . '/dl_' . $pid . '_' . $story_id . '_' . basename($vfile);
            $ffmpeg_logs[] = "Downloading scene {$s['seq_no']} [VIDEO]: $remote_url";
            if (download_file($remote_url, $tmp_dest, 300)) {
                $vabs = $tmp_dest;
                $tmp_videos[] = $tmp_dest;
                $ffmpeg_logs[] = "  -> Downloaded: " . round(filesize($tmp_dest)/1048576, 1) . " MB";
            } else {
                $msg = "Scene {$s['seq_no']} (story {$story_id}) [VIDEO] SKIPPED — failed to download $remote_url";
                $ffmpeg_logs[] = "  -> FAILED to download $remote_url";
                $skip_log[]    = $msg;
                @unlink($tmp_dest);
                continue;
            }
        }

        if (!file_exists($vabs)) {
            $skip_log[] = "Scene {$s['seq_no']} (story {$story_id}): SKIPPED — final path does not exist: $vabs";
            continue;
        }

        $captions = load_scene_captions($conn, $pid, $story_id);

        $scenes[] = [
            'seq'      => $s['seq_no'],
            'story_id' => $story_id,
            'vabs'     => $vabs,
            'aabs'     => $aabs_ok,
            'captions' => $captions,
            'is_image' => $is_image,
            'duration' => (float)($s['duration'] ?? 0),
        ];
    }

    if (empty($scenes)) {
        // Collect what we tried for debugging
        $sq2 = mysqli_query($conn,
            "SELECT image_file, image_folder FROM hdb_podcast_stories
             WHERE podcast_id=$pid
             ORDER BY seq_no ASC LIMIT 3");
        $tried = [];
        while ($r = mysqli_fetch_assoc($sq2)) {
            $f  = clean_folder($r['image_folder'] ?? 'podcast_videos');
            $fn = trim($r['image_file'] ?? '');
            $p  = $ROOT_DIR . '/' . $f . '/' . $fn;
            $tried[] = $p . ' → ' . (file_exists($p) ? 'EXISTS' : 'NOT FOUND');
        }
        $debug_url = '';
        try {
            $debug_dir = $ROOT_DIR . '/debug_logs';
            if (!is_dir($debug_dir)) @mkdir($debug_dir, 0755, true);
            $debug_file = $debug_dir . '/stitch_' . $pid . '_' . time() . '.txt';
            @file_put_contents($debug_file,
                "SKIP LOG (podcast $pid)\n\n" . implode("\n", $skip_log)
                . "\n\nCHECKED PATHS\n\n" . implode("\n", $tried));
            $debug_url = reachable_base_url() . '/debug_logs/' . basename($debug_file);
        } catch (Throwable $e) { /* ignore — log writing is best-effort */ }

        echo json_encode([
            'success'  => false,
            'error'    => 'No valid MP4 scene files found on disk',
            'root_dir' => $ROOT_DIR,
            'checked'  => $tried,
            'skip_log' => $skip_log,
            'debug_log_url' => $debug_url,
        ]);
        exit;
    }

    $tmp_clips   = [];
    // NOTE: was previously `$ffmpeg_logs = [];` here, which wiped out every
    // resolution-phase log line (image/video download attempts, failures,
    // resolved paths) from lines above right before the encoding phase —
    // so even on a successful stitch, there was no way to see why some
    // scenes silently dropped out. Now it just keeps accumulating.
    $t_start     = microtime(true);

    foreach ($scenes as $i => $scene) {
        $tmp = sys_get_temp_dir() . '/clip_' . $pid . '_' . $i . '.mp4';

        $captions   = $scene['captions'];
        $has_audio  = $scene['aabs'] ? 'with audio' : 'silent';
        $cap_count  = count($captions);

        // ── IMAGE SCENE: Ken Burns pan/zoom → MP4 ────────────
        if ($scene['is_image']) {
            // Rule: every scene is exactly SCENE_DURATION_SEC, regardless
            // of narration length or any stored per-scene duration.
            // Narration shorter than this is padded with silence;
            // narration longer than this is cut off (handled inside
            // image_to_video()).
            $duration = (float)SCENE_DURATION_SEC;

            $ffmpeg_logs[] = "Scene {$scene['seq']} [IMAGE] duration={$duration}s variant=" . ($i % 6) . " $has_audio {$cap_count} captions";

            $result = image_to_video(
                $FFMPEG,
                $scene['vabs'],
                $scene['aabs'],
                $duration,
                $tmp,
                $captions,
                $FONT_MAP,
                $FONT_BOLD_MAP,
                $FONT_FALLBACK,
                $i
            );
            $rc  = $result['exit'];
            $out = $result['output'];

            $ffmpeg_logs[] = "Scene {$scene['seq']} [IMAGE] exit=$rc: " . implode(' | ', array_slice($out, -3));
            if ($rc !== 0) {
                $ffmpeg_logs[] = "  Full output: " . implode(' ', $out);
            }

        // ── VIDEO SCENE: trim to audio length + burn captions ─
        } else {
            $vf = build_vf_filter($captions, $FONT_MAP, $FONT_BOLD_MAP, $FONT_FALLBACK);
            $sd = number_format(SCENE_DURATION_SEC, 3, '.', '');

            // Rule: every scene is exactly SCENE_DURATION_SEC, narrated or
            // not — no extra hang-time tacked on past that. tpad/apad pad
            // video+audio up to the full duration if the source clip or
            // narration is shorter; the trailing -t truncates everything
            // back to exactly SCENE_DURATION_SEC either way.
            $vf_pad = $vf . ',tpad=stop_mode=clone:stop_duration=' . $sd;

            if ($scene['aabs']) {
                $ffmpeg_logs[] = "Scene {$scene['seq']} [VIDEO] VF: " . $vf_pad;

                $clip_cmd = escapeshellcmd($FFMPEG)
                    . ' -y'
                    . ' -ss 0 -t ' . $sd . ' -i ' . escapeshellarg($scene['vabs'])
                    . ' -ss 0 -t ' . $sd . ' -i ' . escapeshellarg($scene['aabs'])
                    . ' -vf ' . escapeshellarg($vf_pad)
                    . ' -af ' . escapeshellarg("apad=whole_dur={$sd}")
                    . ' -t ' . $sd
                    // Lock to the same 25fps every image-derived scene uses —
                    // source video clips otherwise keep their own native fps
                    // (24/30/60...), which breaks sync once concatenated with
                    // "-c copy" against the 25fps image scenes.
                    . ' -r 25'
                    . ' -c:v libx264 -preset fast -crf 28 -pix_fmt yuv420p'
                    . ' -c:a aac -b:a 128k -ar 44100 -ac 2'
                    . ' -movflags +faststart'
                    . ' ' . escapeshellarg($tmp)
                    . ' 2>&1';
            } else {
                // No narration for this scene. Add a silent AAC track
                // (instead of -an) so this clip has the same 1 video + 1
                // audio stream layout as every other clip in the concat
                // list — mixing audio-less clips in with narrated ones is
                // a classic cause of "-c copy" concat desyncing or dropping
                // audio partway through the final stitched video.
                $ffmpeg_logs[] = "Scene {$scene['seq']} [VIDEO] VF: " . $vf_pad;
                $clip_cmd = escapeshellcmd($FFMPEG)
                    . ' -y'
                    . ' -ss 0 -t ' . $sd . ' -i ' . escapeshellarg($scene['vabs'])
                    . ' -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100'
                    . ' -vf ' . escapeshellarg($vf_pad)
                    . ' -map 0:v:0 -map 1:a:0'
                    . ' -t ' . $sd
                    . ' -r 25'
                    . ' -c:v libx264 -preset fast -crf 28 -pix_fmt yuv420p'
                    . ' -c:a aac -b:a 128k -ar 44100 -ac 2'
                    . ' -shortest'
                    . ' -movflags +faststart'
                    . ' ' . escapeshellarg($tmp)
                    . ' 2>&1';
            }

            $out = []; $rc = 0;
            exec($clip_cmd, $out, $rc);

            $ffmpeg_logs[] = "Scene {$scene['seq']} [VIDEO] ($has_audio, {$cap_count} captions, exit $rc): "
                           . implode(' | ', array_slice($out, -3));
            if ($rc !== 0) {
                $ffmpeg_logs[] = "  Full output: " . implode(' ', $out);
            }
        }

        if ($rc === 0 && file_exists($tmp) && filesize($tmp) > 0) {
            $tmp_clips[] = $tmp;
        }
    }
    if (empty($tmp_clips)) {
        $debug_url = '';
        try {
            $debug_dir = $ROOT_DIR . '/debug_logs';
            if (!is_dir($debug_dir)) @mkdir($debug_dir, 0755, true);
            $debug_file = $debug_dir . '/stitch_' . $pid . '_' . time() . '.txt';
            @file_put_contents($debug_file, "FFMPEG LOG (podcast $pid)\n\n" . implode("\n", $ffmpeg_logs));
            $debug_url = reachable_base_url() . '/debug_logs/' . basename($debug_file);
        } catch (Throwable $e) { /* ignore — log writing is best-effort */ }

        echo json_encode([
            'success'     => false,
            'error'       => 'All clips failed to encode',
            'log'         => implode("\n", $ffmpeg_logs),
            'debug_log_url' => $debug_url,
            'elapsed_sec' => round(microtime(true) - $t_start, 1),
        ]);
        exit;
    }

    $list_file  = sys_get_temp_dir() . '/stitch_' . $pid . '_' . time() . '.txt';
    $out_dir    = $ROOT_DIR . '/published_videos';
    if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);
    $output_mp4 = $out_dir . '/podcast_' . $pid . '.mp4';

    $list = '';
    foreach ($tmp_clips as $p) {
        $list .= "file '" . str_replace("'", "'\\''", $p) . "'\n";
    }
    file_put_contents($list_file, $list);

    // Remove stale output first — ffmpeg -y truncates in place and would hit
    // "Permission denied" if the existing file is owned by another user (e.g.
    // www-data) without world-write. The dir is world-writable (no sticky bit),
    // so unlink succeeds for any process user; ffmpeg then creates a fresh file.
    if (file_exists($output_mp4)) @unlink($output_mp4);

    $cmd = escapeshellcmd($FFMPEG)
         . ' -y'
         . ' -f concat -safe 0'
         . ' -i ' . escapeshellarg($list_file)
         . ' -c copy'
         . ' -movflags +faststart'
         . ' ' . escapeshellarg($output_mp4)
         . ' 2>&1';

    $ffmpeg_out = []; $exit_code = 0;
    exec($cmd, $ffmpeg_out, $exit_code);

    // ── Detailed error log ────────────────────────────────────────────────────
    $log_path  = __DIR__ . '/a_errors.log';
    $log_entry = "[" . date('Y-m-d H:i:s') . "] podcast=$pid exit=$exit_code\n"
               . "CMD: $cmd\n"
               . "OUT: " . implode("\n", $ffmpeg_out) . "\n"
               . "out_dir=" . $out_dir . " exists=" . (is_dir($out_dir)?'yes':'no') . " writable=" . (is_writable($out_dir)?'yes':'no') . "\n"
               . "clips=" . count($tmp_clips) . " list=$list_file\n"
               . str_repeat("-", 80) . "\n";
    file_put_contents($log_path, $log_entry, FILE_APPEND);

    foreach ($tmp_clips as $t) @unlink($t);
    foreach ($tmp_videos as $t) @unlink($t);
    @unlink($list_file);

    $elapsed = round(microtime(true) - $t_start, 1);
    $log     = implode("\n", array_merge($ffmpeg_logs, $ffmpeg_out));

    if ($exit_code !== 0 || !file_exists($output_mp4)) {
        echo json_encode([
            'success'     => false,
            'error'       => 'FFmpeg concat failed (exit ' . $exit_code . ')',
            'log'         => $log,
            'cmd'         => $cmd,
            'elapsed_sec' => $elapsed,
        ]);
        exit;
    }

    // ── Mix background music ──────────────────────────────────────────────────
    $with_music = false;
    if ($music_abs) {
        $mixed_mp4 = $out_dir . '/podcast_' . $pid . '_mixed.mp4';
        if (file_exists($mixed_mp4)) @unlink($mixed_mp4);
        $mv = number_format(max(0.0, min(2.0, $music_volume)), 2);
        $vv = number_format(max(0.0, min(2.0, $voice_volume)), 2);

        // Probe for existing audio track
        $probe     = shell_exec(escapeshellcmd($FFMPEG) . ' -i ' . escapeshellarg($output_mp4) . ' 2>&1') ?: '';
        $has_voice = stripos($probe, 'Audio:') !== false;

        if ($has_voice) {
            $filter = "[0:a]volume={$vv}[v];[1:a]volume={$mv}[m];[v][m]amix=inputs=2:duration=first:dropout_transition=2[out]";
            $a_map  = '-map "[out]"';
        } else {
            $filter = "[1:a]volume={$mv}[out]";
            $a_map  = '-map "[out]"';
        }

        $mix_cmd = escapeshellcmd($FFMPEG)
            . ' -y'
            . ' -i '            . escapeshellarg($output_mp4)
            . ' -stream_loop -1 -i ' . escapeshellarg($music_abs)
            . ' -filter_complex ' . escapeshellarg($filter)
            . ' -map 0:v ' . $a_map
            . ' -c:v copy -c:a aac -b:a 192k -ar 44100 -ac 2 -shortest'
            . ' -movflags +faststart'
            . ' ' . escapeshellarg($mixed_mp4)
            . ' 2>&1';

        $mix_out = []; $mix_rc = 0;
        exec($mix_cmd, $mix_out, $mix_rc);
        $log .= "\n\n[Music mix]: " . implode(' | ', array_slice($mix_out, -4));

        if ($mix_rc === 0 && file_exists($mixed_mp4) && filesize($mixed_mp4) > 0) {
            @unlink($output_mp4);
            rename($mixed_mp4, $output_mp4);
            $with_music = true;
        } else {
            @unlink($mixed_mp4);
            $log .= "\n[Music mix FAILED — keeping voice-only version]";
        }
        // Clean up downloaded music temp file after mix attempt
        if ($music_tmp) @unlink($music_tmp);
    } // end if ($music_abs)

    $mp4_filename = 'podcast_' . $pid . '.mp4';
    $mp4_url      = 'published_videos/' . $mp4_filename;
    $size_mb      = round(filesize($output_mp4) / 1048576, 1);
    $total_caps   = array_sum(array_map(fn($s) => count($s['captions']), $scenes));
    mysqli_query($conn,
        "UPDATE hdb_podcasts SET
            video_status   = 'RECORDED',
            video_filename = '" . mysqli_real_escape_string($conn, $mp4_filename) . "'
         WHERE id = $pid"
    );

    echo json_encode([
        'success'        => true,
        'mp4_url'        => $mp4_url,
        'mp4_size_mb'    => $size_mb,
        'elapsed_sec'    => $elapsed,
        'clips'          => count($tmp_clips),
        'scenes_used'    => count($scenes),
        'skip_log'       => $skip_log, // any scenes that got silently dropped, and why
        'with_audio'     => count(array_filter($scenes, fn($s) => $s['aabs'] !== null)),
        'total_captions' => $total_caps,
        'with_music'     => $with_music,
        'music_file'     => $with_music ? basename($music_abs) : null,
        'music_volume'   => $with_music ? $music_volume : null,
        'voice_volume'   => $with_music ? $voice_volume : null,
        'music_debug'    => [
            'db_music_file'   => $music_file,
            'db_music_folder' => $music_folder,
            'music_abs'       => $music_abs,
            'music_found'     => !empty($music_abs),
            'log'             => array_filter($ffmpeg_logs, fn($l) => stripos($l,'music')!==false || stripos($l,'Downloading music')!==false),
        ],
    ]);
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'error'   => 'PHP Exception: ' . $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Stitch Video (MP4)</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44; --mid-blue: #143b63; --accent: #5fd1ff;
  --green: #10b981; --orange: #f59e0b; --red: #ef4444;
  --text: #1e293b; --muted: #64748b; --border: #e2e8f0;
  --bg: #f8fafc; --card: #ffffff;
  --shadow: 0 4px 12px rgba(0,0,0,0.08);
}
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
.vidora-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; background: linear-gradient(90deg, #0f2a44, #143b63); color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,0.15); position: sticky; top: 0; z-index: 1000; }
.brand-link { text-decoration: none; display: flex; align-items: center; gap: 8px; }
.brand-icon { font-size: 24px; }
.brand-name { font-size: 18px; font-weight: 700; }
.brand-video { color: #fff; }
.brand-vizard { color: #5fd1ff; }
.back-link { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.75); text-decoration: none; display: flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1.5px solid rgba(255,255,255,.25); border-radius: 8px; transition: all .15s; }
.back-link:hover { color: #fff; background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.45); }
.page-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 36px 16px 60px; }
.wiz-card { background: var(--card); border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); width: 100%; max-width: 800px; }
.wiz-card-header { padding: 22px 28px 20px; background: linear-gradient(90deg, #0f2a44, #143b63); border-radius: 16px 16px 0 0; }
.wiz-card-header h1 { font-size: 21px; font-weight: 700; color: #fff; margin: 0 0 4px; }
.wiz-card-header p { font-size: 13px; color: rgba(255,255,255,.7); margin: 0; }
.wiz-card-body { padding: 28px; }
.step-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
.step-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.field-label { display: block; font-size: 12px; font-weight: 700; color: var(--dark-blue); margin-bottom: 7px; text-transform: uppercase; letter-spacing: .04em; }
.field-select { width: 100%; padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 14px; font-family: inherit; color: var(--text); background: #fff; outline: none; cursor: pointer; transition: border-color .15s; }
.field-select:focus { border-color: var(--dark-blue); }
.scenes-wrap { display: none; margin-top: 22px; border: 1.5px solid var(--border); border-radius: 12px; overflow: hidden; }
.scenes-head { padding: 12px 16px; background: #f1f5f9; border-bottom: 1px solid var(--border); font-size: 12px; font-weight: 700; color: var(--dark-blue); display: flex; justify-content: space-between; align-items: center; }
.scenes-body { max-height: 320px; overflow-y: auto; }
.scene-row { display: grid; grid-template-columns: 30px 1fr 70px 70px 80px; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
.scene-row:last-child { border-bottom: none; }
.scene-num { width: 26px; height: 26px; border-radius: 50%; background: var(--dark-blue); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
.scene-file { color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.scene-ok   { color: var(--green); font-weight: 700; text-align: center; }
.scene-miss { color: var(--red);   font-weight: 700; text-align: center; }
.scene-warn { color: var(--orange); font-weight: 700; text-align: center; }
.scene-cap  { color: var(--accent); font-weight: 700; text-align: center; font-size: 11px; }
.scenes-col-head { display: grid; grid-template-columns: 30px 1fr 70px 70px 80px; gap: 10px; padding: 6px 16px; background: #e8edf2; font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid var(--border); }
.info-box { padding: 11px 14px; border-radius: 10px; font-size: 12px; font-weight: 600; margin-top: 14px; display: none; }
.info-box.warn    { background: #fffbeb; border: 1.5px solid #fde68a; color: #92400e; }
.info-box.danger  { background: #fef2f2; border: 1.5px solid #fecaca; color: #dc2626; }
.info-box.success { background: #f0fdf4; border: 1.5px solid #bbf7d0; color: #065f46; }
.info-box.info    { background: #eff6ff; border: 1.5px solid #bfdbfe; color: #1d4ed8; }
.prog-wrap { display: none; margin-top: 18px; }
.prog-header { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); font-weight: 700; margin-bottom: 6px; }
.prog-track { height: 8px; background: #f1f5f9; border-radius: 100px; overflow: hidden; border: 1px solid var(--border); }
.prog-fill { height: 100%; background: linear-gradient(90deg, #0f2a44, #5fd1ff); border-radius: 100px; transition: width .5s ease; width: 0%; }
.status-msg { display: none; margin-top: 14px; padding: 11px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; align-items: center; gap: 10px; background: #f0f9ff; border: 1.5px solid #bae6fd; color: #0369a1; }
.status-msg.error   { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
.status-msg.success { background: #f0fdf4; border-color: #bbf7d0; color: #065f46; }
.btn-row { display: flex; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
.btn { padding: 12px 22px; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; transition: all .18s; display: flex; align-items: center; gap: 8px; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary { background: linear-gradient(135deg, #0f2a44, #143b63); color: #fff; }
.btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(15,42,68,.3); }
.btn-ghost { background: #f1f5f9; border: 1.5px solid var(--border); color: var(--muted); }
.result-wrap { display: none; margin-top: 24px; }
.result-wrap video { width: 100%; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); display: block; margin-bottom: 14px; max-height: 500px; object-fit: contain; background: #000; }
.download-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px; background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 10px; color: #065f46; font-size: 14px; font-weight: 700; text-decoration: none; transition: all .18s; }
.download-btn:hover { background: #dcfce7; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
</style>
</head>
<body>

<header class="vidora-header">
  <a class="brand-link" href="videovizard.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></span>
  </a>
  <a class="back-link" href="vizard_browser.php">← Dashboard</a>
</header>

<div class="page-wrap">
  <div class="wiz-card">
    <div class="wiz-card-header">
      <h1>🎞️ Stitch &amp; Export Video (MP4)</h1>
      <p>Each scene: 5s · 1080×1920 · H.264 CRF 28 · captions burned in from hdb_captions</p>
    </div>
    <div class="wiz-card-body">

      <div class="step-label">Step 1 — Select Podcast</div>
      <label class="field-label" for="podcastSelect">Podcast</label>
      <select class="field-select" id="podcastSelect" onchange="loadScenes()">
        <option value="">-- Choose a Podcast --</option>
        <?php
        $res = mysqli_query($conn,
            "SELECT id, title FROM hdb_podcasts WHERE admin_id=$admin_id ORDER BY id DESC LIMIT 100");
        while ($p = mysqli_fetch_assoc($res)):
            $label = '#' . $p['id'] . ' — ' . htmlspecialchars($p['title'] ?? 'Untitled');
        ?>
        <option value="<?= $p['id'] ?>"><?= $label ?></option>
        <?php endwhile; ?>
      </select>

      <div class="scenes-wrap" id="scenesWrap">
        <div class="scenes-head">
          <span id="scenesTitle">Scenes</span>
          <span id="scenesMissing" style="color:var(--red);font-size:11px;"></span>
        </div>
        <div class="scenes-col-head">
          <div></div>
          <div>File</div>
          <div style="text-align:center">Video</div>
          <div style="text-align:center">Audio</div>
          <div style="text-align:center">Captions</div>
        </div>
        <div class="scenes-body" id="scenesBody"></div>
      </div>

      <div class="info-box warn"    id="warnMissing">⚠️ Some scenes have no media file — they will be skipped.</div>
      <div class="info-box warn"    id="warnNoAudio">🔇 Some scenes have no audio file — those scenes will be silent.</div>
      <div class="info-box info"    id="infoNoCaps">ℹ️ No captions found — video will be exported without text overlays.</div>
      <div class="info-box danger"  id="errNoVideos">❌ No media files (video or image) found for this podcast.</div>

      <div class="step-label" id="step2Label" style="margin-top:24px;display:none;">Step 2 — Stitch MP4</div>

      <div class="prog-wrap" id="progWrap">
        <div class="prog-header">
          <span id="progLabel">Encoding clips + burning captions…</span>
          <span id="progPct">0%</span>
        </div>
        <div class="prog-track"><div class="prog-fill" id="progFill"></div></div>
        <div style="margin-top:8px;">
          <span id="elapsedLabel" style="font-size:12px;font-weight:700;color:var(--muted);">⏱ 0s elapsed</span>
        </div>
      </div>

      <div class="status-msg" id="statusMsg"></div>

      <pre id="debugBox" style="display:none;margin-top:12px;background:#0f2a44;border-radius:8px;
           padding:12px 16px;font-size:11px;color:#7dd3fc;max-height:260px;overflow-y:auto;
           white-space:pre-wrap;line-height:1.6;"></pre>

      <div class="btn-row" id="btnRow" style="display:none;">
        <button class="btn btn-primary" id="stitchBtn" onclick="startStitch()">🎬 Stitch into MP4</button>
        <button class="btn btn-ghost"   onclick="runDebug()">🔍 Debug</button>
        <button class="btn btn-ghost"   onclick="reset()">↺ Reset</button>
      </div>

      <div class="result-wrap" id="resultWrap">
        <video id="resultVideo" controls playsinline></video>
        <a class="download-btn" id="downloadBtn" href="#" download>⬇ Download MP4</a>
      </div>

    </div>
  </div>
</div>

<script>
let currentPodcastId  = null;
let currentVideoFiles = [];

async function loadScenes() {
  const pid = document.getElementById('podcastSelect').value;
  reset(false);
  if (!pid) return;
  currentPodcastId = pid;
  showStatus('Loading scenes…', 'default');
  const fd = new FormData();
  fd.append('action', 'get_scenes');
  fd.append('podcast_id', pid);
  try {
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();
    hideStatus();
    if (!data.success) { showStatus('❌ ' + (data.error || 'Failed'), 'error'); return; }
    renderScenes(data.scenes, data.title);
  } catch(e) { showStatus('❌ Network error: ' + e.message, 'error'); }
}

function renderScenes(scenes, title) {
  const body    = document.getElementById('scenesBody');
  const missEl  = document.getElementById('scenesMissing');
  const warnEl  = document.getElementById('warnMissing');
  const warnAu  = document.getElementById('warnNoAudio');
  const infoCap = document.getElementById('infoNoCaps');
  const errEl   = document.getElementById('errNoVideos');
  const step2   = document.getElementById('step2Label');
  const btnRow  = document.getElementById('btnRow');

  body.innerHTML = '';
  currentVideoFiles = [];
  let missingVid = 0, missingAud = 0, totalCaps = 0;

  scenes.forEach((s, i) => {
    let folder = (s.image_folder || 'podcast_images').replace(/\/$/, '');
    if (/^(user|admin)_id_\d+_company_id_\d+(\/|$)/.test(folder) && folder.indexOf('user_media/') !== 0) {
        folder = 'user_media/' + folder;
    }
    const vfile  = (s.image_file  || '').trim();
    const afile  = (s.audio_file  || '').trim();
    const caps   = parseInt(s.caption_count) || 0;
    const isVid  = vfile && /\.mp4$/i.test(vfile);
    const isImg  = vfile && /\.(jpg|jpeg|png|webp|gif)$/i.test(vfile);
    const hasMedia = isVid || isImg;
    const isAud  = afile.length > 0;
    const path   = vfile ? folder + '/' + vfile : '';

    if (hasMedia) currentVideoFiles.push(path);
    else missingVid++;
    if (!isAud) missingAud++;
    totalCaps += caps;

    const mediaIcon = isVid ? '✅' : isImg ? '🖼️' : '❌';
    const mediaCls  = hasMedia ? 'scene-ok' : 'scene-miss';

    const row = document.createElement('div');
    row.className = 'scene-row';
    row.innerHTML = `
      <div class="scene-num">${s.seq_no || (i+1)}</div>
      <div class="scene-file" title="${path}">${path || '<em style="color:#cbd5e1">No file</em>'}</div>
      <div class="${mediaCls}">${mediaIcon}</div>
      <div class="${isAud ? 'scene-ok' : 'scene-warn'}">${isAud ? '🔊' : '🔇'}</div>
      <div class="scene-cap">${caps > 0 ? '🅰️ ' + caps : '—'}</div>`;
    body.appendChild(row);
  });

  document.getElementById('scenesTitle').textContent =
    `${title} — ${scenes.length} scene${scenes.length !== 1 ? 's' : ''}`;
  document.getElementById('scenesWrap').style.display = 'block';

  if (currentVideoFiles.length === 0) {
    errEl.style.display  = 'block';
    warnEl.style.display = 'none';
    warnAu.style.display = 'none';
    btnRow.style.display = 'none';
    step2.style.display  = 'none';
  } else {
    errEl.style.display   = 'none';
    warnEl.style.display  = missingVid > 0 ? 'block' : 'none';
    warnAu.style.display  = missingAud > 0 ? 'block' : 'none';
    infoCap.style.display = totalCaps === 0 ? 'block' : 'none';
    if (missingVid > 0) missEl.textContent = missingVid + ' scene(s) have no media';
    step2.style.display  = 'flex';
    btnRow.style.display = 'flex';
  }
}

async function runDebug() {
  const dbgBox = document.getElementById('debugBox');
  dbgBox.style.display = 'block';
  dbgBox.textContent = 'Running debug…';
  const fd = new FormData();
  fd.append('action', 'debug');
  fd.append('podcast_id', currentPodcastId);
  const res = await fetch(window.location.href, { method: 'POST', body: fd });
  dbgBox.textContent = await res.text();
}

async function startStitch() {
  if (!currentPodcastId || !currentVideoFiles.length) return;

  const btn    = document.getElementById('stitchBtn');
  const dbgBox = document.getElementById('debugBox');
  dbgBox.style.display = 'none';
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Encoding & stitching…';
  showProgress('Downloading clips · Burning captions · Mixing audio · H.264…', 5);

  const t0 = Date.now();
  const elEl = document.getElementById('elapsedLabel');
  const ticker = setInterval(() => {
    const s = Math.floor((Date.now() - t0) / 1000);
    const m = Math.floor(s / 60), ss = s % 60;
    elEl.textContent = '⏱ ' + (m > 0 ? m + 'm ' : '') + ss + 's elapsed';
    setProgressPct(Math.min(5 + Math.floor(s * 1.5), 90));
  }, 1000);

  const fd = new FormData();
  fd.append('action',     'start_stitch');
  fd.append('podcast_id', currentPodcastId);
  fd.append('base_url',   window.location.origin + '/');  // e.g. https://videovizard.com/

  try {
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();
    clearInterval(ticker);

    const sec     = data.elapsed_sec || 0;
    const elapsed = sec >= 60 ? Math.floor(sec/60) + 'm ' + (sec%60) + 's' : sec + 's';

    if (!data.success) {
      hideProgress();
      showStatus('❌ ' + (data.error || 'FFmpeg failed') + ' — after ' + elapsed, 'error');
      let info = '';
      if (data.skip_log && data.skip_log.length) info += 'Why scenes were skipped:\n' + data.skip_log.join('\n') + '\n\n';
      if (data.checked && data.checked.length)   info += 'Paths checked:\n' + data.checked.join('\n') + '\n\n';
      if (data.log) info += 'FFmpeg output:\n' + data.log;
      if (data.cmd) info += '\n\nCommand:\n' + data.cmd;
      if (info) { dbgBox.textContent = info; dbgBox.style.display = 'block'; dbgBox.scrollIntoView({ behavior:'smooth', block:'nearest' }); }
      btn.disabled = false;
      btn.innerHTML = '🎬 Stitch into MP4';
      return;
    }

    setProgressPct(100);
    const sizeTxt  = data.mp4_size_mb ? ' · ' + data.mp4_size_mb + ' MB' : '';
    const audioTxt = data.with_audio !== undefined ? ' · ' + data.with_audio + ' with audio' : '';
    const capTxt   = data.total_captions ? ' · ' + data.total_captions + ' captions burned' : '';
    const musTxt   = data.with_music
        ? ' · 🎵 music mixed (vol ' + Math.round((data.music_volume||0)*100) + '% / voice ' + Math.round((data.voice_volume||0)*100) + '%)'
        : ' · ⚠️ no music';
    showStatus('✅ Done in ' + elapsed + ' — ' + data.clips + ' clips' + audioTxt + capTxt + musTxt + sizeTxt, 'success');
    // Show music debug info
    if (data.music_debug) {
        dbgBox.textContent = 'Music debug:\n' + JSON.stringify(data.music_debug, null, 2);
        dbgBox.style.display = 'block';
    }
    setTimeout(hideProgress, 600);
    showResult(data.mp4_url);
    resetBtn();

  } catch(e) {
    clearInterval(ticker);
    showStatus('❌ Network error: ' + e.message, 'error');
    hideProgress();
    btn.disabled = false;
    btn.innerHTML = '🎬 Stitch into MP4';
  }
}

function showResult(mp4Url) {
  const wrap = document.getElementById('resultWrap');
  document.getElementById('resultVideo').src = mp4Url + '?t=' + Date.now();
  document.getElementById('downloadBtn').href = mp4Url;
  document.getElementById('downloadBtn').download = 'podcast_' + currentPodcastId + '.mp4';
  wrap.style.display = 'block';
  wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function showStatus(msg, type) {
  const el = document.getElementById('statusMsg');
  el.textContent = msg;
  el.className = 'status-msg' + (type === 'error' ? ' error' : type === 'success' ? ' success' : '');
  el.style.display = 'flex';
}
function hideStatus() { document.getElementById('statusMsg').style.display = 'none'; }
function showProgress(label, pct) {
  document.getElementById('progWrap').style.display = 'block';
  document.getElementById('progLabel').textContent = label;
  setProgressPct(pct);
}
function setProgressPct(pct) {
  document.getElementById('progFill').style.width = pct + '%';
  document.getElementById('progPct').textContent  = pct + '%';
}
function hideProgress() { document.getElementById('progWrap').style.display = 'none'; }
function resetBtn() {
  const btn = document.getElementById('stitchBtn');
  btn.disabled = false;
  btn.innerHTML = '🎬 Stitch into MP4';
}
function reset(clearSelect = true) {
  if (clearSelect) {
    document.getElementById('podcastSelect').value = '';
    currentPodcastId = null; currentVideoFiles = [];
  }
  ['scenesWrap','warnMissing','warnNoAudio','infoNoCaps','errNoVideos','step2Label','btnRow','resultWrap','debugBox']
    .forEach(id => document.getElementById(id).style.display = 'none');
  document.getElementById('scenesBody').innerHTML = '';
  hideProgress(); hideStatus(); resetBtn();
}
</script>
</body>
</html>