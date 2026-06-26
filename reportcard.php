<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '256M');

// Database connection
$dbhost = "localhost";
$dbase = "hypnotherapy_db"; 
$dbuser = "inaamalvi1403"; 
$dbpass = "AllahuAkbar786"; 

$conn = mysqli_connect($dbhost, $dbuser, $dbpass, $dbase);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8");

// Parameters
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 1;
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 46;

// ============================================
// FUNCTION: Get Baseline (from stress_assessment_impact)
// ============================================
// ============================================
// FUNCTION: Get Baseline (normalized to lowercase)
// ============================================
function get_baseline($conn, $client_id, $assessment_id) {
    $query = "SELECT area_name, impact_description, severity_score, severity_level 
              FROM stress_assessment_impact 
              WHERE client_id = ? AND assessment_id = ?
              ORDER BY area_name";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $client_id, $assessment_id);
    mysqli_stmt_execute($stmt);
    
    $area_name = null;
    $impact_description = null;
    $severity_score = null;
    $severity_level = null;
    
    mysqli_stmt_bind_result($stmt, $area_name, $impact_description, $severity_score, $severity_level);
    
    $baseline = [];
    while (mysqli_stmt_fetch($stmt)) {
        // ✅ Normalize to lowercase for matching
        $area_key = strtolower($area_name);
        
        $baseline[$area_key] = [
            'area_name' => $area_name,  // Keep original for display
            'area_key' => $area_key,     // Lowercase for matching
            'impact_description' => $impact_description,
            'severity_score' => $severity_score,
            'severity_level' => $severity_level
        ];
    }
    
    mysqli_stmt_close($stmt);
    return $baseline;
}

// ============================================
// FUNCTION: Get Progress (normalized to lowercase)
// ============================================
function get_progress($conn, $client_id, $assessment_id) {
    $query = "SELECT session_number, session_date, area_name, 
                     improvement_score, improvement_label, user_notes 
              FROM stress_impact_progress 
              WHERE client_id = ? AND assessment_id = ?
              ORDER BY area_name, session_number";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $client_id, $assessment_id);
    mysqli_stmt_execute($stmt);
    
    $session_number = null;
    $session_date = null;
    $area_name = null;
    $improvement_score = null;
    $improvement_label = null;
    $user_notes = null;
    
    mysqli_stmt_bind_result($stmt, $session_number, $session_date, $area_name, 
                            $improvement_score, $improvement_label, $user_notes);
    
    $progress = [];
    while (mysqli_stmt_fetch($stmt)) {
        // ✅ Normalize to lowercase for matching
        $area_key = strtolower($area_name);
        
        if (!isset($progress[$area_key])) {
            $progress[$area_key] = [];
        }
        $progress[$area_key][] = [
            'session_number' => $session_number,
            'session_date' => $session_date,
            'area_name' => $area_name,  // Keep original
            'improvement_score' => $improvement_score,
            'improvement_label' => $improvement_label,
            'user_notes' => $user_notes
        ];
    }
    
    mysqli_stmt_close($stmt);
    return $progress;
}

