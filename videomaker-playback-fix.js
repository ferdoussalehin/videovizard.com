// ============================================================
// videomaker-playback-fix.js
// Drop-in replacement — fixes blank canvas & double-image bugs
//
// HOW TO USE:
//   1. Save this file as  videomaker-playback-fix.js  in your
//      project root (same folder as your PHP file).
//   2. Add ONE line just before </body> in your PHP file:
//        <script src="videomaker-playback-fix.js"></script>
//   3. Done. These functions override the broken ones above.
// ============================================================

// ── Preload cache (shared with main script) ───────────────────
window.preloadCache = window.preloadCache || {};

// ── Preload all scenes ────────────────────────────────────────
async function preloadAllScenes() {
    const stopBtn = document.getElementById('floatingStopBtn');
    if (stopBtn) {
        stopBtn.style.display    = 'block';
        stopBtn.style.background = '#f59e0b';
        stopBtn.innerText        = '⏳ Loading...';
    }

    await Promise.all(scenes.map(scene => new Promise(resolve => {
        const slots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
        const promises = slots.map(slot => new Promise(res => {
            const fn = (scene[slot] || '').trim();
            if (!fn) { res(); return; }

            const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
            if (isVid) {
                window.preloadCache[fn] = { type: 'video', src: 'podcast_videos/' + fn };
                res();
                return;
            }
            if (window.preloadCache[fn]) { res(); return; }

            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload  = () => { window.preloadCache[fn] = { type: 'image', element: img }; res(); };
            img.onerror = () => res();
            setTimeout(res, 5000);
            img.src = 'podcast_images/' + fn + '?t=' + Date.now();
        }));
        Promise.all(promises).then(resolve);
    })));

    if (stopBtn) {
        stopBtn.style.background = '#dc2626';
        stopBtn.innerText        = '⏹ Stop';
        stopBtn.style.display    = 'none';
    }
    console.log('✅ Preload complete:', Object.keys(window.preloadCache).length, 'files cached');
}

// ── Swap image background IN-PLACE (no clear = no blank flash) 
async function swapImageBackground(filename) {
    return new Promise(resolve => {
        // Remove DOM video if we're switching from video to image
        const oldVid = document.getElementById('backgroundVideo');
        if (oldVid) { oldVid.pause(); oldVid.remove(); }
        if (currentBackgroundVideo) { currentBackgroundVideo = null; }

        // Restore canvas opacity in case it was transparent for video
        const canvasEl = document.getElementById('fabricCanvas');
        if (canvasEl) canvasEl.style.backgroundColor = '';

        fabric.Image.fromURL(
            'podcast_images/' + filename,
            img => {
                if (!img) { resolve(); return; }

                const cW    = fabricCanvas.width;
                const cH    = fabricCanvas.height;
                const scale = Math.max(cW / img.width, cH / img.height);

                img.set({
                    left:        (cW - img.width  * scale) / 2,
                    top:         (cH - img.height * scale) / 2,
                    scaleX:      scale,
                    scaleY:      scale,
                    selectable:  false,
                    evented:     false,
                    hasControls: false,
                    hasBorders:  false,
                    originX:     'left',
                    originY:     'top'
                });

                // ADD new image first, THEN remove old — eliminates blank frame
                const oldBg = currentBackgroundImage;
                fabricCanvas.add(img);
                fabricCanvas.sendToBack(img);
                if (oldBg) fabricCanvas.remove(oldBg);

                currentBackgroundImage = img;
                fabricCanvas.renderAll();

                // Start Ken Burns on the new background
                const scene  = scenes.find(s => s.id == currentSceneId);
                const effect = (scene && scene.ken_burns_effect) ? scene.ken_burns_effect : 'zoom-in';
                applyKenBurnsToCanvas(img, effect);

                resolve();
            },
            { crossOrigin: 'anonymous' }
        );
    });
}

