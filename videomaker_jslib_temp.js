/**
 * CalmBot Videomaker - Portrait + Audio Fix + Logo Overlay
 */
//console.log('🔥 EMERGENCY TEST - If you see this, JavaScript is running');
//alert('EMERGENCY TEST - JavaScript is running!');


// ========== GLOBAL VARIABLES (MUST BE FIRST) ==========
var lastActiveRowId = null;
var isPlayingSequence = false; 
var currentAudioPlayer = null; 
var typewriterInterval = null;
var mediaRecorder = null;
var recordedChunks = [];
var audioContext = null;
var mixerDestination = null;
var currentPodcastId = null;


document.body.style.border = '5px solid red';





// ========== LOGO STATE (Global) ==========
// At the beginning of your script
window.logoState = {
    showDefaultLogo: true,
    logoImg: null,
    companyName: null,
    logoSize: 80,
    logoPosition: 'bottom-right',
    fetchAttempted: false,
    dataLoaded: false
};



// ========== MODAL STATE VARIABLES ==========
let currentMainTab = 'data';
let selectedImage = null;
let selectedVideo = null;
let imageFiles = [];
let videoFiles = [];

// Flags to prevent multiple simultaneous operations
let isLoading = {
    images: false,
    videos: false
};
let isRendering = false;
let renderTimeout = null;

// ========== LOGO FUNCTIONS ==========
function handleLogoUpload(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        window.logoState.logoImg = new Image();
        window.logoState.logoImg.src = e.target.result;
        window.logoState.logoImg.onload = () => {
            window.logoState.showDefaultLogo = false;
            document.getElementById('logoStatusText').innerText = '✓ Custom: ' + file.name;
            updateLogoPreview();
        };
    };
    reader.readAsDataURL(file);
}

function resetToDefaultLogo() {
    window.logoState.showDefaultLogo = true;
    window.logoState.logoImg = null;
    document.getElementById('logoStatusText').innerText = '✓ StressReleasor Logo Active';
    const upload = document.getElementById('logoUpload');
    if (upload) upload.value = '';
    updateLogoPreview();
}

function updateLogoPreview() {
    const overlay = document.getElementById('logoPreviewOverlay');
    const content = document.getElementById('logoPreviewContent');
    if (!overlay || !content) return;
	
	if (window.logoState.logoEnabled === false) {
        overlay.style.display = 'none';
        return;
    }
	
	overlay.style.display = 'block';
    overlay.style.position = 'absolute';
    overlay.style.zIndex = '999';
	
    const logoS = window.logoState;
    const pos = logoS.logoPosition;
    const size = logoS.logoSize * 0.5;

    overlay.style.top = pos.includes('top') ? '10px' : 'auto';
    overlay.style.bottom = pos.includes('bottom') ? '10px' : 'auto';
    overlay.style.left = pos.includes('left') ? '10px' : (pos === 'top' || pos === 'bottom' ? '50%' : 'auto');
    overlay.style.right = pos.includes('right') ? '10px' : 'auto';
    overlay.style.transform = (pos === 'top' || pos === 'bottom') ? 'translateX(-50%)' : 'none';

    const align = pos.includes('left') ? 'left' : (pos === 'top' || pos === 'bottom') ? 'center' : 'right';

    if (logoS.showDefaultLogo) {
        content.innerHTML = `
            <div style="text-align:${align}; font-family:'Inter',sans-serif;">
                <div style="font-size:${size * 0.4}px; line-height:1;">🛟</div>
                <div style="font-size:${size * 0.3}px; font-weight:900; color:#10b981; margin-top:3px; line-height:1;">StressReleasor.com</div>
                <div style="font-size:${size * 0.15}px; color:white; margin-top:2px; line-height:1;">Always here to help & support</div>
            </div>
        `;
    } else if (logoS.logoImg && logoS.logoImg.complete && logoS.logoImg.naturalWidth > 0) {
        // Show logo image + company name below it
        const h = size;
        const w = (logoS.logoImg.naturalWidth / logoS.logoImg.naturalHeight) * h;
        const companyName = logoS.companyName || '';
        content.innerHTML = `
            <div style="text-align:${align}; font-family:'Inter',sans-serif;">
                <img src="${logoS.logoImg.src}" style="width:${w}px; height:${h}px; display:block; ${align === 'center' ? 'margin:0 auto;' : align === 'right' ? 'margin-left:auto;' : ''}">
                ${companyName ? `<div style="font-size:14px; font-weight:900; color:#10b981; margin-top:4px; line-height:1;">${companyName}</div>` : ''}
            </div>
        `;
    } else if (logoS.companyName) {
        // No logo — just company name
        content.innerHTML = `
            <div style="font-family:'Inter',sans-serif; text-align:${align};">
                <div style="font-size:14px; font-weight:900; color:#10b981; line-height:1;">${logoS.companyName}</div>
            </div>
        `;
    }
}

// ========== COOKIE SETTINGS SYSTEM ==========
function saveSettings() {
    const settings = {
        fontFamily: document.getElementById('fontFamilyPicker').value,
        fontStyle: document.getElementById('fontStylePicker').value,
        fontSize: document.getElementById('fontSizePicker').value,
        fontColor: document.getElementById('fontColorPicker').value,
        fontBgColor: document.getElementById('fontBgColorPicker').value,
        fontBgEnabled: document.getElementById('fontBgEnabled').checked,
        fontBgOpacity: document.getElementById('fontBgOpacity').value,
        lineSpacing: document.getElementById('lineSpacingPicker').value,
        paraSpacing: document.getElementById('paraSpacingPicker').value,
        textPosition: document.getElementById('textPositionPicker').value,
        rate: document.getElementById('ratePicker').value,
        textStyle: document.getElementById('textStylePicker').value,
        textSpeed: document.getElementById('textSpeedOffset').value,
        logoSize: document.getElementById('logoSizePicker')?.value || '60',
        logoPosition: document.getElementById('logoPositionPicker')?.value || 'top'
    };
    document.cookie = "vmSettings=" + encodeURIComponent(JSON.stringify(settings)) + "; path=/; max-age=31536000";
    applyFontSettings();
}

function loadSettings() {
    const match = document.cookie.match(/vmSettings=([^;]+)/);
    if (!match) return;
    try {
        const s = JSON.parse(decodeURIComponent(match[1]));
        if (s.fontFamily) document.getElementById('fontFamilyPicker').value = s.fontFamily;
        if (s.fontStyle) document.getElementById('fontStylePicker').value = s.fontStyle;
        if (s.fontSize) document.getElementById('fontSizePicker').value = s.fontSize;
        if (s.fontColor) document.getElementById('fontColorPicker').value = s.fontColor;
        if (s.fontBgColor) document.getElementById('fontBgColorPicker').value = s.fontBgColor;
        if (s.fontBgEnabled !== undefined) document.getElementById('fontBgEnabled').checked = s.fontBgEnabled;
        if (s.fontBgOpacity) { 
            document.getElementById('fontBgOpacity').value = s.fontBgOpacity; 
            document.getElementById('bgOpacityVal').innerText = s.fontBgOpacity; 
        }
        if (s.lineSpacing) document.getElementById('lineSpacingPicker').value = s.lineSpacing;
        if (s.paraSpacing) document.getElementById('paraSpacingPicker').value = s.paraSpacing;
        if (s.textPosition) document.getElementById('textPositionPicker').value = s.textPosition;
        if (s.rate) document.getElementById('ratePicker').value = s.rate;
        if (s.textStyle) document.getElementById('textStylePicker').value = s.textStyle;
        if (s.textSpeed) document.getElementById('textSpeedOffset').value = s.textSpeed;
        
        if (s.logoSize && document.getElementById('logoSizePicker')) {
            document.getElementById('logoSizePicker').value = s.logoSize;
            window.logoState.logoSize = parseInt(s.logoSize);
        }
        if (s.logoPosition && document.getElementById('logoPositionPicker')) {
            document.getElementById('logoPositionPicker').value = s.logoPosition;
            window.logoState.logoPosition = s.logoPosition;
        }
        applyFontSettings();
    } catch(e) {}
}

