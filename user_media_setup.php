<?php
// ============================================================
// user_media_setup.php
// Creates user media folder structure and sets correct
// ownership on the spot. Include this ONCE on login or
// at the top of any page that needs user media.
//
// Usage (include at top of any page after session_start):
//   require_once __DIR__ . '/user_media_setup.php';
//   $user_media = ensureUserMediaFolder($admin_id, $company_id);
//   // $user_media['path']    = absolute path
//   // $user_media['rel']     = relative URL path
//   // $user_media['ok']      = true/false
//   // $user_media['error']   = error message if ok=false
// ============================================================

// ── Detect web server user ─────────────────────────────────
function getWebServerUser(): string {
    // Check common web server process owners
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (!empty($info['name'])) return $info['name'];
    }
    // Fallback: check known web server users
    foreach (['www-data', 'apache', 'nginx', 'nobody', 'httpd'] as $u) {
        if (function_exists('posix_getpwnam') && posix_getpwnam($u) !== false) return $u;
    }
    return 'www-data'; // safe default
}

// ── Create folder with correct permissions and ownership ───
function createSecureFolder(string $path): bool {
    if (is_dir($path)) {
        @chmod($path, 0777);
        return true;
    }

    // Check parent is writable before attempting mkdir
    $parent = dirname($path);
    if (!is_dir($parent)) {
        // Try to create parent first recursively — but only if grandparent exists
        if (!is_dir(dirname($parent))) {
            error_log("[user_media_setup] Parent missing: $parent");
            return false;
        }
        @mkdir($parent, 0755, true);
    }
    if (!is_writable($parent)) {
        @chmod($parent, 0777);
        if (!is_writable($parent)) {
            error_log("[user_media_setup] Not writable: $parent");
            return false;
        }
    }

    if (!@mkdir($path, 0777, true) && !is_dir($path)) {
        error_log("[user_media_setup] mkdir FAILED: $path");
        return false;
    }
    @chmod($path, 0777);

    $webUser = getWebServerUser();
    if (function_exists('posix_getpwnam')) {
        $info = @posix_getpwnam($webUser);
        if ($info) { @chown($path, $info['uid']); @chgrp($path, $info['gid']); }
    }
    return true;
}

// ── Main function: ensure user media folder exists ─────────
function ensureUserMediaFolder(int $admin_id, int $company_id): array {
    if (!$admin_id) {
        return ['ok'=>false,'error'=>'No admin_id','path'=>'','rel'=>''];
    }

    $base    = __DIR__ . '/user_media';
    $folder  = 'user_id_' . $admin_id . '_company_id_' . $company_id;
    $absBase = $base . '/' . $folder;
    $relBase = 'user_media/' . $folder;

    // ── Check/create the parent user_media/ folder first ──
    if (!is_dir($base)) {
        // Try creating it
        @mkdir($base, 0777, true);
        if (!is_dir($base)) {
            $msg = "user_media/ folder missing. Run on server: mkdir -p $base && chmod 777 $base";
            error_log("[user_media_setup] $msg");
            return ['ok'=>false,'error'=>$msg,'path'=>$absBase,'rel'=>$relBase];
        }
    }
    // Make parent writable
    @chmod($base, 0777);
    if (!is_writable($base)) {
        $msg = "user_media/ not writable. Run: chmod 777 $base";
        error_log("[user_media_setup] $msg");
        return ['ok'=>false,'error'=>$msg,'path'=>$absBase,'rel'=>$relBase];
    }

    // Create base user folder + all needed subfolders
    $subfolders = [
        '',
        '/product_images',
        '/product_previews',
        '/podcast_images',
        '/podcast_videos',
        '/podcast_audios',
    ];

    $errors = [];
    foreach ($subfolders as $sub) {
        $dir = $absBase . $sub;
        if (!createSecureFolder($dir)) {
            $errors[] = $sub ?: '/';
        }
    }

    if (!empty($errors)) {
        // Try chmod on parent as fallback
        @chmod($base, 0777);
        @chmod($absBase, 0777);
        // Retry failed folders
        $stillFailed = [];
        foreach ($errors as $sub) {
            $dir = $absBase . ($sub === '/' ? '' : $sub);
            if (!createSecureFolder($dir)) {
                $stillFailed[] = $sub;
            }
        }
        if (!empty($stillFailed)) {
            $msg = 'Could not create: ' . implode(', ', $stillFailed) . ' under ' . $absBase;
            error_log("[user_media_setup] ERROR: $msg");
            return ['ok'=>false,'error'=>$msg,'path'=>$absBase,'rel'=>$relBase];
        }
    }

    // Write a tiny .htaccess to protect direct file listing
    $htaccess = $absBase . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess,
            "Options -Indexes\n" .
            "<FilesMatch \"\\.(php|php3|php4|php5|phtml|pl|py|rb|sh|cgi)$\">\n" .
            "  Deny from all\n" .
            "</FilesMatch>\n"
        );
    }

    return [
        'ok'    => true,
        'error' => '',
        'path'  => $absBase,
        'rel'   => $relBase,
        'subs'  => array_map(fn($s) => $absBase . $s, array_filter($subfolders)),
    ];
}

// ── Helper: get sub-folder path ────────────────────────────
// Usage: getUserMediaPath($admin_id, $company_id, 'product_images')
function getUserMediaPath(int $admin_id, int $company_id, string $sub = ''): string {
    $base = __DIR__ . '/user_media/user_id_' . $admin_id . '_company_id_' . $company_id;
    $path = $sub ? $base . '/' . ltrim($sub, '/') : $base;
    if (!is_dir($path)) {
        // Try creating with 777 — make parent writable first
        @chmod(dirname($path), 0777);
        @mkdir($path, 0777, true);
        @chmod($path, 0777);
    }
    if (!is_writable($path)) @chmod($path, 0777);
    return $path;
}

function getUserMediaRel(int $admin_id, int $company_id, string $sub = ''): string {
    $base = 'user_media/user_id_' . $admin_id . '_company_id_' . $company_id;
    return $sub ? $base . '/' . ltrim($sub, '/') : $base;
}

// ── Auto-run if called directly (CLI setup tool) ───────────
if (php_sapi_name() === 'cli' && isset($argv[1], $argv[2])) {
    $uid = (int)$argv[1];
    $cid = (int)$argv[2];
    echo "Setting up user_media for user_id=$uid company_id=$cid...\n";
    $result = ensureUserMediaFolder($uid, $cid);
    if ($result['ok']) {
        echo "✅ OK: " . $result['path'] . "\n";
        foreach ($result['subs'] as $s) echo "   📁 $s\n";
    } else {
        echo "❌ FAILED: " . $result['error'] . "\n";
        echo "   Fix: chmod -R 755 " . __DIR__ . "/user_media/\n";
        echo "        chown -R www-data:www-data " . __DIR__ . "/user_media/\n";
    }
    exit;
}
