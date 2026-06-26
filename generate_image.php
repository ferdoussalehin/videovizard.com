<?php
// generate_image.php
// ── All image generation is now handled inside wizard_step2.php ──────────────
// This file exists only for backwards-compatibility with any direct calls.
// It maps the old action name to generate_image_api and forwards to wizard_step2.php.

// Remap old action name so wizard_step2.php picks it up correctly
if (isset($_POST['action']) && $_POST['action'] === 'generate_single_image') {
    $_POST['action'] = 'generate_image_api';
}

// Forward to wizard_step2.php which owns all actions including generate_image_api
define('WIZARD_INCLUDED', true);
require __DIR__ . '/wizard_step2.php';