// ============================================
// FUNCTION: Generate Analysis for Each Area
// ============================================
function generate_analysis($area_name, $baseline_score, $session_data) {
    if (empty($session_data)) {
        return "⏳ <strong>Awaiting Follow-up:</strong> No progress tracking data yet for this area. Complete your next follow-up session to see improvement metrics.";
    }
    
    // Get latest session score
    $latest = end($session_data);
    $latest_score = $latest['improvement_score'];
    
    if ($latest_score === null) {
        if ($latest['user_notes']) {
            return "💬 <strong>Feedback Received:</strong> \"" . htmlspecialchars($latest['user_notes']) . "\" - Numeric tracking will help measure progress more precisely.";
        }
        return "📝 <strong>In Progress:</strong> Tracking data being collected for this area.";
    }
    
    // Calculate improvement
    $improvement = $latest_score - $baseline_score;
    $total_sessions = count($session_data);
    
    if ($improvement >= 75) {
        return "🎉 <strong>Excellent Progress!</strong> You've shown a {$improvement}% improvement in " . 
               ucwords(str_replace('_', ' ', $area_name)) . " over {$total_sessions} sessions. " .
               "The hypnotherapy protocol is working exceptionally well. Continue reinforcing with daily audio sessions.";
    } elseif ($improvement >= 50) {
        return "✅ <strong>Good Progress.</strong> {$improvement}% improvement over {$total_sessions} sessions. " .
               "You're moving in the right direction. A deeper transformation session would help solidify these gains and prevent regression.";
    } elseif ($improvement >= 25) {
        return "📈 <strong>Moderate Improvement.</strong> {$improvement}% improvement over {$total_sessions} sessions. " .
               "Progress is happening, but there's room for deeper transformation. Consider a live breakthrough session to accelerate results.";
    } elseif ($improvement > 0) {
        return "⚠️ <strong>Slight Improvement.</strong> Only {$improvement}% improvement over {$total_sessions} sessions. " .
               "Progress is slow. This area needs focused intervention with a deep session or live work to break through resistance patterns.";
    } else {
        return "🚨 <strong>No Improvement Yet.</strong> After {$total_sessions} sessions, this area shows no measurable progress. " .
               "This requires immediate attention. A live session with Inam is strongly recommended for breakthrough results.";
    }
}

// ============================================
// FUNCTION: Get Overall Recommendation
// ============================================
function get_recommendation($baseline, $progress) {
    $critical_count = 0;
    $severe_count = 0;
    $no_improvement = 0;
    $areas_tracked = 0;
    
    foreach ($baseline as $area => $base) {
        $sev_level = strtolower($base['severity_level']);
        if ($sev_level == 'critical') $critical_count++;
        if ($sev_level == 'severe') $severe_count++;
        
        // Check if this area has progress data
        if (isset($progress[$area]) && !empty($progress[$area])) {
            $areas_tracked++;
            $latest = end($progress[$area]);
            if ($latest['improvement_score'] !== null) {
                $improvement = $latest['improvement_score'] - $base['severity_score'];
                if ($improvement <= 0) $no_improvement++;
            }
        }
    }
    
    // Decision logic
    if ($critical_count >= 2 || $no_improvement >= 3) {
        return 'live';  // Recommend live session
    } elseif ($severe_count >= 3 || $no_improvement >= 2) {
        return 'deep';  // Recommend deep session
    } else {
        return 'audio';  // Continue with audio
    }
}

// ============================================
// GET DATA
// ============================================
$baseline = get_baseline($conn, $client_id, $assessment_id);
$progress = get_progress($conn, $client_id, $assessment_id);

// Get client info
$client_query = "SELECT firstname, lastname, current_issue FROM hdb_clients WHERE id = ?";
$stmt = mysqli_prepare($conn, $client_query);
mysqli_stmt_bind_param($stmt, "i", $client_id);
mysqli_stmt_execute($stmt);
$firstname = null;
$lastname = null;
$current_issue = null;
mysqli_stmt_bind_result($stmt, $firstname, $lastname, $current_issue);
mysqli_stmt_fetch($stmt);
mysqli_stmt_close($stmt);

$recommendation = get_recommendation($baseline, $progress);

