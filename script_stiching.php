<?php
/**
 * MP3 Audio Stitcher
 * Save this file as: mp3-stitcher.php
 * 
 * Usage in your other files:
 * require_once 'mp3-stitcher.php';
 * $stitcher = new MP3Stitcher();
 * $stitcher->stitch(['file1.mp3', 'file2.mp3'], 'output.mp3');
 */
require_once __DIR__ . '/function_store.php';
class MP3Stitcher {
    
    /**
     * Stitch multiple MP3 files into one
     * 
     * @param array $files - Array of MP3 file paths
     * @param string $outputFile - Where to save the combined file
     * @return bool - True if successful
     */
    public function stitch($files, $outputFile) {
        // Check we have files
        if (empty($files)) {
            throw new Exception("No files provided");
        }
        
        // Check all files exist
        foreach ($files as $file) {
            if (!file_exists($file)) {
                throw new Exception("File not found: " . $file);
            }
        }
        
        // Create output directory if needed
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Open output file
        $output = fopen($outputFile, 'wb');
        if (!$output) {
            throw new Exception("Cannot create output file");
        }
        
        // Combine all files
        foreach ($files as $index => $file) {
            $content = file_get_contents($file);
            
            // Remove ID3 tags from all files after the first
            // This prevents gaps between audio segments
            if ($index > 0) {
                $content = $this->stripID3Tags($content);
            }
            
            fwrite($output, $content);
        }
        
        fclose($output);
        return file_exists($outputFile);
    }
    
    /**
     * Remove ID3 tags from MP3 content
     */
    private function stripID3Tags($content) {
        // Remove ID3v2 tag (beginning)
        if (substr($content, 0, 3) === 'ID3') {
            $size = $this->getID3v2Size($content);
            $content = substr($content, $size);
        }
        
        // Remove ID3v1 tag (end, 128 bytes)
        if (substr($content, -128, 3) === 'TAG') {
            $content = substr($content, 0, -128);
        }
        
        return $content;
    }
    
    /**
     * Calculate ID3v2 tag size
     */
    private function getID3v2Size($content) {
        $size = 0;
        for ($i = 6; $i < 10; $i++) {
            $size = ($size << 7) | (ord($content[$i]) & 0x7F);
        }
        return $size + 10;
    }
}