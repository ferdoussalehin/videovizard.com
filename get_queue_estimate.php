<?php
/**
 * API endpoint to get queue estimate for user
 * Called after scene creation to show estimated completion time
 */

include 'config.php';

header('Content-Type: application/json');

$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

if (!$podcast_id) {
    echo json_encode(['success' => false, 'error' => 'No podcast_id provided']);
    exit;
}

// Get estimate
function getQueueEstimate($conn, $podcast_id) {
    // Count pending scenes for this podcast
    $pending = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT COUNT(*) as count FROM hdb_video_gen 
         WHERE podcast_id = $podcast_id AND status = 'pending' AND phase = 'sadtalker'"));
    
    // Count scenes in queue ahead (all podcasts)
    $ahead = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT COUNT(*) as count FROM hdb_video_gen 
         WHERE status IN ('pending', 'processing') 
         AND id < (SELECT COALESCE(MIN(id), 0) FROM hdb_video_gen WHERE podcast_id = $podcast_id)"));
    
    // Average time per video from stats (or default 120 seconds)
    $avg_time = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT AVG(duration_seconds) as avg_time FROM hdb_video_stats WHERE status = 'success'"));
    $avg_per_scene = $avg_time['avg_time'] ?? 120;
    
    $total_scenes = $pending['count'] + $ahead['count'];
    $total_seconds = $total_scenes * $avg_per_scene;
    $total_minutes = ceil($total_seconds / 60);
    
    // Get processing stats for fine-tuning
    $stats = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT 
            COUNT(CASE WHEN status = 'success' THEN 1 END) as total_success,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as total_failed,
            AVG(CASE WHEN status = 'success' THEN duration_seconds END) as avg_success_time
         FROM hdb_video_stats"));
    
    return [
        'success' => true,
        'podcast_id' => $podcast_id,
        'your_scenes_pending' => (int)$pending['count'],
        'scenes_ahead_in_queue' => (int)$ahead['count'],
        'total_scenes_remaining' => $total_scenes,
        'estimated_minutes' => $total_minutes,
        'estimated_seconds' => $total_seconds,
        'estimated_time_readable' => formatTime($total_minutes),
        'avg_time_per_scene' => round($avg_per_scene, 1),
        'historical_stats' => [
            'total_videos_generated' => (int)($stats['total_success'] ?? 0),
            'success_rate' => $stats['total_success'] + $stats['total_failed'] > 0 
                ? round(($stats['total_success'] / ($stats['total_success'] + $stats['total_failed'])) * 100, 1)
                : 100,
            'avg_processing_time' => round($stats['avg_success_time'] ?? 120, 1)
        ]
    ];
}

function formatTime($minutes) {
    if ($minutes < 1) return 'less than a minute';
    if ($minutes < 60) return "$minutes minute" . ($minutes > 1 ? 's' : '');
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($mins == 0) return "$hours hour" . ($hours > 1 ? 's' : '');
    return "$hours hour" . ($hours > 1 ? 's' : '') . " and $mins minute" . ($mins > 1 ? 's' : '');
}

echo json_encode(getQueueEstimate($conn, $podcast_id), JSON_PRETTY_PRINT);

mysqli_close($conn);
?>