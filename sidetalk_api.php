<?php
/**
 * SadTalker API - Updated PHP Examples
 * Works with the new Modal FastAPI endpoint
 */

// Your Modal API endpoint
// After deployment, you'll get: https://your-username--sadtalker-app-fastapi-app.modal.run
$MODAL_URL = "https://inaamalvi1--sadtalker-app-fastapi-app.modal.run"; // e.g., https://username--sadtalker-app-fastapi-app.modal.run
$API_ENDPOINT = $MODAL_URL . "/generate";
/**
 * Example 1: Generate video from image + audio file
 */
function example1_image_audio($api_endpoint) {
    echo "Example 1: Generating video from image + audio...\n";
    
    $ch = curl_init();
    
    $postData = array(
        'source_image' => new CURLFile('/path/to/your/image.jpg', 'image/jpeg', 'image.jpg'),
        'driven_audio' => new CURLFile('/path/to/your/audio.wav', 'audio/wav', 'audio.wav'),
        'preprocess' => 'crop',
        'still_mode' => 'true',
        'use_enhancer' => 'false',
        'batch_size' => '2',
        'size' => '256',
        'pose_style' => '0',
        'exp_scale' => '1.0'
    );
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => $api_endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300, // 5 minutes timeout
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode == 200) {
        file_put_contents('generated_video.mp4', $response);
        echo "✓ Success! Video saved as generated_video.mp4\n";
    } else {
        echo "✗ Error: HTTP $httpCode\n";
        $errorData = json_decode($response, true);
        if ($errorData && isset($errorData['detail'])) {
            echo "Error: " . $errorData['detail'] . "\n";
        } else {
            echo "Response: $response\n";
        }
    }
    
    curl_close($ch);
}

/**
 * Example 2: Generate video from image + text (TTS) - COST OPTIMIZED
 */
function example2_image_text_optimized($api_endpoint) {
    echo "\nExample 2: Generating video from image + text (optimized)...\n";
    
    $ch = curl_init();
    
    // Minimal parameters = fastest/cheapest
    $postData = array(
        'source_image' => new CURLFile('/path/to/your/image.jpg', 'image/jpeg', 'image.jpg'),
        'input_text' => 'Hello, this is a test of SadTalker API!',
        // Using defaults: crop, still_mode=true, size=256
    );
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => $api_endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode == 200) {
        file_put_contents('generated_video_tts.mp4', $response);
        echo "✓ Success! Video saved as generated_video_tts.mp4\n";
    } else {
        echo "✗ Error: HTTP $httpCode\n";
        echo "Response: $response\n";
    }
    
    curl_close($ch);
}

/**
 * Example 3: Generate video with error handling
 */
function example3_with_error_handling($api_endpoint, $image_path, $text) {
    echo "\nExample 3: Generating with error handling...\n";
    
    // Validate inputs
    if (!file_exists($image_path)) {
        echo "✗ Error: Image file not found: $image_path\n";
        return false;
    }
    
    $ch = curl_init();
    
    $postData = array(
        'source_image' => new CURLFile($image_path, mime_content_type($image_path), basename($image_path)),
        'input_text' => $text,
        'preprocess' => 'crop',
        'still_mode' => 'true'
    );
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => $api_endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FAILONERROR => false,
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        echo "✗ CURL Error: $error\n";
        return false;
    }
    
    if ($httpCode == 200) {
        $filename = 'output_' . time() . '.mp4';
        file_put_contents($filename, $response);
        echo "✓ Success! Video saved as $filename\n";
        return $filename;
    } else {
        echo "✗ Error: HTTP $httpCode\n";
        // Try to decode JSON error message
        $errorData = json_decode($response, true);
        if ($errorData && isset($errorData['detail'])) {
            echo "Error message: " . $errorData['detail'] . "\n";
        } else {
            echo "Response: $response\n";
        }
        return false;
    }
}

/**
 * Example 4: Check API health
 */
function example4_health_check($modal_url) {
    echo "\nExample 4: Health Check...\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $modal_url . "/health",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        echo "✓ API is healthy: $response\n";
        return true;
    } else {
        echo "✗ API health check failed: HTTP $httpCode\n";
        return false;
    }
}

/**
 * Example 5: Upload from URL (download first, then upload)
 */
function example5_from_url($api_endpoint, $image_url, $text) {
    echo "\nExample 5: Generating from image URL...\n";
    
    // Download image first
    $temp_image = tempnam(sys_get_temp_dir(), 'img_') . '.jpg';
    $image_data = file_get_contents($image_url);
    
    if ($image_data === false) {
        echo "✗ Error: Could not download image from URL\n";
        return false;
    }
    
    file_put_contents($temp_image, $image_data);
    
    // Generate video
    $ch = curl_init();
    
    $postData = array(
        'source_image' => new CURLFile($temp_image, 'image/jpeg', 'image.jpg'),
        'input_text' => $text,
        'preprocess' => 'crop',
        'still_mode' => 'true'
    );
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => $api_endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
    ));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Clean up temp file
    unlink($temp_image);
    
    if ($httpCode == 200) {
        $filename = 'video_from_url.mp4';
        file_put_contents($filename, $response);
        echo "✓ Success! Video saved as $filename\n";
        return $filename;
    } else {
        echo "✗ Error: HTTP $httpCode\n";
        return false;
    }
}