// ── Swap video background IN-PLACE ───────────────────────────
async function swapVideoBackground(fullPath) {
    return new Promise(resolve => {
        if (window.kenBurnsFrame) {
            cancelAnimationFrame(window.kenBurnsFrame);
            window.kenBurnsFrame = null;
        }
        if (window._videoFrameLoop) {
            cancelAnimationFrame(window._videoFrameLoop);
            window._videoFrameLoop = null;
        }

        const container = document.getElementById('canvasContainer');
        if (!container) { resolve(); return; }

        // Remove Fabric background image
        if (currentBackgroundImage) {
            fabricCanvas.remove(currentBackgroundImage);
            currentBackgroundImage = null;
        }

        // Reuse existing <video> element if possible — avoids flicker
        let video     = document.getElementById('backgroundVideo');
        const reusing = !!video;

        if (!video) {
            video               = document.createElement('video');
            video.id            = 'backgroundVideo';
            video.muted         = true;
            video.loop          = true;
            video.playsInline   = true;
            video.setAttribute('playsinline', '');
            video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;' +
                                  'object-fit:cover;z-index:1;pointer-events:none;';
            container.appendChild(video);
        }

        // Make Fabric canvas transparent so DOM video shows through
        const canvasEl = document.getElementById('fabricCanvas');
        if (canvasEl) canvasEl.style.backgroundColor = 'transparent';
        fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', () => {});

        const basePath = fullPath.split('?')[0];
        if (reusing && video.src && video.src.includes(basePath)) {
            // Same video — just restart from beginning
            video.currentTime  = 0;
            currentBackgroundVideo = video;
            resolve();
            return;
        }

        const timeout = setTimeout(resolve, 4000); // safety

        video.onloadeddata = () => {
            clearTimeout(timeout);
            video.play()
                .then(() => { currentBackgroundVideo = video; resolve(); })
                .catch(() => { currentBackgroundVideo = video; resolve(); });
        };
        video.onerror = () => { clearTimeout(timeout); resolve(); };

        video.src = fullPath + '?t=' + Date.now();
        video.load();
    });
}

// ── Fast scene loader — no canvas.clear() ────────────────────
async function loadSceneForPlayback(sceneIndex) {
    const scene = scenes[sceneIndex];
    if (!scene || !fabricCanvas) return;

    // Stop Ken Burns without touching canvas
    if (window.kenBurnsFrame) {
        cancelAnimationFrame(window.kenBurnsFrame);
        window.kenBurnsFrame = null;
    }
    if (window._slideshowTimer) {
        clearInterval(window._slideshowTimer);
        window._slideshowTimer = null;
    }

    // Find primary media file
    const isVid = fn => fn && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    const slots  = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    let primary  = (scene[currentImageField] || '').trim();
    if (!primary) {
        for (const slot of slots) {
            if ((scene[slot] || '').trim()) { primary = scene[slot].trim(); break; }
        }
    }

    if (isVid(primary)) {
        await swapVideoBackground('podcast_videos/' + primary);
    } else if (primary) {
        await swapImageBackground(primary);
    }

    // Load captions for this scene from DB
    sceneCaptions = {};
    await loadSceneCaptions(scene.id);

    // Remove ONLY caption/logo objects — background stays on canvas
    fabricCanvas.getObjects()
        .filter(o => o.captionId || o.isLogo)
        .forEach(o => fabricCanvas.remove(o));

    // Add captions back
    for (const key in sceneCaptions) {
        if (isNaN(key)) continue;
        const cap = sceneCaptions[key];
        if (cap.image_file && cap.image_file.trim()) {
            await addImageBoxToCanvas(cap.id, cap.image_file, false);
        } else if (cap.text_content) {
            await addMainCaptionToFabric(scene, cap);
        }
    }

    if (scene.logo_enabled && scene.logo_name) await addLogoToFabric(scene);

    fabricCanvas.renderAll();
}

// ── Kick off caption animations for current scene ─────────────
function startSceneCaptionAnimations() {
    if (!fabricCanvas) return;
    setTimeout(() => {
        fabricCanvas.getObjects()
            .filter(o => o.captionId)
            .forEach(o => {
                const style = (o.animationStyle || 'none');
                if (style && style !== 'none' && style !== 'static') {
                    startCaptionAnimation(o);
                }
            });
    }, 100);
}

// ── PLAY PREVIEW ──────────────────────────────────────────────
async function playFullSequence() {
    if (!scenes || !scenes.length) { alert('No scenes to play'); return; }
    if (isPlayingSequence) { stopPlayback(); return; }
    if (isRecording) return;

    hideAllOverlays();
    await preloadAllScenes();

    isPlayingSequence = true;
    currentSceneIndex = 0;
    currentSceneId    = scenes[0].id;
    updateSceneIndicator();

    showStopBtn();

    // Load first scene (no transition on scene 0)
    await loadSceneForPlayback(0);
    startSceneCaptionAnimations();

    // Start background music
    if (podcastMusicFile) _startBgMusic('preview-bg-music');

    await runSceneAudio(0);
}