function applyFontSettings() {
    const el = document.getElementById('typewriter-text');
    if (!el) return;
    
    const fontFamily = document.getElementById('fontFamilyPicker').value;
    const style = document.getElementById('fontStylePicker').value;
    const size = document.getElementById('fontSizePicker').value;
    const color = document.getElementById('fontColorPicker').value;
    const bgColor = document.getElementById('fontBgColorPicker').value;
    const bgEnabled = document.getElementById('fontBgEnabled').checked;
    const bgOpacity = document.getElementById('fontBgOpacity').value;
    const lineSpacing = document.getElementById('lineSpacingPicker').value;
    const textPosition = document.getElementById('textPositionPicker').value;

    el.style.fontFamily = fontFamily + ', sans-serif';
    el.style.fontWeight = (style === 'bold' || style === 'bold-italic') ? '800' : 'normal';
    el.style.fontStyle = (style === 'italic' || style === 'bold-italic') ? 'italic' : 'normal';
    el.style.fontSize = size + 'px';
    el.style.color = color;
    el.style.lineHeight = lineSpacing;

    const container = document.getElementById('typewriter-container');
    if (container) {
        container.style.top = '';
        container.style.bottom = '';
        container.style.alignItems = 'center';
        if (textPosition === 'top') {
            container.style.top = '10%';
            container.style.bottom = 'auto';
        } else if (textPosition === 'center') {
            container.style.top = '40%';
            container.style.bottom = 'auto';
        } else {
            container.style.top = 'auto';
            container.style.bottom = '15%';
        }
    }

    if (bgEnabled) {
        const r = parseInt(bgColor.slice(1,3),16);
        const g = parseInt(bgColor.slice(3,5),16);
        const b = parseInt(bgColor.slice(5,7),16);
        el.style.backgroundColor = `rgba(${r},${g},${b},${bgOpacity})`;
        el.style.padding = '4px 10px';
        el.style.borderRadius = '6px';
    } else {
        el.style.backgroundColor = 'transparent';
        el.style.padding = '0';
    }
}

// ========== PROJECT TIME CALCULATORS ==========
function updateTotalProjectTime() {
    let totalActual = 0, totalPredicted = 0;
    document.querySelectorAll('.status-badge.ready').forEach(badge => {
        const timeMatch = badge.innerText.match(/(\d+(\.\d+)?)/);
        if (timeMatch) totalActual += parseFloat(timeMatch[0]);
    });
    
    const rate = parseFloat(document.getElementById('ratePicker').value) || 1.15;
    document.querySelectorAll('.script-input').forEach(textarea => {
        const text = textarea.value;
        const words = text.trim().split(/\s+/).filter(w => w.length > 0).length;
        let est = (words / (155 * rate)) * 60 + ((text.match(/[.,!?;]/g) || []).length * 0.4);
        totalPredicted += est;
    });
    
    document.getElementById('actualTotal').innerText = totalActual.toFixed(1) + "s";
    document.getElementById('predictedTotal').innerText = totalPredicted.toFixed(1) + "s";
}

function estimateDuration(rowId) {
    const text = document.getElementById('text-' + rowId).value;
    const rate = parseFloat(document.getElementById('ratePicker').value) || 1.15;
    const words = text.trim().split(/\s+/).filter(w => w.length > 0).length;
    let est = (words / (155 * rate)) * 60 + ((text.match(/[.,!?;]/g) || []).length * 0.4);
    const display = document.getElementById('duration-prediction');
    if (display) display.innerHTML = `Row ${rowId} Est: <b>${est.toFixed(1)}s</b>`;
    updateTotalProjectTime();
}

// ========== TEXT ENGINE ==========
//  all text handling is happening here

// const rawText = document.getElementById('text-' + rowId).value || '';
function startTextEngine(rowId, durationSeconds) {
    const textElement = document.getElementById('typewriter-text');
    const style = document.getElementById('textStylePicker')?.value || 'typewriter';
    
    // 1. UPDATED: Use text-display if available, fallback to text- contents
    const row = document.getElementById('row-' + rowId);
    const rawText = document.getElementById('text-' + rowId).value || '';
    
    // 2. LOGO FLAG: Handle visibility
    const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
    const logoOverlay = document.getElementById('logoPreviewOverlay');
    if (logoOverlay) logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';

    const speedSlider = document.getElementById('textSpeedOffset');
    // FIX: Invert the speed logic - lower number = faster, higher number = slower
    let speedMultiplier = parseFloat(speedSlider.value) || 0.85;
    
    // Log for debugging
    console.log("Speed multiplier (raw):", speedMultiplier);
    
    let cleanText = rawText.replace(/<break[^>]*>/gi, '\n').replace(/\[.*?\]/g, ''); 
    const segments = cleanText.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    const totalWords = segments.reduce((acc, seg) => acc + seg.split(/\s+/).length, 0);
    
    clearTimeout(typewriterInterval);
    textElement.innerText = '';
    textElement.classList.remove('text-dimmed');
    
    // Reset transformations
    textElement.style.transition = "none"; 
    textElement.style.transform = "translateY(0)"; 

    if (segments.length === 0 || durationSeconds <= 0) return;

    // --- SCROLL LOGIC ---
    if (style === 'scroll') {
        textElement.innerText = segments.join('\n');
        const textHeight = textElement.scrollHeight;
        // FIX: For scroll, higher multiplier = slower scroll (longer duration)
        const scrollDuration = durationSeconds * speedMultiplier;
        
        setTimeout(() => {
            textElement.style.transition = `transform ${scrollDuration}s linear`;
            textElement.style.transform = `translateY(-${textHeight}px)`;
        }, 50);
        return;
    }

    // --- STATIC MODE ---
    if (style === 'static') {
        textElement.innerText = segments.join('\n');
        return;
    }

    let segIdx = 0, wordIdx = 0;
    
    function process() {
        if (style === 'breathe') {
            if (segIdx >= segments.length) return;
            textElement.classList.add('text-dimmed');
            setTimeout(() => {
                textElement.innerText = segments[segIdx++];
                textElement.classList.remove('text-dimmed');
                // FIX: For breathe, higher multiplier = longer delay = slower
                const delay = ((durationSeconds * speedMultiplier) / segments.length) * 1000;
                console.log("Breathe delay:", delay, "ms");
                typewriterInterval = setTimeout(process, delay);
            }, 600);
        } 
        else if (style === 'typewriter') {
            const words = segments[segIdx].split(/\s+/);
            if (wordIdx === 0) textElement.innerText = '';
            textElement.innerText += (textElement.innerText ? " " : "") + words[wordIdx];
            
            wordIdx++;
            if (wordIdx >= words.length) { 
                segIdx++; 
                wordIdx = 0; 
            }
            if (segIdx < segments.length) {
                // FIX: For typewriter, higher multiplier = longer delay = slower typing
                const ms = ((durationSeconds * speedMultiplier) / totalWords) * 1000;
                console.log("Typewriter delay:", ms, "ms");
                typewriterInterval = setTimeout(process, ms);
            }
        }
    }
    process();
}


function showSegment() {
    // CRITICAL: Clear the text completely
    textElement.innerText = '';
    
    // Small delay to ensure clearing happens
    setTimeout(() => {
        if (currentIndex < segments.length) {
            // Set the new text
            textElement.innerText = segments[currentIndex];
            console.log(`Segment ${currentIndex + 1}: "${segments[currentIndex]}"`);
            
            currentIndex++;
            
            if (currentIndex < segments.length) {
                // Schedule next segment
                typewriterInterval = setTimeout(showSegment, timePerSegment);
            }
        }
    }, 50);
}  




function updateMonitorMedia(rowId) {
    const row = document.getElementById('row-' + rowId);
    if (!row) return;

    const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
    const logoOverlay = document.getElementById('logoPreviewOverlay');
    if (logoOverlay) logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';

    const vUrl = row.getAttribute('data-video'), iUrl = row.getAttribute('data-image');
    const vTag = document.getElementById('videoPreview'), iTag = document.getElementById('imagePreview'), placeholder = document.getElementById('videoPlaceholder');

    const badge = document.getElementById('status-' + rowId);
    const dMatch = badge ? badge.innerText.match(/(\d+(\.\d+)?)/) : null;
    const duration = dMatch ? parseFloat(dMatch[0]) : 3.0;

    startTextEngine(rowId, duration);

    if (vUrl && vUrl.split('/').pop().length > 4) {
        vTag.src = vUrl;
        vTag.style.display = "block";
        vTag.style.zIndex = 2;
        iTag.style.zIndex = 1;
        vTag.play().catch(e => {});
        placeholder.style.display = "none";
    } else if (iUrl && iUrl.split('/').pop().length > 4) {
        const newSrc = iUrl + "?v=" + Date.now();
        const preloader = new Image();
        preloader.onload = () => {
            iTag.src = newSrc;
            iTag.style.display = "block";
            iTag.style.zIndex = 2;
            vTag.style.zIndex = 1;
            vTag.pause(); vTag.style.display = "none"; vTag.src = "";
            placeholder.style.display = "none";
        };
        preloader.src = newSrc;
    } else {
        vTag.style.display = "none"; vTag.pause(); vTag.src = "";
        iTag.style.display = "none"; iTag.src = "";
        placeholder.style.display = "block";
    }
}

