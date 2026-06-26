# Instagram Business Login - PHP Demo

# Before this works on production, you must:
- Whitelist redirect URI in your Instagram app dashboard: https://videovizard.com/meta/ig_callback_vizard.php under App → Instagram → API setup with Instagram login → Business login settings.
- Verify scope availability — your Instagram app must have instagram_business_basic and instagram_business_content_publish registered. (You mentioned instagram_basic / instagram_content_publish, but those only work via Facebook Login — the IG Login endpoint at instagram.com/oauth/authorize only accepts the instagram_business_* names. If your app is configured with the legacy names, the auth call will fail.)
- Users connecting Instagram must have an Instagram Business or Creator account (not Personal) — Login with Instagram for Business won't accept Personal accounts for content_publish.
- Existing users who connected via the old combined flow will keep working for FB posts (their facebook_page_* rows are untouched), but their old instagram row was created with a FB Page token + IG Business ID — that token works against graph.facebook.com but not graph.instagram.com. They'll need to click "Connect Instagram" once to re-auth via the new flow.

This is a minimal PHP example that demonstrates the Instagram Business Login flow (authorization code -> short-lived token -> long-lived token -> refresh). It's intended as a starting point — do not use in production without adding secure storage for tokens, CSRF protection, input validation, and error handling.

Files:
- `config.php` - configuration (put your App ID, App Secret, and redirect URI here)
- `index.php` - shows the embed/login link
- `callback.php` - handles the redirect, exchanges the code for a short-lived token, and exchanges for a long-lived token (server-side)
- `demo_api.php` - example call to the Instagram Graph API and token refresh action

Setup

1. Copy this folder into your webroot (e.g., `c:\laragon\www\Instagram-login`).
2. Edit `config.php` and set `instagram_app_id`, `instagram_app_secret`, and `redirect_uri`. The `redirect_uri` must match the one configured in your Meta App Dashboard exactly.
3. Ensure your app has the Instagram product added and Business Login configured. Use the Embed URL from the App Dashboard or build the URL using `index.php`.

Testing (Windows PowerShell example):

```powershell
# Start Laragon/Apache, then open a browser to:
# http://localhost/Instagram-login/index.php
# Or use the configured redirect URI if using HTTPS and a domain.
```

Security notes
- Never expose your app secret in client-side code.
- Store long-lived tokens securely (database, encrypted storage).
- Implement CSRF / state parameter when building a production login flow.

References
- Business Login documentation: https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login



Next steps I can take (pick any)
Add CSRF protection (state param) to the flow and validate in callback.php.
Replace session storage with a small SQLite or MySQL token store and example migration.
Add examples for publishing content or reading business account data (depends on app access & permissions).
Wrap API calls in a small reusable PHP class for clearer usage and unit tests.
If you'd like, I can:

Implement the state/CSRF parameter in the demo now.
Switch the demo to store tokens in a SQLite DB and show how to retrieve them.
Add more API examples (e.g., list media, publish) once you confirm the permissions you need.
Which of those would you like next?

-------------------

I'll create and update a short todo list for the remaining steps, marking the verification step as in-progress. Then I'll run PHP's syntax checks (php -l) on the four PHP files to ensure there are no syntax errors. Expected outcome: each file reports either "No syntax errors detected" or shows error output.

php -l "c:\laragon\www\Instagram-login\config.php"; php -l "c:\laragon\www\Instagram-login\index.php"; php -l "c:\laragon\www\Instagram-login\callback.php"; php -l "c:\laragon\www\Instagram-login\demo_api.php"
