<?php
/**
 * StressReleasor Batch Translation Tool
 * Translates questions from database to multiple languages using ChatGPT
 * 
 * Usage: php batch_translate_complete_fixed.php <language> <table> <start_id> <end_id>
 * Example: php batch_translate_complete_fixed.php es hdb_Questions_Stress 1 50
 */

require_once 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php'; // Your file with callChatGPT_inam()

class CompleteTranslator {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Translate text using ChatGPT (your existing function)
     */
    private function translateText($text, $targetLanguage, $context = '') {
        $languageInstructions = [
            'es' => 'Spanish (Latin American Spanish, warm and empathetic tone)',
            'fr' => 'French (Standard French, professional yet caring tone)',
            'ar' => 'Arabic (Modern Standard Arabic, respectful and empathetic tone)',
            'ur' => 'Urdu (Standard Urdu, respectful and warm tone)',
            'hi' => 'Hindi (Standard Hindi, warm and supportive tone)'
        ];
        
        $prompt = "You are a professional translator specializing in mental health and therapeutic content.

Translate the following text into {$languageInstructions[$targetLanguage]}.

CRITICAL REQUIREMENTS:
1. Maintain empathetic, warm, therapeutic tone
2. Preserve ALL SSML tags EXACTLY as they appear (like <break time=\"0.5s\"/>)
3. Keep placeholders like {response}, {name} EXACTLY as they appear
4. Keep conversational style - avoid overly formal or clinical language
5. For keywords: translate each word/phrase, keep comma-separated
6. Only translate the actual TEXT content, not the XML/SSML tags

Context: {$context}

Text to translate:
\"{$text}\"

IMPORTANT: Respond ONLY with the translation, nothing else. Keep all SSML tags and placeholders unchanged.";

        $result = callChatGPT_inam($prompt, "gpt-4o-mini");
        
        if (!$result['success']) {
            error_log("Translation failed: " . $result['error'] . PHP_EOL, 3, __DIR__ . "/translation_errors.log");
            return null;
        }
        
        $translation = trim($result['response']);
        
        // Clean up any markdown formatting that ChatGPT might add
        $translation = preg_replace('/^["\']|["\']$/', '', $translation); // Remove surrounding quotes
        $translation = preg_replace('/^```.*\n|\n```$/', '', $translation); // Remove code blocks
        
        return $translation;
    }
    
    /**
     * Create URL-friendly slug with language prefix
     */
    private function createUrlSlug($text, $language) {
        // Remove SSML tags for URL creation
        $cleanText = strip_tags($text);
        
        // For Arabic/Urdu/Hindi, use simple format
        if (in_array($language, ['ar', 'ur', 'hi'])) {
            return $language . '_question';
        }
        
        // Transliterate to ASCII for URL
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cleanText);
        
        if (!$slug) {
            // Fallback if transliteration fails
            return $language . '_question';
        }
        
        // Convert to lowercase
        $slug = strtolower($slug);
        
        // Replace spaces and special chars with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        
        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');
        
        // Limit length
        $slug = substr($slug, 0, 50);
        
        // Add language prefix
        return $language . '_' . $slug;
    }
    
