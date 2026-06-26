<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'dbconnect_hdb.php';

// Get assessment ID
$assessment_id = $_GET['assessment_id'] ?? 21;

// Fetch primary stress data
$sql_primary = "SELECT id, client_id, session_name, category, issue, client_description, duration, intensity, desired_outcome FROM stress_assessment_primary WHERE id = ?";
$stmt = $conn->prepare($sql_primary);

if ($stmt === false) {
    die("Query failed: " . $conn->error);
}

$stmt->bind_param("i", $assessment_id);
$stmt->execute();
$stmt->bind_result($id, $client_id, $session_name, $category, $issue, $client_description, $duration, $intensity, $desired_outcome);
$stmt->fetch();
$stmt->close();

$primary_data = [
    'id' => $id,
    'category' => $category,
    'issue' => $issue,
    'client_description' => $client_description,
    'duration' => $duration,
    'intensity' => $intensity,
    'desired_outcome' => $desired_outcome
];

if (!$primary_data['id']) {
    die("<h1>No assessment found with ID: $assessment_id</h1>");
}

// Fetch impact data
$sql_impact = "SELECT id, assessment_id, client_id, session_name, category, issue, area_name, severity_score, severity_level, impact_description FROM stress_assessment_impact WHERE assessment_id = ? ORDER BY area_name";
$stmt = $conn->prepare($sql_impact);

if (!$stmt) {
    die("Impact query failed: " . $conn->error);
}

$stmt->bind_param("i", $assessment_id);
$stmt->execute();
$stmt->bind_result($imp_id, $imp_assessment_id, $imp_client_id, $imp_session_name, $imp_category, $imp_issue, $imp_area_name, $imp_severity_score, $imp_severity_level, $imp_impact_description);

$impact_data = [];
while ($stmt->fetch()) {
    $impact_data[] = [
        'area_name' => $imp_area_name,
        'severity_score' => $imp_severity_score,
        'severity_level' => $imp_severity_level,
        'impact_description' => $imp_impact_description
    ];
}
$stmt->close();

// Analyze and score severity
$analyzed_impacts = analyzeSeverity($impact_data);

// Sort by severity first, then alphabetically within each level
usort($analyzed_impacts, function($a, $b) {
    $severity_order = [
        'Critical' => 5,
        'Severe' => 4,
        'Moderate' => 3,
        'Mild' => 2,
        'Stable' => 1
    ];
    
    $a_level = $severity_order[$a['severity_level']] ?? 0;
    $b_level = $severity_order[$b['severity_level']] ?? 0;
    
    if ($a_level !== $b_level) {
        return $b_level - $a_level;
    }
    
    return strcmp($a['area'], $b['area']);
});

// Count by severity
$critical = array_filter($analyzed_impacts, fn($i) => $i['severity_level'] === 'Critical');
$severe = array_filter($analyzed_impacts, fn($i) => $i['severity_level'] === 'Severe');
$moderate = array_filter($analyzed_impacts, fn($i) => $i['severity_level'] === 'Moderate');
$mild = array_filter($analyzed_impacts, fn($i) => $i['severity_level'] === 'Mild');
$stable = array_filter($analyzed_impacts, fn($i) => $i['severity_level'] === 'Stable');

// === FUNCTIONS ===

function getSymptomExplanation($symptom) {
    $explanations = [
        'chest_pain' => 'Your body is in fight-or-flight mode',
        'headaches' => 'Tension and stress are triggering physical pain',
        'stomach_pain' => 'Your gut is directly affected by stress hormones',
        'fatigue' => 'Your body is exhausted from being on high alert',
        'not_worthy' => 'Stress is damaging your self-perception',
        'incapable' => 'Stress is undermining your confidence',
        'anxious' => 'Your nervous system is in constant alert mode',
        'nightmares' => 'Stress is manifesting in your dreams',
        'waking_early' => 'Stress hormones are disrupting your sleep cycle',
        'daytime_sleepiness' => 'Poor sleep quality is affecting your energy',
        'relationship_strain' => 'Stress is creating distance with loved ones',
        'cant_concentrate' => 'Stress is impairing your focus and attention',
        'dreading_work' => 'Work has become a source of anxiety',
        'burnt_out' => 'Running on empty with no reserves left',
        'overthinking' => 'Analysis paralysis is preventing action',
        'fear_failure' => 'Worried about disappointing others',
        'doubting_self' => 'Imposter syndrome is strong',
        'unexpected_expenses' => 'Financial surprises adding to stress',
        'overeating' => 'Using food for emotional comfort',
        'disrupted_routine' => 'Normal patterns are falling apart',
        'feeling_more' => 'The stress is intensifying over time',
        'increasing' => 'Getting worse over time',
        'not_sure' => 'Uncertain about the pattern',
        'unsure' => 'Hard to pinpoint exactly'
    ];
    
    return $explanations[$symptom] ?? 'This is affecting your wellbeing';
}

