<?php
/**
 * Advanced Branding Manager for VideoVizard.com
 * Handles Header, Footer, and Main Captions via a single dynamic interface.
 */

$configFile = 'branding_config.json';

// Default structure for each text type
$defaultTypeSettings = [
    'font_family' => 'Inter',
    'font_size'   => '24px',
    'font_color'  => '#ffffff',
    'bg_color'    => '#000000',
    'alignment'   => 'center',
    'animation'   => 'fade-in',
    'effects'     => 'none'
];

$defaultBranding = [
    'header'       => $defaultTypeSettings,
    'footer'       => array_merge($defaultTypeSettings, ['font_size' => '14px', 'animation' => 'none']),
    'main_caption' => array_merge($defaultTypeSettings, ['font_color' => '#ffff00', 'effects' => 'shadow']),
    'logo' => [
        'width' => '150px',
        'placement_h' => 'left',
        'placement_v' => 'top'
    ]
];

// Load or Initialize
if (file_exists($configFile)) {
    $branding = json_decode(file_get_contents($configFile), true);
} else {
    $branding = $defaultBranding;
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['text_type'];
    $branding[$type] = [
        'font_family' => $_POST['font_family'],
        'font_size'   => $_POST['font_size'],
        'font_color'  => $_POST['font_color'],
        'bg_color'    => $_POST['bg_color'],
        'alignment'   => $_POST['alignment'],
        'animation'   => $_POST['animation'],
        'effects'     => $_POST['effects'],
    ];
    // Keep logo settings intact
    file_put_contents($configFile, json_encode($branding, JSON_PRETTY_PRINT));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VideoVizard Pro Branding</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #1a1a1a; color: #eee; padding: 40px; }
        .card { max-width: 600px; background: #2d2d2d; padding: 30px; border-radius: 12px; margin: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-size: 0.85rem; color: #bbb; }
        select, input { width: 100%; padding: 10px; background: #3d3d3d; border: 1px solid #555; border-radius: 6px; color: white; box-sizing: border-box; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn { background: #007bff; color: white; border: none; padding: 12px; border-radius: 6px; width: 100%; cursor: pointer; font-weight: bold; margin-top: 20px; }
        .btn:hover { background: #0056b3; }
        hr { border: 0; border-top: 1px solid #444; margin: 25px 0; }
        .type-selector { background: #444; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #007bff; }
    </style>
</head>
<body>

<div class="card">
    <h2>Branding Configuration</h2>
    
    <form method="POST" id="brandingForm">
        <div class="type-selector">
            <label>Select Text Category to Edit:</label>
            <select name="text_type" id="text_type" onchange="updateFields()">
                <option value="header">Header</option>
                <option value="footer">Footer</option>
                <option value="main_caption">Main Caption</option>
            </select>
        </div>

        <div class="grid">
            <div class="form-group">
                <label>Font Family</label>
                <input type="text" name="font_family" id="font_family">
            </div>
            <div class="form-group">
                <label>Font Size</label>
                <input type="text" name="font_size" id="font_size">
            </div>
        </div>

        <div class="grid">
            <div class="form-group">
                <label>Text Color</label>
                <input type="color" name="font_color" id="font_color">
            </div>
            <div class="form-group">
                <label>Background Color</label>
                <input type="color" name="bg_color" id="bg_color">
            </div>
        </div>

        <div class="form-group">
            <label>Alignment</label>
            <select name="alignment" id="alignment">
                <option value="left">Left</option>
                <option value="center">Center</option>
                <option value="right">Right</option>
            </select>
        </div>

        <div class="grid">
            <div class="form-group">
                <label>Animation</label>
                <select name="animation" id="animation">
                    <option value="none">None</option>
                    <option value="fade-in">Fade In</option>
                    <option value="slide-up">Slide Up</option>
                    <option value="typewriter">Typewriter</option>
                    <option value="zoom-in">Zoom In</option>
                </select>
            </div>
            <div class="form-group">
                <label>Text Effects</label>
                <select name="effects" id="effects">
                    <option value="none">None</option>
                    <option value="shadow">Drop Shadow</option>
                    <option value="outline">Text Outline</option>
                    <option value="glow">Glow</option>
                    <option value="italic">Italic</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn">Update & Save Category</button>
    </form>
</div>

<script>
    // Pass PHP data to JavaScript
    const brandingData = <?php echo json_encode($branding); ?>;

    function updateFields() {
        const selectedType = document.getElementById('text_type').value;
        const data = brandingData[selectedType];

        document.getElementById('font_family').value = data.font_family;
        document.getElementById('font_size').value = data.font_size;
        document.getElementById('font_color').value = data.font_color;
        document.getElementById('bg_color').value = data.bg_color;
        document.getElementById('alignment').value = data.alignment;
        document.getElementById('animation').value = data.animation;
        document.getElementById('effects').value = data.effects;
    }

    // Initialize on page load
    window.onload = updateFields;
</script>

</body>
</html>