// ========== RECORDING ENGINE ==========
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

    if (!audioContext) {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        mixerDestination = audioContext.createMediaStreamDestination();
    }
    await audioContext.resume();

    audios.forEach(audio => {
        if (!audio.dataset.connected) {
            const source = audioContext.createMediaElementSource(audio);
            source.connect(mixerDestination);
            source.connect(audioContext.destination);
            audio.dataset.connected = "true";
        }
    });

    drawToCanvas(canvas, ctx); 
    const canvasStream = canvas.captureStream(30);
    
    const combined = new MediaStream([
        ...canvasStream.getVideoTracks(),
        ...mixerDestination.stream.getAudioTracks()
    ]);

    // ✅ Force WebM with VP8/OPUS for best compatibility
    let options = { 
        videoBitsPerSecond: 5000000,
        mimeType: 'video/webm;codecs=vp8,opus'
    };
    
    // Check if VP8+OPUS is supported, fallback to other WebM options
    if (!MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')) {
        if (MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')) {
            options.mimeType = 'video/webm;codecs=vp9,opus';
            console.log("✅ Using WebM VP9+Opus");
        } else if (MediaRecorder.isTypeSupported('video/webm;codecs=vp8')) {
            options.mimeType = 'video/webm;codecs=vp8';
            console.log("✅ Using WebM VP8 (no audio codec specified)");
        } else if (MediaRecorder.isTypeSupported('video/webm')) {
            options.mimeType = 'video/webm';
            console.log("✅ Using WebM (default)");
        } else {
            console.error("❌ WebM not supported!");
            alert("Your browser doesn't support WebM recording. Please try Chrome, Firefox, or Edge.");
            return;
        }
    } else {
        console.log("✅ Using WebM VP8+Opus (best compatibility)");
    }

    mediaRecorder = new MediaRecorder(combined, options);
    recordedChunks = [];

    mediaRecorder.ondataavailable = e => { 
        if (e.data.size > 0) recordedChunks.push(e.data);
    };
   
    mediaRecorder.onstop = async () => {
        if (recordedChunks.length === 0) {
            console.error("❌ Cannot save: No data was captured.");
            return;
        }
        
        const blob = new Blob(recordedChunks, { type: 'video/webm' });
        
        // Save locally
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = `podcast_${Date.now()}.webm`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        // Save to server
        const formData = new FormData();
        const projectTitle = window.podcastData?.title || 'unknown';
        const urlParams = new URLSearchParams(window.location.search);
        const podcastId = urlParams.get('podcast_id') || window.podcastData?.id || '0';
        const langCode = urlParams.get('lang_code') || window.podcastData?.lang_code || window.currentLangCode || 'en';

        formData.append('video_blob', blob, `video.webm`);
        formData.append('project_title', projectTitle);
        formData.append('lang_code', langCode);
        formData.append('podcast_id', podcastId);
        
        try {
            const response = await fetch('/save_recorded_video.php', { 
                method: 'POST', 
                body: formData 
            });
            const data = await response.json();
            
            if(data.success) {
                console.log("✅ Saved:", data.path);
                updateVideoCreateStatus(projectTitle, langCode);
            }
        } catch (err) {
            console.error("❌ Network/PHP Error:", err);
        }

        btn.innerHTML = "⏺ Record";
        btn.classList.replace('btn-purple', 'btn-red');
    };

    mediaRecorder.start(100); // Capture every 100ms
    btn.innerHTML = "⏹ Stop Recording";
    btn.classList.replace('btn-red', 'btn-purple');

    function render() {
        if (mediaRecorder && mediaRecorder.state === "recording") {
            drawToCanvas(canvas, ctx); 
            requestAnimationFrame(render);
        }
    }
    render();
    
    setTimeout(() => { 
        if(typeof playFullSequence === 'function') playFullSequence(true); 
    }, 500);
}
// ========== DRAW TO CANVAS ==========
// ========== DRAW TO CANVAS ==========
async function drawToCanvas(canvas, ctx) {
    const scale = canvas.width / 360; 
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Draw media
    const vTag = document.getElementById('videoPreview');
    const iTag = document.getElementById('imagePreview');
    const media = (vTag.style.display !== 'none') ? vTag : iTag;

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

    // Get company data from session via API
    const logoS = window.logoState;
    
    // If we don't have data yet, fetch it (only once)
    if (!logoS.dataLoaded && !logoS.fetchAttempted) {
        logoS.fetchAttempted = true;
        try {
            const response = await fetch('get-client-logo.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });
            
            if (response.ok) {
                const data = await response.json();
                console.log('Company data received:', data);
                
                // Store company name
                logoS.companyName = data.companyname || 'Company';
                
                // Check if we have a VALID logo file (not just a placeholder)
                const hasValidLogo = data.logo_file && 
                                     data.logo_file !== 'logo_mini.png' && 
                                     !data.logo_file.includes('default') &&
                                     data.logo_file !== null;
                
                if (hasValidLogo) {
                    console.log('Attempting to load logo:', data.logo_file);
                    const img = new Image();
                    //img.crossOrigin = "Anonymous";
                    
					img.onload = () => {
						logoS.logoImg = img;
						logoS.showDefaultLogo = false;
						logoS.dataLoaded = true;
						updateLogoPreview(); // ← ADD THIS
						drawToCanvas(canvas, ctx);
					};
                    
                    img.onerror = () => {
						logoS.logoImg = null;
						logoS.showDefaultLogo = false;
						logoS.dataLoaded = true;
						updateLogoPreview(); // ← ADD THIS
						drawToCanvas(canvas, ctx);
					};
                    img.src = data.logo_file;
                    return; // Don't draw yet, wait for image to load
                } else {
                    console.log('No valid logo file, using company name:', logoS.companyName);
                    logoS.logoImg = null;
                    logoS.showDefaultLogo = false;
                    logoS.dataLoaded = true;
					updateLogoPreview();
                }
            } else {
                logoS.dataLoaded = true;
            }
        } catch (error) {
            console.error('Error loading company data:', error);
            logoS.companyName = 'Company';
            logoS.logoImg = null;
            logoS.dataLoaded = true;
        }
    }

    // Draw gradient
    const grad = ctx.createLinearGradient(0, canvas.height * 0.5, 0, canvas.height);
    grad.addColorStop(0, 'rgba(0,0,0,0)');
    grad.addColorStop(1, 'rgba(0,0,0,0.8)');
    ctx.fillStyle = grad; 
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // ===== DRAW LOGO/COMPANY NAME =====
	if (logoS.logoEnabled === false) {
    // skip drawing logo and company name entirely
	} 
	else 
	{
		ctx.save();

		const lSize = logoS.logoSize || 80;
		const pos = logoS.logoPosition || 'bottom-right';
		let lx, ly;

		ctx.shadowColor = "rgba(0,0,0,0.8)";
		ctx.shadowBlur = 20;

		if (logoS.showDefaultLogo) {
			// Default StressReleasor.com logo — original working code unchanged
			if (pos.includes('top')) ly = 40;
			else ly = canvas.height - (lSize * 1.2) - 40;
			
			if (pos === 'top' || pos === 'bottom') lx = canvas.width / 2;
			else if (pos.includes('left')) lx = lSize * 0.6;
			else lx = canvas.width - (lSize * 0.6);

			ctx.textAlign = (pos === 'top' || pos === 'bottom') ? 'center' : (pos.includes('left') ? 'left' : 'right');
			ctx.textBaseline = 'top';

			ctx.font = `${lSize * 0.4}px Arial`;
			ctx.fillStyle = "#ffffff";
			ctx.fillText("🛟", lx, ly);

			ctx.font = `900 ${lSize * 0.3}px 'Inter', sans-serif`;
			ctx.fillStyle = "#10b981";
			ctx.fillText("StressReleasor.com", lx, ly + (lSize * 0.45));

			ctx.font = `${lSize * 0.15}px 'Inter', sans-serif`;
			ctx.fillStyle = "white";
			ctx.fillText("\u201cPress play. Release stress.\u201d", lx, ly + (lSize * 0.8));

		} else if (logoS.logoImg && logoS.logoImg.complete && logoS.logoImg.naturalWidth > 0) {
			// Draw logo image
			const aspect = logoS.logoImg.naturalWidth / logoS.logoImg.naturalHeight;
			const lh = lSize;
			const lw = lSize * aspect;

			// Logo position — change 160 to move logo up/down independently
			if (pos.includes('top')) ly = 40;
			else ly = canvas.height - lh - 160;

			if (pos === 'top' || pos === 'bottom') lx = (canvas.width - lw) / 2;
			else if (pos.includes('left')) lx = 40;
			else lx = canvas.width - lw - 40;

			// PNG transparency is handled natively by canvas
			ctx.drawImage(logoS.logoImg, lx, ly, lw, lh);

			// Company name — completely independent fixed position
			// change 50 to move name up/down (bigger = higher up from bottom)
			const companyName = logoS.companyName || '';
			if (companyName) {
				const fixedFontSize = 28 * scale;
				const nameY = canvas.height - (50 * scale); // ← change 50 to adjust name position
				const nameX = pos.includes('left') ? 40 
							: (pos === 'top' || pos === 'bottom') ? canvas.width / 2 
							: canvas.width - 40;

				ctx.font = `900 ${fixedFontSize}px 'Inter', sans-serif`;
				ctx.fillStyle = "#10b981";
				ctx.textBaseline = 'bottom';
				ctx.textAlign = pos.includes('left') ? 'left' 
							  : (pos === 'top' || pos === 'bottom') ? 'center' 
							  : 'right';
				ctx.fillText(companyName, nameX, nameY);
			}

		} else {
			// No logo image — draw company name only with fixed size
			const companyName = logoS.companyName || 'Company';
			const fixedFontSize = 28 * scale;

			if (pos.includes('top')) ly = 40;
			else ly = canvas.height - 80 - 40;

			if (pos === 'top' || pos === 'bottom') lx = canvas.width / 2;
			else if (pos.includes('left')) lx = 40;
			else lx = canvas.width - 40;

			ctx.textAlign = pos.includes('left') ? 'left' 
						  : (pos === 'top' || pos === 'bottom') ? 'center' 
						  : 'right';
			ctx.textBaseline = 'top';
			ctx.font = `900 ${fixedFontSize}px 'Inter', sans-serif`;
			ctx.fillStyle = "#10b981";
			ctx.fillText(companyName, lx, ly);
		}

		ctx.restore();
	}
    // ===== TEXT DRAWING CODE =====
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
    ctx.textBaseline = "alphabetic";

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
    const textHeight = fontSz * scale * 0.8;

    let totalTextHeight = 0;
    lines.forEach((l, i) => {
        totalTextHeight += lineHeight;
        if (l.paraBreak && i < lines.length - 1) totalTextHeight += paraExtra;
    });

    let startY;
    if (textPosition === 'top') startY = 80 * scale + lineHeight;
    else if (textPosition === 'center') startY = (canvas.height - totalTextHeight) / 2 + lineHeight;
    else startY = canvas.height - (150 * scale) - totalTextHeight + lineHeight;

    if (bgEnabled) {
        ctx.shadowBlur = 0; 
        ctx.shadowOffsetX = 0; 
        ctx.shadowOffsetY = 0;
        
        const r = parseInt(bgColor.slice(1,3),16);
        const g = parseInt(bgColor.slice(3,5),16);
        const b = parseInt(bgColor.slice(5,7),16);
        ctx.fillStyle = `rgba(${r},${g},${b},${bgOpacity})`;
        
        let yPos = startY;
        lines.forEach((l, i) => {
            const textWidth = ctx.measureText(l.text).width;
            const padding = 15 * scale;
            const bgWidth = textWidth + (padding * 2);
            const bgX = (canvas.width - bgWidth) / 2;
            
            const bgY = yPos - lineHeight + (lineHeight - textHeight) / 2;
            const bgHeight = lineHeight - (lineHeight - textHeight) * 0.2;
            
            ctx.beginPath();
            ctx.roundRect(bgX, bgY, bgWidth, bgHeight, 10 * scale);
            ctx.fill();
            
            yPos += lineHeight;
            if (l.paraBreak && i < lines.length - 1) yPos += paraExtra;
        });
        
        ctx.fillStyle = fontColor;
        ctx.shadowBlur = 8 * scale; 
        ctx.shadowColor = "rgba(0,0,0,0.9)";
        ctx.shadowOffsetX = 2 * scale; 
        ctx.shadowOffsetY = 2 * scale;
    }

    let yPos = startY;
    lines.forEach((l, i) => {
        ctx.fillText(l.text, canvas.width / 2, yPos);
        yPos += lineHeight;
        if (l.paraBreak && i < lines.length - 1) yPos += paraExtra;
    });

    ctx.shadowBlur = 0;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 0;
}
// Helper function for rounded rectangles (add this at the end of your script)
CanvasRenderingContext2D.prototype.roundRect = function(x, y, w, h, r) {
    if (w < 2 * r) r = w / 2;
    if (h < 2 * r) r = h / 2;
    this.moveTo(x + r, y);
    this.lineTo(x + w - r, y);
    this.quadraticCurveTo(x + w, y, x + w, y + r);
    this.lineTo(x + w, y + h - r);
    this.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    this.lineTo(x + r, y + h);
    this.quadraticCurveTo(x, y + h, x, y + h - r);
    this.lineTo(x, y + r);
    this.quadraticCurveTo(x, y, x + r, y);
    return this;
};

// ========== VIDEO CREATE STATUS ==========
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
        }
    })
    .catch(err => console.error('DB update error:', err));
}

