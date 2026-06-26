<?php
/**
 * fb_save_pages.php — shared helper to persist the user's chosen Facebook pages.
 *
 * Each selected page is stored as its own row with platform = 'facebook_page_{id}'
 * so the posting code (which filters LIKE 'facebook_page_%') can find every page
 * token. The set is written fresh each time: any page the user did NOT select is
 * removed, so deselected/stale pages never linger.
 *
 * $pages: array of ['id'=>, 'name'=>, 'access_token'=>] (page access tokens).
 */
function fb_save_selected_pages(mysqli $conn, int $admin_id, int $company_id, array $pages, string $expiry): int {
    mysqli_query($conn,
        "DELETE FROM hdb_oauth_tokens
         WHERE admin_id=$admin_id AND company_id=$company_id AND platform LIKE 'facebook_page_%'");

    $saved = 0;
    foreach ($pages as $page) {
        if (empty($page['id']) || empty($page['access_token'])) continue;
        $pageId    = mysqli_real_escape_string($conn, substr((string)$page['id'],            0, 100));
        $pageName  = mysqli_real_escape_string($conn, substr((string)($page['name'] ?? ''),  0, 200));
        $pageToken = mysqli_real_escape_string($conn, (string)$page['access_token']);
        $exp       = mysqli_real_escape_string($conn, $expiry);
        $now       = mysqli_real_escape_string($conn, date('Y-m-d H:i:s'));
        $fbPlatE   = mysqli_real_escape_string($conn, 'facebook_page_' . substr((string)$page['id'], 0, 50));

        mysqli_query($conn,
            "INSERT INTO hdb_oauth_tokens
                 (company_id,admin_id,platform,access_token,channel_id,channel_name,token_expiry,created_at,updated_at)
             VALUES ($company_id,$admin_id,'$fbPlatE','$pageToken','$pageId','$pageName','$exp','$now','$now')
             ON DUPLICATE KEY UPDATE
                 company_id=$company_id, access_token='$pageToken', channel_name='$pageName',
                 token_expiry='$exp', updated_at='$now'");
        if (!mysqli_errno($conn)) $saved++;
    }
    return $saved;
}
