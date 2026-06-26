<?php
// standalone_image_gen.php - Improved with better timeout handling
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();

require_once 'config.php';

// Support multiple API key variables
$apiKey = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;

// Modal API endpoint
$MODAL_URL = 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image';

// Function to enhance prompt using ChatGPT
function enhancePrompt($prompt, $apiKey) {
    $systemPrompt = "You are an expert image prompt engineer. Enhance the following prompt for a photorealistic image generation model.
    
IMPORTANT RULES:
- Use cool-neutral daylight lighting (5500K-6500K), never warm/yellow tones
- Add: 'soft natural daylight', 'cool white studio lighting', 'vibrant true-to-life colors'
- Camera: 35mm lens, shallow depth of field
- End with: 'photorealistic, sharp focus, no warm cast'
- Keep under 150 words

Return ONLY the enhanced prompt text, no explanation, no JSON.";

    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Enhance this prompt: " . $prompt]
        ],
        'temperature' => 0.7,
        'max_tokens' => 300
    ];
    
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return $prompt;
    }
    
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? $prompt;
}

// Function to generate image using Modal/FLUX with better timeout and retry
function generateWithModal($prompt, $maxRetries = 2) {
    global $MODAL_URL;
    
    $payload = json_encode([
        'prompt' => $prompt,
        'style' => 'cinematic',
        'width' => 768,
        'height' => 1344
    ]);
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal attempt $attempt for prompt: ".substr($prompt,0,50)."\n", FILE_APPEND);
        
        $ch = curl_init($MODAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90,  // Increased to 90 seconds
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($payload)
            ]
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        file_put_contents(__DIR__.'/a_errors.log', date('Y-m-d H:i:s')." | Modal attempt $attempt: http=$httpCode, duration={$duration}s, errno=$curlErrno, error=$curlError\n", FILE_APPEND);
        
        // Success
        if ($curlErrno === 0 && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            
            if (!empty($data['image'])) {
                $imageData = base64_decode($data['image']);
                $filename = 'modal_' . time() . '_' . mt_rand(1000, 9999) . '.png';
                $filepath = __DIR__ . '/generated_images/' . $filename;
                
                if (!is_dir(__DIR__ . '/generated_images')) {
                    mkdir(__DIR__ . '/generated_images', 0777, true);
                }
                
                if (file_put_contents($filepath, $imageData) !== false) {
                    return [
                        'success' => true,
                        'filename' => $filename,
                        'filepath' => 'generated_images/' . $filename,
                        'source' => 'Modal/FLUX',
                        'seed' => $data['seed'] ?? null,
                        'duration' => $duration
                    ];
                }
            }
        }
        
        // If not last attempt, wait before retry (in case of cold start)
        if ($attempt < $maxRetries) {
            sleep(3); // Wait 3 seconds before retry
        }
    }
    
    return [
        'success' => false, 
        'error' => 'Modal API failed after ' . $maxRetries . ' attempts. This usually means the server is cold starting. Try again or use OpenAI.'
    ];
}

// Function to generate image using OpenAI DALL-E 3
function generateWithOpenAI($prompt, $apiKey) {
    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'size' => '1024x1792',
        'quality' => 'standard',
        'n' => 1
    ];
    
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        $msg = $error['error']['message'] ?? "HTTP $httpCode";
        return ['success' => false, 'error' => "OpenAI error: $msg"];
    }
    
    $json = json_decode($response, true);
    $imageUrl = $json['data'][0]['url'] ?? null;
    $revisedPrompt = $json['data'][0]['revised_prompt'] ?? '';
    
    if (!$imageUrl) {
        return ['success' => false, 'error' => 'No image URL returned'];
    }
    
    $imageData = file_get_contents($imageUrl);
    $filename = 'openai_' . time() . '_' . mt_rand(1000, 9999) . '.png';
    $filepath = __DIR__ . '/generated_images/' . $filename;
    
    if (!is_dir(__DIR__ . '/generated_images')) {
        mkdir(__DIR__ . '/generated_images', 0777, true);
    }
    
    if (file_put_contents($filepath, $imageData) === false) {
        return ['success' => false, 'error' => 'Failed to save image'];
    }
    
    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => 'generated_images/' . $filename,
        'source' => 'OpenAI DALL-E 3',
        'revised_prompt' => $revisedPrompt
    ];
}