function analyzeSeverity($impact_data) {
    $severity_map = [
        'chest_pain' => 10, 'headaches' => 7, 'stomach_pain' => 7,
        'fatigue' => 7, 'not_worthy' => 9, 'incapable' => 9,
        'anxious' => 8, 'nightmares' => 8, 'waking_early' => 7,
        'daytime_sleepiness' => 6, 'relationship_strain' => 8,
        'cant_concentrate' => 9, 'dreading_work' => 8, 'burnt_out' => 10,
        'overthinking' => 7, 'fear_failure' => 8, 'doubting_self' => 8,
        'unexpected_expenses' => 6, 'overeating' => 6,
        'disrupted_routine' => 6, 'feeling_more' => 7,
        'increasing' => 7, 'not_sure' => 5, 'unsure' => 5,
        'no_impact' => 0, 'no' => 0
    ];
    
    $analyzed = [];
    
    foreach ($impact_data as $impact) {
        $area = $impact['area_name'];
        $description = $impact['impact_description'];
        
        $symptoms = explode('|', $description);
        
        $max_severity = 0;
        $symptom_labels = [];
        
        foreach ($symptoms as $symptom) {
            $symptom = trim($symptom);
            if (empty($symptom) || $symptom === 'no' || $symptom === 'no_impact') continue;
            
            $severity = $severity_map[$symptom] ?? 5;
            if ($severity > $max_severity) {
                $max_severity = $severity;
            }
            
            $label = ucwords(str_replace('_', ' ', $symptom));
            $explanation = getSymptomExplanation($symptom);
            $symptom_labels[] = [
                'label' => $label,
                'explanation' => $explanation
            ];
        }
        
        $level = 'Stable';
        if ($max_severity >= 9) $level = 'Critical';
        elseif ($max_severity >= 7) $level = 'Severe';
        elseif ($max_severity >= 5) $level = 'Moderate';
        elseif ($max_severity >= 3) $level = 'Mild';
        
        $analyzed[] = [
            'area' => $area,
            'severity_score' => $max_severity,
            'severity_level' => $level,
            'symptoms' => $symptom_labels
        ];
    }
    
    return $analyzed;
}

