read this Meta developer document in the url and give me Instagram login implementation in PHP language

https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login


----------------------

I'll fetch the Meta developer page you linked to read the exact OAuth endpoints and recommended flow, so I can produce a correct PHP implementation (what I'll fetch: the page content and expected outcome: summarize endpoints and steps). Then I'll create a small PHP example (login button, callback, token exchange, long-lived token, sample API call) and a README. I'll fetch the page now.

Auto approved for this profile (Edit)

I'm going to create a minimal PHP example (config, index, callback, api demo, README) implementing the Business Login flow: authorization, code exchange, long-lived token exchange, and refresh. Expected outcome: runnable example you can drop into your webroot and test with your Meta app credentials. I'll add files now.

config.php+17-1
Now I'll add the public pages: index.php (login link), callback.php (exchange code -> short token -> long token), demo_api.php (example Graph API call), and README. I'll create these files next.

index.php+24-1
callback.php+104-1
demo_api.php+72-1
README.md+32-1
I'll create and update a short todo list for the remaining steps, marking the verification step as in-progress. Then I'll run PHP's syntax checks (php -l) on the four PHP files to ensure there are no syntax errors. Expected outcome: each file reports either "No syntax errors detected" or shows error output.

Created 3 todos


php -l "c:\laragon\www\Instagram-login\config.php"; php -l "c:\laragon\www\Instagram-login\index.php"; php -l "c:\laragon\www\Instagram-login\callback.php"; php -l "c:\laragon\www\Instagram-login\demo_api.php"
Progress update: created files and ran PHP lint — all files passed with no syntax errors. Next I'll mark the verification todo as completed and then provide a concise summary, usage steps, and security notes.

Starting (3/3) Provide usage and next steps

What I did next
I read the Meta (Facebook) "Business Login for Instagram" developer doc you linked, then implemented a minimal, runnable PHP example demonstrating the full Business Login flow:

authorization (embed URL)
exchange code -> short-lived token
exchange short-lived -> long-lived token (server-side)
refresh long-lived token
example Graph API call using the long-lived token
Files added under c:\laragon\www\Instagram-login:

config.php — configuration (placeholders for App ID, App Secret, redirect URI, scopes)
index.php — builds the embed URL and shows a login link
callback.php — receives the authorization code, exchanges it for short-lived token, then exchanges for long-lived token and stores tokens in session (demo)
demo_api.php — sample /me Graph API call and server-side token refresh button
README.md — setup and security notes
I ran PHP syntax checks (php -l) on all PHP files — result: No syntax errors detected.

Why this matches the doc (summary)
From the Meta docs the required endpoints and steps are:

Authorization URL: https://www.instagram.com/oauth/authorize
params: client_id, redirect_uri, response_type=code, scope (instagram_business_* scopes)
Exchange code -> short-lived token: POST https://api.instagram.com/oauth/access_token
params: client_id, client_secret, grant_type=authorization_code, redirect_uri, code
Exchange short-lived -> long-lived token: GET https://graph.instagram.com/access_token
params: grant_type=ig_exchange_token, client_secret, access_token
Refresh long-lived token: GET https://graph.instagram.com/refresh_access_token
params: grant_type=ig_refresh_token, access_token
My example follows these exact endpoints and flow (server-side token exchanges for security).

Quick usage / run steps (Windows PowerShell-friendly)
Copy the folder to your webroot (already created at c:\laragon\www\Instagram-login).
Edit config.php and set:
instagram_app_id => your Instagram App ID
instagram_app_secret => your Instagram App Secret
redirect_uri => your callback URL (must exactly match App Dashboard setting; e.g., https://yourdomain.example/callback.php)
(Optionally adjust scopes)
Ensure your Meta app has the Instagram product added and Business Login configured (set redirect URI and embed URL if required).
Start your web server (Laragon/Apache). Open in browser:
http://localhost/Instagram-login/index.php
Click "Log in with Instagram" to begin the Business Login flow.
After consent you will be redirected to callback.php which exchanges the code, stores tokens in session, and links to demo_api.php.
Visit demo_api.php to see a sample /me response and to call the refresh endpoint.
Example shell commands (PowerShell):

Security & production notes (important)
Do NOT commit your app secret into source control. Use environment variables or secure server-side config.
Your app secret must never be exposed to client-side code.
Implement state parameter / CSRF protection in the authorization step for production.
Store tokens securely (database, encrypted storage) rather than session for real apps.
Handle errors and rate limits from Graph API gracefully.
After initial testing, move to HTTPS for redirect URIs and production traffic.
What changed (file list & purpose)
config.php — holds app id, secret, redirect_uri, scopes.
index.php — login link (builds Instagram embed URL).
callback.php — exchanges code for tokens; exchanges short-lived token to long-lived token; stores in session.
demo_api.php — sample Graph API call and token refresh example.
README.md — instructions and notes.
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