function user_media_folder_name($admin_id, $company_id) {
    return 'user_id_' . (int)$admin_id . '_company_id_' . (int)$company_id;
}
function user_media_dir($admin_id, $company_id) {
    return __DIR__ . '/user_media/' . user_media_folder_name($admin_id, $company_id) . '/';
}
function ensure_user_media_dir($admin_id, $company_id) {
    $dir = user_media_dir($admin_id, $company_id);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}
function user_media_filename($admin_id, $company_id, $ext) {
    return 'user_' . (int)$admin_id . '_co_' . (int)$company_id . '_' . date('Ymd_His') . '_' . mt_rand(100,999) . '.' . $ext;
}