function getWhyItMatters($area, $severity_level) {
    $matters = [
        'Body' => [
            'Critical' => 'Chronic physical stress symptoms can lead to long-term health issues if not addressed. Your body needs relief urgently.',
            'Severe' => 'Physical symptoms are your body\'s way of telling you it\'s overwhelmed. Addressing this now prevents more serious health problems.',
            'Moderate' => 'Your body is showing signs of stress that shouldn\'t be ignored. Early intervention can prevent escalation.',
        ],
        'Mood' => [
            'Critical' => 'Your emotional wellbeing is severely compromised, affecting your ability to think clearly and make decisions.',
            'Severe' => 'Emotional distress at this level impacts every area of your life and requires immediate attention.',
            'Moderate' => 'Your emotional state needs support to prevent it from affecting other areas of your wellbeing.',
        ],
        'Sleep' => [
            'Critical' => 'Sleep deprivation amplifies stress, creates a vicious cycle, and impairs your ability to function effectively.',
            'Severe' => 'Poor sleep is undermining your body\'s ability to recover and cope with stress.',
            'Moderate' => 'Sleep disruption affects your mood, energy, and concentration. Improving sleep will help everything else.',
        ],
        'Relationships' => [
            'Critical' => 'Isolation and relationship strain compound stress and remove your support system when you need it most.',
            'Severe' => 'Relationship difficulties are both a cause and effect of stress, creating a challenging cycle.',
            'Moderate' => 'Your connections with others are being affected. Maintaining relationships provides crucial support.',
        ],
        'Work' => [
            'Critical' => 'Ironically, stress about work is making it harder to actually complete your tasks, creating a self-fulfilling prophecy.',
            'Severe' => 'Work performance is significantly impaired, which likely increases your stress further.',
            'Moderate' => 'Declining work performance adds to stress and can have real consequences. Early intervention is key.',
        ],
        'Decisions' => [
            'Critical' => 'Impaired decision-making affects every area of your life and can lead to choices you later regret.',
            'Severe' => 'Decision difficulties are preventing you from taking action to improve your situation.',
            'Moderate' => 'Struggling with decisions adds mental burden and can lead to paralysis or poor choices.',
        ],
        'Confidence' => [
            'Critical' => 'Severely damaged confidence creates a downward spiral, making it harder to take positive action.',
            'Severe' => 'Low confidence is preventing you from accessing your actual capabilities and strengths.',
            'Moderate' => 'Declining confidence affects your willingness to tackle challenges and seek help.',
        ],
        'Finances' => [
            'Critical' => 'Financial stress compounds all other stress and requires immediate attention to prevent crisis.',
            'Severe' => 'Money worries create constant anxiety and limit your options for managing other stressors.',
            'Moderate' => 'Financial concerns add mental burden and deserve attention before they escalate.',
        ],
        'Behaviour' => [
            'Critical' => 'Severely disrupted habits and routines make everything harder and reduce your ability to cope.',
            'Severe' => 'Major behavioral changes indicate significant stress and can become problematic patterns.',
            'Moderate' => 'Changing habits affect your wellbeing and addressing them now prevents entrenchment.',
        ]
    ];
    
    return $matters[$area][$severity_level] ?? 'Addressing this area will improve your overall wellbeing and stress levels.';
}

function getFutureVision($analyzed_impacts, $issue) {
    $visions = [];
    
    foreach ($analyzed_impacts as $impact) {
        if ($impact['severity_level'] !== 'Critical' && $impact['severity_level'] !== 'Severe') {
            continue;
        }
        
        foreach ($impact['symptoms'] as $symptom) {
            $symptom_key = strtolower(str_replace(' ', '_', $symptom['label']));
            
            $outcomes = [
                'chest_pain' => 'the tightness in your chest isn\'t there',
                'headaches' => 'you wake up without that pounding headache',
                'cant_sleep' => 'you had your first full night\'s sleep in weeks',
                'waking_early' => 'you sleep through the entire night',
                'nightmares' => 'your dreams are peaceful',
                'anxious' => 'you feel calm and centered',
                'overwhelmed' => 'you feel in control',
                'not_worthy' => 'you feel confident in yourself',
                'incapable' => 'you know you can handle this',
                'relationship_strain' => 'you feel connected to your loved ones again',
                'cant_concentrate' => 'your mind is clear and focused',
                'dreading_work' => 'you actually look forward to your day',
                'burnt_out' => 'you have energy and enthusiasm again',
                'doubting_self' => 'you trust yourself',
                'fear_failure' => 'you feel capable and confident'
            ];
            
            if (isset($outcomes[$symptom_key])) {
                $visions[] = $outcomes[$symptom_key];
            }
        }
    }
    
    $visions = array_unique($visions);
    $visions = array_slice($visions, 0, 3);
    
    if (empty($visions)) {
        return "It's 7 days from now. You wake up feeling refreshed and capable. When you think about " . strtolower(str_replace('_', ' ', $issue)) . ", you feel calm and in control instead of anxious and overwhelmed.";
    }
    
    $vision = "It's 7 days from now. You wake up and... " . $visions[0];
    if (isset($visions[1])) {
        $vision .= ". " . ucfirst($visions[1]);
    }
    $vision .= ". When you think about " . strtolower(str_replace('_', ' ', $issue)) . ", you feel calm and capable instead of anxious and overwhelmed.";
    
    return $vision;
}

