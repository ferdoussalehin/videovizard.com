// 4. RECORDING ENGINE (PORTRAIT + AUDIO FIX)
function toggleRecording() {
    if (mediaRecorder && mediaRecorder.state === "recording") {
        stopPlayback(); 
    } else {
        captureVideo(); 
    }
}

async function captureVideo() {
    const canvas = document.getElementById('exportCanvas');
    const ctx = canvas.getContext('2d');
    const btn = document.getElementById('recordBtn');
    
    const audios = Array.from(document.querySelectorAll('#sceneTable audio'));
    if (audios.length === 0) return alert('No audio found.');

    // --- 1. PROPER AUDIO ROUTING ---
    if (!audioContext) {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        mixerDestination = audioContext.createMediaStreamDestination();
    }
    await audioContext.resume();

    // Connect all audio elements to the mixer so the recorder can "hear" them
    audios.forEach(audio => {
        // Only connect if not already connected (prevents volume doubling)
        if (!audio.dataset.connected) {
            const source = audioContext.createMediaElementSource(audio);
            source.connect(mixerDestination);
            source.connect(audioContext.destination); // Connect to speakers so you can hear it
            audio.dataset.connected = "true";
        }
    });

    // --- 2. INITIALIZE STREAMS ---
    // Ensure canvas has content before capturing
    drawToCanvas(canvas, ctx); 
    const canvasStream = canvas.captureStream(30);
    
    const combined = new MediaStream([
        ...canvasStream.getVideoTracks(),
        ...mixerDestination.stream.getAudioTracks()
    ]);

    // Fallback for mimeType support
    const options = { videoBitsPerSecond: 5000000 };
    if (MediaRecorder.isTypeSupported('video/webm; codecs=vp9,opus')) {
        options.mimeType = 'video/webm; codecs=vp9,opus';
    }

    mediaRecorder = new MediaRecorder(combined, options);
    recordedChunks = [];

    mediaRecorder.ondataavailable = e => { 
        if (e.data.size > 0) {
            recordedChunks.push(e.data);
            console.log("Chunk recorded:", e.data.size);
        }
    };
   
    mediaRecorder.onstop = async () => {
        console.log("Recorder stopped. Total chunks:", recordedChunks.length);
        
        if (recordedChunks.length === 0) {
            console.error("❌ Cannot save: No data was captured. Check CORS or Audio routing.");
            return;
        }

        const blob = new Blob(recordedChunks, { type: 'video/webm' });
        
        // --- 3. DOWNLOAD FIX ---
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = `podcast_${Date.now()}.webm`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        // --- 4. PHP SAVE FIX ---
        const formData = new FormData();
        const projectTitle = document.querySelector('[name=title_filter]')?.value || 'unknown';
        const langCode = document.querySelector('[name=lang_filter]')?.value || 'no_lang';
        
        // Always provide a filename (3rd param) to ensure PHP $_FILES is populated
        formData.append('video_blob', blob, 'video.webm');
        formData.append('project_title', projectTitle);
        formData.append('lang_code', langCode);

        console.log("Sending to PHP...");
        try {
            const response = await fetch('save_recorded_video.php', { 
                method: 'POST', 
                body: formData 
            });
            const data = await response.json();
            
            if(data.success) {
                console.log("✅ Saved:", data.path);
                updateVideoCreateStatus(projectTitle, langCode);
            } else {
                console.error("❌ Server rejected save:", data.message);
            }
        } catch (err) {
            console.error("❌ Network/PHP Error:", err);
        }

        btn.innerHTML = "⏺ Record";
        btn.classList.replace('btn-purple', 'btn-red');
    };

    // Start recording
    mediaRecorder.start(100); // Record in 100ms intervals to ensure chunks are generated
    btn.innerHTML = "⏹ Stop Recording";
    btn.classList.replace('btn-red', 'btn-purple');

    function render() {
        if (mediaRecorder && mediaRecorder.state === "recording") {
            drawToCanvas(canvas, ctx); 
            requestAnimationFrame(render);
        }
    }
    render();
    
    // Slight delay to ensure recorder is ready before playing audio
    setTimeout(() => { 
        if(typeof playFullSequence === 'function') playFullSequence(true); 
    }, 500);
}


