/**
 * videomaker_playback_fix.js  v4  — Per-scene recording + server stitch
 * ─────────────────────────────────────────────────────────────────────────────
 * Strategy: record each scene as a separate WebM clip, upload each clip,
 * then ask the server to FFmpeg-concat them (trimming the first 500 ms of
 * every clip to remove decoder warm-up jitter).
 *
 * What changes vs the original:
 *   • startRecording() is replaced — per-scene loop instead of one long pass
 *   • A new _recordOneScene() helper handles one scene's MediaRecorder cycle
 *   • Upload uses ajax_action=save_scene_clip  (new, see videomaker_ajax.php)
 *   • Stitch uses ajax_action=stitch_scenes    (new, see videomaker_ajax.php)
 *   • All other ajax flows (upload overlay, MP4 poll, scheduler) unchanged
 *   • playSceneWithDynamicSlots, playAudio, preloadAll, showScene unchanged
 *     (the original jerk-fix attempts are removed — they are no longer needed
 *      because each scene now records independently with media already playing)
 *
 * NOTHING else in videomaker.php is changed.
 * Add one line before </body>:
 *   <script src="videomaker_playback_fix.js"></script>
 * ─────────────────────────────────────────────────────────────────────────────
 */

(function () {
    'use strict';

    if (typeof SCENES === 'undefined') {
        console.error('[playback_fix] Core globals not ready.'); return;
    }

    // Preserve original
    window._orig_startRecording = window.startRecording;

    // ── Constants ─────────────────────────────────────────────────────────────
    var WARMUP_MS     = 500;   // ms to record before scene starts (server trims this)
    var REC_BITRATE   = 4000000;

    // ── UI helpers ────────────────────────────────────────────────────────────
    function _setOverlay(title, desc, show) {
        var ov    = document.getElementById('uploadOverlay');
        var otitle = document.getElementById('overlayTitle');
        var odesc  = document.getElementById('overlayDesc');
        var otimer = document.getElementById('overlayTimer');
        if (!ov) return;
        if (show) {
            if (otitle) otitle.textContent = title;
            if (odesc)  odesc.innerHTML    = desc;
            if (otimer) otimer.textContent = '';
            ov.style.display = 'flex';
        } else {
            ov.style.display = 'none';
        }
    }

    function _setProgress(label) {
        var msg = document.getElementById('preloadMsg');
        if (msg) msg.textContent = label;
    }

    // ── Choose MIME ───────────────────────────────────────────────────────────
    function _chooseMime() {
        var types = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp9,opus',
            'video/webm'
        ];
        for (var i = 0; i < types.length; i++) {
            if (MediaRecorder.isTypeSupported(types[i])) return types[i];
        }
        return 'video/webm';
    }

    // ── Build Audio Context for recording ────────────────────────────────────
    function _buildAudioContext(stream) {
        try {
            var actx = new (window.AudioContext || window.webkitAudioContext)();
            var dest = actx.createMediaStreamDestination();
            window._recActx = actx;
            window._recDest = dest;

            // Silent oscillator keeps the audio track alive
            var osc  = actx.createOscillator();
            var gain = actx.createGain();
            gain.gain.value = 0;
            osc.connect(gain); gain.connect(dest); osc.start();

            // Route all preloaded video elements into the recorder
            Object.values(vidEls).forEach(function (v) {
                try {
                    var src = actx.createMediaElementSource(v);
                    src.connect(dest); src.connect(actx.destination);
                } catch (_) {}
            });

            // Route background music
            if (bgAudio) {
                try {
                    var bsrc = actx.createMediaElementSource(bgAudio);
                    bsrc.connect(dest);
                } catch (_) {}
            }

            dest.stream.getAudioTracks().forEach(function (t) { stream.addTrack(t); });
        } catch (e) {
            L('Audio context: ' + e.message, 'wrn');
        }
    }

    // ── Record a single scene — returns a Blob or null ────────────────────────
    // Flow:
    //   1. Start MediaRecorder
    //   2. Wait WARMUP_MS (canvas is rendering the previous scene's last frame,
    //      or blank — server will trim this)
    //   3. Play the scene (audio + video/image sequence)
    //   4. Stop MediaRecorder, collect blob
    async function _recordOneScene(scene, index, mime, stream) {
        return new Promise(async function (resolve) {
            var chunks = [];
            var mr;

            try {
                mr = new MediaRecorder(stream, {
                    mimeType: mime,
                    videoBitsPerSecond: REC_BITRATE
                });
            } catch (e) {
                L('MR scene ' + (index + 1) + ': ' + e.message, 'err');
                resolve(null); return;
            }

            mr.ondataavailable = function (e) {
                if (e.data && e.data.size > 0) chunks.push(e.data);
            };

            mr.onstop = function () {
                var blob = new Blob(chunks, { type: mime });
                resolve(blob);
            };

            // Start recording
            mr.start(200); // collect chunks every 200ms

            // Warmup — let the recorder settle; canvas shows previous scene
            await new Promise(function (r) { setTimeout(r, WARMUP_MS); });

            if (!isRecording) { mr.stop(); return; } // user stopped

            // Now play this scene — audio + visuals run simultaneously
            // showScene(instant=true) swaps immediately with no crossfade
            // so the canvas shows the correct media from frame 1 of real content
            await _playSceneForRecording(scene, index);

            // Stop the recorder for this scene
            if (mr.state !== 'inactive') mr.stop();
        });
    }

    // ── Play one scene during recording (no crossfade, instant swap) ──────────
    async function _playSceneForRecording(scene, index) {
        // Swap canvas instantly — media is already preloaded and seeked
        await showScene(index, true); // instant=true: no crossfade, no jerk

        var enabledSlots   = getEnabledSlotsForScene(scene);
        var slotsWithMedia = enabledSlots.filter(function (s) {
            return (scene[s] || '').trim() !== '';
        });

        // Start audio
        var audioPromise = playSceneAudio(scene,
            isTalkingHeadScene(scene) ? vidEls[(scene.image_file || '').trim()] : null);

        if (slotsWithMedia.length === 0) {
            await audioPromise;
            return;
        }

        var videoSlots = slotsWithMedia.filter(function (s) {
            var f = (scene[s] || '').trim();
            return f && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(f);
        });
        var imageSlots = slotsWithMedia.filter(function (s) {
            var f = (scene[s] || '').trim();
            return f && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(f);
        });

        if (videoSlots.length > 0) {
            await Promise.all([
                playVideoSequence(scene, videoSlots, audioPromise, true),
                audioPromise
            ]);
        } else if (imageSlots.length > 0) {
            // Get audio duration
            var durMs = await new Promise(function (resolve) {
                var a = currentAudio;
                if (a && isFinite(a.duration) && a.duration > 0) {
                    resolve(Math.ceil(a.duration * 1000)); return;
                }
                var fallback = (parseInt(scene.duration) || 5) * 1000;
                if (!a) { resolve(fallback); return; }
                var done = false;
                var finish = function (ms) { if (!done) { done = true; resolve(ms); } };
                a.addEventListener('loadedmetadata',
                    function () { finish(Math.ceil(a.duration * 1000)); }, { once: true });
                setTimeout(function () { finish(fallback); }, 2000);
            });
            await Promise.all([
                playImageSequence(scene, imageSlots, durMs, audioPromise, true),
                audioPromise
            ]);
        } else {
            await audioPromise;
        }
    }

    // ── Upload one scene clip ─────────────────────────────────────────────────
    async function _uploadSceneClip(blob, sceneIndex, totalScenes) {
        var fname = 'podcast_' + PODCAST_ID + '_scene_' + sceneIndex + '.webm';
        _setProgress('Uploading scene ' + (sceneIndex + 1) + '/' + totalScenes + '…');
        try {
            var fd = new FormData();
            fd.append('ajax_action', 'save_scene_clip');
            fd.append('podcast_id',  PODCAST_ID);
            fd.append('scene_index', sceneIndex);
            fd.append('warmup_ms',   WARMUP_MS);
            fd.append('video',       blob, fname);
            var r    = await fetch(location.href, { method: 'POST', body: fd });
            var text = await r.text();
            console.log('[scene_clip] scene ' + sceneIndex + ' server response:', text);
            var data;
            try { data = JSON.parse(text); } catch(e) {
                throw new Error('Non-JSON response: ' + text.substring(0, 200));
            }
            if (!data.success) throw new Error(data.message || 'Upload failed');
            L('✅ Scene ' + (sceneIndex + 1) + ' uploaded (' + (data.size_mb || '?') + ' MB)', 'ok');
            return true;
        } catch (e) {
            L('⚠ Scene ' + (sceneIndex + 1) + ' upload failed: ' + e.message, 'err');
            console.error('[scene_clip] upload error scene ' + sceneIndex + ':', e);
            return false;
        }
    }

    // ── Ask server to stitch all clips ────────────────────────────────────────
    async function _stitchScenes(totalScenes) {
        _setOverlay('🎬 Stitching scenes…',
            'Combining ' + totalScenes + ' clips into final video.<br>This takes 1–3 minutes.', true);
        L('🎬 Stitching ' + totalScenes + ' scenes…', 'ok');

        // Start stitch job
        var jobId = null;
        try {
            var fd = new FormData();
            fd.append('ajax_action',  'stitch_scenes');
            fd.append('podcast_id',   PODCAST_ID);
            fd.append('scene_count',  totalScenes);
            fd.append('warmup_ms',    WARMUP_MS);
            var r    = await fetch(location.href, { method: 'POST', body: fd });
            var data = await r.json();
            if (!data.success) throw new Error(data.message || 'Stitch failed');
            jobId = data.job_id;
            if (!jobId) throw new Error('No job ID');
        } catch (e) {
            _setOverlay('', '', false);
            L('⚠ Stitch failed: ' + e.message, 'err');
            return false;
        }

        // Poll for completion — same pattern as existing MP4 conversion
        return new Promise(function (resolve) {
            var attempts  = 0;
            var maxAttempts = 120; // 10 minutes max
            var elapsed   = 0;
            var otimer    = document.getElementById('overlayTimer');

            var tick = setInterval(function () {
                elapsed++;
                if (otimer) otimer.textContent = '⏱ ' + elapsed + 's elapsed';
            }, 1000);

            var poll = setInterval(async function () {
                attempts++;
                try {
                    var fd = new FormData();
                    fd.append('ajax_action', 'poll_mp4_convert'); // reuse same poller
                    fd.append('job_id',      jobId);
                    fd.append('podcast_id',  PODCAST_ID);
                    var r    = await fetch(location.href, { method: 'POST', body: fd });
                    var data = await r.json();

                    if (data.status === 'done') {
                        clearInterval(poll); clearInterval(tick);
                        _setOverlay('', '', false);
                        L('✅ Final MP4 ready!', 'ok');
                        var mp4Url      = 'published_videos/podcast_' + PODCAST_ID + '.mp4';
                        var mp4Filename = 'podcast_' + PODCAST_ID + '.mp4';
                        window._mp4Ready   = true;
                        window._mp4Url     = mp4Url;
                        window._mp4Filename = mp4Filename;
                        openSchedModalWithMp4(mp4Url, mp4Filename, data.mp4_size_mb || '?');
                        resolve(true);
                    } else if (data.status === 'failed' || data.status === 'error') {
                        clearInterval(poll); clearInterval(tick);
                        _setOverlay('', '', false);
                        L('⚠ Stitch failed on server.', 'err');
                        resolve(false);
                    } else if (attempts >= maxAttempts) {
                        clearInterval(poll); clearInterval(tick);
                        _setOverlay('', '', false);
                        L('⚠ Stitch timeout.', 'err');
                        resolve(false);
                    }
                } catch (e) {
                    console.warn('[playback_fix] stitch poll error:', e);
                }
            }, 5000);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MAIN — replace startRecording
    // ─────────────────────────────────────────────────────────────────────────
    window.startRecording = async function () {
        window._processingComplete = false;

        // Queue notification (unchanged from original)
        try {
            var fd = new FormData();
            fd.append('ajax_action', 'queue_generate');
            fd.append('podcast_id',  PODCAST_ID);
            var r    = await fetch(location.href, { method: 'POST', body: fd });
            var data = await r.json();
            if (data.success) {
                var pos  = data.position, mins = data.minutes;
                alert('✅ Your video has been queued!\n\nPosition: ' + pos +
                      '\nEstimated time: ' + mins + ' minute' + (mins !== 1 ? 's' : '') +
                      '\n\n(' + pos + ' video' + (pos !== 1 ? 's' : '') + ' × 3 minutes each)');
            }
        } catch (e) { console.warn('Queue insert failed:', e); }

        discardRec();

        // Preload all scenes
        var ov  = document.getElementById('preloadOverlay');
        var msg = document.getElementById('preloadMsg');
        if (ov)  { ov.style.opacity = '1'; ov.classList.remove('gone'); }
        if (msg) msg.textContent = 'Loading media…';
        await preloadAll();
        if (ov) { ov.style.transition = 'opacity .4s'; ov.style.opacity = '0';
                  setTimeout(function(){ov.classList.add('gone');}, 450); }

        // Wait for render loop to have a few frames
        await new Promise(function (res) {
            var chk = function () { framesDrawn >= 5 ? res() : requestAnimationFrame(chk); };
            chk();
        });

        // Capture stream from HD canvas
        var stream;
        try {
            stream = canvasHD.captureStream(30);
        } catch (e) {
            L('captureStream: ' + e.message, 'err'); return;
        }

        // Build audio graph
        _buildAudioContext(stream);

        var mime = _chooseMime();

        // UI: recording started
        isRecording = true;
        canvas.classList.add('recording');
        document.getElementById('recBar').classList.add('on');
        document.getElementById('dlPanel').classList.remove('on');
        document.getElementById('ibRecord').innerHTML = '<span class="ico">⏹</span>Stop';
        updateNavButtons();
        L('Recording scenes…', 'inf');
        syncPodcastThumbnail();
        logActivity('record_start', 'scenes:' + SCENES.length);

        if (bgAudio) { bgAudio.currentTime = 0; bgAudio.play().catch(function(){}); }

        // ── PER-SCENE RECORDING LOOP ──────────────────────────────────────────
        var sceneBlobs   = [];
        var uploadFailed = false;

        for (var i = 0; i < SCENES.length; i++) {
            if (!isRecording) break;

            L('⏺ Recording scene ' + (i + 1) + '/' + SCENES.length + '…', 'inf');

            // Prepare this scene's media: seek video to t=0, decode first frame
            await prepareNextScene(i);

            // Record this scene
            var blob = await _recordOneScene(SCENES[i], i, mime, stream);

            if (!isRecording) break; // user stopped mid-scene

            if (!blob || blob.size < 1000) {
                L('⚠ Scene ' + (i + 1) + ' produced empty clip — skipping', 'err');
                continue;
            }

            var sizeMb = (blob.size / 1024 / 1024).toFixed(2);
            L('Scene ' + (i + 1) + ' recorded — ' + sizeMb + ' MB', 'ok');

            // Upload immediately while next scene records
            var ok = await _uploadSceneClip(blob, i, SCENES.length);
            if (!ok) uploadFailed = true;
            sceneBlobs.push({ index: i, ok: ok });
        }

        // Stop everything
        if (bgAudio) bgAudio.pause();
        if (currentAudio) { currentAudio.pause(); currentAudio = null; }
        canvas.classList.remove('recording');
        document.getElementById('recBar').classList.remove('on');
        isRecording = false;
        document.getElementById('ibRecord').innerHTML = '<span class="ico">⏺</span>Generate Video';
        window._recActx = null;
        window._recDest = null;
        updateNavButtons();

        if (uploadFailed) {
            L('⚠ Some scenes failed to upload. Cannot stitch.', 'err');
            return;
        }

        var recorded = sceneBlobs.filter(function (s) { return s.ok; }).length;
        if (recorded === 0) {
            L('⚠ No scenes recorded.', 'err'); return;
        }

        L('✅ All ' + recorded + ' scenes uploaded. Starting stitch…', 'ok');
        logActivity('record_done', recorded + '_scenes');
        await updateVideoStatusToRecorded(PODCAST_ID);

        // Stitch on server
        await _stitchScenes(SCENES.length);
    };

    console.log('[videomaker_playback_fix.js v4] Per-scene recording active.');

})();