function getAreaIcon($area) {
    $icons = [
        'Body' => '💪', 'Mood' => '❤️', 'Sleep' => '😴',
        'Relationships' => '👥', 'Work' => '💼', 'Decisions' => '🧠',
        'Confidence' => '💪', 'Finances' => '💰', 'Behaviour' => '🏠',
        'Occurance' => '📅', 'Commitment' => '🎯', 'Open Reflection' => '💭'
    ];
    return $icons[$area] ?? '📊';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Stress Assessment Report - StressReleasor</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            padding: 20px;
            line-height: 1.6;
            color: #2c3e50;
        }
        .report-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .report-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .report-header h1 { font-size: 32px; margin-bottom: 10px; }
        .report-date { margin-top: 15px; font-size: 14px; opacity: 0.8; }
        .report-section { padding: 30px; border-bottom: 1px solid #e9ecef; }
        .report-section:last-child { border-bottom: none; }
        .section-title {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
        }
        .summary-box {
            background: #e0f2fe;
            border-left: 5px solid #0284c7;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .summary-box strong { color: #0369a1; }
        .empathic-message {
            background: #fef3c7;
            border-left: 5px solid #f59e0b;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-style: italic;
            color: #92400e;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-number { font-size: 42px; font-weight: 700; display: block; }
        .stat-label { font-size: 14px; opacity: 0.9; }
        .impact-grid { display: grid; gap: 20px; margin-top: 20px; }
        .impact-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border-left: 6px solid;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .impact-critical {
            border-left-color: #dc2626;
            background: linear-gradient(to right, #fef2f2 0%, white 100%);
        }
        .impact-critical .impact-header { color: #dc2626; }
        .impact-critical .severity-badge { background: #dc2626; }
        .impact-severe {
            border-left-color: #ea580c;
            background: linear-gradient(to right, #fff7ed 0%, white 100%);
        }
        .impact-severe .impact-header { color: #ea580c; }
        .impact-severe .severity-badge { background: #ea580c; }
        .impact-moderate {
            border-left-color: #f59e0b;
            background: linear-gradient(to right, #fef3c7 0%, white 100%);
        }
        .impact-moderate .impact-header { color: #d97706; }
        .impact-moderate .severity-badge { background: #f59e0b; }
        .impact-stable {
            border-left-color: #10b981;
            background: linear-gradient(to right, #d1fae5 0%, white 100%);
        }
        .impact-stable .impact-header { color: #059669; }
        .impact-stable .severity-badge { background: #10b981; }
        .impact-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .impact-header h3 { font-size: 20px; }
        .severity-badge {
            padding: 6px 16px;
            border-radius: 20px;
            color: white;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .symptom-list { list-style: none; margin-left: 0; }
        .symptom-list li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
            color: #4b5563;
        }
        .symptom-list li:before {
            content: "•";
            position: absolute;
            left: 0;
            font-size: 20px;
        }
        .cta-button {
            display: inline-block;
            padding: 15px 40px;
            margin: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 30px;
            font-weight: 600;
        }
        .cta-button:hover { opacity: 0.9; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            body { padding: 10px; }
            .report-section { padding: 20px; }
        }
    </style>
</head>
<body>

<div class="report-container">
    
    <div class="report-header">
        <h1>🧘 Your Personalized Stress Assessment</h1>
        <p>Understanding How Stress is Impacting Your Life</p>
        <div class="report-date">📅 Generated: <?php echo date('F j, Y \a\t g:i A'); ?></div>
    </div>

    <!-- SUMMARY -->
    <div class="report-section">
        <h2 class="section-title">📋 Assessment Summary</h2>
        
        <div class="summary-box">
            <p><strong>Primary Issue:</strong> 
                <?php echo ucwords(str_replace('_', ' ', $primary_data['category'] ?? 'Not specified')); ?> - 
                <?php echo ucwords(str_replace('_', ' ', $primary_data['issue'] ?? 'Not specified')); ?>
            </p>
            
            <?php if (!empty($primary_data['client_description'])): ?>
            <p style="margin-top: 15px;"><strong>What You Shared:</strong><br>
            "<?php echo htmlspecialchars($primary_data['client_description']); ?>"</p>
            <?php endif; ?>
            
            <p style="margin-top: 15px;">
                <strong>Duration:</strong> <?php echo ucwords(str_replace('_', ' ', $primary_data['duration'] ?? 'Not specified')); ?><br>
                <strong>Intensity:</strong> <?php echo $primary_data['intensity'] ?? 'Not specified'; ?><br>
                <strong>Your Goal:</strong> <?php echo ucwords(str_replace('_', ' ', $primary_data['desired_outcome'] ?? 'Not specified')); ?>
            </p>
        </div>
        
        <div class="empathic-message">
            💙 <strong>I hear you, and what you're feeling is completely valid.</strong><br><br>
            Experiencing stress related to <?php echo strtolower(str_replace('_', ' ', $primary_data['category'] ?? 'various challenges')); ?>, 
            specifically <?php echo strtolower(str_replace('_', ' ', $primary_data['issue'] ?? 'what you described')); ?>, can feel overwhelming.<br><br>
            The fact that you're here seeking help shows incredible self-awareness and strength.
        </div>
    </div>

    <!-- STATS -->
    <div class="report-section">
        <h2 class="section-title">📊 Impact Overview</h2>
        
        <div class="stats-grid">
            <div class="stat-card" style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">
                <span class="stat-number"><?php echo count($critical); ?></span>
                <span class="stat-label">Critical Impact</span>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);">
                <span class="stat-number"><?php echo count($severe); ?></span>
                <span class="stat-label">Severe Impact</span>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                <span class="stat-number"><?php echo count($moderate); ?></span>
                <span class="stat-label">Moderate Impact</span>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                <span class="stat-number"><?php echo count($stable); ?></span>
                <span class="stat-label">Stable Areas</span>
            </div>
        </div>
    </div>

    <!-- IMPACT DETAILS -->
    <div class="report-section">
        <h2 class="section-title">🎯 Detailed Life Impact Analysis</h2>
        
        <div class="impact-grid">
            <?php foreach ($analyzed_impacts as $impact): 
                $severity_class = 'impact-' . strtolower($impact['severity_level']);
            ?>
            
            <div class="impact-card <?php echo $severity_class; ?>">
                <div class="impact-header">
                    <h3><?php echo getAreaIcon($impact['area']); ?> <?php echo $impact['area']; ?></h3>
                    <span class="severity-badge"><?php echo $impact['severity_level']; ?></span>
                </div>
                <ul class="symptom-list">
                    <?php foreach ($impact['symptoms'] as $symptom): ?>
                    <li>
                        <strong><?php echo $symptom['label']; ?></strong> - 
                        <?php echo $symptom['explanation']; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                
                <?php if ($impact['severity_level'] !== 'Stable'): ?>
                <p style="margin-top: 15px; padding: 15px; background: rgba(<?php 
                    echo $impact['severity_level'] === 'Critical' ? '220, 38, 38' : 
                        ($impact['severity_level'] === 'Severe' ? '234, 88, 12' : '245, 158, 11'); 
                ?>, 0.1); border-radius: 8px; font-size: 14px;">
                    ⚠️ <strong>Why this matters:</strong> <?php echo getWhyItMatters($impact['area'], $impact['severity_level']); ?>
                </p>
                <?php endif; ?>
            </div>
            
            <?php endforeach; ?>
        </div>
    </div>

    <!-- PROFESSIONAL ASSESSMENT -->
    <div class="report-section">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 35px; border-radius: 12px;">
            <h2 style="font-size: 28px; margin-bottom: 25px; text-align: center;">💡 My Professional Assessment & Recommendation</h2>
            
            <div style="background: rgba(255,255,255,0.1); padding: 25px; border-radius: 8px; margin-bottom: 25px;">
                <h3 style="font-size: 22px; margin-bottom: 20px;">🎯 What This Assessment Tells Me</h3>
                <p style="font-size: 16px; line-height: 1.8; margin-bottom: 15px;">Based on your comprehensive assessment, here's the reality:</p>
                <ul style="margin-left: 25px; line-height: 2; font-size: 16px;">
                    <?php if (count($critical) > 0): ?>
                    <li><strong><?php echo count($critical); ?> critical area<?php echo count($critical) > 1 ? 's' : ''; ?></strong> in the danger zone</li>
                    <?php endif; ?>
                    <?php if (count($severe) > 0): ?>
                    <li><strong><?php echo count($severe); ?> severe area<?php echo count($severe) > 1 ? 's' : ''; ?></strong> significantly impaired</li>
                    <?php endif; ?>
                    <?php if (count($moderate) > 0): ?>
                    <li><strong><?php echo count($moderate); ?> moderate area<?php echo count($moderate) > 1 ? 's' : ''; ?></strong> need support</li>
                    <?php endif; ?>
                    <li><strong>Duration:</strong> Already <?php echo strtolower(str_replace('_', ' ', $primary_data['duration'])); ?></li>
                </ul>
            </div>

            <div style="background: rgba(255,255,255,0.15); padding: 25px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid white;">
                <p style="font-size: 18px; line-height: 1.9; margin-bottom: 15px;">💙 <strong>Here's what I want you to understand:</strong></p>
                <p style="font-size: 16px; line-height: 1.8; margin-bottom: 15px;">
                    This level of stress isn't sustainable. Your body and mind are sending you urgent signals that they need support right now - these aren't character flaws or signs of weakness. They're your body's alarm system telling you: <strong>"We need help."</strong>
                </p>
                <p style="font-size: 17px; line-height: 1.8; font-weight: 600;">
                    But here's the good news: This is exactly what I specialize in helping people overcome. And it's more fixable than you think.
                </p>
            </div>

            <div style="background: white; color: #1f2937; padding: 25px; border-radius: 8px;">
                <h3 style="font-size: 22px; margin-bottom: 20px; color: #667eea;">🎯 My Recommendation:</h3>
                <div style="display: grid; gap: 15px;">
                    <div style="display: flex; gap: 12px;">
                        <span style="color: #667eea; font-size: 24px;">✓</span>
                        <div>
                            <p style="font-weight: 600;">Immediate relief session</p>
                            <p style="color: #6b7280; font-size: 14px;">Get your stress level down TODAY so you can actually function</p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <span style="color: #667eea; font-size: 24px;">✓</span>
                        <div>
                            <p style="font-weight: 600;">Root cause protocol</p>
                            <p style="color: #6b7280; font-size: 14px;">Address WHY your nervous system is stuck in this pattern</p>
                        </div>
                    </div>
                </div>
                
                <div style="background: #fee2e2; padding: 20px; border-radius: 8px; margin-top: 25px; border-left: 4px solid #dc2626;">
                    <p style="color: #991b1b; font-weight: 600; margin-bottom: 10px;">⚠️ Important:</p>
                    <p style="color: #7f1d1d; line-height: 1.7; font-size: 15px;">
                        If left unaddressed, this stress pattern can lead to burnout, panic attacks, or complete shutdown. The time to act is NOW, not later.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- FREE AUDIO & OFFERS -->
    <div class="report-section">
        <h2 class="section-title">🎯 Your Next Steps</h2>
        
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; text-align: center;">
            <h3 style="font-size: 26px; margin-bottom: 15px;">🎧 Your FREE Personalized Audio is Ready</h3>
            <p style="font-size: 16px; margin-bottom: 20px; opacity: 0.95; line-height: 1.7;">
                In a few moments, your personalized audio will be ready and you will see the <strong>"Audio Play"</strong> button active at the bottom.
            </p>
            <p style="font-size: 16px; margin-bottom: 20px; opacity: 0.95; line-height: 1.7;">
                Listen to this <strong>before you get up from your bed</strong> and <strong>before you go to sleep</strong>. Use headphones for best results.
            </p>
            <div style="background: rgba(255,255,255,0.2); padding: 15px; border-radius: 8px;">
                <p style="font-size: 14px; opacity: 0.9;">⏰ This is your personal audio available for the next <strong>14 days</strong></p>
            </div>
        </div>

        <div style="background: #fff3cd; border-left: 5px solid #ffc107; padding: 25px; border-radius: 8px; margin-bottom: 30px;">
            <p style="font-size: 17px; font-weight: 600; color: #856404; margin-bottom: 15px;">⚠️ But I need to be honest with you about something important...</p>
            <p style="color: #856404; line-height: 1.8;">
                The free audio provides relaxation, but your underlying issue remains unresolved. Without a strategy to handle future stress, the pattern typically returns.
            </p>
        </div>

        <div style="padding: 25px; background: #f8f9fa; border-radius: 8px; margin-bottom: 30px;">
            <h4 style="color: #dc2626; margin-bottom: 15px; font-size: 20px;">Here's What Happens if You Only Use the Free Audio:</h4>
            <div style="display: grid; gap: 15px;">
                <div style="display: flex; gap: 10px;">
                    <span style="color: #10b981; font-size: 24px;">✓</span>
                    <p style="color: #6b7280;">You will definitely feel better and find relief</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <span style="color: #dc2626; font-size: 24px;">✗</span>
                    <p style="color: #6b7280;">However, the same triggers will likely activate again - because we haven't addressed the root cause</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <span style="color: #dc2626; font-size: 24px;">✗</span>
                    <p style="color: #6b7280;">This audio provides relaxation, but your underlying issue remains unresolved</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <span style="color: #dc2626; font-size: 24px;">✗</span>
                    <p style="color: #6b7280;">Without a strategy to handle future stress, the pattern typically returns</p>
                </div>
            </div>
            <p style="margin-top: 20px; font-weight: 600; color: #667eea; font-size: 18px; text-align: center;">
                This is where most people in your situation make a critical decision...
            </p>
        </div>

        <!-- DEEP SESSION OFFER -->
        <div style="border: 3px solid #667eea; border-radius: 15px; padding: 35px; margin-bottom: 30px; background: linear-gradient(to bottom, #f8f9ff 0%, white 100%);">
            <div style="text-align: center; margin-bottom: 25px;">
                <div style="display: inline-block; background: #dc2626; color: white; padding: 8px 20px; border-radius: 20px; font-size: 14px; font-weight: 600; margin-bottom: 15px;">
                    🔥 LAUNCH SPECIAL - LIMITED TIME
                </div>
                <h3 style="font-size: 32px; color: #667eea; margin-bottom: 10px;">💎 Deep Transformation Session</h3>
                <p style="font-size: 20px; color: #6b7280;">
                    <span style="text-decoration: line-through; color: #9ca3af;">$49</span> 
                    <span style="font-size: 36px; font-weight: 700; color: #dc2626; margin: 0 10px;">$29</span>
                </p>
                <p style="color: #667eea; font-weight: 600; margin-top: 10px;">Personally Reviewed by Inam Alvi, Certified Clinical Hypnotherapist</p>
            </div>

            <div style="background: #e0f2fe; padding: 20px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid #0284c7;">
                <p style="font-size: 17px; font-style: italic; color: #0369a1; line-height: 1.8;">
                    <strong>Imagine this:</strong> <?php echo getFutureVision($analyzed_impacts, $primary_data['issue']); ?>
                </p>
                <p style="margin-top: 15px; font-weight: 600; color: #0369a1; font-size: 16px;">
                    That's not wishful thinking. That's what happens when we address the root patterns.
                </p>
            </div>

            <div style="text-align: center;">
                <a href="#" class="cta-button" style="font-size: 20px; padding: 18px 50px;">💎 Yes, I Want My Deep Session - $29</a>
                <p style="margin-top: 15px; font-size: 14px; color: #6b7280;">Secure checkout • Instant access • 7-day guarantee</p>
            </div>
        </div>

        <!-- LIVE SESSION -->
        <div style="border: 3px solid #dc2626; border-radius: 15px; padding: 35px; margin-bottom: 30px; background: linear-gradient(to bottom, #fef2f2 0%, white 100%);">
            <div style="text-align: center; margin-bottom: 25px;">
                <h3 style="font-size: 32px; color: #dc2626; margin-bottom: 10px;">🚀 60-Minute Live Session with Inam Alvi</h3>
                <p style="font-size: 20px; color: #6b7280;">
                    <span style="text-decoration: line-through; color: #9ca3af;">$119</span> 
                    <span style="font-size: 36px; font-weight: 700; color: #dc2626; margin: 0 10px;">$99</span>
                </p>
            </div>

            <div style="text-align: center;">
                <a href="#" class="cta-button" style="font-size: 20px; padding: 18px 50px; background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);">🚀 Yes, I Want Live Breakthrough - $99</a>
                <p style="margin-top: 15px; font-size: 14px; color: #6b7280;">Video or phone • 60 minutes • Recording included</p>
            </div>
        </div>

        <div style="text-align: center;">
            <a href="#" class="cta-button" style="background: white; color: #667eea; border: 2px solid #667eea;" onclick="openChat(); return false;">
                💬 I Have Questions - Chat with Me
            </a>
        </div>
    </div>

</div>

 

</body>
</html>