// 5. DRAW TO CANVAS (Fixed Aspect Ratio + Logo + Bottom Captions)
function drawToCanvas(canvas, ctx) {
    const scale = canvas.width / 360; 
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    const vTag = document.getElementById('videoPreview');
    const iTag = document.getElementById('imagePreview');
    const media = (vTag.style.display !== 'none') ? vTag : iTag;

    // --- 1. DRAW BACKGROUND (FIXED CROP LOGIC) ---
    if (media && (media.tagName === 'VIDEO' ? media.readyState >= 2 : media.complete)) {
        const mw = (media.tagName === 'VIDEO') ? media.videoWidth : media.naturalWidth;
        const mh = (media.tagName === 'VIDEO') ? media.videoHeight : media.naturalHeight;
        
        const canvasAspect = canvas.width / canvas.height;
        const mediaAspect = mw / mh;
        let dw, dh, dx, dy;

        if (mediaAspect > canvasAspect) {
            dh = canvas.height;
            dw = canvas.height * mediaAspect;
            dx = (canvas.width - dw) / 2;
            dy = 0;
        } else {
            dw = canvas.width;
            dh = canvas.width / mediaAspect;
            dx = 0;
            dy = (canvas.height - dh) / 2;
        }
        ctx.drawImage(media, dx, dy, dw, dh);
    }

    // --- 2. GRADIENT OVERLAY (Darker Bottom) ---
    const grad = ctx.createLinearGradient(0, canvas.height * 0.5, 0, canvas.height);
    grad.addColorStop(0, 'rgba(0,0,0,0)');
    grad.addColorStop(1, 'rgba(0,0,0,0.8)');
    ctx.fillStyle = grad; 
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // --- 2b. LOGO OVERLAY (StressReleasor Default or Custom) ---
    const logoS = window.logoState;
    if (logoS.showDefaultLogo || (logoS.logoImg && logoS.logoImg.complete)) {
        const dampenedScale = 1 + (scale - 1) * 0.4; 
        const lSize = logoS.logoSize * dampenedScale;
        const pos = logoS.logoPosition;
        let lx, ly;

        ctx.save();
        ctx.shadowColor = "rgba(0,0,0,0.8)";
        ctx.shadowBlur = 20;

        if (logoS.showDefaultLogo) {
            // Position Y
            if (pos.includes('top')) {
                ly = 40;
            } else {
                ly = canvas.height - (lSize * 1.2) - 40;
            }
            // Position X
            if (pos === 'top' || pos === 'bottom') {
                lx = canvas.width / 2;
            } else if (pos.includes('left')) {
                lx = lSize * 0.6;
            } else {
                lx = canvas.width - (lSize * 0.6);
            }

            ctx.textAlign = (pos === 'top' || pos === 'bottom') ? 'center' : (pos.includes('left') ? 'left' : 'right');
            ctx.textBaseline = 'top';

            // Emoji icon
            ctx.font = `${lSize * 0.4}px Arial`;
            ctx.fillStyle = "#ffffff";
            ctx.fillText("🛟", lx, ly);

            // Brand name
            ctx.font = `900 ${lSize * 0.3}px 'Inter', sans-serif`;
            ctx.fillStyle = "#10b981";
            ctx.fillText("StressReleasor", lx, ly + (lSize * 0.45));

            // Tagline
            ctx.font = `${lSize * 0.15}px 'Inter', sans-serif`;
            ctx.fillStyle = "white";
            ctx.fillText("Always here to help & support", lx, ly + (lSize * 0.8));

        } else {
            // Custom uploaded logo image
            const aspect = logoS.logoImg.width / logoS.logoImg.height;
            const lw = lSize * aspect;
            const lh = lSize;

            if (pos.includes('top')) { ly = 40; } 
            else { ly = canvas.height - lh - 40; }

            if (pos === 'top' || pos === 'bottom') { lx = (canvas.width - lw) / 2; } 
            else if (pos.includes('left')) { lx = 40; } 
            else { lx = canvas.width - lw - 40; }

            ctx.drawImage(logoS.logoImg, lx, ly, lw, lh);
        }
        ctx.restore();

        // Clean up shadow after logo
        ctx.shadowBlur = 0;
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = 0;
    }

    // --- 3. CAPTIONS (WITH FONT SETTINGS, POSITION, SPACING) ---
    const fontSz = parseInt(document.getElementById('fontSizePicker').value) || 26;
    const fontFamily = document.getElementById('fontFamilyPicker').value || 'Arial';
    const fontStyle = document.getElementById('fontStylePicker').value;
    const fontColor = document.getElementById('fontColorPicker').value;
    const bgEnabled = document.getElementById('fontBgEnabled').checked;
    const bgColor = document.getElementById('fontBgColorPicker').value;
    const bgOpacity = parseFloat(document.getElementById('fontBgOpacity').value);
    const lineSpacingVal = parseFloat(document.getElementById('lineSpacingPicker').value) || 1.4;
    const paraSpacingVal = parseInt(document.getElementById('paraSpacingPicker').value) || 8;
    const textPosition = document.getElementById('textPositionPicker').value || 'bottom';

    let fontStr = `${fontSz * scale}px ${fontFamily}`;
    if (fontStyle === 'bold' || fontStyle === 'bold-italic') fontStr = `bold ${fontStr}`;
    if (fontStyle === 'italic' || fontStyle === 'bold-italic') fontStr = `italic ${fontStr}`;

    ctx.font = fontStr;
    ctx.fillStyle = fontColor;
    ctx.textAlign = "center";
    ctx.textBaseline = "bottom";

    ctx.shadowBlur = 8 * scale; 
    ctx.shadowColor = "rgba(0,0,0,0.9)";
    ctx.shadowOffsetX = 2 * scale;
    ctx.shadowOffsetY = 2 * scale;
    
    const textContent = document.getElementById('typewriter-text').innerText;
    const rawLines = textContent.split('\n').filter(l => l.trim() !== "");

    const maxWidth = canvas.width * 0.88;
    const lines = [];
    rawLines.forEach((rawLine, rIdx) => {
        const words = rawLine.split(' ');
        let currentLine = '';
        words.forEach(word => {
            const testLine = currentLine ? currentLine + ' ' + word : word;
            if (ctx.measureText(testLine).width > maxWidth && currentLine) {
                lines.push({ text: currentLine, paraBreak: false });
                currentLine = word;
            } else {
                currentLine = testLine;
            }
        });
        if (currentLine) lines.push({ text: currentLine, paraBreak: rIdx < rawLines.length - 1 });
    });
    
    const lineHeight = (fontSz * lineSpacingVal) * scale;
    const paraExtra = paraSpacingVal * scale;

    let totalTextHeight = 0;
    lines.forEach((l, i) => {
        totalTextHeight += lineHeight;
        if (l.paraBreak && i < lines.length - 1) totalTextHeight += paraExtra;
    });

    let startY;
    if (textPosition === 'top') {
        startY = 80 * scale;
    } else if (textPosition === 'center') {
        startY = (canvas.height - totalTextHeight) / 2;
    } else {
        const bottomPadding = 150 * scale; 
        startY = canvas.height - bottomPadding - totalTextHeight;
    }

    if (bgEnabled) {
        ctx.shadowBlur = 0; ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
        const r = parseInt(bgColor.slice(1,3),16);
        const g = parseInt(bgColor.slice(3,5),16);
        const b = parseInt(bgColor.slice(5,7),16);
        ctx.fillStyle = `rgba(${r},${g},${b},${bgOpacity})`;
        let yPos = startY;
        lines.forEach((l, i) => {
            const w = ctx.measureText(l.text).width + (20 * scale);
            const x = (canvas.width - w) / 2;
            ctx.fillRect(x, yPos - (fontSz * scale * 0.8), w, lineHeight);
            yPos += lineHeight;
            if (l.paraBreak && i < lines.length - 1) yPos += paraExtra;
        });
        ctx.fillStyle = fontColor;
        ctx.shadowBlur = 8 * scale; ctx.shadowColor = "rgba(0,0,0,0.9)";
        ctx.shadowOffsetX = 2 * scale; ctx.shadowOffsetY = 2 * scale;
    }

    let yPos = startY;
    lines.forEach((l, i) => {
        ctx.fillText(l.text, canvas.width / 2, yPos + lineHeight * 0.8);
        yPos += lineHeight;
        if (l.paraBreak && i < lines.length - 1) yPos += paraExtra;
    });

    ctx.shadowBlur = 0;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 0;
} 

