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
        // Logo settings
        logoSize: document.getElementById('logoSizePicker') ? document.getElementById('logoSizePicker').value : '60',
        logoPosition: document.getElementById('logoPositionPicker') ? document.getElementById('logoPositionPicker').value : 'top'
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
        if (s.fontBgOpacity) { document.getElementById('fontBgOpacity').value = s.fontBgOpacity; document.getElementById('bgOpacityVal').innerText = s.fontBgOpacity; }
        if (s.lineSpacing) document.getElementById('lineSpacingPicker').value = s.lineSpacing;
        if (s.paraSpacing) document.getElementById('paraSpacingPicker').value = s.paraSpacing;
        if (s.textPosition) document.getElementById('textPositionPicker').value = s.textPosition;
        if (s.rate) document.getElementById('ratePicker').value = s.rate;
        if (s.textStyle) document.getElementById('textStylePicker').value = s.textStyle;
        if (s.textSpeed) document.getElementById('textSpeedOffset').value = s.textSpeed;
        // Logo settings
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

    // Update text position on the preview monitor
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

// 1. PROJECT TIME CALCULATORS
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