// ========== SEQUENCE & INIT ==========
// Add this debug version of playFullSequence to see what's happening
function playFullSequence(isRecording = false) {
    console.log('playFullSequence called, isRecording:', isRecording);
    console.log('isPlayingSequence:', isPlayingSequence);
    
    if (isPlayingSequence && !isRecording) {
        console.log('Stopping playback');
        stopPlayback();
        return;
    }
    
    const audios = Array.from(document.querySelectorAll('#sceneTable audio'));
    console.log('Found audios:', audios.length);
    
    // Log each audio element's id and src
    audios.forEach((audio, index) => {
        console.log(`Audio ${index}:`, {
            id: audio.id,
            src: audio.src ? audio.src.substring(0, 50) + '...' : 'no src',
            parent: audio.parentElement?.tagName
        });
    });
    
    if (audios.length === 0) {
        console.log('No audio found');
        return;
    }

    isPlayingSequence = true;
    const playBtn = document.getElementById('playAllBtn');
    if (playBtn) { 
        playBtn.innerHTML = "⏹ Stop"; 
        playBtn.classList.replace('btn-purple', 'btn-red'); 
    }

    let currentIdx = 0;
    
    function playNext() {
        console.log('playNext called, currentIdx:', currentIdx, 'total:', audios.length);
        
        if (!isPlayingSequence || currentIdx >= audios.length) { 
            console.log('Playback complete or stopped');
            stopPlayback(); 
            return; 
        }
        
        currentAudioPlayer = audios[currentIdx];
        console.log('Current audio player:', currentAudioPlayer.id);
        
        // Try to get rowId from the audio's parent or data attribute
        let rowId = null;
        
        // First try to get from ID pattern audio-player-{id}
        if (currentAudioPlayer.id && currentAudioPlayer.id.startsWith('audio-player-')) {
            rowId = currentAudioPlayer.id.replace('audio-player-', '');
        } else {
            // Try to find the parent row
            const row = currentAudioPlayer.closest('tr');
            if (row) {
                rowId = row.id.replace('row-', '');
            }
        }
        
        console.log('Found rowId:', rowId);
        
        if (rowId) {
            const row = document.getElementById(`row-${rowId}`);
            if (row) {
                const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
                const logoOverlay = document.getElementById('logoPreviewOverlay');
                if (logoOverlay) logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';

                updateMonitorMedia(rowId);
            }
        }

        currentAudioPlayer.onended = () => { 
            console.log('Audio ended, moving to next');
            currentIdx++; 
            playNext(); 
        };
        
        console.log('Playing audio...');
        currentAudioPlayer.play().catch(e => {
            console.error('Error playing audio:', e);
            // Skip to next on error
            currentIdx++; 
            playNext();
        });
    }
    
    playNext();
}

function stopPlayback() {
    isPlayingSequence = false;
    clearTimeout(typewriterInterval);
    if (mediaRecorder && mediaRecorder.state === "recording") mediaRecorder.stop();

    const playBtn = document.getElementById('playAllBtn');
    if (playBtn) { 
        playBtn.innerHTML = "▶️ Play All"; 
        playBtn.classList.replace('btn-red', 'btn-purple'); 
    }
    
    const recBtn = document.getElementById('recordBtn');
    if (recBtn) { 
        recBtn.innerHTML = "⏺ Record"; 
        recBtn.classList.replace('btn-purple', 'btn-red'); 
    }

    if (currentAudioPlayer) { 
        currentAudioPlayer.pause(); 
        currentAudioPlayer.currentTime = 0; 
    }
}


function switchMainTab(tab) {
    console.log(`🔄 switchMainTab called with tab: ${tab}`);
    
    if (currentMainTab === tab) {
        console.log(`⚠️ Already on tab ${tab}, skipping`);
        return;
    }
    
    currentMainTab = tab;
    
    document.querySelectorAll('.main-tab').forEach(t => t.classList.remove('active'));
    const activeTab = document.getElementById(`tab-${tab}`);
    if (activeTab) activeTab.classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.style.display = 'none';
    });
    
    const activeContent = document.getElementById(`${tab}-tab-content`);
    if (activeContent) activeContent.style.display = 'block';
    
    setTimeout(() => {
        if (tab === 'images') {
            if (imageFiles.length === 0) loadMedia('images');
            else renderImageGrid(imageFiles);
        } else if (tab === 'videos') {
            if (videoFiles.length === 0) loadMedia('videos');
            else renderVideoGrid(videoFiles);
        }
    }, 100);
}

async function loadMediaLibraries() {
    try {
        await Promise.all([loadMedia('images'), loadMedia('videos')]);
    } catch (error) {
        console.error('Error loading media libraries:', error);
    }
}

