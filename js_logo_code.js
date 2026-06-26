/**
 * Logo Management Script
 * Handles custom logo uploads, default state, and live preview rendering.
 */

// Initialize global state if not already defined by another script
window.logoState = window.logoState || {
    logoImg: null,
    showDefaultLogo: true,
    logoPosition: 'top-left',
    logoSize: 100, // Default base size
    opacity: 1.0
};

/**
 * Handles the file input change for logo uploads
 */
window.handleLogoUpload = function(input) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (e) => {
        const img = new Image();
        img.src = e.target.result;
        img.onload = () => {
            window.logoState.logoImg = img;
            window.logoState.showDefaultLogo = false;
            
            const statusLabel = document.getElementById('logoStatusText');
            if (statusLabel) {
                statusLabel.innerText = '✓ Custom: ' + file.name;
            }
            
            window.updateLogoPreview();
        };
    };
    reader.readAsDataURL(file);
};

/**
 * Reverts the logo to the default "StressReleasor" branding
 */
window.resetToDefaultLogo = function() {
    window.logoState.showDefaultLogo = true;
    window.logoState.logoImg = null;
    
    const statusLabel = document.getElementById('logoStatusText');
    if (statusLabel) {
        statusLabel.innerText = '✓ StressReleasor Logo Active';
    }
    
    const uploadInput = document.getElementById('logoUpload');
    if (uploadInput) uploadInput.value = '';
    
    window.updateLogoPreview();
};

/**
 * Updates the visual preview in the 'monitor' or preview overlay
 */
window.updateLogoPreview = function() {
    const overlay = document.getElementById('logoPreviewOverlay');
    const content = document.getElementById('logoPreviewContent');
    if (!overlay || !content) return;

    const logoS = window.logoState;
    const pos = logoS.logoPosition || 'top-left';
    const size = (logoS.logoSize || 100) * 0.5; // Scale down for 360px monitor

    // Reset styles before applying new positioning
    overlay.style.top = 'auto';
    overlay.style.bottom = 'auto';
    overlay.style.left = 'auto';
    overlay.style.right = 'auto';
    overlay.style.transform = 'none';

    // Apply logical positioning
    if (pos.includes('top')) overlay.style.top = '10px';
    if (pos.includes('bottom')) overlay.style.bottom = '10px';
    
    if (pos.includes('left')) {
        overlay.style.left = '10px';
    } else if (pos.includes('right')) {
        overlay.style.right = '10px';
    } else if (pos === 'top' || pos === 'bottom') {
        overlay.style.left = '50%';
        overlay.style.transform = 'translateX(-50%)';
    }

    // Render Content
    if (logoS.showDefaultLogo) {
        content.innerHTML = `
            <div style="text-align:center; font-family:'Inter',sans-serif; pointer-events:none;">
                <div style="font-size:${size * 0.4}px; line-height:1;">🛟</div>
                <div style="font-size:${size * 0.3}px; font-weight:900; color:#10b981; margin-top:3px; line-height:1;">StressReleasor</div>
                <div style="font-size:${size * 0.15}px; color:white; margin-top:2px; opacity:0.8; line-height:1;">Always here to help & support</div>
            </div>
        `;
    } else if (logoS.logoImg && logoS.logoImg.complete) {
        const aspectRatio = logoS.logoImg.width / logoS.logoImg.height;
        const h = size;
        const w = aspectRatio * h;
        content.innerHTML = `<img src="${logoS.logoImg.src}" style="width:${w}px; height:${h}px; display:block; object-fit:contain;">`;
    } else {
        content.innerHTML = '';
    }
};