// ============================================
// DISPLAY NAMES MAPPING
// ============================================
$display_names = [
    'body' => 'Physical Health & Body',
    'mood' => 'Mood & Emotions',
    'sleep' => 'Sleep Quality',
    'relationships' => 'Relationships & Family',
    'work' => 'Work & Career',
    'decisions' => 'Decision Making & Focus',
    'confidence' => 'Confidence & Self-Esteem',
    'finances' => 'Finances & Money',
    'behavior' => 'Behavior & Habits',
    'frequency' => 'Stress Frequency & Intensity'
];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Progress Report - <?php echo htmlspecialchars($firstname); ?></title>
<style>
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    margin: 0;
    padding: 20px;
    line-height: 1.6;
}
.container {
    max-width: 900px;
    margin: 0 auto;
    background: white;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    overflow: hidden;
}
.header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px 30px;
    text-align: center;
}
.header h1 {
    margin: 0 0 10px 0;
    font-size: 32px;
}
.header .subtitle {
    font-size: 16px;
    opacity: 0.9;
}
.content {
    padding: 40px 30px;
}
.intro {
    background: #f0f9ff;
    border-left: 4px solid #3b82f6;
    padding: 20px;
    margin-bottom: 30px;
    border-radius: 8px;
}
.intro p {
    margin: 0;
    color: #1e40af;
    font-size: 16px;
}
.area-card {
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    margin-bottom: 30px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.area-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px 25px;
    font-size: 22px;
    font-weight: 600;
}
.area-body {
    padding: 25px;
}
.session-row {
    display: grid;
    grid-template-columns: 120px 1fr 150px;
    gap: 20px;
    padding: 15px;
    border-bottom: 1px solid #f3f4f6;
    align-items: center;
}
.session-row:last-of-type {
    border-bottom: none;
}
.session-badge {
    background: #3b82f6;
    color: white;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
}
.baseline-badge {
    background: #f59e0b;
}
.session-content {
    font-size: 15px;
    color: #374151;
}
.score-badge {
    display: inline-block;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
}
.score-0 { background: #fee2e2; color: #991b1b; }
.score-25 { background: #fed7aa; color: #9a3412; }
.score-50 { background: #fef3c7; color: #92400e; }
.score-75 { background: #d9f99d; color: #3f6212; }
.score-100 { background: #bbf7d0; color: #14532d; }
.severity-critical { background: #fee2e2; color: #991b1b; }
.severity-severe { background: #fed7aa; color: #9a3412; }
.severity-moderate { background: #fef3c7; color: #92400e; }
.severity-mild { background: #d9f99d; color: #3f6212; }
.analysis-box {
    background: #f9fafb;
    border-left: 4px solid #667eea;
    padding: 20px;
    margin-top: 20px;
    border-radius: 8px;
    font-size: 15px;
    line-height: 1.8;
}
.recommendation-section {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 3px solid #f59e0b;
    border-radius: 16px;
    padding: 40px 30px;
    margin-top: 40px;
    text-align: center;
}
.recommendation-section h2 {
    color: #92400e;
    margin: 0 0 20px 0;
    font-size: 28px;
}
.recommendation-section p {
    color: #78350f;
    font-size: 16px;
    margin-bottom: 30px;
    line-height: 1.6;
}
.cta-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 30px;
}
.cta-button {
    display: block;
    padding: 20px 30px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    font-size: 16px;
    text-align: center;
    transition: all 0.3s;
}
.cta-primary {
    background: #ef4444;
    color: white;
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
}
.cta-primary:hover {
    background: #dc2626;
    transform: translateY(-2px);
}
.cta-secondary {
    background: #3b82f6;
    color: white;
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
}
.cta-secondary:hover {
    background: #2563eb;
    transform: translateY(-2px);
}
.price {
    font-size: 24px;
    font-weight: 700;
    display: block;
    margin-top: 10px;
}
.old-price {
    text-decoration: line-through;
    opacity: 0.6;
    font-size: 18px;
    margin-right: 10px;
}
.user-notes {
    font-style: italic;
    color: #6b7280;
    background: #f9fafb;
    padding: 10px 15px;
    border-radius: 8px;
    border-left: 3px solid #9ca3af;
}
.no-progress {
    text-align: center;
    padding: 20px;
    color: #9ca3af;
    font-style: italic;
}
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>📊 Your Progress Report</h1>
        <div class="subtitle">
            <?php echo htmlspecialchars($firstname . ' ' . $lastname); ?> | 
            <?php echo htmlspecialchars(ucwords($current_issue)); ?> | 
            <?php echo date('F j, Y'); ?>
        </div>
    </div>
    
    <div class="content">
        <div class="intro">
            <p>
                <strong><?php echo htmlspecialchars($firstname); ?>,</strong> 
                here's your detailed progress across all life areas affected by your stress. 
                Each section shows your baseline (where you started), your follow-up sessions, 
                and personalized analysis with recommendations.
            </p>
        </div>
        
        <?php
        // Only show areas from baseline table
        foreach ($baseline as $area => $base):
            // Get matching progress for this area (by area_name)
            $area_progress = isset($progress[$area]) ? $progress[$area] : [];
            
            // Get display name
            $area_display = isset($display_names[$area]) ? $display_names[$area] : ucwords(str_replace('_', ' ', $area));
            $severity_class = 'severity-' . strtolower($base['severity_level']);
        ?>
        
        <div class="area-card">
            <div class="area-header">
                <?php echo htmlspecialchars($area_display); ?>
            </div>
            
            <div class="area-body">
                <!-- Baseline (Session 1) -->
                <div class="session-row">
                    <div class="session-badge baseline-badge">Session 1<br><small>Baseline</small></div>
                    <div class="session-content">
                        <strong><?php echo htmlspecialchars($base['impact_description']); ?></strong>
                    </div>
                    <div>
                        <span class="score-badge <?php echo $severity_class; ?>">
                            <?php echo strtoupper($base['severity_level']); ?>
                        </span>
                    </div>
                </div>
                
                <!-- Progress Sessions (Session 2, 3, 4...) -->
                <?php if (!empty($area_progress)): ?>
                    <?php foreach ($area_progress as $session): 
                        $score = $session['improvement_score'];
                        $score_class = $score !== null ? 'score-' . $score : '';
                    ?>
                    <div class="session-row">
                        <div class="session-badge">Session <?php echo $session['session_number']; ?></div>
                        <div class="session-content">
                            <?php if ($session['user_notes']): ?>
                                <div class="user-notes">
                                    "<?php echo htmlspecialchars($session['user_notes']); ?>"
                                </div>
                            <?php else: ?>
                                <?php echo htmlspecialchars($session['improvement_label']); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($score !== null): ?>
                                <span class="score-badge <?php echo $score_class; ?>">
                                    <?php echo $score; ?>% Relief
                                </span>
                            <?php else: ?>
                                <span style="color: #9ca3af;">Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-progress">
                        No follow-up data yet for this area.
                    </div>
                <?php endif; ?>
                
                <!-- Analysis -->
                <div class="analysis-box">
                    <strong>📊 Analysis:</strong><br>
                    <?php echo generate_analysis($area, $base['severity_score'], $area_progress); ?>
                </div>
            </div>
        </div>
        
        <?php endforeach; ?>
        
        <!-- Recommendations -->
        <div class="recommendation-section">
            <?php if ($recommendation == 'live'): ?>
                <h2>🚨 Critical Areas Need Immediate Attention</h2>
                <p>
                    Based on your progress data, you have multiple critical or stalled areas. 
                    A <strong>Live Breakthrough Session</strong> with Inam is the fastest path to measurable results.
                </p>
                
                <div class="cta-buttons">
                    <a href="#" class="cta-button cta-primary">
                        🚀 Live Session with Inam
                        <span class="price">
                            <span class="old-price">$119</span> $99
                        </span>
                    </a>
                    <a href="#" class="cta-button cta-secondary">
                        💎 Deep Session
                        <span class="price">
                            <span class="old-price">$49</span> $29
                        </span>
                    </a>
                </div>
                
            <?php elseif ($recommendation == 'deep'): ?>
                <h2>⚡ Ready for Deeper Transformation?</h2>
                <p>
                    You've made some progress, but several areas need focused work. 
                    A <strong>Deep Transformation Session</strong> will address root patterns and accelerate your results significantly.
                </p>
                
                <div class="cta-buttons">
                    <a href="#" class="cta-button cta-primary">
                        💎 Deep Session
                        <span class="price">
                            <span class="old-price">$49</span> $29
                        </span>
                    </a>
                    <a href="#" class="cta-button cta-secondary">
                        🎧 Continue with Audio
                        <span class="price">FREE</span>
                    </a>
                </div>
                
            <?php else: ?>
                <h2>✅ You're Making Great Progress!</h2>
                <p>
                    Keep using your personalized audio daily. Your progress is solid. 
                    If you want to accelerate results even further, consider upgrading to a Deep Session.
                </p>
                
                <div class="cta-buttons">
                    <a href="#" class="cta-button cta-secondary">
                        🎧 Continue with Audio
                        <span class="price">FREE</span>
                    </a>
                    <a href="#" class="cta-button cta-primary">
                        💎 Optional: Deep Session
                        <span class="price">$29</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<?php mysqli_close($conn); ?>

</body>
</html>