async function loadMedia(type) {
    if (isLoading[type]) {
        console.log(`⚠️ Already loading ${type}, skipping...`);
        return;
    }
    
    isLoading[type] = true;
    console.log(`🔍 loadMedia started for type: ${type}`);
    
    try {
        const gridId = type === 'images' ? 'image-grid' : 'video-grid';
        const grid = document.getElementById(gridId);
        
        if (grid) {
            grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px;"><div class="spinner" style="margin:0 auto 15px;"></div>Loading ' + type + '...</div>';
        }
        
        const formData = new FormData();
        formData.append('type', type);
        
        const response = await fetch('get_media.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            if (type === 'images') {
                imageFiles = data.data?.images || data.images || [];
                console.log(`🖼️ Loaded ${imageFiles.length} images`);
                
                if (currentMainTab === 'images') {
                    renderImageGrid(imageFiles);
                }
            } else {
                videoFiles = data.data?.videos || data.videos || [];
                console.log(`🎥 Loaded ${videoFiles.length} videos`);
                
                if (currentMainTab === 'videos') {
                    renderVideoGrid(videoFiles);
                }
            }
        }
    } catch (error) {
        console.error(`❌ Error loading ${type}:`, error);
        const grid = document.getElementById(type === 'images' ? 'image-grid' : 'video-grid');
        if (grid) {
            grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:40px; color:#ef4444;">Error loading ${type}</div>`;
        }
    } finally {
        isLoading[type] = false;
    }
}

function renderImageGrid(images) {
    if (isRendering) {
        console.log("⚠️ Already rendering, skipping...");
        return;
    }
    
    isRendering = true;
    console.log(`🖼️ renderImageGrid called with`, images?.length || 0, `images`);
    
    const grid = document.getElementById('image-grid');
    if (!grid) {
        console.error(`❌ image-grid element not found!`);
        isRendering = false;
        return;
    }
    
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><span style="font-size:48px; display:block; margin-bottom:15px;">🖼️</span>No images found</div>';
        isRendering = false;
        return;
    }
    
    let html = '';
    images.forEach(img => {
        const isSelected = selectedImage && selectedImage.name === img.name;
        const safeName = img.name.replace(/'/g, "\\'");
        const safePath = img.path.replace(/'/g, "\\'");
        
        html += `
            <div class="media-item ${isSelected ? 'selected' : ''}" data-name="${safeName}" data-path="${safePath}" data-type="image">
                <img src="${img.path}?v=${Date.now()}" class="media-preview" alt="${img.name}" loading="lazy">
                <div class="media-info">
                    <div class="media-name">${img.name}</div>
                    <div class="media-size">${img.formatted_size || ''}</div>
                </div>
                <div class="select-indicator">✓</div>
            </div>
        `;
    });
    
    grid.innerHTML = html;
    
    document.querySelectorAll('#image-grid .media-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            const name = this.dataset.name;
            const path = this.dataset.path;
            selectMedia('image', name, path);
        });
    });
    
    if (selectedImage) {
        const statusDiv = document.createElement('div');
        statusDiv.className = 'selection-status';
        statusDiv.style.cssText = 'grid-column:1/-1; margin-top:15px; padding:10px; background:#f0f9ff; border-radius:6px; display:flex; justify-content:space-between; align-items:center;';
        statusDiv.innerHTML = `
            <span>✅ Selected: ${selectedImage.name}</span>
            <button class="use-media-btn" data-type="image">Use This File</button>
        `;
        grid.appendChild(statusDiv);
        
        statusDiv.querySelector('.use-media-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            useSelectedMedia('image');
        });
    }
    
    isRendering = false;
    console.log("✅ Image grid rendered");
}

function renderVideoGrid(videos) {
    const grid = document.getElementById('video-grid');
    if (!grid) return;
    
    if (!videos || videos.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><span style="font-size:48px; display:block; margin-bottom:15px;">🎥</span>No videos found</div>';
        return;
    }
    
    let html = '';
    videos.forEach(vid => {
        const isSelected = selectedVideo && selectedVideo.name === vid.name;
        const safeName = vid.name.replace(/'/g, "\\'");
        const safePath = vid.path.replace(/'/g, "\\'");
        
        html += `
            <div class="media-item ${isSelected ? 'selected' : ''}" data-name="${safeName}" data-path="${safePath}" data-type="video">
                <video class="media-preview" src="${vid.path}?v=${Date.now()}" muted preload="metadata" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0;"></video>
                <div class="media-info">
                    <div class="media-name">${vid.name}</div>
                    <div class="media-size">${vid.formatted_size || ''}</div>
                </div>
                <div class="select-indicator">✓</div>
            </div>
        `;
    });
    
    grid.innerHTML = html;
    
    document.querySelectorAll('#video-grid .media-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            const name = this.dataset.name;
            const path = this.dataset.path;
            selectMedia('video', name, path);
        });
    });
    
    if (selectedVideo) {
        const statusDiv = document.createElement('div');
        statusDiv.className = 'selection-status';
        statusDiv.style.cssText = 'grid-column:1/-1; margin-top:15px; padding:10px; background:#f0f9ff; border-radius:6px; display:flex; justify-content:space-between; align-items:center;';
        statusDiv.innerHTML = `
            <span>✅ Selected: ${selectedVideo.name}</span>
            <button class="use-media-btn" data-type="video">Use This File</button>
        `;
        grid.appendChild(statusDiv);
        
        statusDiv.querySelector('.use-media-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            useSelectedMedia('video');
        });
    }
}

function selectMedia(type, name, path) {
    console.log(`🎯 selectMedia called:`, { type, name, path });
    
    if (type === 'image') {
        selectedImage = { name, path };
        if (renderTimeout) clearTimeout(renderTimeout);
        renderTimeout = setTimeout(() => {
            renderImageGrid(imageFiles);
        }, 50);
    } else {
        selectedVideo = { name, path };
        if (renderTimeout) clearTimeout(renderTimeout);
        renderTimeout = setTimeout(() => {
            renderVideoGrid(videoFiles);
        }, 50);
    }
}

function useSelectedMedia(type) {
    if (type === 'image' && selectedImage) {
        document.getElementById('selected_server_image').value = selectedImage.name;
        
        const container = document.getElementById('image_current');
        if (container) {
            container.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px; margin-top:10px;">
                    <img src="${selectedImage.path}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                    <span style="color:#059669; font-weight:600;">${selectedImage.name} (selected from library)</span>
                </div>
            `;
        }
        switchMainTab('data');
    } else if (type === 'video' && selectedVideo) {
        document.getElementById('selected_server_video').value = selectedVideo.name;
        
        const container = document.getElementById('video_current');
        if (container) {
            container.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px; margin-top:10px;">
                    <video src="${selectedVideo.path}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" muted></video>
                    <span style="color:#059669; font-weight:600;">${selectedVideo.name} (selected from library)</span>
                </div>
            `;
        }
        switchMainTab('data');
    }
}

function filterMedia(type) {
    const searchInput = document.getElementById(type === 'image' ? 'image-search' : 'video-search');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    
    if (type === 'image') {
        const filtered = imageFiles.filter(img => img.name.toLowerCase().includes(searchTerm));
        renderImageGrid(filtered);
    } else {
        const filtered = videoFiles.filter(vid => vid.name.toLowerCase().includes(searchTerm));
        renderVideoGrid(filtered);
    }
}

function clearServerSelection(type) {
    if (type === 'image') {
        document.getElementById('selected_server_image').value = '';
        selectedImage = null;
        if (currentMainTab === 'images') {
            renderImageGrid(imageFiles);
        }
    } else {
        document.getElementById('selected_server_video').value = '';
        selectedVideo = null;
        if (currentMainTab === 'videos') {
            renderVideoGrid(videoFiles);
        }
    }
}

