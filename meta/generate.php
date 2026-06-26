<?php
session_start();
?>
<?php

// Add cache-control headers to prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Get the background image, team crest images, score, subain and subaout from the request parameters
$background_path = $_GET['background'];
$home_crest_path = $_GET['home_crest'];
$away_crest_path = $_GET['away_crest'];
$subain = $_GET['subain'];
$subaout = $_GET['subaout'];

// Process subain field
if (strpos($subain, '&') !== false) {
    // If there's '&' in the subain field, split by '&' and take surname of each person
    $subain_parts = explode('&', $subain);

    $subain_surnames = [];
    foreach ($subain_parts as $name) {
        // Split the name by spaces and take the last name
        $parts = explode(' ', trim($name));
        $subain_surnames[] = strtoupper(end($parts));
    }
    // Join the surnames with ' & ' and assign to $subain variable
    $subain = implode(' & ', $subain_surnames);
} else {
    // Otherwise, take the surname of the single person
    $subain_parts = explode(' ', trim($subain));
    $subain = strtoupper(end($subain_parts));
}
$subain = wordwrap($subain, 15, "\n");
if (strpos($subaout, '&') !== false) {
    // If there's '&' in the subaout field, split by '&' and take surname of each person
    $subaout_parts = explode('&', $subaout);

    $subaout_surnames = [];
    foreach ($subaout_parts as $name) {
        // Split the name by spaces and take the last name
        $parts = explode(' ', trim($name));
        $subaout_surnames[] = strtoupper(end($parts));
    }
    // Join the surnames with ' & ' and assign to $subaout variable
    $subaout = implode(' & ', $subaout_surnames);
} else {
    // Otherwise, take the surname of the single person
    $subaout_parts = explode(' ', trim($subaout));
    $subaout = strtoupper(end($subaout_parts));
}
// Create a new image from the background image
$background = imagecreatefrompng($background_path);
$width = imagesx($background);
$height = imagesy($background);

// Create a new image with the same dimensions as the background image
$image = imagecreatetruecolor($width, $height);

// Copy the background image to the new image
imagecopy($image, $background, 0, 0, 0, 0, $width, $height);

// Add the team crests to the image
$home_crest = imagecreatefrompng($home_crest_path);
$away_crest = imagecreatefrompng($away_crest_path);

imagecopy($image, $home_crest, 50, 50, 0, 0, imagesx($home_crest), imagesy($home_crest));
imagecopy($image, $away_crest, $width - 50 - imagesx($away_crest), 50, 0, 0, imagesx($away_crest), imagesy($away_crest));

// Add the score to the image
$font = 'tungsten.woff';
$font_size = 80;
$white = imagecolorallocate($image, 0,0,0);

$text_width = imagettfbbox($font_size, 0, $font, $score)[2];
$text_x = ($width - $text_width) / 2;

imagettftext($image, $font_size, 0, $text_x, 150, $white, $font, $score);

// Add the subain and subaout text to the image
$white = imagecolorallocate($image, 0,0,0);
$subain_font_size = 100;
$subaout_font_size = 100;

$subain_x = ($width - imagettfbbox($subain_font_size, 0, $font, $subain)[2]) / 2;
$subain_y = 550;
imagettftext($image, $subain_font_size, 0, $subain_x, $subain_y, $white, $font, $subain);

$subaout_x = ($width - imagettfbbox($subaout_font_size, 0, $font, $subaout)[2]) / 2;
$subaout_y = $height - 150;
imagettftext($image, $subaout_font_size, 0, $subaout_x, $subaout_y, $white, $font, $subaout);


// Output the image as a PNG
// header('Content-Type: image/png');
imagepng($image);

// Generate a unique filename with the current time
$timestamp = time(); // Get the current timestamp
$filename = 'image_' . date('Ymd_His', $timestamp) . '.png'; // Example: image_20230516_153029.png

// Save the image to a specific location on the server with the generated filename
$imagePath = __DIR__ .'/generated/'. $filename; // Replace with your desired image path
imagepng($image, $imagePath);


// Log the filename to a logfile
$logFile = __DIR__ .'/generated/logfile.log';
$logMessage = date('Y-m-d H:i:s') . ' - ' . $filename . "\n";
file_put_contents($logFile, $logMessage, FILE_APPEND);

// Get the base64 representation of the image
// ob_start();
// imagepng($image);
// $image_data = ob_get_contents();
// ob_end_clean();

// Echo the base64 data URI
echo $filename;
$_SESSION['myData'] = $filename; 

// Clean up
imagedestroy($image);
imagedestroy($background);
imagedestroy($home_crest);
imagedestroy($away_crest);
// You can now use $imagePath to access the saved image on your server with the unique filename

?>
