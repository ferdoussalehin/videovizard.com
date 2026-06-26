// ============================================================
// COMBINED RECORDING MODULE — one WebM for all scenes
// Saved 2026-05-21
// ============================================================

async function startRecording() {
    window._processingComplete = false;
    // Insert into queue and show estimated wait time
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'queue_generate');
        fd.append('podcast_id',  PODCAST_ID);
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (data.success) {
            const pos  = data.position;
            const mins = data.minutes;
            alert(`✅ Your video has been queued!\n\nPosition in queue: ${pos}\nEstimated time: ${mins} minute${mins !== 1 ? 's' : ''}\n\n(${pos} video${pos !== 1 ? 's' : ''} × 3 minutes each)`);
        }
    } catch(e) { console.warn('Queue insert failed:', e); }

    discardRec();

    // Load all enabled-slot media before recording
    const ov = document.getElementById('preloadOverlay');
    if (ov) { ov.style.opacity='1'; ov.classList.remove('gone'); }
    const msg = document.getElementById('preloadMsg');
    if (msg) msg.textContent = 'Loading media…';
    await preloadAll();
    if (ov) { ov.style.transition='opacity .4s'; ov.style.opacity='0'; setTimeout(()=>ov.classList.add('gone'),450); }

    await new Promise(res => {
        const chk = () => framesDrawn >= 5 ? res() : requestAnimationFrame(chk);
        chk();
    });
    
    let stream;
    try {
        stream = canvasHD.captureStream(30);
    } catch (e) {
        L('captureStream: ' + e.message, 'err');
        return;
    }
    
    try {
        const actx = new (window.AudioContext || window.webkitAudioContext)();
        const dest = actx.createMediaStreamDestination();
        window._recActx = actx;
        window._recDest = dest;

        const osc = actx.createOscillator();
        const gainNode = actx.createGain();
        gainNode.gain.value = 0;
        osc.connect(gainNode);
        gainNode.connect(dest);
        osc.start();

        Object.values(vidEls).forEach(v => {
            try {
                const source = actx.createMediaElementSource(v);
                source.connect(dest);
                source.connect(actx.destination);
            } catch (_) {}
        });
        if (bgAudio) {
            try {
                const source = actx.createMediaElementSource(bgAudio);
                source.connect(dest);
            } catch (_) {}
        }
        dest.stream.getAudioTracks().forEach(t => stream.addTrack(t));
    } catch (e) {
        L('Audio: ' + e.message, 'wrn');
    }

    let MIME = 'video/webm;codecs=vp9,opus';
    if (MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')) {
        MIME = 'video/webm;codecs=vp8,opus';
    } else if (MediaRecorder.isTypeSupported('video/webm')) {
        MIME = 'video/webm';
    } else if (MediaRecorder.isTypeSupported('video/mp4')) {
        MIME = 'video/mp4';
    }

    recChunks = [];
    try {
        mr = new MediaRecorder(stream, {
            mimeType: MIME,
            videoBitsPerSecond: 4000000
        });
    } catch (e) {
        L('MR: ' + e.message, 'err');
        return;
    }

    mr.ondataavailable = e => {
        if (e.data && e.data.size > 0) recChunks.push(e.data);
        document.getElementById('recSize').textContent = (recChunks.reduce((s, c) => s + c.size, 0) / 1024 / 1024).toFixed(1) + ' MB';
    };

    mr.onstop = async () => {
        if (window._processingComplete) return;
        window._processingComplete = true;

        recBlob = new Blob(recChunks, { type: MIME });
        const mb = (recBlob.size / 1024 / 1024).toFixed(2);
        document.getElementById('recBar').classList.remove('on');
        canvas.classList.remove('recording');
        isRecording = false;
        document.getElementById('ibRecord').innerHTML = '<span class="ico">⏺</span>Generate Video';
        window._recActx = null;
        window._recDest = null;
        updateNavButtons();
        L(`✅ Recording done — ${mb} MB`, 'ok');
        logActivity('record_done', mb + 'MB');

        const fname = `podcast_${PODCAST_ID}.webm`;
        _vsRecFname = fname;

        _vsRecURL   = URL.createObjectURL(recBlob);

        const overlay = document.getElementById('uploadOverlay');
        const overlayTitle = document.getElementById('overlayTitle');
        const overlayDesc = document.getElementById('overlayDesc');
        const overlayTimer = document.getElementById('overlayTimer');
        if (overlay) {
            if (overlayTitle) overlayTitle.textContent = '⬆ Uploading your video…';
            if (overlayDesc) overlayDesc.innerHTML = 'Sending to server. Please wait…';
            if (overlayTimer) overlayTimer.textContent = '';
            overlay.style.display = 'flex';
        }
        L('⬆ Uploading WebM to server…', 'inf');

        let uploadOk = false;
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'save_published_video');
            fd.append('podcast_id', PODCAST_ID);
            fd.append('video', recBlob, fname);
            const r = await fetch(location.href, { method: 'POST', body: fd });
            const data = await r.json();
            uploadOk = data.success;
            if (!uploadOk) throw new Error(data.message || 'Upload failed');
            L('✅ Video uploaded to server', 'ok');
            await updateVideoStatusToRecorded(PODCAST_ID);
        } catch(e) {
            if (overlay) overlay.style.display = 'none';
            L('⚠ Upload failed: ' + e.message, 'err');
            // Still let user download the local WebM blob (server upload failed, use object URL)
            showWebmDownloadOnly(PODCAST_ID, _vsRecURL);
            openSchedModal(recBlob, _vsRecURL, fname);
            return;
        }

        if (overlayTitle) overlayTitle.textContent = '🎬 Converting to MP4…';
        if (overlayDesc) overlayDesc.innerHTML = 'Your video is being converted for social media.<br>This takes 1–3 minutes.';
        L('🎬 Starting MP4 conversion…', 'ok');

        let jobId = null;
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'start_mp4_convert');
            fd.append('podcast_id', PODCAST_ID);
            const r = await fetch(location.href, { method: 'POST', body: fd });
            const data = await r.json();
            if (data.fallback || !data.success) throw new Error('fallback');
            jobId = data.job_id;
            if (!jobId) throw new Error('No job ID');
            L('⏳ Conversion started…', 'ok');
        } catch(e) {
            if (overlay) overlay.style.display = 'none';
            L('⚠ MP4 conversion unavailable — WebM ready.', 'wrn');
            // Always show a download option so the user can save their video
            showWebmDownloadOnly(PODCAST_ID);
            openSchedModal(recBlob, _vsRecURL, fname);
            return;
        }

        let attempts = 0;
        const maxAttempts = 72;
        let elapsedSec = 0;
        const timerTick = setInterval(() => {
            elapsedSec++;
            if (overlayTimer) overlayTimer.textContent = `⏱ ${elapsedSec}s elapsed`;
        }, 1000);

        const poll = setInterval(async () => {
            attempts++;
            try {
                const fd = new FormData();
                fd.append('ajax_action', 'poll_mp4_convert');
                fd.append('job_id', jobId);
                fd.append('podcast_id', PODCAST_ID);
                const r = await fetch(location.href, { method: 'POST', body: fd });
                const data = await r.json();

                if (data.status === 'done') {
                    clearInterval(poll);
                    clearInterval(timerTick);
                    if (overlay) overlay.style.display = 'none';
                    L('✅ MP4 ready!', 'ok');
                    const mp4Url = 'published_videos/podcast_' + PODCAST_ID + '.mp4';
                    const mp4Filename = 'podcast_' + PODCAST_ID + '.mp4';
                    window._mp4Ready = true;
                    window._mp4Url = mp4Url;
                    window._mp4Filename = mp4Filename;
                    openSchedModalWithMp4(mp4Url, mp4Filename, data.mp4_size_mb || '?');
                } else if (data.status === 'failed' || data.status === 'error') {
                    clearInterval(poll);
                    clearInterval(timerTick);
                    if (overlay) overlay.style.display = 'none';
                    L('⚠ MP4 conversion failed — WebM ready.', 'wrn');
                    showWebmDownloadOnly(PODCAST_ID);
                    openSchedModal(recBlob, _vsRecURL, fname);
                } else if (attempts >= maxAttempts) {
                    clearInterval(poll);
                    clearInterval(timerTick);
                    if (overlay) overlay.style.display = 'none';
                    L('⚠ Conversion timeout — WebM ready.', 'wrn');
                    showWebmDownloadOnly(PODCAST_ID);
                    openSchedModal(recBlob, _vsRecURL, fname);
                }
            } catch(e) {
                console.warn('Poll error:', e);
            }
        }, 5000);
    };

    // START RECORDING — one scene at a time
    await new Promise(res => requestAnimationFrame(res));

    isRecording = true;
    canvas.classList.add('recording');
    document.getElementById('recBar').classList.add('on');
    document.getElementById('dlPanel').classList.remove('on');
    document.getElementById('ibRecord').innerHTML = '<span class="ico">⏹</span>Stop';
    updateNavButtons();
    L('Recording scenes…', 'inf');
    syncPodcastThumbnail();
    logActivity('record_start', 'scenes:' + SCENES.length);

    if (bgAudio) { bgAudio.currentTime = 0; bgAudio.play().catch(() => {}); }

    console.log('\n========== RECORDING START (per-scene) ==========');

    for (let i = 0; i < SCENES.length; i++) {
        if (!isRecording) break;

        L(`⏺ Recording scene ${i + 1}/${SCENES.length}…`, 'inf');

        // Seek scene media to t=0 (no pre-play)
        await prepareNextScene(i);

        // ── Record one scene ──────────────────────────────────────────────
        const sceneBlob = await new Promise(async (resolveScene) => {
            const chunks = [];
            let sceneMr;
            try {
                sceneMr = new MediaRecorder(stream, {
                    mimeType: MIME,
                    videoBitsPerSecond: 4000000
                });
            } catch (e) {
                L(`MR scene ${i + 1}: ${e.message}`, 'err');
                resolveScene(null); return;
            }

            sceneMr.ondataavailable = e => {
                if (e.data && e.data.size > 0) chunks.push(e.data);
                const total = chunks.reduce((s, c) => s + c.size, 0);
                document.getElementById('recSize').textContent =
                    (total / 1024 / 1024).toFixed(1) + ' MB';
            };

            sceneMr.onstop = () => resolveScene(new Blob(chunks, { type: MIME }));

            // Show this scene on canvas BEFORE starting recorder
            // so the first captured frame is already this scene, not the previous one
            showScene(i, true);

            // Wait two rAF ticks — canvas has rendered the new scene's first frame
            await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

            if (!isRecording) { resolveScene(null); return; }

            // Start recorder — canvas is clean, showing only this scene
            sceneMr.start(200);

            // Play audio + media for this scene
            const audioPromise = playSceneAudio(
                SCENES[i],
                isTalkingHeadScene(SCENES[i])
                    ? vidEls[(SCENES[i].image_file || '').trim()] : null
            );

            const enabledSlots   = getEnabledSlotsForScene(SCENES[i]);
            const slotsWithMedia = enabledSlots.filter(s => (SCENES[i][s] || '').trim() !== '');
            const videoSlots     = slotsWithMedia.filter(s =>
                /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test((SCENES[i][s] || '').trim()));
            const imageSlots     = slotsWithMedia.filter(s =>
                !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test((SCENES[i][s] || '').trim()));

            if (videoSlots.length > 0) {
                await Promise.all([
                    playVideoSequence(SCENES[i], videoSlots, audioPromise, true),
                    audioPromise
                ]);
            } else if (imageSlots.length > 0) {
                const durMs = await new Promise(resolve => {
                    const a = currentAudio;
                    if (a && isFinite(a.duration) && a.duration > 0) {
                        resolve(Math.ceil(a.duration * 1000)); return;
                    }
                    const fallback = (parseInt(SCENES[i].duration) || 5) * 1000;
                    if (!a) { resolve(fallback); return; }
                    let d2 = false;
                    const f2 = ms => { if (!d2) { d2 = true; resolve(ms); } };
                    a.addEventListener('loadedmetadata',
                        () => f2(Math.ceil(a.duration * 1000)), { once: true });
                    setTimeout(() => f2(fallback), 2000);
                });
                await Promise.all([
                    playImageSequence(SCENES[i], imageSlots, durMs, audioPromise, true),
                    audioPromise
                ]);
            } else {
                await audioPromise;
            }

            if (sceneMr.state !== 'inactive') sceneMr.stop();
        });

        if (!isRecording) break;

        if (!sceneBlob || sceneBlob.size < 1000) {
            L(`⚠ Scene ${i + 1} empty — skipped`, 'err');
            continue;
        }

        const sizeMb = (sceneBlob.size / 1024 / 1024).toFixed(2);
        L(`Scene ${i + 1} recorded — ${sizeMb} MB`, 'ok');

        // Upload this scene clip
        try {
            const fname = `podcast_${PODCAST_ID}_scene_${i}.webm`;
            const fd2 = new FormData();
            fd2.append('ajax_action', 'save_scene_clip');
            fd2.append('podcast_id',  PODCAST_ID);
            fd2.append('scene_index', i);
            fd2.append('video',       sceneBlob, fname);
            const r2   = await fetch(location.href, { method: 'POST', body: fd2 });
            const text = await r2.text();
            let d2;
            try { d2 = JSON.parse(text); }
            catch(e) { throw new Error('Server error: ' + text.substring(0, 100)); }
            if (!d2.success) throw new Error(d2.message || 'Upload failed');
            L(`✅ Scene ${i + 1} saved (${d2.size_mb || '?'} MB)`, 'ok');
        } catch (e) {
            L(`⚠ Scene ${i + 1} upload failed: ${e.message}`, 'err');
            console.error('Upload error:', e);
        }
    }

    // Done — clean up
    if (bgAudio) bgAudio.pause();
    if (currentAudio) { currentAudio.pause(); currentAudio = null; }
    canvas.classList.remove('recording');
    document.getElementById('recBar').classList.remove('on');
    isRecording = false;
    document.getElementById('ibRecord').innerHTML = '<span class="ico">⏺</span>Generate Video';
    window._recActx = null;
    window._recDest = null;
    updateNavButtons();
    logActivity('record_done', 'scenes:' + SCENES.length);
    await updateVideoStatusToRecorded(PODCAST_ID);
    L('✅ All scenes recorded and saved. Ready for stitching.', 'ok');
}