// ========== CLONE MEDIA TO ALL LANGUAGES ==========
/*
function cloneMediaToLangs() {
    const titleSelect = document.querySelector('[name=title_filter]');
    const langSelect = document.querySelector('[name=lang_filter]');
    const title = titleSelect ? titleSelect.value : '';
    const lang = langSelect ? langSelect.value : '';

    if (!title) { alert('Please select a project first.'); return; }

    const msg = `Clone all images & videos from "${lang.toUpperCase()}" to ALL other languages for:\n\n"${title}"\n\nThis will overwrite existing media in other languages. Continue?`;
    if (!confirm(msg)) return;

    const btn = document.getElementById('cloneMediaBtn');
    const gs = document.getElementById('globalStatus');
    btn.disabled = true;
    btn.innerText = '⏳ Cloning...';
    if (gs) gs.innerHTML = '<span style="color:#2563eb;">Cloning media...</span>';

    const fd = new FormData();
    fd.append('title', title);
    fd.append('source_lang', lang);

    fetch('clone_media.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = '📋 Clone Media → All Langs';
        if (data.success) {
            let summary = `✅ Cloned from ${data.source_lang.toUpperCase()} (${data.source_scenes} scenes)\n\n`;
            data.details.forEach(d => {
                summary += `${d.lang.toUpperCase()}: ${d.updated}/${d.matched} scenes updated\n`;
            });
            alert(summary);
            if (gs) gs.innerHTML = '✅ Media cloned to ' + data.target_langs.length + ' languages (' + data.total_updated + ' rows)';
        } else {
            alert('Clone failed: ' + (data.error || 'Unknown error'));
            if (gs) gs.innerHTML = '<span style="color:#dc2626;">❌ Clone failed</span>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = '📋 Clone Media → All Langs';
        alert('Network error: ' + err.message);
    });
}

*/
// Download audio file
// Download audio file with enhanced feedback
// Download single audio file
/*
function downloadSingleAudio(id) {
    console.log("Downloading audio for ID:", id);
    
    // Find the audio element
    const audioPlayer = document.getElementById('audio-player-' + id);
    
    if (!audioPlayer) {
        alert('No audio found for this row. Please generate audio first.');
        return;
    }
    
    // Get the audio source
    const audioSrc = audioPlayer.src;
    
    if (!audioSrc || audioSrc === '') {
        alert('Audio source not found.');
        return;
    }
    
    // Get the row for actor info
    const row = document.getElementById('row-' + id);
    const actor = row ? row.getAttribute('data-actor') : 'host';
    
    // Get text preview for filename
    const textElement = document.getElementById('text-' + id);
    const textPreview = textElement ? textElement.value.substring(0, 30) : 'audio';
    const cleanText = textPreview.replace(/[^\w\s]/gi, '').replace(/\s+/g, '_').toLowerCase();
    
    // Create filename
    const timestamp = new Date().getTime();
    const filename = `${actor}_${cleanText}_${timestamp}.mp3`;
    
    // Show downloading status
    const statusCell = document.getElementById('status-' + id);
    const originalContent = statusCell.innerHTML;
    statusCell.innerHTML = '<span class="status-badge" style="background:#dbeafe; color:#1e40af;">⬇️ Downloading...</span>';
    
    // Create download link
    const link = document.createElement('a');
    link.href = audioSrc;
    link.download = filename;
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Restore status after 2 seconds
    setTimeout(() => {
        statusCell.innerHTML = originalContent;
    }, 2000);
}

*/
// ========== GENERATION ENGINE ==========
/*
async function generateSingle(id) {
    console.log("Generating for ID:", id);
    
    const row = document.getElementById('row-' + id);
    const actor = row ? row.getAttribute('data-actor') : 'host';
    
    let voicePicker = (actor === 'guest') ? 
        document.getElementById('guestVoicePicker') : 
        document.getElementById('hostVoicePicker');

    const ratePicker = document.getElementById('ratePicker');
    const textElement = document.getElementById('text-' + id);
    const langFilter = document.querySelector('[name=lang_filter]');

    if (!voicePicker || !ratePicker || !textElement) {
        console.error("UI elements missing for ID:", id);
        return;
    }

    const statusCell = document.getElementById('status-' + id);
    statusCell.innerHTML = '<span class="status-badge" style="background:#fef3c7; color:#92400e;">⏳ Generating...</span>';

    const formData = new FormData();
    formData.append('row_id', id);
    formData.append('text', textElement.value);
    formData.append('lang_code', langFilter ? langFilter.value : 'en-US');
    formData.append('voice_id', voicePicker.value);
    formData.append('rate', ratePicker.value);

    try {
        const response = await fetch('generate_voice.php', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            const timestamp = new Date().getTime();
            const audioUrl = data.file + '?v=' + timestamp;

			



            statusCell.innerHTML = `
                <div class="audio-controls" style="display: flex; align-items: center; gap: 8px;">
                    <span class="status-badge ready" style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-size:11px;">✅ Ready</span>
                    <button type="button" class="btn-play-small" 
                            style="padding: 2px 8px; cursor: pointer;"
                            onclick="toggleRowAudio('${id}')" 
                            id="play-btn-${id}">
                        ▶️ Play
                    </button>
                    <audio id="audio-player-${id}" src="${audioUrl}" style="display:none;"></audio>
                </div>
            `;

            row.setAttribute('data-audio', audioUrl);
            if (typeof updateMonitorMedia === "function") updateMonitorMedia(id);
            if (typeof updateTotalProjectTime === "function") updateTotalProjectTime();

        } else {
            alert("API Error: " + data.message);
            statusCell.innerHTML = '<span class="status-badge" style="color:red;">❌ Failed</span>';
        }
    } catch (err) {
        console.error("Fetch Error:", err);
        statusCell.innerHTML = '<span class="status-badge" style="color:red;">❌ Net Error</span>';
    }
}
*/
/*
async function processAllScenes() {
    const rows = document.querySelectorAll('.scene-row');
    const btn = document.getElementById('batchBtn');
    btn.disabled = true;
    btn.innerText = "⏳ Processing...";

    for (let row of rows) {
        const id = row.id.replace('row-', '');
        if (!row.querySelector('.status-badge.ready')) {
            await generateSingle(id);
        }
    }

    btn.disabled = false;
    btn.innerText = "🚀 Batch All";
    location.reload();
}
*/
/*
async function generateAllImages() {
    const rows = document.querySelectorAll('.scene-row');
    const btn = document.getElementById('btn-generate-all');
    
    console.log("Found rows:", rows.length);
    if (rows.length === 0) {
        alert("No scenes found to process.");
        return;
    }

    if(!confirm(`Start generating AI images for ${rows.length} rows?`)) return;
    
    btn.disabled = true;
    btn.innerText = "⏳ Generating...";

    for (let row of rows) {
        const id = row.id.replace('row-', '');
        const prompt = row.getAttribute('data-prompt');
        const statusCell = document.getElementById(`img-status-${id}`);

        if (!prompt || prompt.trim() === "") {
            console.warn(`Skipping ID ${id}: No prompt.`);
            continue;
        }
        if (!statusCell) {
            console.error(`Missing element: img-status-${id}`);
            continue; 
        }

        statusCell.innerHTML = '<span style="font-size:10px; color: blue;">⌛ AI Working...</span>';
        
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'generate_scene_image');
            fd.append('id', id);
            fd.append('prompt', prompt);

            const resp = await fetch(window.location.href, { method: 'POST', body: fd });
            if (!resp.ok) throw new Error(`Server returned status ${resp.status}`);
            const data = await resp.json();

            if (data.success) {
                statusCell.innerHTML = `<img src="${data.filepath}?v=${Date.now()}" class="preview-thumb" style="width:50px; height:50px; border-radius: 4px; border: 1px solid #ccc;">`;
                row.setAttribute('data-image', data.filepath);
            } else {
                statusCell.innerHTML = '<span style="color:red;">❌ API Error</span>';
                console.error(`API Error for ${id}:`, data.message);
            }
        } catch (e) {
            statusCell.innerHTML = '<span style="color:red;">❌ Fail</span>';
            console.error(`Error for ${id}:`, e);
        }
    }

    btn.disabled = false;
    btn.innerText = "🎨 Generate All Images";
    alert("Batch generation complete!");
}
*/
function getVoiceForRow(rowId) {
    const row = document.getElementById('row-' + rowId);
    const actor = row.getAttribute('data-actor').toLowerCase();
    
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;

    if (actor === 'guest') {
        if (!guestVoice) { alert("Please select a Guest voice!"); return null; }
        return guestVoice;
    } else {
        if (!hostVoice) { alert("Please select a Host voice!"); return null; }
        return hostVoice;
    }
}
/*
async function startGeneration() {
    const rows = document.querySelectorAll('.scene-row');
    const btn = document.getElementById('generate-btn');

    if (!confirm("Generate audio for all rows?")) return;

    btn.disabled = true;
    btn.innerText = "⏳ Processing Audio...";

    for (let row of rows) {
        const id = row.id.replace('row-', '');
        row.classList.add('row-active');
        await generateSingle(id);
        row.classList.remove('row-active');
    }

    btn.disabled = false;
    btn.innerText = "🎤 Gen Audio";
    alert("Audio generation complete!");
    updateTotalProjectTime();
}
*/
function toggleRowAudio(id) {
    const player = document.getElementById('audio-player-' + id);
    const btn = document.getElementById('play-btn-' + id);
    
    if (!player) { console.error("Audio player not found for ID:", id); return; }

    if (player.paused) {
        document.querySelectorAll('audio').forEach(a => { if(a !== player) a.pause(); });
        player.play();
        if(btn) btn.innerText = "⏸ Pause";
    } else {
        player.pause();
        if(btn) btn.innerText = "▶️ Play";
    }
    
    player.onended = () => { if(btn) btn.innerText = "▶️ Play"; };
}

async function deleteRow(id) {
    if (!confirm(`Delete Scene ID ${id}? This cannot be undone.`)) return;

    const rowElement = document.getElementById(`row-${id}`);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_scene_row');
        fd.append('id', id);

        const resp = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            rowElement.style.transition = 'all 0.3s';
            rowElement.style.opacity = '0';
            rowElement.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                rowElement.remove();
                if (typeof calculateProjectTotals === "function") calculateProjectTotals();
            }, 300);
        } else {
            alert("Error deleting row: " + data.message);
        }
    } catch (e) {
        console.error("Delete failed", e);
        alert("Server error while trying to delete.");
    }
}

