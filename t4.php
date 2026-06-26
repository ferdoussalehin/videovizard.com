<?php
ob_start();
require_once 'dbconnect_hdb.php';
if (empty($chatgpt_api_key) && file_exists(__DIR__.'/config.php')) {
    require_once __DIR__.'/config.php';
}
function jsonOut($d){ ob_end_clean(); header('Content-Type: application/json'); echo json_encode($d); exit; }

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Test 1: can we scan the folder at all?
    if ($action === 'scan') {
        $files = scandir('podcast_images/');
        jsonOut(array('ok'=>true, 'count'=>count($files), 'first5'=>array_slice($files,0,5)));
    }

    // Test 2: scan with extension filter, no DB
    if ($action === 'scan2') {
        $exts = array('jpg','jpeg','png','webp','gif','mp4','webm','mov');
        $out = array();
        foreach (scandir('podcast_images/') as $f) {
            if ($f==='.'||$f==='..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext,$exts)) continue;
            $out[] = $f;
        }
        jsonOut(array('ok'=>true,'count'=>count($out),'first3'=>array_slice($out,0,3)));
    }

    // Test 3: scan + DB lookup for first 5 files only
    if ($action === 'scan3') {
        $exts = array('jpg','jpeg','png','webp','gif','mp4','webm','mov');
        $files = array();
        $i = 0;
        foreach (scandir('podcast_images/') as $f) {
            if ($f==='.'||$f==='..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext,$exts)) continue;
            if ($i >= 5) break;
            $safe = mysqli_real_escape_string($conn, $f);
            $res  = mysqli_query($conn, "SELECT id,status FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
            $row  = ($res) ? mysqli_fetch_assoc($res) : null;
            $files[] = array('name'=>$f,'in_db'=>($row?true:false));
            $i++;
        }
        jsonOut(array('ok'=>true,'files'=>$files));
    }

    // Test 4: full get_files scan but NO json_encode — just count
    if ($action === 'scan4') {
        $exts = array('jpg','jpeg','png','webp','gif','mp4','webm','mov');
        $count = 0;
        $errors = array();
        foreach (scandir('podcast_images/') as $f) {
            if ($f==='.'||$f==='..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext,$exts)) continue;
            $safe = mysqli_real_escape_string($conn, $f);
            if ($safe === false) { $errors[] = 'escape failed: '.$f; continue; }
            $res  = mysqli_query($conn, "SELECT id,status,natural_language_tags,embedding,media_type_format FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
            if (!$res) { $errors[] = 'query failed: '.mysqli_error($conn); continue; }
            $count++;
        }
        jsonOut(array('ok'=>true,'count'=>$count,'errors'=>$errors));
    }

    // Test 5: full get_files including json_encode of all results
    if ($action === 'scan5') {
        ini_set('memory_limit','256M');
        $exts = array('jpg','jpeg','png','webp','gif','mp4','webm','mov');
        $files = array();
        $folders = array('image'=>'podcast_images/','video'=>'podcast_videos/');
        foreach ($folders as $kind => $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if ($f==='.'||$f==='..') continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext,$exts)) continue;
                $safe = mysqli_real_escape_string($conn, $f);
                $res  = mysqli_query($conn, "SELECT id,status,natural_language_tags,embedding,media_type_format FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
                $row  = ($res) ? mysqli_fetch_assoc($res) : null;
                $files[] = array(
                    'name'   => $f,
                    'ext'    => $ext,
                    'kind'   => $kind,
                    'folder' => $dir,
                    'in_db'  => ($row ? true : false),
                    'status' => ($row && $row['status']) ? $row['status'] : '',
                    'has_nl' => ($row && !empty($row['natural_language_tags'])),
                    'has_emb'=> ($row && !empty($row['embedding'])),
                    'db_id'  => ($row ? $row['id'] : null),
                    'format' => ($row && !empty($row['media_type_format'])) ? $row['media_type_format'] : '',
                );
            }
        }
        $encoded = json_encode(array('success'=>true,'files'=>$files));
        if ($encoded === false) {
            jsonOut(array('ok'=>false,'msg'=>'json_encode FAILED: '.json_last_error_msg(),'file_count'=>count($files)));
        }
        ob_end_clean();
        header('Content-Type: application/json');
        echo $encoded;
        exit;
    }

    jsonOut(array('ok'=>false,'msg'=>'unknown action'));
}
echo 'Tests ready. Try:<br>';
echo '<a href="?action=scan">scan</a> | ';
echo '<a href="?action=scan2">scan2</a> | ';
echo '<a href="?action=scan3">scan3</a> | ';
echo '<a href="?action=scan4">scan4</a> | ';
echo '<a href="?action=scan5">scan5</a>';
?>
