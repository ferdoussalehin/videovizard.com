/**
 * CalmBot Videomaker - Portrait + Audio Fix + Logo Overlay
 * CLEANED VERSION - Fixed row click handlers, removed conflicting debug code
 */

// ========== GLOBAL VARIABLES ==========
var lastActiveRowId = null;
var isPlayingSequence = false;
var currentAudioPlayer = null;
var typewriterInterval = null;
var mediaRecorder = null;
var recordedChunks = [];
var audioContext = null;
var mixerDestination = null;
var currentPodcastId = null;

// ========== LOGO STATE ==========
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
        content.innerHTML = `
            <div style="font-family:'Inter',sans-serif; text-align:${align};">
                <div style="font-size:14px; font-weight:900; color:#10b981; line-height:1;">${logoS.companyName}</div>
            </div>
        `;
    }
}

// ========== COOKIE SETTINGS ==========
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
    } catch (e) {}
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
        const r = parseInt(bgColor.slice(1, 3), 16);
        const g = parseInt(bgColor.slice(3, 5), 16);
        const b = parseInt(bgColor.slice(5, 7), 16);
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
function startTextEngine(rowId, durationSeconds) {
    const textElement = document.getElementById('typewriter-text');
    const style = document.getElementById('textStylePicker')?.value || 'typewriter';

    const row = document.getElementById('row-' + rowId);
    const rawText = document.getElementById('text-' + rowId).value || '';

    const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
    const logoOverlay = document.getElementById('logoPreviewOverlay');
    if (logoOverlay) logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';

    let speedMultiplier = parseFloat(document.getElementById('textSpeedOffset').value) || 0.85;

    let cleanText = rawText.replace(/<break[^>]*>/gi, '\n').replace(/\[.*?\]/g, '');
    const segments = cleanText.split('\n').map(s => s.trim()).filter(s => s.length > 0);
    const totalWords = segments.reduce((acc, seg) => acc + seg.split(/\s+/).length, 0);

    clearTimeout(typewriterInterval);
    textElement.innerText = '';
    textElement.classList.remove('text-dimmed');
    textElement.style.transition = "none";
    textElement.style.transform = "translateY(0)";

    if (segments.length === 0 || durationSeconds <= 0) return;

    if (style === 'scroll') {
        textElement.innerText = segments.join('\n');
        const textHeight = textElement.scrollHeight;
        const scrollDuration = durationSeconds * speedMultiplier;
        setTimeout(() => {
            textElement.style.transition = `transform ${scrollDuration}s linear`;
            textElement.style.transform = `translateY(-${textHeight}px)`;
        }, 50);
        return;
    }

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
                const delay = ((durationSeconds * speedMultiplier) / segments.length) * 1000;
                typewriterInterval = setTimeout(process, delay);
            }, 600);
        } else if (style === 'typewriter') {
            const words = segments[segIdx].split(/\s+/);
            if (wordIdx === 0) textElement.innerText = '';
            textElement.innerText += (textElement.innerText ? " " : "") + words[wordIdx];
            wordIdx++;
            if (wordIdx >= words.length) {
                segIdx++;
                wordIdx = 0;
            }
            if (segIdx < segments.length) {
                const ms = ((durationSeconds * speedMultiplier) / totalWords) * 1000;
                typewriterInterval = setTimeout(process, ms);
            }
        }
    }
    process();
}