// 5b. UPDATE VIDEO CREATE STATUS
function updateVideoCreateStatus(title, langCode) {
    fetch('update_video_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title, lang_code: langCode })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            console.log('✓ DB updated: video_create=yes for ' + data.updated + ' rows');
            const gs = document.getElementById('globalStatus');
            if (gs) gs.innerHTML = '✅ Video saved & DB updated (' + data.updated + ' rows)';
        } else {
            console.error('DB update failed:', data.error);
        }
    })
    .catch(err => console.error('DB update error:', err));
}

// 6. SEQUENCE & INIT
function playFullSequence(isRecording = false) {
    if (isPlayingSequence && !isRecording) { stopPlayback(); return; }
    const audios = Array.from(document.querySelectorAll('#sceneTable audio'));
    if (audios.length === 0) return;

    isPlayingSequence = true;
    const playBtn = document.getElementById('playAllBtn');
    if (playBtn) { playBtn.innerHTML = "⏹ Stop"; playBtn.classList.replace('btn-purple', 'btn-red'); }

    let currentIdx = 0;
    function playNext() {
    if (!isPlayingSequence || currentIdx >= audios.length) { stopPlayback(); return; }
    
    currentAudioPlayer = audios[currentIdx];
    const rowId = currentAudioPlayer.id.replace('audio-player-', '');
    const row = document.getElementById(`row-${rowId}`);

    // 1. Handle Logo Visibility based on logo_flag
    const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
    const logoOverlay = document.getElementById('logoPreviewOverlay');
    if (logoOverlay) {
        logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';
    }

    // 2. Pass rowId to the monitor to load text_display
    updateMonitorMedia(rowId); 

    currentAudioPlayer.onended = () => { currentIdx++; playNext(); };
    currentAudioPlayer.play();
}
	function playNext() {
		if (!isPlayingSequence || currentIdx >= audios.length) { stopPlayback(); return; }
		
		currentAudioPlayer = audios[currentIdx];
		const rowId = currentAudioPlayer.id.replace('audio-player-', '');
		const row = document.getElementById(`row-${rowId}`);

		// 1. Handle Logo Visibility based on logo_flag
		const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
		const logoOverlay = document.getElementById('logoPreviewOverlay');
		if (logoOverlay) {
			logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';
		}

		// 2. Pass rowId to the monitor to load text_display
		updateMonitorMedia(rowId); 

		currentAudioPlayer.onended = () => { currentIdx++; playNext(); };
		currentAudioPlayer.play();
	}
    playNext();
}

function stopPlayback() {
    isPlayingSequence = false;
    clearTimeout(typewriterInterval);
    if (mediaRecorder && mediaRecorder.state === "recording") mediaRecorder.stop();

    const btn = document.getElementById('playAllBtn');
    if (btn) { btn.innerHTML = "▶️ Play All"; btn.classList.replace('btn-red', 'btn-purple'); }
    
    const recBtn = document.getElementById('recordBtn');
    if (recBtn) { recBtn.innerHTML = "⏺ Record"; recBtn.classList.replace('btn-purple', 'btn-red'); }

    if (currentAudioPlayer) { currentAudioPlayer.pause(); currentAudioPlayer.currentTime = 0; }
}

