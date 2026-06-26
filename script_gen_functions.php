<?
// Add these functions to your script_gen.php file, preferably near the other database functions
// (around line 400-500, before the HTML section)

/**
 * Set podcast thumbnail from first scene's image
 * @param int $podcast_id The podcast ID
 * @return bool Success status
 */
function setPodcastThumbnailFromFirstScene($podcast_id, $conn) {
    // Get the first scene (lowest seq_no) that has an image file
    $query = "SELECT image_file FROM hdb_podcast_stories 
              WHERE podcast_id = $podcast_id 
              AND image_file IS NOT NULL 
              AND image_file != ''
              ORDER BY seq_no ASC 
              LIMIT 1";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $scene = mysqli_fetch_assoc($result);
        $thumbnail = $scene['image_file'];
        
        // Update the podcast with this thumbnail
        $update_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $podcast_id";
        
        if (mysqli_query($conn, $update_sql)) {
            error_log("✅ Thumbnail set for podcast #$podcast_id: $thumbnail");
            return true;
        } else {
            error_log("❌ Failed to update thumbnail for podcast #$podcast_id: " . mysqli_error($conn));
            return false;
        }
    }
    
    error_log("⚠️ No image found for podcast #$podcast_id thumbnail");
    return false;
}

/**
 * Update podcast thumbnail when a scene's image changes
 * @param int $scene_id The scene ID that was updated
 * @param int $podcast_id The podcast ID
 * @param string $new_image The new image filename
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function updatePodcastThumbnailOnImageChange($scene_id, $podcast_id, $new_image, $conn) {
    // Check if this scene is the first scene (lowest seq_no)
    $check_query = "SELECT MIN(seq_no) as first_seq FROM hdb_podcast_stories WHERE podcast_id = $podcast_id";
    $check_result = mysqli_query($conn, $check_query);
    
    if ($check_result && mysqli_num_rows($check_result) > 0) {
        $row = mysqli_fetch_assoc($check_result);
        $first_seq = $row['first_seq'];
        
        // Get this scene's seq_no
        $scene_query = "SELECT seq_no FROM hdb_podcast_stories WHERE id = $scene_id";
        $scene_result = mysqli_query($conn, $scene_query);
        
        if ($scene_result && mysqli_num_rows($scene_result) > 0) {
            $scene_row = mysqli_fetch_assoc($scene_result);
            
            // If this is the first scene, update the podcast thumbnail
            if ($scene_row['seq_no'] == $first_seq) {
                $update_sql = "UPDATE hdb_podcasts SET thumbnail = '$new_image' WHERE id = $podcast_id";
                
                if (mysqli_query($conn, $update_sql)) {
                    error_log("✅ Thumbnail updated for podcast #$podcast_id from scene #$scene_id: $new_image");
                    return true;
                }
            }
        }
    }
    
    return false;
}

/**
 * Force update podcast thumbnail (can be called manually)
 * @param int $podcast_id The podcast ID
 * @param string $image_file The image file to set as thumbnail
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function forceUpdatePodcastThumbnail($podcast_id, $image_file, $conn) {
    $image_file = mysqli_real_escape_string($conn, $image_file);
    $update_sql = "UPDATE hdb_podcasts SET thumbnail = '$image_file' WHERE id = $podcast_id";
    
    if (mysqli_query($conn, $update_sql)) {
        error_log("✅ Thumbnail force-updated for podcast #$podcast_id: $image_file");
        return true;
    } else {
        error_log("❌ Failed to force-update thumbnail for podcast #$podcast_id: " . mysqli_error($conn));
        return false;
    }
}


?>