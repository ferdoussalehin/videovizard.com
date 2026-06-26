<?php

/**
 * Generate AI image using OpenAI API optimized for vertical video
 * 
 * @param string $prompt - Detailed image generation prompt
 * @param string $imageName - Desired filename (without extension)
 * @param string $resolution - Image size (default: "1024x1792" for vertical video)
 * @param string $folder - Folder path to save image
 * @param string $apiKey - OpenAI API key
 * @return array - Returns array with status, message, and filepath
 */
function generateAndSaveImage($prompt, $imageName, $resolution = "1024x1792", $folder = "podcast_images", $apiKey = null) {
    
    // Validate API key
    if (empty($apiKey)) {
        return [
            'success' => false,
            'message' => 'API key is required',
            'filepath' => null
        ];
    }
    
    // Validate resolution with recommendations
    $validResolutions = [
        "1024x1792" => "Vertical (9:16) - Instagram/TikTok/Shorts - RECOMMENDED",
        "1792x1024" => "Horizontal (16:9) - YouTube landscape",
        "1024x1024" => "Square (1:1) - NOT recommended for video"
    ];
    
    if (!array_key_exists($resolution, $validResolutions)) {
        return [
            'success' => false,
            'message' => 'Invalid resolution. Valid options: ' . print_r($validResolutions, true),
            'filepath' => null
        ];
    }
    
    // ===== ENHANCED PROMPT WITH AMERICAN CULTURE INSTRUCTIONS =====
    list($targetWidth, $targetHeight) = explode('x', $resolution);
    $targetWidth = (int)$targetWidth;
    $targetHeight = (int)$targetHeight;
    
    $enhancedPrompt = "A professional DSLR photograph, real-life photography style, NOT illustration, NOT digital art, NOT abstract. 
                       STRICT RULES: 
                       - Must look like a real photo taken by a professional photographer
                       - Natural realistic human faces only, no distortion or surreal features
                       - Natural real-world colors, normal lighting (daylight or warm indoor)
                       - NO neon colors, NO fantasy, NO abstract shapes or patterns
                       - American people in modern casual clothing, mix of men and women
                       - Real American settings (homes, offices, parks, cafes, streets)
                       - Think Getty Images or Shutterstock stock photo quality
                       - Perfectly upright, NOT tilted or slanted
                       
                       Subject: " . $prompt;
    
    // Prepare API request with enhanced prompt
    $data = [
        "model" => "dall-e-3",
        "prompt" => $enhancedPrompt,
        "size" => $resolution,
        "response_format" => "b64_json",
        "n" => 1,
        "quality" => "hd"
    ];
    
    // Initialize cURL
    $ch = curl_init("https://api.openai.com/v1/images/generations");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    // Execute request
    $response = curl_exec($ch);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'success' => false,
            'message' => 'Curl Error: ' . $error,
            'filepath' => null
        ];
    }
    
    curl_close($ch);
    
    // Decode JSON response
    $result = json_decode($response, true);
    
    // Check if image generation was successful
    if (!isset($result['data'][0]['b64_json'])) {
        $errorMessage = isset($result['error']['message']) ? $result['error']['message'] : 'Image generation failed';
        return [
            'success' => false,
            'message' => $errorMessage,
            'filepath' => null,
            'api_response' => $result
        ];
    }
    
    // Get Base64 image
    $base64Image = $result['data'][0]['b64_json'];
    
    // Decode image
    $imageData = base64_decode($base64Image);
    
    // ===== ROBUST FIX FOR ORIENTATION =====
    // Always force the correct dimensions regardless of what DALL-E returns
    $sourceImage = imagecreatefromstring($imageData);
    
    if ($sourceImage) {
        $origWidth = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);
        
        error_log(date('Y-m-d H:i:s') . " | Image received: {$origWidth}x{$origHeight} | Target: {$targetWidth}x{$targetHeight}\n", 3, __DIR__ . "/a_debug.log");
        
        $needsFix = false;
        
        // Check if orientation is wrong
        // For portrait (1024x1792): width should be < height
        // For landscape (1792x1024): width should be > height
        if ($targetHeight > $targetWidth) {
            // We want PORTRAIT but got LANDSCAPE
            if ($origWidth > $origHeight) {
                $needsFix = true;
            }
        } elseif ($targetWidth > $targetHeight) {
            // We want LANDSCAPE but got PORTRAIT
            if ($origHeight > $origWidth) {
                $needsFix = true;
            }
        }
        
        if ($needsFix) {
            error_log(date('Y-m-d H:i:s') . " | Rotating image to fix orientation\n", 3, __DIR__ . "/a_debug.log");
            $rotatedImage = imagerotate($sourceImage, 90, 0);
            imagedestroy($sourceImage);
            $sourceImage = $rotatedImage;
            
            // Update dimensions after rotation
            $origWidth = imagesx($sourceImage);
            $origHeight = imagesy($sourceImage);
        }
        
        // ALWAYS resize/resample to exact target dimensions to ensure crisp output
        $finalImage = imagecreatetruecolor($targetWidth, $targetHeight);
        
        // Preserve quality - enable alpha blending
        imagealphablending($finalImage, false);
        imagesavealpha($finalImage, true);
        
        // High-quality resample to exact target size
        imagecopyresampled(
            $finalImage, $sourceImage,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $origWidth, $origHeight
        );
        
        // Save as PNG with maximum quality (compression level 1 = best quality, 9 = best compression)
        ob_start();
        imagepng($finalImage, null, 1);
        $imageData = ob_get_clean();
        
        imagedestroy($sourceImage);
        imagedestroy($finalImage);
        
        error_log(date('Y-m-d H:i:s') . " | Final image: {$targetWidth}x{$targetHeight} | Size: " . strlen($imageData) . " bytes\n", 3, __DIR__ . "/a_debug.log");
    }
    
    // Create folder if not exists
    if (!file_exists($folder)) {
        if (!mkdir($folder, 0755, true)) {
            return [
                'success' => false,
                'message' => 'Failed to create folder: ' . $folder,
                'filepath' => null
            ];
        }
    }
    
    // Sanitize filename
    $safeImageName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $imageName);
    
    // Generate full filepath
    $filepath = $folder . "/" . $safeImageName . ".png";
    
    // Save file
    if (file_put_contents($filepath, $imageData) === false) {
        return [
            'success' => false,
            'message' => 'Failed to save image to: ' . $filepath,
            'filepath' => null
        ];
    }
    
    // Strip any EXIF data that could cause rotation in viewers
    // Re-read and re-save to clean metadata
    $cleanImage = imagecreatefrompng($filepath);
    if ($cleanImage) {
        imagepng($cleanImage, $filepath, 1);
        imagedestroy($cleanImage);
    }
    
    return [
        'success' => true,
        'message' => 'Image saved successfully',
        'filepath' => $filepath,
        'resolution' => $targetWidth . 'x' . $targetHeight
    ];
}
?>