    /**
     * Translate button labels in JSON
     */
    private function translateButtonValue($buttonValueJson, $targetLanguage) {
        if (empty($buttonValueJson)) {
            return null;
        }
        
        // Parse JSON
        $buttonData = json_decode($buttonValueJson, true);
        
        if (!$buttonData || !isset($buttonData['options'])) {
            return $buttonValueJson; // Return as-is if no options
        }
        
        // Translate each option label
        $translatedOptions = [];
        
        foreach ($buttonData['options'] as $option) {
            $originalLabel = $option['label'] ?? '';
            
            if (empty($originalLabel)) {
                $translatedOptions[] = $option;
                continue;
            }
            
            echo "        - Translating option: {$originalLabel}...";
            
            $translatedLabel = $this->translateText(
                $originalLabel,
                $targetLanguage,
                "This is a multiple choice option label for a mental health questionnaire"
            );
            
            if (!$translatedLabel) {
                echo " ❌ (using original)\n";
                $translatedLabel = $originalLabel; // Fallback to original
            } else {
                echo " ✅\n";
            }
            
            $translatedOptions[] = [
                'value' => $option['value'], // Keep value same (used in code)
                'label' => $translatedLabel   // Translate label (shown to user)
            ];
            
            sleep(1); // Rate limiting between option translations
        }
        
        // Rebuild JSON structure
        $buttonData['options'] = $translatedOptions;
        
        return json_encode($buttonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Get questions from database with ALL fields
     */
    private function getQuestionsFromDB($table, $startId, $endId) {
        $sql = "SELECT 
                    id,
                    question_key,
                    question_stage,
                    question_text,
                    validation_msg,
                    therapist_comment,
                    question_text_url,
                    question_text_audio,
                    keywords,
                    button_value,
                    button_type
                FROM {$table}
                WHERE id >= ? AND id <= ?
                ORDER BY id";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            die("Database error: " . $this->conn->error . "\n");
        }
        
        $stmt->bind_param('ii', $startId, $endId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $questions[] = $row;
        }
        
        $stmt->close();
        
        return $questions;
    }
    
    /**
     * Translate complete question with ALL fields
     */
    public function translateBatch($table, $startId, $endId, $language) {
        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║  Translating {$table}\n";
        echo "║  IDs: {$startId} to {$endId}\n";
        echo "║  Language: {$language}\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        
        $questions = $this->getQuestionsFromDB($table, $startId, $endId);
        
        if (empty($questions)) {
            echo "\n⚠️  No questions found in this ID range.\n";
            return 0;
        }
        
        echo "Found " . count($questions) . " questions to process.\n\n";
        
        // Load existing translations if file exists
        $jsonFile = __DIR__ . "/../languages/{$language}.json";
        $existingTranslations = [];
        
        if (file_exists($jsonFile)) {
            $existingTranslations = json_decode(file_get_contents($jsonFile), true) ?? [];
            echo "📄 Loaded existing translations: " . count($existingTranslations) . " entries\n\n";
        } else {
            echo "📄 Creating new translation file\n\n";
        }
        
        $translated = 0;
        $skipped = 0;
        $failed = 0;
        
        foreach ($questions as $index => $q) {
            $questionKey = $q['question_key'];
            $currentNum = $index + 1;
            $totalNum = count($questions);
            
            echo "──────────────────────────────────────────────────────────────\n";
            echo "Question {$currentNum}/{$totalNum}: {$questionKey}\n";
            echo "──────────────────────────────────────────────────────────────\n";
            
            // Skip if already translated
            if (isset($existingTranslations[$questionKey])) {
                echo "  ⏭️  Already translated - SKIPPING\n\n";
                $skipped++;
                continue;
            }
            
            // 1. Translate question text
            echo "  🔄 Question text...";
            $translatedQuestion = $this->translateText(
                $q['question_text'],
                $language,
                "This is a therapeutic chatbot question for {$q['question_stage']} assessment"
            );
            
            if (!$translatedQuestion) {
                echo " ❌ FAILED\n";
                $failed++;
                continue;
            }
            echo " ✅\n";
            
            // 2. Translate validation message
            echo "  🔄 Validation message...";
            $translatedValidation = '';
            if (!empty($q['validation_msg'])) {
                $translatedValidation = $this->translateText(
                    $q['validation_msg'],
                    $language,
                    "This is a validation message asking the user to provide a response"
                );
                echo " ✅\n";
            } else {
                echo " ⏭️ (empty)\n";
            }
            
            // 3. Translate therapist comment
            echo "  🔄 Therapist comment...";
            $translatedComment = '';
            if (!empty($q['therapist_comment'])) {
                $translatedComment = $this->translateText(
                    $q['therapist_comment'],
                    $language,
                    "This is an empathetic therapist response to validate the user's answer"
                );
                echo " ✅\n";
            } else {
                echo " ⏭️ (empty)\n";
            }
            
            // 4. Translate audio SSML text
            echo "  🔄 Audio SSML text...";
            $translatedAudioText = '';
            if (!empty($q['question_text_audio'])) {
                $translatedAudioText = $this->translateText(
                    $q['question_text_audio'],
                    $language,
                    "This is SSML text for text-to-speech audio generation. Keep all SSML tags like <break time=\"0.5s\"/> unchanged. Only translate the spoken text."
                );
                echo " ✅\n";
            } else {
                echo " ⏭️ (empty)\n";
            }
            
            // 5. Create URL slug
            echo "  🔄 Creating URL slug...";
            $translatedUrl = $this->createUrlSlug($translatedQuestion, $language);
            echo " ✅ ({$translatedUrl})\n";
            
            // 6. Create audio filename
            $audioFilename = "{$questionKey}_{$language}.mp3";
            
            // 7. Translate keywords
            echo "  🔄 Keywords...";
            $translatedKeywords = '';
            if (!empty($q['keywords'])) {
                $translatedKeywords = $this->translateText(
                    $q['keywords'],
                    $language,
                    "These are comma-separated keywords related to mental health topics. Translate each keyword/phrase."
                );
                echo " ✅\n";
            } else {
                echo " ⏭️ (empty)\n";
            }
            
            // 8. Translate button values (if exists)
            $translatedButtonValue = null;
            if (!empty($q['button_value'])) {
                echo "  🔄 Button options:\n";
                $translatedButtonValue = $this->translateButtonValue($q['button_value'], $language);
            }
            
            // 9. Save all translations
            $existingTranslations[$questionKey] = [
                'question_text' => $translatedQuestion,
                'validation_msg' => $translatedValidation,
                'therapist_comment' => $translatedComment,
                'question_text_url' => $translatedUrl,
                'question_text_audio' => $translatedAudioText,
                'audio_filename' => $audioFilename,
                'keywords' => $translatedKeywords,
                'button_value' => $translatedButtonValue,
                'button_type' => $q['button_type'] ?? ''
            ];
            
            echo "  💾 Saving to JSON...";
            $this->saveJSON($language, $existingTranslations);
            echo " ✅\n";
            
            echo "  ✅ COMPLETED: {$questionKey}\n\n";
            $translated++;
            
            // Rate limiting between questions
            if ($currentNum < $totalNum) {
                echo "  💤 Pausing 2 seconds...\n\n";
                sleep(2);
            }
        }
        
        // Final summary
        echo "\n╔════════════════════════════════════════════════════════════╗\n";
        echo "║  BATCH COMPLETE\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n📊 Results:\n";
        echo "   ✅ Translated: {$translated}\n";
        echo "   ⏭️  Skipped: {$skipped}\n";
        echo "   ❌ Failed: {$failed}\n";
        echo "   📄 Total in JSON: " . count($existingTranslations) . "\n";
        echo "\n💾 File: languages/{$language}.json\n";
        
        return $translated;
    }
    
    /**
     * Save translations to JSON file
     */
    private function saveJSON($language, $translations) {
        $jsonFile = __DIR__ . "/../languages/{$language}.json";
        
        // Create directory if it doesn't exist
        $dir = dirname($jsonFile);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Sort by key for easier reading
        ksort($translations);
        
        // Create JSON with pretty formatting
        $jsonData = json_encode(
            $translations,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        
        if ($jsonData === false) {
            error_log("JSON encoding error: " . json_last_error_msg() . PHP_EOL, 3, __DIR__ . "/translation_errors.log");
            return false;
        }
        
        $result = file_put_contents($jsonFile, $jsonData);
        
        if ($result === false) {
            error_log("Failed to write JSON file: {$jsonFile}" . PHP_EOL, 3, __DIR__ . "/translation_errors.log");
            return false;
        }
        
        return true;
    }
}

// ============================================
// COMMAND LINE INTERFACE
// ============================================

if (php_sapi_name() !== 'cli') {
    die("❌ This script must be run from command line\n");
}

// Get command line arguments
$language = $argv[1] ?? null;
$table = $argv[2] ?? null;
$startId = isset($argv[3]) ? (int)$argv[3] : null;
$endId = isset($argv[4]) ? (int)$argv[4] : null;

// Show usage if arguments missing
if (!$language || !$table || !$startId || !$endId) {
    echo "\n╔════════════════════════════════════════════════════════════╗\n";
    echo "║     StressReleasor Batch Translation Tool                 ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n\n";
    echo "Usage:\n";
    echo "  php batch_translate_complete_fixed.php <language> <table> <start_id> <end_id>\n\n";
    echo "Arguments:\n";
    echo "  language   : es, fr, ar, ur, or hi\n";
    echo "  table      : Database table name (e.g., hdb_Questions_Stress)\n";
    echo "  start_id   : Starting ID number\n";
    echo "  end_id     : Ending ID number\n\n";
    echo "Examples:\n";
    echo "  Test with 5 questions:\n";
    echo "    php batch_translate_complete_fixed.php es hdb_Questions_Stress 1 5\n\n";
    echo "  Translate full batch:\n";
    echo "    php batch_translate_complete_fixed.php es hdb_Questions_Stress 1 50\n\n";
    echo "  Continue from where you left off:\n";
    echo "    php batch_translate_complete_fixed.php es hdb_Questions_Stress 51 100\n\n";
    echo "Available languages:\n";
    echo "  es - Spanish\n";
    echo "  fr - French\n";
    echo "  ar - Arabic\n";
    echo "  ur - Urdu\n";
    echo "  hi - Hindi\n\n";
    exit(1);
}

// Validate language
$validLanguages = ['es', 'fr', 'ar', 'ur', 'hi'];
if (!in_array($language, $validLanguages)) {
    die("❌ Invalid language. Use: es, fr, ar, ur, or hi\n");
}

// Validate IDs
if ($startId < 1 || $endId < $startId) {
    die("❌ Invalid ID range. Start ID must be >= 1 and End ID must be >= Start ID\n");
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("❌ Database connection failed: " . ($conn->connect_error ?? 'Connection not established') . "\n");
}

// Check if table exists
$result = $conn->query("SHOW TABLES LIKE '{$table}'");
if ($result->num_rows == 0) {
    die("❌ Table '{$table}' does not exist in database\n");
}

// Create translator and run
$translator = new CompleteTranslator($conn);

try {
    $translator->translateBatch($table, $startId, $endId, $language);
    echo "\n✅ Translation complete!\n\n";
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    error_log("Translation error: " . $e->getMessage() . PHP_EOL, 3, __DIR__ . "/translation_errors.log");
    exit(1);
}
?>