// ── Scene audio runner (preview) ──────────────────────────────
async function runSceneAudio(index) {
    if (!isPlayingSequence || index >= scenes.length) {
        stopPlayback();
        return;
    }

    const scene = scenes[index];
    currentSceneIndex = index;
    currentSceneId    = scene.id;
    updateSceneIndicator();

    const audioFile = (scene.audio_file) || (audio_files && audio_files[scene.id]);

    if (!audioFile) {
        // No audio — hold for 1.5 s then advance
        setTimeout(async () => {
            if (!isPlayingSequence) return;
            stopCaptionAnimation();
            await advancePreviewToScene(index + 1);
        }, 1500);
        return;
    }

    const player = new Audio();
    currentAudioPlayer = player;
    player.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

    player.onloadedmetadata = () => {
        const durMs = player.duration * 1000;

        // Pre-warm next scene image while current audio plays
        warmNextScene(index + 1, durMs);

        player.play().catch(() => {});
    };

    player.onended = async () => {
        if (!isPlayingSequence) return;
        stopCaptionAnimation();
        await advancePreviewToScene(index + 1);
    };

    player.onerror = async () => {
        if (!isPlayingSequence) return;
        stopCaptionAnimation();
        setTimeout(() => advancePreviewToScene(index + 1), 300);
    };

    player.load();
}

async function advancePreviewToScene(nextIndex) {
    if (!isPlayingSequence || nextIndex >= scenes.length) {
        stopPlayback();
        return;
    }

    // Transition covers the canvas swap
    if (currentTransitionStyle && currentTransitionStyle !== 'none') {
        await playTransition(currentTransitionStyle);
    }

    currentSceneIndex = nextIndex;
    currentSceneId    = scenes[nextIndex].id;

    await loadSceneForPlayback(nextIndex);
    startSceneCaptionAnimations();
    await runSceneAudio(nextIndex);
}

// ── Pre-warm next scene image into browser cache ───────────────
function warmNextScene(nextIndex, currentDurMs) {
    if (nextIndex >= scenes.length) return;
    const delay = Math.max((currentDurMs * 0.7), currentDurMs - 800);
    setTimeout(() => {
        const next = scenes[nextIndex];
        if (!next) return;
        const fn = (next[currentImageField] || next.image_file || '').trim();
        if (!fn || /\.(mp4|webm|mov)$/i.test(fn) || window.preloadCache[fn]) return;
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => { window.preloadCache[fn] = { type: 'image', element: img }; };
        img.src = 'podcast_images/' + fn;
    }, delay);
}

// ── Stop preview playback ─────────────────────────────────────
function stopPlayback() {
    isPlayingSequence = false;

    if (currentAudioPlayer) {
        try { currentAudioPlayer.pause(); } catch(e) {}
        currentAudioPlayer = null;
    }

    ['preview-bg-music', 'render-bg-music'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { try { el.pause(); } catch(e) {} el.remove(); }
    });

    stopCaptionAnimation();
    hideStopBtn();

    const ov = document.getElementById('_transitionOverlay');
    if (ov) ov.remove();

    L('⏹ Playback stopped');
}

// ── RECORDING ─────────────────────────────────────────────────
async function startRecording() {
    if (isRecording) { stopRecording(); return; }
    if (!scenes || !scenes.length) { alert('No scenes to record'); return; }

    isRecording         = true;
    recordedChunks      = [];
    renderAudioElements = [];
    _renderLock         = -1;

    hideAllOverlays();
    await preloadAllScenes();
    showStopBtn();

    // Load first scene before starting recorder
    currentSceneIndex = 0;
    currentSceneId    = scenes[0].id;
    updateSceneIndicator();

    await loadSceneForPlayback(0);
    await new Promise(r => setTimeout(r, 300)); // let canvas settle

    if (podcastMusicFile) _startBgMusic('render-bg-music');

    startMediaRecorder();
    await new Promise(r => setTimeout(r, 300)); // let recorder initialise

    startSceneCaptionAnimations();
    await runSceneRender(0);
}

