# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

VideoVizard is an AI-powered video creation SaaS (videovizard.com). Users select a niche/topic, the system generates a script using GPT-4o, generates per-scene images (Flux primary, OpenAI DALL-E fallback), generates voiceover (Azure TTS or OpenAI TTS), and stitches everything into a video via FFmpeg. Finished videos can be scheduled to publish to Facebook, TikTok, YouTube, LinkedIn, and X.

## Tech stack

- **Backend**: PHP (no framework), flat file structure — everything lives in the webroot
- **Database**: MySQL, accessed via both `mysqli` and `PDO` in the same codebase
- **Frontend**: Vanilla JS + inline CSS; no build step, no bundler
- **Node.js**: Only `package.json` / `veo.js` for a standalone Gemini Veo test script — not part of the main app

## Running the app

This is a live Apache/PHP server. There is no build step or dev server to start — changes to `.php` files take effect immediately. View at `https://videovizard.com/` or locally via Apache.

To run the Veo Node script only:
```bash
node veo.js
```

## Database

**Primary DB**: `user_hypnotherapy_db2` (localhost)  
**Connection file**: `dbconnect_hdb.php` — always use this for main-app queries. It provides both `$conn` (mysqli) and `$pdo` (PDO).

`dbconnect.php` connects to a legacy `alvia_db` and is mostly unused in the main app.

Key tables:
- `hdb_users` — registered users, plan_type (`free_trial`, `personal`, `agency`)
- `hdb_companies` — workspaces linked to users
- `hdb_podcasts` — the central content record (each "podcast" is a video project)
- `hdb_podcast_stories` — per-scene rows for a podcast (script, image, audio, video paths)
- `hdb_social_media` — topic/category/title bank for content pilot
- `hdb_schedule` — scheduled social media posts
- `hdb_oauth_tokens` — social platform OAuth tokens per user

## Configuration / secrets

All API keys and credentials are hardcoded in `config.php` (no `.env`). Every file that needs keys does `include 'config.php'` or `require_once 'config.php'`.

Credentials in `config.php`:
- OpenAI (`$chatgpt_api_key`, `$myApiKey`, `$apiKey`)
- Azure TTS (`$azure_apiKey`)
- Google Gemini (`$gemini_apiKey`, `$google_api_key`)
- ElevenLabs (`$eleven_lab_api_key`)
- FAL.ai (`$falApiKey`)
- Facebook app, TikTok app, Stripe keys — defined as constants

## Session / auth

Include `session_config.php` at the top of pages that need sessions. It sets up a 1-year session lifetime and refreshes the cookie on each load.

Guard pattern used on every protected page:
```php
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }
$admin_id = (int)$_SESSION['admin_id'];
```

## Core workflow files

| File | Role |
|------|------|
| `vizard_scriptgen.php` | Landing page — user picks content type to create |
| `vizard_scriptgen_2.php` | Active script-generation wizard (multi-tab) |
| `videomaker.php` | Main video editor — scene management, image gen, audio gen, stitching |
| `videomaker_functions.php` | PHP helpers for videomaker |
| `videomaker.js` / `videomaker_jslib.js` | JS for the video editor UI |
| `content_pilot.php` | Automated workflow: selects topic → generates → builds video |
| `image_generation_functions.php` | Shared image gen functions (Flux + OpenAI fallback) |
| `generate_image_api.php` | HTTP endpoint for image generation (wraps above) |
| `generate_voice.php` | HTTP endpoint for TTS (Azure or OpenAI route) |
| `azure_tts_utility.php` | Azure TTS curl helper |
| `vps_stitch.php` | FFmpeg video stitching — runs as CLI worker on VPS |
| `stitch_caller.php` | Triggers `vps_stitch.php` over HTTP |
| `social_schedule.php` | Saves/updates social post schedules |
| `vizard_scheduler.php` | Social publishing scheduler UI |

## External services

- **Flux** (image gen primary): Modal-hosted at `https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image`
- **OpenAI**: GPT-4o for script gen, DALL-E for image gen fallback, `gpt-4o-mini-tts` for TTS
- **Azure Cognitive Services**: Primary TTS (neural voices)
- **VPS FFmpeg server**: `http://187.124.249.46/videovizard.com/vps_stitch.php` — authenticated with shared secret `VS_FFmpeg_2026_Secret!`
- **WAN video generation**: `wan_worker.php` / `wan_text2_video_api.php` for AI text-to-video
- **SadTalker**: Avatar/talking-head video generation via `sadtalker_processor.php`
- **Stripe**: Payments — `stripe_checkout.php`, `stripe_webhook.php`
- **Brevo**: Email via `brevo_new_api.php`

## Social platform OAuth

Each platform has its own connect/callback pair:
- Facebook: `facebook_connect.php` / `facebook-callback.php`
- TikTok: `tiktok_connect_vizard.php` / `tiktok_callback_vizard.php`
- YouTube: `youtube_connect.php` / `youtube_callback.php`
- LinkedIn: `linkedin/` directory
- X/Twitter: `x_connect_vizard.php` / `x_callback.php`

OAuth tokens stored in `hdb_oauth_tokens`.

## File naming conventions

The root is flat and contains many backup/versioned files. Working files are distinguished from archived ones:
- Active: no date suffix (e.g. `videomaker.php`, `generate_script.php`)
- Archived: date or backup suffix (e.g. `videomaker_2026_05_17.php`, `generate_script_backup_03_broll_generated.php`)

When editing, target the file **without** a date/backup suffix unless specifically working on a historical version.

## AJAX pattern

Most dynamic actions POST to the same PHP file that renders the page, gated by `$_POST['action']` or `$_POST['ajax_action']`. Response is always JSON. Authentication is checked via `$_SESSION['admin_id']` at the top of each handler.

## Error logging

Errors are logged to files in the webroot (not syslog):
- `a_errors.log` — main application errors
- `a_debug.log`, `a_inam_debug.log` — debug traces
- `azure_debug.log` — TTS errors
- `image_generation.log`, `video_generation.log` — generation pipeline logs