function updatePodcast(podcastId) {
    const btn = document.getElementById('updateBtn');
    if (!podcastId) { alert("No Podcast ID found."); return; }

    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = "⏳ Updating...";

    const formData = new FormData();
    formData.append('podcast_id', podcastId);

    fetch('update_podcast_status.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = "✅ Recorded";
            btn.classList.replace('btn-green', 'btn-secondary');
        } else {
            alert("Error: " + data.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    updateTotalProjectTime();
    updateLogoPreview();
    
    const firstRow = document.querySelector('.scene-row');
    if (firstRow) {
        const rowId = firstRow.id.replace('row-', '');
        firstRow.classList.add('row-active');
        updateMonitorMedia(rowId);
    }

    const canvas = document.getElementById('exportCanvas');
    const ctx = canvas.getContext('2d');
    
    function idleLoop() {
        drawToCanvas(canvas, ctx);
        requestAnimationFrame(idleLoop);
    }
    idleLoop();

    document.querySelectorAll('.script-input').forEach(textarea => {
        const rowId = textarea.id.replace('text-', '');
        textarea.addEventListener('focus', () => {
            document.querySelectorAll('.scene-row').forEach(r => r.classList.remove('row-active'));
            document.getElementById('row-' + rowId).classList.add('row-active');
            updateMonitorMedia(rowId);
        });
    });

    // ===== ADD ROW CLICK HANDLERS HERE =====
    console.log('Adding row click handlers...');
	
	const rows = document.querySelectorAll('.scene-row');
	console.log('Found ' + rows.length + ' rows to attach handlers to');

	document.querySelectorAll('.scene-row').forEach(row => {
		console.log('Attaching click handler to row:', row.id);
		
		row.addEventListener('click', function(e) {
			console.log('🔴 CLICK EVENT FIRED for row:', this.id);
			
			// Don't trigger if clicking on button or input
			if (e.target.tagName === 'BUTTON' || e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
				console.log('Click ignored - target is:', e.target.tagName);
				return;
			}
			
			const rowId = this.id.replace('row-', '');
			console.log('👆 Row clicked, extracted ID:', rowId);
			
			// Remove active class from all rows
			document.querySelectorAll('.scene-row').forEach(r => {
				r.classList.remove('row-active');
			});
			
			// Add active class to clicked row
			this.classList.add('row-active');
			console.log('Active class added to row');
			
			// Update the monitor
			if (typeof updateMonitorMedia === 'function') {
				console.log('Calling updateMonitorMedia for row:', rowId);
				updateMonitorMedia(rowId);
			} else {
				console.error('updateMonitorMedia is not a function!');
			}
		});
	});
	
	
	
	
  

    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const rowId = document.getElementById('edit_row_id').value;
            const formData = new FormData(this);

            submitBtn.disabled = true;
            submitBtn.innerText = "Saving...";

            fetch('update_scene.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    const textarea = document.getElementById(`text-${rowId}`);
                    if(textarea) textarea.value = formData.get('text_contents');
                    
                    const statusCell = document.getElementById(`status-${rowId}`);
                    if(statusCell) statusCell.innerHTML = '<span class="status-badge">⏳ Pending (Updated)</span>';
                    
                    const row = document.getElementById(`row-${rowId}`);
                    if(row) {
                        if(data.new_image) row.setAttribute('data-image', 'podcast_images/' + data.new_image);
                        if(data.new_video) row.setAttribute('data-video', 'podcast_videos/' + data.new_video);
                        
                        row.style.transition = "background 0.5s";
                        row.style.backgroundColor = "#dcfce7";
                        setTimeout(() => { row.style.backgroundColor = ""; }, 1500);
                        
                        updateMonitorMedia(rowId);
                    }
                    closeModal();
                } else {
                    alert('Update failed: ' + data.message);
                }
            })
            .catch(err => console.error("Error:", err))
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerText = "💾 Save Changes";
            });
        });
    }
});
// Directly update scene with selected image (ONE FUNCTION)
async function updateSceneWithImage() {
    if (!selectedImage) {
        alert('Please select an image first');
        return;
    }
    
    const rowId = document.getElementById('edit_row_id').value;
    if (!rowId) {
        alert('No scene ID found');
        return;
    }
    
    // Show loading state
    const btn = document.getElementById('image-use-top-btn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Updating...';
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('row_id', rowId);
    formData.append('image_file', selectedImage.name); // Use 'image_file' not 'image_file_direct'
    
    try {
        const response = await fetch('update_scene_direct.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update the display
            const container = document.getElementById('image_current');
            if (container) {
                container.innerHTML = `
                    <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px; margin-top:10px;">
                        <img src="${selectedImage.path}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                        <span style="color:#059669; font-weight:600;">${selectedImage.name}</span>
                    </div>
                `;
            }
            
            // Also set the hidden input for the main form
            document.getElementById('selected_server_image').value = selectedImage.name;
            
            showToast('✅ Image updated successfully!', 'success');
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        console.error('Error updating image:', error);
        alert('Network error occurred');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

// Show/hide the button when an image is selected
function updateImageButton() {
    const btn = document.getElementById('image-use-top-btn');
    if (btn) {
        btn.style.display = selectedImage ? 'flex' : 'none';
    }
}

// Update your selectMedia function
function selectMedia(type, name, path) {
    if (type === 'image') {
        selectedImage = { name, path };
        selectedVideo = null;
        if (renderTimeout) clearTimeout(renderTimeout);
        renderTimeout = setTimeout(() => {
            renderImageGrid(imageFiles);
            updateImageButton(); // Show the button
        }, 50);
    }
}

// Toast notification function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 24px;
        background: ${type === 'success' ? '#10b981' : '#3b82f6'};
        color: white;
        border-radius: 8px;
        font-weight: 600;
        z-index: 9999;
        animation: slideIn 0.3s ease;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}











// Click outside to close modal
window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeModal();
    }
};

// Show/hide the button when an image is selected
function updateImageButton() {
    const btn = document.getElementById('image-use-top-btn');
    if (btn) {
        btn.style.display = selectedImage ? 'flex' : 'none';
    }
}



// ========== SIMPLE MODAL FUNCTIONS ==========
let currentEditingRow = null;

function simpleOpenModal(rowId) {
    const row = document.getElementById('row-' + rowId);
    if (!row) {
        console.error('Row not found:', rowId);
        return;
    }
    
    currentEditingRow = rowId;
    document.getElementById('simpleSceneId').innerText = rowId;
    document.getElementById('simpleRowId').value = rowId;
    
    // Get current values from the row
    const textContents = document.getElementById('text-' + rowId)?.value || '';
    const textDisplay = row.getAttribute('data-text-display') || '';
    const prompt = row.getAttribute('data-prompt') || '';
    const logoFlag = row.getAttribute('data-logo-flag') || '1';
    
    // Fill the form
    document.getElementById('simpleTextContents').value = textContents;
    document.getElementById('simpleTextDisplay').value = textDisplay;
    document.getElementById('simplePrompt').value = prompt;
    document.getElementById('simpleLogoFlag').value = logoFlag;
    
    // Show current media
    const imageFile = row.getAttribute('data-image')?.replace('podcast_images/', '') || '';
    const videoFile = row.getAttribute('data-video')?.replace('podcast_videos/', '') || '';
    
    const imageDiv = document.getElementById('simpleCurrentImage');
    const videoDiv = document.getElementById('simpleCurrentVideo');
    
    if (imageFile) {
        imageDiv.innerHTML = `<img src="podcast_images/${imageFile}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;"> <span style="margin-left:8px;">${imageFile}</span>`;
    } else {
        imageDiv.innerHTML = '<span style="color:#94a3b8;">No image assigned</span>';
    }
    
    if (videoFile) {
        videoDiv.innerHTML = `<video src="podcast_videos/${videoFile}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" muted></video> <span style="margin-left:8px;">${videoFile}</span>`;
    } else {
        videoDiv.innerHTML = '<span style="color:#94a3b8;">No video assigned</span>';
    }
    
    // Show the modal
    document.getElementById('simpleEditModal').style.display = 'block';
    document.getElementById('simpleSaveStatus').innerHTML = '';
}

function simpleCloseModal() {
    document.getElementById('simpleEditModal').style.display = 'none';
    currentEditingRow = null;
}

async function simpleSaveScene() {
    const rowId = document.getElementById('simpleRowId').value;
    if (!rowId) return;
    
    const statusDiv = document.getElementById('simpleSaveStatus');
    statusDiv.innerHTML = '⏳ Saving...';
    statusDiv.style.color = '#2563eb';
    
    // Get form values
    const textContents = document.getElementById('simpleTextContents').value;
    const textDisplay = document.getElementById('simpleTextDisplay').value;
    const prompt = document.getElementById('simplePrompt').value;
    const logoFlag = document.getElementById('simpleLogoFlag').value;
    
    const formData = new FormData();
    formData.append('row_id', rowId);
    formData.append('text_contents', textContents);
    formData.append('text_display', textDisplay);
    formData.append('prompt', prompt); 
    formData.append('logo_flag', logoFlag);
    
    try {
        const response = await fetch('simple_update_scene.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update the UI
            const textarea = document.getElementById('text-' + rowId);
            if (textarea) textarea.value = textContents;
            
            const row = document.getElementById('row-' + rowId);
            if (row) {
                row.setAttribute('data-text-display', textDisplay);
                row.setAttribute('data-prompt', prompt);
                row.setAttribute('data-logo-flag', logoFlag);
                
                // Highlight the row to show success
                row.style.transition = 'background 0.5s';
                row.style.backgroundColor = '#dcfce7';
                setTimeout(() => {
                    row.style.backgroundColor = '';
                }, 1500);
            }
            
            statusDiv.innerHTML = '✅ Saved successfully!';
            statusDiv.style.color = '#059669';
            
            // Close modal after success
            setTimeout(() => {
                simpleCloseModal();
            }, 1000);
            
        } else {
            statusDiv.innerHTML = '❌ Error: ' + (data.message || 'Unknown error');
            statusDiv.style.color = '#dc2626';
        }
    } catch (error) {
        console.error('Save error:', error);
        statusDiv.innerHTML = '❌ Network error';
        statusDiv.style.color = '#dc2626';
    }
}
// Debug: Monitor image loading
const originalUpdateMonitorMedia = updateMonitorMedia;
updateMonitorMedia = function(rowId) {
    console.log('🖼️ updateMonitorMedia called for row:', rowId);
    
    const row = document.getElementById('row-' + rowId);
    if (!row) {
        console.error('Row not found:', rowId);
        return;
    }

    const imageUrl = row.getAttribute('data-image');
    const videoUrl = row.getAttribute('data-video');
    console.log('Row data:', { imageUrl, videoUrl });

    const vTag = document.getElementById('videoPreview');
    const iTag = document.getElementById('imagePreview');
    const placeholder = document.getElementById('videoPlaceholder');

    console.log('Video element exists:', !!vTag);
    console.log('Image element exists:', !!iTag);

    if (imageUrl && imageUrl !== 'podcast_images/') {
        console.log('Attempting to load image:', imageUrl);
        
        // Test if image exists
        const testImg = new Image();
        testImg.onload = () => console.log('✅ Image loaded successfully:', imageUrl);
        testImg.onerror = () => console.error('❌ Image failed to load:', imageUrl);
        testImg.src = imageUrl + '?v=' + Date.now();

        // Update the actual preview
        iTag.src = imageUrl + '?v=' + Date.now();
        iTag.style.display = 'block';
        vTag.style.display = 'none';
        if (placeholder) placeholder.style.display = 'none';
    } else {
        console.log('No image found for row:', rowId);
    }

    // Call the original function
    originalUpdateMonitorMedia(rowId);
};

// ========== DIRECT DEBUG ==========
console.log('🔍 Debug mode activated');

// Force a test on page load
window.addEventListener('load', function() {
    console.log('📄 Page fully loaded');
    
    // Check if we have a first row
    const firstRow = document.querySelector('.scene-row');
    if (firstRow) {
        const rowId = firstRow.id.replace('row-', '');
        console.log('First row ID:', rowId);
        
        // Get the image URL
        const imageUrl = firstRow.getAttribute('data-image');
        console.log('First row image URL:', imageUrl);
        
        // Test if image exists
        if (imageUrl && imageUrl !== 'podcast_images/') {
            const testImg = new Image();
            testImg.onload = () => console.log('✅ First row image loaded:', imageUrl);
            testImg.onerror = () => console.error('❌ First row image failed to load:', imageUrl);
            testImg.src = imageUrl + '?v=' + Date.now();
        }
    }
    
    // Check all monitor elements
    console.log('Monitor elements:');
    console.log('- videoPreview:', document.getElementById('videoPreview'));
    console.log('- imagePreview:', document.getElementById('imagePreview'));
    console.log('- typewriter-container:', document.getElementById('typewriter-container'));
    console.log('- exportCanvas:', document.getElementById('exportCanvas'));
});

// Add click handler to test button
document.addEventListener('DOMContentLoaded', function() {
    const testBtn = document.createElement('button');
    testBtn.textContent = 'Test Monitor';
    testBtn.style.cssText = 'position:fixed; bottom:20px; right:20px; z-index:9999; padding:10px; background:#2563eb; color:white; border:none; border-radius:5px; cursor:pointer;';
    testBtn.onclick = function() {
        console.log('🧪 Test button clicked');
        
        // Try to force update first row
        const firstRow = document.querySelector('.scene-row');
        if (firstRow) {
            const rowId = firstRow.id.replace('row-', '');
            console.log('Testing with row:', rowId);
            
            if (typeof updateMonitorMedia === 'function') {
                console.log('Calling updateMonitorMedia');
                updateMonitorMedia(rowId);
            } else {
                console.error('updateMonitorMedia is not a function!');
            }
        }
    };
    document.body.appendChild(testBtn);
    console.log('✅ Test button added to page');
});

// Override console to ensure we see messages
const originalLog = console.log;
console.log = function() {
    originalLog.apply(console, arguments);
    // Also try to show in a visible div
    const debugDiv = document.getElementById('debug-console');
    if (debugDiv) {
        debugDiv.innerHTML += '<div>' + Array.from(arguments).join(' ') + '</div>';
    }
};

// Add a visible debug div
const debugDiv = document.createElement('div');
debugDiv.id = 'debug-console';
debugDiv.style.cssText = 'position:fixed; bottom:10px; left:10px; background:black; color:lime; padding:10px; max-height:200px; overflow:auto; z-index:10000; font-family:monospace; font-size:11px; width:400px;';
document.body.appendChild(debugDiv);
console.log('🟢 Debug console ready');




console.log('✅ Row click handlers added');
// ========== ULTIMATE DEBUG ==========
console.log('🚨 ULTIMATE DEBUG ACTIVATED');

// Create a big red test button
const megaTestBtn = document.createElement('button');
megaTestBtn.textContent = '🔴 MEGA TEST - Force Image Display';
megaTestBtn.style.cssText = `
    position: fixed;
    top: 100px;
    right: 20px;
    z-index: 99999;
    padding: 20px;
    background: red;
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 20px;
    font-weight: bold;
    box-shadow: 0 0 20px rgba(255,0,0,0.5);
`;
document.body.appendChild(megaTestBtn);

megaTestBtn.onclick = function() {
    console.log('🔴 MEGA TEST BUTTON CLICKED');
    
    // Get first row
    const firstRow = document.querySelector('.scene-row');
    if (!firstRow) {
        alert('No rows found!');
        return;
    }
    
    const rowId = firstRow.id.replace('row-', '');
    const imageUrl = firstRow.getAttribute('data-image');
    
    console.log('First row:', { rowId, imageUrl });
    
    // DIRECT MANIPULATION - bypass all functions
    const imagePreview = document.getElementById('imagePreview');
    const videoPreview = document.getElementById('videoPreview');
    
    if (imagePreview && imageUrl && imageUrl !== 'podcast_images/') {
        console.log('Setting image directly to:', imageUrl);
        imagePreview.src = imageUrl + '?v=' + Date.now();
        imagePreview.style.display = 'block';
        videoPreview.style.display = 'none';
        alert('Image should now be visible in monitor!');
    } else {
        alert('No image URL found. Image URL: ' + imageUrl);
    }
};




// Click outside to close modal
window.onclick = function(event) {
    const modal = document.getElementById('simpleEditModal');
    if (event.target === modal) {
        simpleCloseModal();
    }
};
// Use selected image

// Directly update scene with selected image (NEW)

// Update your selectMedia function
function selectMedia(type, name, path) {
    if (type === 'image') {
        selectedImage = { name, path };
        selectedVideo = null;
        if (renderTimeout) clearTimeout(renderTimeout);
        renderTimeout = setTimeout(() => {
            renderImageGrid(imageFiles);
            updateImageButton();
        }, 50);
    }
}
// ========== ULTRA SIMPLE ROW TEST ==========
setTimeout(function() {
    console.log('🔵 ULTRA SIMPLE TEST RUNNING');
    
    // Try to find rows
    const rows = document.querySelectorAll('.scene-row');
    console.log('Found ' + rows.length + ' rows with class "scene-row"');
    
    if (rows.length > 0) {
        // Log the first row's HTML to see what's there
        console.log('First row HTML:', rows[0].outerHTML.substring(0, 200));
        
        // Try the simplest possible click handler
        rows[0].onclick = function() {
            alert('🔴🔴🔴 ROW CLICKED! This proves click handlers work!');
            this.style.backgroundColor = 'red';
        };
        
        console.log('✅ Simple onclick handler added to first row');
    } else {
        console.log('❌ No rows found with class "scene-row"');
        
        // Look for any rows at all
        const allRows = document.querySelectorAll('tr');
        console.log('Total rows in table:', allRows.length);
    }
}, 3000);