// ========== MONITOR MEDIA ==========
function updateMonitorMedia(rowId) {
    const row = document.getElementById('row-' + rowId);
    if (!row) return;

    const logoFlag = parseInt(row.getAttribute('data-logo-flag')) || 0;
    const logoOverlay = document.getElementById('logoPreviewOverlay');
    if (logoOverlay) logoOverlay.style.display = (logoFlag === 1) ? 'block' : 'none';

    const vUrl = row.getAttribute('data-video');
    const iUrl = row.getAttribute('data-image');
    const vTag = document.getElementById('videoPreview');
    const iTag = document.getElementById('imagePreview');
    const placeholder = document.getElementById('videoPlaceholder');

    const badge = document.getElementById('status-' + rowId);
    const dMatch = badge ? badge.innerText.match(/(\d+(\.\d+)?)/) : null;
    const duration = dMatch ? parseFloat(dMatch[0]) : 3.0;

    startTextEngine(rowId, duration);

    // Check video — has a real filename (more than just the folder path)
    const hasVideo = vUrl && vUrl.trim() !== '' && vUrl.split('/').pop().length > 3;
    // Check image — has a real filename
    const hasImage = iUrl && iUrl.trim() !== '' && iUrl.split('/').pop().length > 3;

    if (hasVideo) {
        vTag.src = vUrl;
        vTag.style.display = "block";
        vTag.style.zIndex = 2;
        iTag.style.zIndex = 1;
        vTag.play().catch(e => {});
        if (placeholder) placeholder.style.display = "none";
    } else if (hasImage) {
        const newSrc = iUrl + "?v=" + Date.now();
        const preloader = new Image();
        preloader.onload = () => {
            iTag.src = newSrc;
            iTag.style.display = "block";
            iTag.style.zIndex = 2;
            vTag.style.zIndex = 1;
            vTag.pause();
            vTag.style.display = "none";
            vTag.src = "";
            if (placeholder) placeholder.style.display = "none";
        };
        preloader.onerror = () => {
            console.warn('Image failed to load:', newSrc);
            vTag.style.display = "none";
            iTag.style.display = "none";
            if (placeholder) placeholder.style.display = "block";
        };
        preloader.src = newSrc;
    } else {
        vTag.style.display = "none";
        vTag.pause();
        vTag.src = "";
        iTag.style.display = "none";
        iTag.src = "";
        if (placeholder) placeholder.style.display = "block";
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

    let options = {
        videoBitsPerSecond: 5000000,
        mimeType: 'video/webm;codecs=vp8,opus'
    };

    if (!MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')) {
        if (MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')) {
            options.mimeType = 'video/webm;codecs=vp9,opus';
        } else if (MediaRecorder.isTypeSupported('video/webm;codecs=vp8')) {
            options.mimeType = 'video/webm;codecs=vp8';
        } else if (MediaRecorder.isTypeSupported('video/webm')) {
            options.mimeType = 'video/webm';
        } else {
            alert("Your browser doesn't support WebM recording. Please try Chrome, Firefox, or Edge.");
            return;
        }
    }

    mediaRecorder = new MediaRecorder(combined, options);
    recordedChunks = [];

    mediaRecorder.ondataavailable = e => {
        if (e.data.size > 0) recordedChunks.push(e.data);
    };

    mediaRecorder.onstop = async () => {
        if (recordedChunks.length === 0) return;

        const blob = new Blob(recordedChunks, { type: 'video/webm' });

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = `podcast_${Date.now()}.webm`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

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
            if (data.success) {
                updateVideoCreateStatus(projectTitle, langCode);
            }
        } catch (err) {
            console.error("Save error:", err);
        }

        btn.innerHTML = "⏺ Record";
        btn.classList.replace('btn-purple', 'btn-red');
    };

    mediaRecorder.start(100);
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
        if (typeof playFullSequence === 'function') playFullSequence(true);
    }, 500);
}