let _renderLock = -1;

async function runSceneRender(index) {
    if (!isRecording) return;
    if (index >= scenes.length) { finishRecording(); return; }
    if (_renderLock === index) { console.warn('runSceneRender duplicate call skipped'); return; }

    _renderLock = index;

    const scene = scenes[index];
    if (!scene) { _renderLock = -1; await runSceneRender(index + 1); return; }

    L(`🎥 Recording scene ${index + 1} / ${scenes.length}`);

    currentSceneIndex = index;
    currentSceneId    = scene.id;
    updateSceneIndicator();

    // Scene swap (skip transition on scene 0)
    if (index > 0) {
        if (currentTransitionStyle && currentTransitionStyle !== 'none') {
            await playTransition(currentTransitionStyle);
        }
        await loadSceneForPlayback(index);
        await new Promise(r => setTimeout(r, 150)); // settle
    }

    startSceneCaptionAnimations();

    const audioFile = (scene.audio_file) || (audio_files && audio_files[scene.id]);

    if (!audioFile) {
        L(`⚠️ Scene ${index + 1}: no audio — 2 s pause`);
        _renderLock = -1;
        setTimeout(() => { if (isRecording) runSceneRender(index + 1); }, 2000);
        return;
    }

    const audio = new Audio();
    renderAudioElements.push(audio);
    audio.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

    audio.onloadedmetadata = () => {
        const durMs = audio.duration * 1000;
        L(`⏱️ Scene ${index + 1}: ${audio.duration.toFixed(1)} s`);

        warmNextScene(index + 1, durMs);

        audio.play()
            .then(() => {
                setTimeout(() => {
                    if (!isRecording) return;
                    stopCaptionAnimation();
                    _renderLock = -1;
                    runSceneRender(index + 1);
                }, durMs);
            })
            .catch(() => {
                _renderLock = -1;
                setTimeout(() => { if (isRecording) runSceneRender(index + 1); }, 500);
            });
    };

    audio.onerror = () => {
        L(`❌ Audio load failed: ${audioFile}`);
        _renderLock = -1;
        setTimeout(() => { if (isRecording) runSceneRender(index + 1); }, 500);
    };

    audio.load();
}

// ── Start MediaRecorder with composite canvas ─────────────────
function startMediaRecorder() {
    L('🎥 Starting MediaRecorder');

    const fabricEl = document.getElementById('fabricCanvas');
    if (!fabricEl) { console.error('fabricCanvas not found'); return; }

    const cW = fabricEl.width;
    const cH = fabricEl.height;

    // Composite canvas: video BG layer + Fabric lower + Fabric upper (captions)
    let rc = document.getElementById('_recordingCanvas');
    if (!rc) {
        rc               = document.createElement('canvas');
        rc.id            = '_recordingCanvas';
        rc.style.display = 'none';
        document.body.appendChild(rc);
    }
    rc.width  = cW;
    rc.height = cH;

    const rCtx    = rc.getContext('2d');
    const lowerEl = fabricCanvas.lowerCanvasEl || fabricEl;
    const upperEl = fabricCanvas.upperCanvasEl  || null;

    let compositeActive = true;

    (function compositeFrame() {
        if (!compositeActive) return;

        rCtx.clearRect(0, 0, cW, cH);

        // 1. Solid black base
        rCtx.fillStyle = '#000000';
        rCtx.fillRect(0, 0, cW, cH);

        // 2. DOM video (if playing)
        const vid = document.getElementById('backgroundVideo');
        if (vid && vid.readyState >= 2 && !vid.paused && !vid.ended) {
            try { rCtx.drawImage(vid, 0, 0, cW, cH); } catch(e) {}
        }

        // 3. Fabric lower canvas (images, Ken Burns)
        try { rCtx.drawImage(lowerEl, 0, 0, cW, cH); } catch(e) {}

        // 4. Fabric upper canvas (text captions, image captions)
        if (upperEl) {
            try { rCtx.drawImage(upperEl, 0, 0, cW, cH); } catch(e) {}
        }

        // 5. Transition overlay (so transitions appear in the recording)
        const transOv = document.getElementById('_transitionOverlay');
        if (transOv) {
            const opacity = parseFloat(window.getComputedStyle(transOv).opacity) || 0;
            if (opacity > 0.01) {
                rCtx.globalAlpha = opacity;
                rCtx.fillStyle   = '#000000';
                rCtx.fillRect(0, 0, cW, cH);
                rCtx.globalAlpha = 1;
            }
        }

        requestAnimationFrame(compositeFrame);
    })();

    window._compositeActive = () => { compositeActive = false; };

    const stream = rc.captureStream(30);

    const mimeTypes = [
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm',
        'video/mp4'
    ];
    let mimeType = '';
    for (const m of mimeTypes) {
        if (MediaRecorder.isTypeSupported(m)) { mimeType = m; break; }
    }

    try {
        mediaRecorder = new MediaRecorder(
            stream,
            mimeType
                ? { mimeType, videoBitsPerSecond: 6000000 }
                : { videoBitsPerSecond: 6000000 }
        );

        mediaRecorder.ondataavailable = e => {
            if (e.data && e.data.size > 0) recordedChunks.push(e.data);
        };
        mediaRecorder.onstop  = () => { L('⏹️ Recorder stopped — saving'); saveRecordedVideo(); };
        mediaRecorder.onerror = e  => { console.error('MediaRecorder error:', e); stopRecording(); };

        mediaRecorder.start(500); // 500 ms chunks
        L('✅ Recording started — codec: ' + (mimeType || 'browser default'));
    } catch(e) {
        console.error('MediaRecorder failed to start:', e);
        alert('Recording failed to start: ' + e.message);
        isRecording = false;
        hideStopBtn();
    }
}

