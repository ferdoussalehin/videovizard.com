<?php
header('Content-Type: application/json');

include 'dbconnect_hdb.php';
mysqli_set_charset($conn, "utf8mb4");

if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
    // 1. Fetch Main Content
    $sqlMain = "SELECT id, contents_en, contents_ur, contents_ar, contents_hi, contents_es, contents_fr 
            FROM hdb_social_media 
            WHERE (status = '' OR status IS NULL) 
            ORDER BY RAND() 
            LIMIT 1";
    $resMain = mysqli_query($conn, $sqlMain);
    $mainData = mysqli_fetch_assoc($resMain);

    if (!$mainData) {
        echo json_encode(['error' => 'No pending records found']);
        exit;
    }

    // 2. Fetch Random CTA
    // Columns match your list: cta_contents_en, cta_contents_ur, etc.
    $sqlCta = "SELECT * 
               FROM hdb_social_media_cta 
               ORDER BY RAND() LIMIT 1";
    $resCta = mysqli_query($conn, $sqlCta);
    $ctaData = mysqli_fetch_assoc($resCta);

    // 3. Merge them into one response
    // We send both so the JavaScript can handle the language switching correctly
    echo json_encode([
        'main' => $mainData,
        'cta' => $ctaData
    ]);
    exit;
}

//echo "action is ".$_GET['action'];
if (isset($_GET['action']) && $_GET['action'] === 'update_social') {
    $id = intval($_GET['id']);
    $updateSql = "UPDATE hdb_social_media SET status = 'recorded' WHERE id = $id";
//	echo "query i ".$updateSql;
    if(mysqli_query($conn, $updateSql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => mysqli_error($conn)]);
    }
    exit;
}
?> 