// ========== DRAW TO CANVAS ==========
async function drawToCanvas(canvas, ctx) {
    const scale = canvas.width / 360;
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const vTag = document.getElementById('videoPreview');
    const iTag = document.getElementById('imagePreview');
    const media = (vTag && vTag.style.display !== 'none') ? vTag : iTag;

    if (media && (media.tagName === 'VIDEO' ? media.readyState >= 2 : media.complete)) {
        const mw = (media.tagName === 'VIDEO') ? media.videoWidth : media.naturalWidth;
        const mh = (media.tagName === 'VIDEO') ? media.videoHeight : media.naturalHeight;

        if (mw > 0 && mh > 0) {
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
    }

    const logoS = window.logoState;

    if (!logoS.dataLoaded && !logoS.fetchAttempted) {
        logoS.fetchAttempted = true;
        try {
            const response = await fetch('get-client-logo.php', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });

            if (response.ok) {
                const data = await response.json();
                logoS.companyName = data.companyname || 'Company';

                const hasValidLogo = data.logo_file &&
                    data.logo_file !== 'logo_mini.png' &&
                    !data.logo_file.includes('default') &&
                    data.logo_file !== null;

                if (hasValidLogo) {
                    const img = new Image();
                    img.onload = () => {
                        logoS.logoImg = img;
                        logoS.showDefaultLogo = false;
                        logoS.dataLoaded = true;
                        updateLogoPreview();
                        drawToCanvas(canvas, ctx);
                    };
                    img.onerror = () => {
                        logoS.logoImg = null;
                        logoS.showDefaultLogo = false;
                        logoS.dataLoaded = true;
                        updateLogoPreview();
                        drawToCanvas(canvas, ctx);
                    };
                    img.src = data.logo_file;
                    return;
                } else {
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

    // Draw gradient overlay
    const grad = ctx.createLinearGradient(0, canvas.height * 0.5, 0, canvas.height);
    grad.addColorStop(0, 'rgba(0,0,0,0)');
    grad.addColorStop(1, 'rgba(0,0,0,0.8)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // ===== DRAW LOGO =====
    if (logoS.logoEnabled !== false) {
        ctx.save();

        const lSize = logoS.logoSize || 80;
        const pos = logoS.logoPosition || 'bottom-right';
        let lx, ly;

        ctx.shadowColor = "rgba(0,0,0,0.8)";
        ctx.shadowBlur = 20;

        if (logoS.showDefaultLogo) {
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
            const aspect = logoS.logoImg.naturalWidth / logoS.logoImg.naturalHeight;
            const lh = lSize;
            const lw = lSize * aspect;

            if (pos.includes('top')) ly = 40;
            else ly = canvas.height - lh - 160;

            if (pos === 'top' || pos === 'bottom') lx = (canvas.width - lw) / 2;
            else if (pos.includes('left')) lx = 40;
            else lx = canvas.width - lw - 40;

            ctx.drawImage(logoS.logoImg, lx, ly, lw, lh);

            const companyName = logoS.companyName || '';
            if (companyName) {
                const fixedFontSize = 28 * scale;
                const nameY = canvas.height - (50 * scale);
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

    // ===== DRAW TEXT =====
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

        const r = parseInt(bgColor.slice(1, 3), 16);
        const g = parseInt(bgColor.slice(3, 5), 16);
        const b = parseInt(bgColor.slice(5, 7), 16);
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

// ========== ROUNDED RECT POLYFILL ==========
if (!CanvasRenderingContext2D.prototype.roundRect) {
    CanvasRenderingContext2D.prototype.roundRect = function (x, y, w, h, r) {
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
}

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
                const gs = document.getElementById('globalStatus');
                if (gs) gs.innerHTML = '✅ Video saved & DB updated (' + data.updated + ' rows)';
            }
        })
        .catch(err => console.error('DB update error:', err));
}

// ========== PLAYBACK ==========
function playFullSequence(isRecording = false) {
    if (isPlayingSequence && !isRecording) {
        stopPlayback();
        return;
    }

    const audios = Array.from(document.querySelectorAll('#sceneTable audio'));
    if (audios.length === 0) return;

    isPlayingSequence = true;
    const playBtn = document.getElementById('playAllBtn');
    if (playBtn) {
        playBtn.innerHTML = "⏹ Stop";
        playBtn.classList.replace('btn-purple', 'btn-red');
    }

    let currentIdx = 0;

    function playNext() {
        if (!isPlayingSequence || currentIdx >= audios.length) {
            stopPlayback();
            return;
        }

        currentAudioPlayer = audios[currentIdx];

        let rowId = null;
        if (currentAudioPlayer.id && currentAudioPlayer.id.startsWith('audio-player-')) {
            rowId = currentAudioPlayer.id.replace('audio-player-', '');
        } else {
            const row = currentAudioPlayer.closest('tr');
            if (row) rowId = row.id.replace('row-', '');
        }

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
            currentIdx++;
            playNext();
        };

        currentAudioPlayer.play().catch(e => {
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

// ========== MEDIA LIBRARY ==========
function switchMainTab(tab) {
    if (currentMainTab === tab) return;
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

async function loadMedia(type) {
    if (isLoading[type]) return;
    isLoading[type] = true;

    const gridId = type === 'images' ? 'image-grid' : 'video-grid';
    const grid = document.getElementById(gridId);

    if (grid) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px;"><div class="spinner" style="margin:0 auto 15px;"></div>Loading ' + type + '...</div>';
    }

    try {
        const formData = new FormData();
        formData.append('type', type);

        const response = await fetch('get_media.php', { method: 'POST', body: formData });
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

        const data = await response.json();

        if (data.success) {
            if (type === 'images') {
                imageFiles = data.data?.images || data.images || [];
                if (currentMainTab === 'images') renderImageGrid(imageFiles);
            } else {
                videoFiles = data.data?.videos || data.videos || [];
                if (currentMainTab === 'videos') renderVideoGrid(videoFiles);
            }
        }
    } catch (error) {
        console.error(`Error loading ${type}:`, error);
        const grid = document.getElementById(type === 'images' ? 'image-grid' : 'video-grid');
        if (grid) {
            grid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:40px; color:#ef4444;">Error loading ${type}</div>`;
        }
    } finally {
        isLoading[type] = false;
    }
}

function renderImageGrid(images) {
    if (isRendering) return;
    isRendering = true;

    const grid = document.getElementById('image-grid');
    if (!grid) { isRendering = false; return; }

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
        item.addEventListener('click', function (e) {
            e.stopPropagation();
            selectMedia('image', this.dataset.name, this.dataset.path);
        });
    });

    if (selectedImage) {
        const statusDiv = document.createElement('div');
        statusDiv.className = 'selection-status';
        statusDiv.style.cssText = 'grid-column:1/-1; margin-top:15px; padding:10px; background:#f0f9ff; border-radius:6px; display:flex; justify-content:space-between; align-items:center;';
        statusDiv.innerHTML = `<span>✅ Selected: ${selectedImage.name}</span><button class="use-media-btn" data-type="image">Use This File</button>`;
        grid.appendChild(statusDiv);

        statusDiv.querySelector('.use-media-btn').addEventListener('click', function (e) {
            e.stopPropagation();
            useSelectedMedia('image');
        });
    }

    isRendering = false;
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
        item.addEventListener('click', function (e) {
            e.stopPropagation();
            selectMedia('video', this.dataset.name, this.dataset.path);
        });
    });

    if (selectedVideo) {
        const statusDiv = document.createElement('div');
        statusDiv.className = 'selection-status';
        statusDiv.style.cssText = 'grid-column:1/-1; margin-top:15px; padding:10px; background:#f0f9ff; border-radius:6px; display:flex; justify-content:space-between; align-items:center;';
        statusDiv.innerHTML = `<span>✅ Selected: ${selectedVideo.name}</span><button class="use-media-btn" data-type="video">Use This File</button>`;
        grid.appendChild(statusDiv);

        statusDiv.querySelector('.use-media-btn').addEventListener('click', function (e) {
            e.stopPropagation();
            useSelectedMedia('video');
        });
    }
}

function selectMedia(type, name, path) {
    if (type === 'image') {
        selectedImage = { name, path };
        selectedVideo = null;
        if (renderTimeout) clearTimeout(renderTimeout);
        renderTimeout = setTimeout(() => {
            renderImageGrid(imageFiles);
            updateImageButton();
        }, 50);
    } else {
        selectedVideo = { name, path };
        selectedImage = null;
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
                </div>`;
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
                </div>`;
        }
        switchMainTab('data');
    }
}

function filterMedia(type) {
    const searchInput = document.getElementById(type === 'image' ? 'image-search' : 'video-search');
    if (!searchInput) return;
    const searchTerm = searchInput.value.toLowerCase();

    if (type === 'image') {
        renderImageGrid(imageFiles.filter(img => img.name.toLowerCase().includes(searchTerm)));
    } else {
        renderVideoGrid(videoFiles.filter(vid => vid.name.toLowerCase().includes(searchTerm)));
    }
}

function clearServerSelection(type) {
    if (type === 'image') {
        document.getElementById('selected_server_image').value = '';
        selectedImage = null;
        if (currentMainTab === 'images') renderImageGrid(imageFiles);
    } else {
        document.getElementById('selected_server_video').value = '';
        selectedVideo = null;
        if (currentMainTab === 'videos') renderVideoGrid(videoFiles);
    }
}

// ========== VOICE / AUDIO ==========
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

function toggleRowAudio(id) {
    const player = document.getElementById('audio-player-' + id);
    const btn = document.getElementById('play-btn-' + id);
    if (!player) return;

    if (player.paused) {
        document.querySelectorAll('audio').forEach(a => { if (a !== player) a.pause(); });
        player.play();
        if (btn) btn.innerText = "⏸ Pause";
    } else {
        player.pause();
        if (btn) btn.innerText = "▶️ Play";
    }

    player.onended = () => { if (btn) btn.innerText = "▶️ Play"; };
}

// ========== ROW DELETE ==========
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
        alert("Server error while trying to delete.");
    }
}

// ========== PODCAST UPDATE ==========
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
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
}

// ========== DIRECT IMAGE UPDATE ==========
async function updateSceneWithImage() {
    if (!selectedImage) { alert('Please select an image first'); return; }

    const rowId = document.getElementById('edit_row_id').value;
    if (!rowId) { alert('No scene ID found'); return; }

    const btn = document.getElementById('image-use-top-btn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ Updating...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('row_id', rowId);
    formData.append('image_file', selectedImage.name);

    try {
        const response = await fetch('update_scene_direct.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            const container = document.getElementById('image_current');
            if (container) {
                container.innerHTML = `
                    <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px; margin-top:10px;">
                        <img src="${selectedImage.path}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                        <span style="color:#059669; font-weight:600;">${selectedImage.name}</span>
                    </div>`;
            }
            document.getElementById('selected_server_image').value = selectedImage.name;
            showToast('✅ Image updated successfully!', 'success');
        } else {
            alert('Error: ' + data.message);
        }
    } catch (error) {
        alert('Network error occurred');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function updateImageButton() {
    const btn = document.getElementById('image-use-top-btn');
    if (btn) btn.style.display = selectedImage ? 'flex' : 'none';
}

// ========== TOAST ==========
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; top: 20px; right: 20px;
        padding: 12px 24px;
        background: ${type === 'success' ? '#10b981' : '#3b82f6'};
        color: white; border-radius: 8px; font-weight: 600;
        z-index: 9999; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ========== SIMPLE MODAL ==========
let currentEditingRow = null;

function simpleOpenModal(rowId) {
    const row = document.getElementById('row-' + rowId);
    if (!row) return;

    currentEditingRow = rowId;
    document.getElementById('simpleSceneId').innerText = rowId;
    document.getElementById('simpleRowId').value = rowId;

    document.getElementById('simpleTextContents').value = document.getElementById('text-' + rowId)?.value || '';
    document.getElementById('simpleTextDisplay').value = row.getAttribute('data-text-display') || '';
    document.getElementById('simplePrompt').value = row.getAttribute('data-prompt') || '';
    document.getElementById('simpleLogoFlag').value = row.getAttribute('data-logo-flag') || '1';

    const imageFile = row.getAttribute('data-image')?.replace('podcast_images/', '') || '';
    const videoFile = row.getAttribute('data-video')?.replace('podcast_videos/', '') || '';

    const imageDiv = document.getElementById('simpleCurrentImage');
    const videoDiv = document.getElementById('simpleCurrentVideo');

    imageDiv.innerHTML = imageFile
        ? `<img src="podcast_images/${imageFile}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;"> <span style="margin-left:8px;">${imageFile}</span>`
        : '<span style="color:#94a3b8;">No image assigned</span>';

    videoDiv.innerHTML = videoFile
        ? `<video src="podcast_videos/${videoFile}?v=${Date.now()}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" muted></video> <span style="margin-left:8px;">${videoFile}</span>`
        : '<span style="color:#94a3b8;">No video assigned</span>';

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

    const formData = new FormData();
    formData.append('row_id', rowId);
    formData.append('text_contents', document.getElementById('simpleTextContents').value);
    formData.append('text_display', document.getElementById('simpleTextDisplay').value);
    formData.append('prompt', document.getElementById('simplePrompt').value);
    formData.append('logo_flag', document.getElementById('simpleLogoFlag').value);

    try {
        const response = await fetch('simple_update_scene.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            const textarea = document.getElementById('text-' + rowId);
            if (textarea) textarea.value = formData.get('text_contents');

            const row = document.getElementById('row-' + rowId);
            if (row) {
                row.setAttribute('data-text-display', formData.get('text_display'));
                row.setAttribute('data-prompt', formData.get('prompt'));
                row.setAttribute('data-logo-flag', formData.get('logo_flag'));
                row.style.transition = 'background 0.5s';
                row.style.backgroundColor = '#dcfce7';
                setTimeout(() => { row.style.backgroundColor = ''; }, 1500);
            }

            statusDiv.innerHTML = '✅ Saved successfully!';
            statusDiv.style.color = '#059669';
            setTimeout(() => simpleCloseModal(), 1000);
        } else {
            statusDiv.innerHTML = '❌ Error: ' + (data.message || 'Unknown error');
            statusDiv.style.color = '#dc2626';
        }
    } catch (error) {
        statusDiv.innerHTML = '❌ Network error';
        statusDiv.style.color = '#dc2626';
    }
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', () => {
    loadSettings();
    updateTotalProjectTime();
    updateLogoPreview();

    // ===== ROW CLICK HANDLERS (single clean implementation) =====
    document.querySelectorAll('.scene-row').forEach(row => {
        row.addEventListener('click', function (e) {
            // Ignore clicks on interactive elements
            if (['BUTTON', 'INPUT', 'TEXTAREA', 'SELECT', 'A'].includes(e.target.tagName)) return;

            const rowId = this.id.replace('row-', '');

            // Update active state
            document.querySelectorAll('.scene-row').forEach(r => r.classList.remove('row-active'));
            this.classList.add('row-active');

            // Update canvas monitor
            updateMonitorMedia(rowId);
        });
    });

    // Activate first row on load
    const firstRow = document.querySelector('.scene-row');
    if (firstRow) {
        const rowId = firstRow.id.replace('row-', '');
        firstRow.classList.add('row-active');
        updateMonitorMedia(rowId);
    }

    // Textarea focus also updates monitor
    document.querySelectorAll('.script-input').forEach(textarea => {
        const rowId = textarea.id.replace('text-', '');
        textarea.addEventListener('focus', () => {
            document.querySelectorAll('.scene-row').forEach(r => r.classList.remove('row-active'));
            const row = document.getElementById('row-' + rowId);
            if (row) row.classList.add('row-active');
            updateMonitorMedia(rowId);
        });
    });

    // Start canvas idle loop
    const canvas = document.getElementById('exportCanvas');
    const ctx = canvas.getContext('2d');

    function idleLoop() {
        drawToCanvas(canvas, ctx);
        requestAnimationFrame(idleLoop);
    }
    idleLoop();

    // Edit form submit
    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const submitBtn = this.querySelector('button[type="submit"]');
            const rowId = document.getElementById('edit_row_id').value;
            const formData = new FormData(this);

            submitBtn.disabled = true;
            submitBtn.innerText = "Saving...";

            fetch('update_scene.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const textarea = document.getElementById(`text-${rowId}`);
                        if (textarea) textarea.value = formData.get('text_contents');

                        const statusCell = document.getElementById(`status-${rowId}`);
                        if (statusCell) statusCell.innerHTML = '<span class="status-badge">⏳ Pending (Updated)</span>';

                        const row = document.getElementById(`row-${rowId}`);
                        if (row) {
                            if (data.new_image) row.setAttribute('data-image', 'podcast_images/' + data.new_image);
                            if (data.new_video) row.setAttribute('data-video', 'podcast_videos/' + data.new_video);

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

// ===== SINGLE window.onclick for modals =====
window.onclick = function (event) {
    const simpleModal = document.getElementById('simpleEditModal');
    const editModal = document.getElementById('editModal');

    if (simpleModal && event.target === simpleModal) simpleCloseModal();
    if (editModal && event.target === editModal && typeof closeModal === 'function') closeModal();
};