function finishRecording() {
    L('🏁 All scenes recorded');
    isRecording = false;
    _stopComposite();
    _stopBgMusicElement('render-bg-music');
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    } else {
        saveRecordedVideo();
    }
    hideStopBtn();
}

function stopRecording() {
    L('⏹️ stopRecording called');
    isRecording = false;
    _renderLock = -1;
    _stopComposite();
    _stopBgMusicElement('render-bg-music');
    renderAudioElements.forEach(a => { try { a.pause(); } catch(e) {} });
    renderAudioElements = [];
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    } else {
        saveRecordedVideo();
    }
    hideStopBtn();
}

function _stopComposite() {
    if (window._compositeActive) { window._compositeActive(); window._compositeActive = null; }
}
function _stopBgMusicElement(id) {
    const el = document.getElementById(id);
    if (el) { try { el.pause(); } catch(e) {} el.remove(); }
}

// ── Save / download recorded video ───────────────────────────
function saveRecordedVideo() {
    if (!recordedChunks || !recordedChunks.length) {
        L('⚠️ No recorded data');
        alert('Recording produced no data — please try again.');
        return;
    }

    const mime = (recordedChunks[0] && recordedChunks[0].type) || 'video/webm';
    const ext  = mime.includes('mp4') ? 'mp4' : 'webm';
    const blob = new Blob(recordedChunks, { type: mime });
    const url  = URL.createObjectURL(blob);

    // Trigger browser download
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'video_' + Date.now() + '.' + ext;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    L(`✅ Video downloaded — ${(blob.size / 1024 / 1024).toFixed(1)} MB`);

    // Upload to server in background
    const fd = new FormData();
    fd.append('ajax_action', 'save_rendered_video');
    fd.append('podcast_id',  window._podcastId  || '0');
    fd.append('lang_code',   window._langCode   || 'en');
    fd.append('video_file',  blob, 'rendered.' + ext);

    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) L('✅ Saved to server: ' + d.filename);
            else           L('⚠️ Server save failed: ' + (d.message || ''));
        })
        .catch(e => L('⚠️ Upload error: ' + e.message));

    URL.revokeObjectURL(url);
    recordedChunks = [];
}