/**
 * Example 6: Class-based implementation (RECOMMENDED)
 */
class SadTalkerAPI {
    private $api_url;
    private $api_endpoint;
    private $timeout;
    
    public function __construct($modal_url, $timeout = 300) {
        $this->api_url = rtrim($modal_url, '/');
        $this->api_endpoint = $this->api_url . '/generate';
        $this->timeout = $timeout;
    }
    
    /**
     * Check if API is healthy
     */
    public function isHealthy() {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $this->api_url . '/health',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode == 200;
    }
    
    /**
     * Generate video
     */
    public function generateVideo($params) {
        $ch = curl_init();
        
        // Build post data
        $postData = array();
        
        // Handle source image
        if (isset($params['source_image_path'])) {
            if (!file_exists($params['source_image_path'])) {
                throw new Exception("Image file not found: " . $params['source_image_path']);
            }
            $postData['source_image'] = new CURLFile(
                $params['source_image_path'],
                mime_content_type($params['source_image_path']),
                basename($params['source_image_path'])
            );
        } else {
            throw new Exception("source_image_path is required");
        }
        
        // Handle audio or text
        if (isset($params['driven_audio_path'])) {
            if (!file_exists($params['driven_audio_path'])) {
                throw new Exception("Audio file not found: " . $params['driven_audio_path']);
            }
            $postData['driven_audio'] = new CURLFile(
                $params['driven_audio_path'],
                mime_content_type($params['driven_audio_path']),
                basename($params['driven_audio_path'])
            );
        } elseif (isset($params['input_text'])) {
            $postData['input_text'] = $params['input_text'];
        } else {
            throw new Exception("Either driven_audio_path or input_text is required");
        }
        
        // Add optional parameters
        $optional_params = ['preprocess', 'still_mode', 'use_enhancer', 'batch_size', 'size', 'pose_style', 'exp_scale'];
        foreach ($optional_params as $param) {
            if (isset($params[$param])) {
                $postData[$param] = (string)$params[$param];
            }
        }
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $this->api_endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: $error");
        }
        
        if ($httpCode != 200) {
            $errorData = json_decode($response, true);
            $errorMsg = isset($errorData['detail']) ? $errorData['detail'] : "HTTP $httpCode";
            throw new Exception("API Error: $errorMsg");
        }
        
        return $response;
    }
    
    /**
     * Generate and save video
     */
    public function generateAndSave($params, $output_file) {
        $video_data = $this->generateVideo($params);
        file_put_contents($output_file, $video_data);
        return $output_file;
    }
}

/**
 * Example 7: Using the class (RECOMMENDED)
 */
function example7_using_class($modal_url) {
    echo "\nExample 7: Using SadTalkerAPI class...\n";
    
    $api = new SadTalkerAPI($modal_url);
    
    // Check if API is healthy first
    if (!$api->isHealthy()) {
        echo "✗ API is not healthy!\n";
        return;
    }
    
    echo "✓ API is healthy\n";
    
    try {
        $video_file = $api->generateAndSave(
            array(
                'source_image_path' => '/path/to/your/image.jpg',
                'input_text' => 'This is a test using the PHP class wrapper.',
                'preprocess' => 'crop',
                'still_mode' => 'true',
                'size' => '256'
            ),
            'class_generated_video.mp4'
        );
        
        echo "✓ Success! Video saved as $video_file\n";
    } catch (Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
    }
}

// ============================================
// Run Examples
// ============================================

echo "=== SadTalker API PHP Examples ===\n";
echo "API Endpoint: $API_ENDPOINT\n\n";

// Uncomment to run examples:
// example1_image_audio($API_ENDPOINT);
// example2_image_text_optimized($API_ENDPOINT);
// example3_with_error_handling($API_ENDPOINT, '/path/to/image.jpg', 'Test text');
// example4_health_check($MODAL_URL);
// example5_from_url($API_ENDPOINT, 'https://example.com/image.jpg', 'Hello world');
// example7_using_class($MODAL_URL);

echo "Instructions:\n";
echo "1. Deploy: modal deploy sadtalker_modal_optimized.py\n";
echo "2. Copy the 'fastapi_app' URL from deployment output\n";
echo "3. Update \$MODAL_URL in this script\n";
echo "4. Uncomment the example functions you want to run\n";
echo "5. Run: php " . basename(__FILE__) . "\n\n";

echo "Cost Optimization Tips:\n";
echo "- Use still_mode=true (faster, cheaper)\n";
echo "- Use size=256 instead of 512\n";
echo "- Avoid use_enhancer unless needed\n";
echo "- Default parameters are already optimized\n";

?>