// Function to test if Modal endpoint is alive
function testModalEndpoint() {
    global $MODAL_URL;
    
    $ch = curl_init($MODAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => 'HEAD'
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode > 0;
}

// Handle AJAX requests
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'test_modal') {
    header('Content-Type: application/json');
    $alive = testModalEndpoint();
    echo json_encode(['success' => true, 'alive' => $alive]);
    exit;
}

if ($action === 'generate_modal') {
    header('Content-Type: application/json');
    
    $originalPrompt = trim($_POST['prompt'] ?? '');
    $enhance = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    
    if (empty($originalPrompt)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a prompt']);
        exit;
    }
    
    // Step 1: Enhance prompt if requested
    $promptToUse = $originalPrompt;
    if ($enhance && $apiKey) {
        $promptToUse = enhancePrompt($originalPrompt, $apiKey);
    }
    
    // Step 2: Generate with Modal
    $result = generateWithModal($promptToUse);
    
    echo json_encode($result);
    exit;
}

if ($action === 'generate_openai') {
    header('Content-Type: application/json');
    
    if (!$apiKey) {
        echo json_encode(['success' => false, 'error' => 'OpenAI API key not found in config.php']);
        exit;
    }
    
    $originalPrompt = trim($_POST['prompt'] ?? '');
    $enhance = isset($_POST['enhance']) && $_POST['enhance'] === 'true';
    
    if (empty($originalPrompt)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a prompt']);
        exit;
    }
    
    $promptToUse = $originalPrompt;
    if ($enhance) {
        $promptToUse = enhancePrompt($originalPrompt, $apiKey);
    }
    
    $result = generateWithOpenAI($promptToUse, $apiKey);
    echo json_encode($result);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Image Generator - Modal vs OpenAI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f2a44 0%, #1a4a7a 100%);
            min-height: 100vh;
            padding: 24px;
        }
        
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: white; margin-bottom: 8px; font-size: 28px; }
        .subtitle { color: rgba(255,255,255,0.7); margin-bottom: 32px; font-size: 14px; }
        
        .main-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        
        .card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .card-header { padding: 20px 24px; border-bottom: 2px solid #f0f0f0; }
        .card-header h2 { font-size: 20px; margin-bottom: 4px; }
        .card-header p { color: #64748b; font-size: 13px; }
        .card-body { padding: 24px; }
        
        .card.modal .card-header { background: linear-gradient(135deg, #7c3aed, #5b21b6); color: white; }
        .card.modal .card-header p { color: rgba(255,255,255,0.8); }
        .card.openai .card-header { background: linear-gradient(135deg, #0f2a44, #1e4a7a); color: white; }
        .card.openai .card-header p { color: rgba(255,255,255,0.8); }
        
        .input-group { margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 6px; }
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            outline: none;
        }
        textarea:focus { border-color: #7c3aed; }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        .checkbox-group input { width: 18px; height: 18px; cursor: pointer; }
        .checkbox-group label { margin-bottom: 0; cursor: pointer; }
        
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-modal { background: linear-gradient(135deg, #7c3aed, #5b21b6); color: white; }
        .btn-openai { background: linear-gradient(135deg, #0f2a44, #1e4a7a); color: white; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.2); }
        .btn:disabled { opacity: 0.6; transform: none; cursor: not-allowed; }
        
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .result-area { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .result-image { margin-top: 16px; text-align: center; }
        .result-image img { max-width: 100%; max-height: 400px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .result-info {
            background: #f8fafc;
            padding: 12px;
            border-radius: 10px;
            font-size: 12px;
            margin-top: 12px;
            word-break: break-all;
        }
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-top: 12px;
        }
        .warning-message {
            background: #fffbeb;
            color: #d97706;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-top: 12px;
            border-left: 4px solid #f59e0b;
        }
        .loading { text-align: center; padding: 40px; color: #64748b; }
        
        @media (max-width: 900px) { .main-grid { grid-template-columns: 1fr; } }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .badge-modal { background: #ede9fe; color: #5b21b6; }
        .badge-openai { background: #e0f2fe; color: #0369a1; }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        .status-online { background: #10b981; box-shadow: 0 0 4px #10b981; }
        .status-offline { background: #ef4444; }
        .status-checking { background: #f59e0b; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    </style>
</head>
<body>
<div class="container">
    <h1>🎨 AI Image Generator</h1>
    <p class="subtitle">Generate images using Modal/FLUX or OpenAI DALL-E 3 — same prompt, different results</p>
    
    <div class="main-grid">
        
        <!-- Modal/FLUX Card -->
        <div class="card modal">
            <div class="card-header">
                <h2>🎭 Generate with Modal/FLUX <span class="badge badge-modal">Beta</span></h2>
                <p>Custom-trained model — first request may take 30-60 seconds (cold start)</p>
            </div>
            <div class="card-body">
                <div class="input-group">
                    <label>Image Prompt</label>
                    <textarea id="modalPrompt" rows="4" placeholder="Describe the image you want to generate..."></textarea>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="modalEnhance" checked>
                    <label>✨ Enhance prompt with AI (recommended)</label>
                </div>
                
                <button class="btn btn-modal" id="generateModalBtn" onclick="generateImage('modal')">
                    🚀 Generate Image
                </button>
                
                <div class="warning-message" id="modalWarning" style="display:none;">
                    ⏳ Modal may take 30-60 seconds on first request (cold start). Please wait...
                </div>
                
                <div id="modalResult" class="result-area"></div>
            </div>
        </div>
        
        <!-- OpenAI DALL-E Card -->
        <div class="card openai">
            <div class="card-header">
                <h2>🎨 Generate with OpenAI DALL-E 3</h2>
                <p>High quality, detailed images — usually 10-20 seconds</p>
            </div>
            <div class="card-body">
                <div class="input-group">
                    <label>Image Prompt</label>
                    <textarea id="openaiPrompt" rows="4" placeholder="Describe the image you want to generate..."></textarea>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="openaiEnhance" checked>
                    <label>✨ Enhance prompt with AI (recommended)</label>
                </div>
                
                <button class="btn btn-openai" id="generateOpenaiBtn" onclick="generateImage('openai')">
                    🤖 Generate Image
                </button>
                
                <div id="openaiResult" class="result-area"></div>
            </div>
        </div>
        
    </div>
    
    <!-- Quick sync buttons -->
    <div style="margin-top: 24px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
        <button onclick="syncPromptToModal()" style="padding: 8px 16px; background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; color: white; cursor: pointer;">
            📋 Copy OpenAI prompt → Modal
        </button>
        <button onclick="syncPromptToOpenAI()" style="padding: 8px 16px; background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; color: white; cursor: pointer;">
            📋 Copy Modal prompt → OpenAI
        </button>
        <button onclick="clearAll()" style="padding: 8px 16px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; color: white; cursor: pointer;">
            🗑️ Clear all
        </button>
    </div>
</div>

<script>
async function generateImage(type) {
    const isModal = type === 'modal';
    const promptElement = document.getElementById(isModal ? 'modalPrompt' : 'openaiPrompt');
    const enhanceElement = document.getElementById(isModal ? 'modalEnhance' : 'openaiEnhance');
    const resultDiv = document.getElementById(isModal ? 'modalResult' : 'openaiResult');
    const btn = document.getElementById(isModal ? 'generateModalBtn' : 'generateOpenaiBtn');
    const warningDiv = document.getElementById(isModal ? 'modalWarning' : null);
    
    const prompt = promptElement.value.trim();
    if (!prompt) {
        resultDiv.innerHTML = '<div class="error-message">Please enter a prompt first</div>';
        return;
    }
    
    // Show warning for Modal (cold start)
    if (isModal && warningDiv) {
        warningDiv.style.display = 'block';
    }
    
    // Show loading
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Generating... (may take 30-60 seconds)';
    resultDiv.innerHTML = '<div class="loading">⏳ Generating image, please wait...<br><small style="color:#64748b">First request may take up to 60 seconds due to cold start</small></div>';
    
    const startTime = Date.now();
    
    try {
        const action = isModal ? 'generate_modal' : 'generate_openai';
        const formData = new FormData();
        formData.append('action', action);
        formData.append('prompt', prompt);
        formData.append('enhance', enhanceElement.checked ? 'true' : 'false');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const duration = Math.round((Date.now() - startTime) / 1000);
        const data = await response.json();
        
        if (warningDiv) warningDiv.style.display = 'none';
        
        if (data.success) {
            resultDiv.innerHTML = `
                <div class="result-image">
                    <img src="${data.filepath}" alt="Generated image" onerror="this.style.display='none'">
                </div>
                <div class="result-info">
                    <strong>✅ Generated with ${data.source}</strong><br>
                    <strong>Time taken:</strong> ${duration} seconds<br>
                    <strong>Filename:</strong> ${data.filename}<br>
                    ${data.seed ? `<strong>Seed:</strong> ${data.seed}<br>` : ''}
                    ${data.duration ? `<strong>API Time:</strong> ${data.duration}s<br>` : ''}
                    ${data.enhanced_prompt ? `<strong>Enhanced Prompt:</strong><br><em>${escapeHtml(data.enhanced_prompt)}</em><br>` : ''}
                    ${data.revised_prompt ? `<strong>DALL-E Revised:</strong><br><em>${escapeHtml(data.revised_prompt)}</em>` : ''}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `<div class="error-message">❌ Error: ${escapeHtml(data.error)}</div>`;
        }
    } catch (error) {
        if (warningDiv) warningDiv.style.display = 'none';
        resultDiv.innerHTML = `<div class="error-message">❌ Network error: ${escapeHtml(error.message)}</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = isModal ? '🚀 Generate Image' : '🤖 Generate Image';
    }
}

function syncPromptToModal() {
    const openaiPrompt = document.getElementById('openaiPrompt').value;
    document.getElementById('modalPrompt').value = openaiPrompt;
}

function syncPromptToOpenAI() {
    const modalPrompt = document.getElementById('modalPrompt').value;
    document.getElementById('openaiPrompt').value = modalPrompt;
}

function clearAll() {
    document.getElementById('modalPrompt').value = '';
    document.getElementById('openaiPrompt').value = '';
    document.getElementById('modalResult').innerHTML = '';
    document.getElementById('openaiResult').innerHTML = '';
    document.getElementById('modalWarning').style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;')
               .replace(/</g, '&lt;')
               .replace(/>/g, '&gt;')
               .replace(/"/g, '&quot;')
               .replace(/'/g, '&#39;');
}

// Example prompts
const examplePrompts = [
    "A confident female CEO in her 30s, fair skin, blonde hair, blue eyes, wearing a navy blue power suit, standing in a modern glass-walled office with city view, bright daylight, looking determined",
    "A male chef in his 40s, Caucasian, light brown hair, wearing a white chef's coat, carefully plating a gourmet dish in a professional kitchen, steam rising, bright cool lighting",
    "A professional therapist with a female patient in a calm clinical office, warm but professional atmosphere, soft natural daylight",
    "A real estate agent showing a modern living room to a couple, bright natural light through large windows, Scandinavian design furniture"
];

function setExample(index) {
    const prompt = examplePrompts[index];
    document.getElementById('modalPrompt').value = prompt;
    document.getElementById('openaiPrompt').value = prompt;
}

// Add example buttons
const exampleDiv = document.createElement('div');
exampleDiv.style.marginTop = '24px';
exampleDiv.style.textAlign = 'center';
exampleDiv.innerHTML = `
    <p style="color: white; margin-bottom: 8px; font-size: 13px;">Example prompts:</p>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;">
        <button onclick="setExample(0)" style="padding: 6px 12px; background: rgba(255,255,255,0.2); border: none; border-radius: 6px; color: white; cursor: pointer; font-size: 12px;">CEO Office</button>
        <button onclick="setExample(1)" style="padding: 6px 12px; background: rgba(255,255,255,0.2); border: none; border-radius: 6px; color: white; cursor: pointer; font-size: 12px;">Chef Kitchen</button>
        <button onclick="setExample(2)" style="padding: 6px 12px; background: rgba(255,255,255,0.2); border: none; border-radius: 6px; color: white; cursor: pointer; font-size: 12px;">Therapy Session</button>
        <button onclick="setExample(3)" style="padding: 6px 12px; background: rgba(255,255,255,0.2); border: none; border-radius: 6px; color: white; cursor: pointer; font-size: 12px;">Real Estate</button>
    </div>
`;
document.querySelector('.container').appendChild(exampleDiv);
</script>
</body>
</html>