// ── Transition ────────────────────────────────────────────────
async function playTransition(type) {
    if (!type || type === 'none') return;

    return new Promise(resolve => {
        const container = document.getElementById('canvasContainer');
        if (!container) { resolve(); return; }

        const old = document.getElementById('_transitionOverlay');
        if (old) old.remove();

        const ov = document.createElement('div');
        ov.id = '_transitionOverlay';
        ov.style.cssText = [
            'position:absolute', 'top:0', 'left:0',
            'width:100%', 'height:100%',
            'z-index:5000', 'pointer-events:none',
            'opacity:0'
        ].join(';');
        container.appendChild(ov);

        const FI = 250; // fade-in ms
        const H  = 60;  // hold ms at peak
        const FO = 250; // fade-out ms

        switch (type) {

            case 'fade':
                ov.style.background  = '#000';
                ov.style.transition  = `opacity ${FI}ms ease`;
                requestAnimationFrame(() => {
                    ov.style.opacity = '1';
                    setTimeout(() => {
                        ov.style.transition = `opacity ${FO}ms ease`;
                        ov.style.opacity    = '0';
                        setTimeout(() => { ov.remove(); resolve(); }, FO);
                    }, FI + H);
                });
                break;

            case 'slide-left':
                ov.style.background = '#000';
                ov.style.opacity    = '1';
                ov.style.transform  = 'translateX(100%)';
                ov.style.transition = `transform ${FI}ms ease`;
                requestAnimationFrame(() => {
                    ov.style.transform = 'translateX(0%)';
                    setTimeout(() => {
                        ov.style.transition = `transform ${FO}ms ease`;
                        ov.style.transform  = 'translateX(-100%)';
                        setTimeout(() => { ov.remove(); resolve(); }, FO);
                    }, FI + H);
                });
                break;

            case 'slide-right':
                ov.style.background = '#000';
                ov.style.opacity    = '1';
                ov.style.transform  = 'translateX(-100%)';
                ov.style.transition = `transform ${FI}ms ease`;
                requestAnimationFrame(() => {
                    ov.style.transform = 'translateX(0%)';
                    setTimeout(() => {
                        ov.style.transition = `transform ${FO}ms ease`;
                        ov.style.transform  = 'translateX(100%)';
                        setTimeout(() => { ov.remove(); resolve(); }, FO);
                    }, FI + H);
                });
                break;

            case 'zoom-out':
                ov.style.background = '#000';
                ov.style.transform  = 'scale(1.4)';
                ov.style.transition = `opacity ${FI}ms ease, transform ${FI}ms ease`;
                requestAnimationFrame(() => {
                    ov.style.opacity   = '1';
                    ov.style.transform = 'scale(1)';
                    setTimeout(() => {
                        ov.style.transition = `opacity ${FO}ms ease`;
                        ov.style.opacity    = '0';
                        setTimeout(() => { ov.remove(); resolve(); }, FO);
                    }, FI + H);
                });
                break;

            case 'blur': {
                ov.remove(); // no overlay for blur — apply CSS filter instead
                const canvasEl = document.getElementById('fabricCanvas');
                const vid      = document.getElementById('backgroundVideo');
                const targets  = [canvasEl, vid].filter(Boolean);
                targets.forEach(t => {
                    t.style.transition = `filter ${FI}ms ease`;
                    t.style.filter     = 'blur(12px)';
                });
                setTimeout(() => {
                    targets.forEach(t => { t.style.filter = 'blur(0px)'; });
                    setTimeout(resolve, FO);
                }, FI + H);
                break;
            }

            case 'wipe':
                ov.style.background = '#000';
                ov.style.opacity    = '1';
                ov.style.transform  = 'translateX(-100%)';
                ov.style.transition = `transform ${FI}ms ease`;
                requestAnimationFrame(() => {
                    ov.style.transform = 'translateX(0%)';
                    setTimeout(() => {
                        ov.style.transition = `transform ${FO}ms ease`;
                        ov.style.transform  = 'translateX(100%)';
                        setTimeout(() => { ov.remove(); resolve(); }, FO);
                    }, FI + H);
                });
                break;

            default:
                ov.remove();
                resolve();
                break;
        }
    });
}

// ── Expose podcast ID / lang for saveRecordedVideo ────────────
// (These are set by the PHP page — just make sure they match)
document.addEventListener('DOMContentLoaded', () => {
    // Read from the debug banner or hidden inputs if present
    const bannerText = document.getElementById('debugBanner')?.innerText || '';
    const pidMatch   = bannerText.match(/podcast_id=(\d+)/);
    const langMatch  = bannerText.match(/lang=(\w+)/);
    window._podcastId = pidMatch  ? pidMatch[1]  : '0';
    window._langCode  = langMatch ? langMatch[1] : 'en';
});

console.log('✅ videomaker-playback-fix.js loaded — playback & recording overrides active');
