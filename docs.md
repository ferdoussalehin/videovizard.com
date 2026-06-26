# claude

### Last session
- This matches scriptgen_2 (which fires on the explicit "AI Video Scenes" action button, not a passive selection). I deliberately did not fire on selectBuildOption('video') itself, because that runs before credit confirmation — firing there would start paid generation even if the user lacks credits or switches back to static.

- If you genuinely want it to fire the instant the card is clicked (before Continue/credit-confirm), say so and I'll move it — but I'd recommend keeping it on Continue. I didn't run a live generation (it costs credits); you can test by going through the Step 6 video path.

### Current flow (synchronous/blocking)

cron_video_gen.php processes one queue row at a time, calls https://fal.run/fal-ai/ltx-2.3/text-to-video (or Kling for image-to-video) and blocks up to 300s waiting for the finished video, then downloads + mediaIngest() + updates hdb_video_gen_que (flag 1→2→3). The loop even refuses to start a second job while one is flag=2. So videos generate strictly one-at-a-time.

### What FAL webhooks change

Instead of fal.run (blocking), you POST to https://queue.fal.run/fal-ai/...?fal_webhook=https://videovizard.com/fal_webhook.php. FAL returns a request_id instantly, runs the job async, and POSTs the result to your webhook when done. This lets you fire off many scenes at once instead of serializing them, and frees the worker from holding a 5-minute connection.

##### Two design forks I'd like your call on before I write the plan:
That is in the saved image

# Fal ai webhook:
- When a user closes the browser mid-generation, any front-end polling or open HTTP connections waiting for a direct response will be cut off. Relying on a front-end trigger to save the video details to your database will fail.

- ​Using a background processing strategy is exactly the right move, but a traditional cron job might be too slow or inefficient if you want near-instant updates as soon as the videos are ready.

- ​Here is a breakdown of the best architectural patterns for VideoVizard to handle disconnected users seamlessly.
#### ​The Best Approach: Fal.ai Webhooks (Recommended)
- ​Instead of forcing a cron job to constantly poll Fal.ai, you should let Fal.ai tell your system when the videos are done. Fal.ai supports webhooks natively. When you dispatch your request, you can provide a webhook_url pointing to an endpoint on your server.
 
#### ​How the Workflow Works
- ​Initiate Request: Your backend fires the 7 video generation requests to Fal.ai asynchronously. In each request payload, you pass a webhook_url (e.g., https://your-api.com/fal-webhook) and include custom metadata (like the user_id, video_id, or project_id).

- ​Immediate DB Update: You instantly create 7 records in your database with a status of processing or pending and return a success message to the UI. The user is now free to close the browser.
- ​Fal.ai Processes Background Job: Fal.ai renders the 6-second videos independently on their serverless GPU infrastructure.

- ​Webhook Execution: Once a video is ready, Fal.ai sends a POST request to your webhook_url containing the generated video URL and your custom metadata.

- Download & Update: Your webhook endpoint picks up this payload, triggers a background script to download the video file from Fal.ai to your specific local folder (or Cloudflare R2), and updates that specific row in the database to completed with the final file path.


> you only use cron_video_gen , take the read part, so read the podcast scenes and fire fal_ai and when you get back update the stories table


# claude plan

I now have a clear picture of the active flow. Let me confirm scope decisions with you before finalizing the plan.

The failing path you describe is the per-scene async submission in promo_step2.php (submit_video_job) — it already submits to queue.fal.run and stores fal_request_id, but completion relies on the browser polling fal_proxy.php. When the browser closes, the video is never downloaded and the scene row stays stuck at video_status='processing'. The cron_video_gen.php path is different — it runs server-side synchronously (blocking a worker for up to 300s per video), so it survives browser close but is inefficient.

