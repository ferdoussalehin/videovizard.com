function loadSceneCaptions(sceneId) {
    sceneCaptions = ALL_CAPTIONS.filter(c => +c.story_id === +sceneId);
    sceneCaptions.forEach(c => {
        if (!captionStates[c.id])
            captionStates[c.id] = { show:'', full:'', words:[], karIdx:0, timer:null };
        if (c.is_visible) startCaptionAnim(c);
    });
    renderCaptionTabs();
    if (selectedCapId && sceneCaptions.find(c => +c.id === +selectedCapId)) {
        selectCaption(selectedCapId);
    } else {
        selectedCapId = null;
        showCaptionEditor(false);
    }
}

// ── Caption animation ──────────────────────────────────────────────
function stopCaptionAnim(capId) {
    const st = captionStates[capId];
    if (!st) return;
    if (st.timer) { clearInterval(st.timer); clearTimeout(st.timer); st.timer = null; }
}
// Returns the correct folder (with trailing slash) for a specific slot on a scene.
// If slot not provided, falls back to sc.image_folder (main slot).
function getSceneFolder(sc, slot) {
    if (!sc) return IMG_BASE;
    const folderCol = SLOT_FOLDER_COL[slot] || 'image_folder';
    const raw = (sc[folderCol] || sc.image_folder || '').trim().replace(/^\/|\/$/g, '');
    const folder = (raw || 'podcast_images') + '/';
    // Re-register all slots into FILE_FOLDER so getFileFolder stays current
    Object.entries(SLOT_FOLDER_COL).forEach(function(entry) {
        const s = entry[0], fc = entry[1];
        const fn = (sc[s] || '').trim();
        if (!fn) return;
        const f = (sc[fc] || sc.image_folder || '').trim().replace(/^\/|\/$/g, '');
        FILE_FOLDER[fn] = (f || 'podcast_images') + '/';
    });
    return folder;
}
// Folder lookup by filename alone (falls back to IMG_BASE)
function getFileFolder(fn) {
    return FILE_FOLDER[fn] || IMG_BASE;
}
function getImageSrc(fn, sc, slot) {
    if (!fn) return '';
    if (fn.startsWith('logo_')) return 'podcast_logos/' + fn;
    return getSceneFolder(sc, slot) + fn;
}

// Add this helper to check if video is truly ready
function isVideoReady(video) {
    return video && 
           video.readyState >= 2 && 
           video.videoWidth > 0 && 
           video.videoHeight > 0;
}

// Update the prepareNextScene function to ensure video is ready
async function prepareNextScene(nextIndex) {
    if (nextIndex < 0 || nextIndex >= SCENES.length) return;
    
    const sc = SCENES[nextIndex];
    const { fn, isVideo, slot } = sceneMedia(sc);
    
    if (isVideo && fn) {
        // Prepare video
        if (!vidEls[fn]) {
            const v = document.createElement('video');
            v.muted = true;
            v.loop = !isTalkingHeadScene(sc);
            v.playsInline = true;
            v.crossOrigin = 'anonymous';
            v.preload = 'auto';
            v.src = getSceneFolder(sc, slot) + fn;
            document.getElementById('vidPool').appendChild(v);
            vidEls[fn] = v;
        }
        
        const video = vidEls[fn];
        
        // Wait for video to have valid dimensions
        if (!isVideoReady(video)) {
            await new Promise(resolve => {
                const checkReady = () => {
                    if (isVideoReady(video)) {
                        resolve();
                    } else {
                        setTimeout(checkReady, 50);
                    }
                };
                
                const timeout = setTimeout(() => resolve(), 2000);
                video.addEventListener('loadeddata', () => {
                    clearTimeout(timeout);
                    resolve();
                }, { once: true });
                
                checkReady();
                video.load();
            });
        }
        
        S.nextType = 'video';
        S.nextVid = video;
        S.nextImg = null;
        
        // Position video at start and ensure it's ready to play
        video.currentTime = 0;
        if (video.paused && isVideoReady(video)) {
            video.play().catch(() => {});
        }
        
    } else if (fn && imgCache[fn]) {
        // Image already loaded
        S.nextType = 'image';
        S.nextImg = imgCache[fn];
        S.nextVid = null;
    } else if (fn) {
        // Load image if not cached
        const img = new Image();
        img.crossOrigin = 'anonymous';
        await new Promise(resolve => {
            img.onload = () => {
                imgCache[fn] = img;
                S.nextType = 'image';
                S.nextImg = img;
                S.nextVid = null;
                resolve();
            };
            img.onerror = resolve;
            setTimeout(resolve, 2000);
            img.src = getImageSrc(fn, sc, slot);
        });
    }
}
function startCaptionAnim(cap) {
    const id = cap.id;
    // Restore extra vertical padding from DB rotation field
    if(cap._extraVPad === undefined) cap._extraVPad = parseInt(cap.rotation) || 0;
    stopCaptionAnim(id);
    const st = captionStates[id] || (captionStates[id] = { show:'', full:'', words:[], karIdx:0, timer:null });
    const text  = cap.text_content || '';
    st.full   = text;
    st.words  = text.split(' ');
    st.karIdx = 0;
    st.show   = '';
    const style = cap.animation_style || 'none';
    const spd   = parseFloat(cap.animation_speed) || 1;

    if (['static','none','fade-in','zoom-in','pop','bounce'].includes(style)) { st.show = text; return; }
    if (style === 'typewriter' || style === 'char-by-char') {
        let i = 0; const ms = Math.round((style==='char-by-char'?60:36)/spd);
        st.timer = setInterval(() => { st.show = text.substring(0,++i); if(i>=text.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    if (style === 'word-reveal') {
        let wi = 0; const ms = Math.round(140/spd);
        st.timer = setInterval(() => { st.show = st.words.slice(0,++wi).join(' '); if(wi>=st.words.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    if (style === 'line-by-line') {
        const chunk=6, chunks=[];
        for(let i=0;i<st.words.length;i+=chunk) chunks.push(st.words.slice(i,i+chunk).join(' '));
        let ci=0; st.show = chunks[ci++]||'';
        const ms = Math.round(900/spd);
        st.timer = setInterval(() => { if(ci>=chunks.length){clearInterval(st.timer);st.timer=null;return;} st.show=chunks[ci++]; }, ms); return;
    }
    if (style === 'karaoke') {
        st.show = text; st.karIdx = 0;
        const ms = Math.round(320/spd);
        st.timer = setInterval(() => { st.karIdx++; if(st.karIdx>=st.words.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    st.show = text;
}

// ── Draw all captions ──────────────────────────────────────────────
function drawAllCaptions() {
    sceneCaptions.forEach(cap => drawOneCaption(cap));
    if (selectedCapId) drawSelectionHandles(selectedCapId);
}

// ── Activity Logger ───────────────────────────────────────────────
function logActivity(action_type, action_detail = '', scene_index = null) {
    const fd = new FormData();
    fd.append('ajax_action',   'log_activity');
    fd.append('podcast_id',    PODCAST_ID);
    fd.append('action_type',   action_type);
    fd.append('action_detail', action_detail);
    if (scene_index !== null) fd.append('scene_index', scene_index);
    fetch(location.href, { method: 'POST', body: fd }).catch(() => {}); // fire-and-forget
}

function drawOneCaption(cap) {
    if (!cap.is_visible && !cap._forceShow) return;

    // ── IMAGE CAPTION ──────────────────────────────────────────
    if (cap.caption_type === 'image') {
        const fn  = cap.text_content || '';
        const px  = parseInt(cap.position_x) || 20;
        const py  = parseInt(cap.position_y) || 20;
        const pw  = parseInt(cap.width)      || 120;
        const ph  = parseInt(cap.rotation)   || 120;  // height stored in rotation column
        cap._bbox = { x:px, y:py, w:pw, h:ph };

        const img = imgCache[fn];
        if (img === null) {
            // Image previously failed to load (404 etc) — draw placeholder, never retry
            ctx.save();
            ctx.fillStyle = 'rgba(100,100,100,0.25)';
            ctx.fillRect(px, py, pw, ph);
            ctx.restore();
        } else if (img) {
            ctx.save();
            ctx.drawImage(img, px, py, pw, ph);
            ctx.restore();
        } else {
            // Placeholder while loading
            ctx.save();
            ctx.fillStyle = 'rgba(100,100,100,0.4)';
            ctx.fillRect(px, py, pw, ph);
            ctx.fillStyle = '#fff';
            ctx.font = '11px Inter';
            ctx.textAlign = 'center';
            ctx.fillText('🖼️', px + pw/2, py + ph/2);
            ctx.restore();
            // Trigger async load — guard with _loading_ so only one request fires
            if (!imgCache['_loading_'+fn]) {
                imgCache['_loading_'+fn] = true;
                const i = new Image();
                i.crossOrigin = 'anonymous';
                i.onload  = () => { imgCache[fn] = i; delete imgCache['_loading_'+fn]; };
                i.onerror = () => { imgCache[fn] = null; delete imgCache['_loading_'+fn]; }; // null = failed, stop retrying
                i.src = getFileFolder(fn) + fn;
            }
        }
        return;  // ← skip all text drawing
    }

    // ... rest of existing drawOneCaption text logic unchanged
    const st = captionStates[cap.id];
    if (!st) return;
    const text = st.show || '';
    if (!text.trim()) { cap._bbox = null; return; }

    const fs        = parseInt(cap.fontsize) || 22;
    const extraVPad = cap._extraVPad ?? parseInt(cap.rotation) ?? 0;
    const pad       = 10 + Math.round(extraVPad / 2);
    const lh        = fs + 7;
    const maxW      = parseInt(cap.width)      || 320;
    const posX      = parseInt(cap.position_x) || 50;
    const posY      = parseInt(cap.position_y) || 400;
    const tAlign    = cap.text_align || 'center';
    const bold      = (cap.fontweight === 'bold' || cap.fontweight === '700') ? 'bold ' : '';
    const italic    = cap.fontstyle === 'italic' ? 'italic ' : '';

    // ── FONT DEBUG ────────────────────────────────────────────────
    // Normalize bare font names that DB may store without CSS stack
    const _fontNorm = {
        // System
        'Arial':                'Arial,sans-serif',
        'Helvetica':            'Helvetica,sans-serif',
        'Verdana':              'Verdana,sans-serif',
        'Georgia':              'Georgia,serif',
        'Impact':               'Impact,fantasy',
        'Courier New':          "'Courier New',monospace",
        'Times New Roman':      "'Times New Roman',serif",
        'Segoe UI':             "'Segoe UI',sans-serif",
        'Inter':                "'Inter',sans-serif",
        'Comic Sans MS':        "'Comic Sans MS',cursive",
        // Sans Serif
        'Poppins':              "'Poppins',sans-serif",
        'Montserrat':           "'Montserrat',sans-serif",
        'Raleway':              "'Raleway',sans-serif",
        'Oswald':               "'Oswald',sans-serif",
        'Anton':                "'Anton',sans-serif",
        'Righteous':            "'Righteous',sans-serif",
        'Black Han Sans':       "'Black Han Sans',sans-serif",
        'Josefin Sans':         "'Josefin Sans',sans-serif",
        'Barlow Condensed':     "'Barlow Condensed',sans-serif",
        'DM Sans':              "'DM Sans',sans-serif",
        'Jost':                 "'Jost',sans-serif",
        'Space Grotesk':        "'Space Grotesk',sans-serif",
        'Syne':                 "'Syne',sans-serif",
        'Tenor Sans':           "'Tenor Sans',sans-serif",
        // Serif
        'Playfair Display':     "'Playfair Display',serif",
        'Lora':                 "'Lora',serif",
        'Libre Baskerville':    "'Libre Baskerville',serif",
        'Crimson Pro':          "'Crimson Pro',serif",
        'EB Garamond':          "'EB Garamond',serif",
        'Cormorant Garamond':   "'Cormorant Garamond',serif",
        'Cormorant SC':         "'Cormorant SC',serif",
        'Roboto Slab':          "'Roboto Slab',serif",
        'DM Serif Display':     "'DM Serif Display',serif",
        'Alfa Slab One':        "'Alfa Slab One',serif",
        'Cinzel':               "'Cinzel',serif",
        'Italiana':             "'Italiana',serif",
        // Display / Promotional
        'Bebas Neue':           "'Bebas Neue',sans-serif",
        'Bangers':              "'Bangers',cursive",
        'Luckiest Guy':         "'Luckiest Guy',cursive",
        'Black Ops One':        "'Black Ops One',cursive",
        'Russo One':            "'Russo One',sans-serif",
        'Teko':                 "'Teko',sans-serif",
        'Boogaloo':             "'Boogaloo',cursive",
        'Fredoka One':          "'Fredoka One',cursive",
        'Lilita One':           "'Lilita One',cursive",
        'Poiret One':           "'Poiret One',cursive",
        // Handwriting & Calligraphy
        'Dancing Script':       "'Dancing Script',cursive",
        'Pacifico':             "'Pacifico',cursive",
        'Lobster':              "'Lobster',cursive",
        'Permanent Marker':     "'Permanent Marker',cursive",
        'Caveat':               "'Caveat',cursive",
        'Great Vibes':          "'Great Vibes',cursive",
        'Alex Brush':           "'Alex Brush',cursive",
        'Pinyon Script':        "'Pinyon Script',cursive",
        'Sacramento':           "'Sacramento',cursive",
        'Satisfy':              "'Satisfy',cursive",
        'Kaushan Script':       "'Kaushan Script',cursive",
        'Yellowtail':           "'Yellowtail',cursive",
        'Allura':               "'Allura',cursive",
        'Marck Script':         "'Marck Script',cursive",
        'Italianno':            "'Italianno',cursive",
        'Mr Dafoe':             "'Mr Dafoe',cursive",
        'Euphoria Script':      "'Euphoria Script',cursive",
        // Custom local fonts
        'NotoNastaliqUrdu':     "'NotoNastaliqUrdu',serif",
        'AttariQuraanWord':     "'AttariQuraanWord',serif",
    };
    let rawFamily = cap.fontfamily || '';
    let family    = _fontNorm[rawFamily] || rawFamily || 'Arial,sans-serif';

    // Log font info to server for every caption on first render
    if (!cap._fontLogged) {
        cap._fontLogged = true;
        const logData = new FormData();
        logData.append('ajax_action',  'debug_log');
        logData.append('cap_id',       cap.id);
        logData.append('cap_name',     cap.caption_name   || '');
        logData.append('cap_type',     cap.caption_type   || '');
        logData.append('raw_family',   rawFamily);
        logData.append('used_family',  family);
        logData.append('fontsize',     cap.fontsize       || '');
        logData.append('fontcolor',    cap.fontcolor      || '');
        logData.append('story_id',     cap.story_id       || '');
        logData.append('podcast_id',   cap.podcast_id     || '');
        fetch(location.href, { method: 'POST', body: logData }).catch(() => {});
    }
    // ── END FONT DEBUG ────────────────────────────────────────────

    ctx.save();
    ctx.font = italic + bold + fs + 'px ' + family;

    // Split on hard line breaks first, then wrap each paragraph
    const paragraphs = text.split('\n');
    const lines = [];
    paragraphs.forEach(para => {
        const trimmed = para.trim();
        if (!trimmed) { lines.push(''); return; } // preserve blank lines as spacers
        const words = trimmed.split(' ');
        let ln = '';
        words.forEach(w => {
            const t = ln ? ln + ' ' + w : w;
            if (ctx.measureText(t).width > maxW && ln) { lines.push(ln); ln = w; } else ln = t;
        });
        if (ln) lines.push(ln);
    });

    const bh = lines.length * lh + pad * 2;
    const bw = maxW;
    cap._bbox = { x: posX, y: posY, w: bw, h: bh };



    const bgOn     = (cap.bg_enabled === 1 || cap.bg_enabled === '1' || cap.bg_enabled === true);
    const bdrThick = parseInt(cap.caption_box_border_thickness) || 0;
    const bdrColor = cap.caption_box_border_color || '#ffffff';

    if (bgOn || bdrThick > 0) {
        ctx.save();
        rrect(ctx, posX, posY, bw, bh, 10);
        if (bgOn) {
            const br = parseInt((cap.bg_color || '#000000').slice(1,3), 16);
            const bg = parseInt((cap.bg_color || '#000000').slice(3,5), 16);
            const bb = parseInt((cap.bg_color || '#000000').slice(5,7), 16);
            ctx.fillStyle = `rgba(${br},${bg},${bb},${parseFloat(cap.bg_opacity) || 0.7})`;
            ctx.fill();
        }
        if (bdrThick > 0) {
            ctx.strokeStyle = bdrColor;
            ctx.lineWidth   = bdrThick;
            ctx.lineJoin    = 'round';
            ctx.stroke();
        }
        ctx.restore();
    }

    let tx, ta;
    if      (tAlign === 'left')  { tx = posX + pad;      ta = 'left';   }
    else if (tAlign === 'right') { tx = posX + bw - pad; ta = 'right';  }
    else                         { tx = posX + bw / 2;   ta = 'center'; }
    ctx.textAlign = ta;

    const fx       = _capEffect(cap);
    let gradFill   = null;
    if (fx === 'gradient') {
        const gr = ctx.createLinearGradient(posX, 0, posX + bw, 0);
        gr.addColorStop(0,   '#ff6b6b');
        gr.addColorStop(.33, '#ffd93d');
        gr.addColorStop(.66, '#6bcb77');
        gr.addColorStop(1,   '#4d96ff');
        gradFill = gr;
    }

    lines.forEach((line, i) => {
        const ty = posY + pad + fs + i * lh;
        ctx.shadowBlur = 0; ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
        if      (fx === 'shadow') { ctx.shadowColor = 'rgba(0,0,0,.95)'; ctx.shadowBlur = 8; ctx.shadowOffsetX = 2; ctx.shadowOffsetY = 2; }
        else if (fx === 'glow')   { ctx.shadowColor = cap.fontcolor || '#fff'; ctx.shadowBlur = 22; }
        else if (fx === '3d')     { ctx.shadowColor = 'rgba(0,0,0,.65)'; ctx.shadowOffsetX = 3; ctx.shadowOffsetY = 3; }
        if (fx === 'outline' || fx === 'stroke') {
            ctx.shadowBlur = 0; ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
            ctx.strokeStyle = cap.stroke_color || '#000';
            ctx.lineWidth   = (parseInt(cap.stroke_width) || 2) * 2;
            ctx.lineJoin    = 'round';
            ctx.strokeText(line, tx, ty);
        }
        ctx.fillStyle = gradFill || (cap.fontcolor || '#ffffff');
        ctx.fillText(line, tx, ty);
        if (cap.underline) {
            const tw = ctx.measureText(line).width;
            const ux = ta === 'center' ? tx - tw/2 : ta === 'right' ? tx - tw : tx;
            ctx.beginPath(); ctx.moveTo(ux, ty + 2); ctx.lineTo(ux + tw, ty + 2);
            ctx.strokeStyle = cap.fontcolor || '#fff'; ctx.lineWidth = 1; ctx.stroke();
        }
    });
    ctx.restore();
}




function _capEffect(cap) {
    if (cap.outline_enabled && +cap.outline_width > 0) return 'outline';
    if (cap.stroke_enabled  && +cap.stroke_width  > 0) return 'stroke';
    return cap._uiEffect || 'none';
}

function drawSelectionHandles(capId) {
    const cap = sceneCaptions.find(c=>+c.id===+capId);
    if (!cap || !cap._bbox) return;
    const {x,y,w,h} = cap._bbox;
    ctx.save();
    ctx.strokeStyle='#3b82f6'; ctx.lineWidth=2; ctx.setLineDash([4,3]);
    ctx.strokeRect(x-2,y-2,w+4,h+4);
    ctx.setLineDash([]);

    // Unified handles for ALL caption types: 4 corners + right-center + bottom-center
    const hs = 12, off = 2;
    const handles = [
        { cx: x - off - hs/2,   cy: y - off - hs/2,   dir:'nw', arrow:'↖' },
        { cx: x + w + off+hs/2, cy: y - off - hs/2,   dir:'ne', arrow:'↗' },
        { cx: x - off - hs/2,   cy: y + h + off+hs/2, dir:'sw', arrow:'↙' },
        { cx: x + w + off+hs/2, cy: y + h + off+hs/2, dir:'se', arrow:'↘' },
        { cx: x + w + off+hs/2, cy: y + h/2,           dir:'e',  arrow:'↔' },
        { cx: x + w/2,          cy: y + h + off+hs/2, dir:'s',  arrow:'↕' },
    ];
    handles.forEach(({cx,cy,dir,arrow}) => {
        const hx = cx - hs/2, hy = cy - hs/2;
        ctx.fillStyle = (dir==='s') ? '#10b981' : '#3b82f6';
        ctx.fillRect(hx, hy, hs, hs);
        ctx.strokeStyle='#fff'; ctx.lineWidth=1.5;
        ctx.strokeRect(hx+1, hy+1, hs-2, hs-2);
        ctx.fillStyle='#fff'; ctx.font='bold 8px Inter';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        ctx.fillText(arrow, cx, cy);
    });
    ctx.restore();
}

// ── Canvas mouse events ────────────────────────────────────────────
function _canvasPos(e) {
    const rect=canvas.getBoundingClientRect();
    return { x:(e.clientX-rect.left)*(CW/rect.width), y:(e.clientY-rect.top)*(CH/rect.height) };
}
function _hitCap(x,y) {
    for(let i=sceneCaptions.length-1;i>=0;i--){
        const c=sceneCaptions[i];
        if(!+c.is_visible) continue;
        // For image captions with no _bbox yet, synthesize from stored fields
        const bbox = c._bbox || (c.caption_type==='image' ? {
            x: parseInt(c.position_x)||20,
            y: parseInt(c.position_y)||20,
            w: parseInt(c.width)||120,
            h: parseInt(c.rotation)||120
        } : null);
        if(!bbox) continue;
        if(x>=bbox.x&&x<=bbox.x+bbox.w&&y>=bbox.y&&y<=bbox.y+bbox.h)return c.id;
    }
    return null;
}
function _isResizeHandle(capId,x,y){
    const cap=sceneCaptions.find(c=>+c.id===+capId);
    if(!cap||!cap._bbox)return false;
    const{x:bx,y:by,w,h}=cap._bbox;
    const hs=12, off=2, tol=10;
    // Handle centers — must match drawSelectionHandles exactly
    const handles=[
        {cx:bx-off-hs/2,       cy:by-off-hs/2,       dir:'nw'},
        {cx:bx+w+off+hs/2,     cy:by-off-hs/2,       dir:'ne'},
        {cx:bx-off-hs/2,       cy:by+h+off+hs/2,     dir:'sw'},
        {cx:bx+w+off+hs/2,     cy:by+h+off+hs/2,     dir:'se'},
        {cx:bx+w+off+hs/2,     cy:by+h/2,             dir:'e'},
        {cx:bx+w/2,            cy:by+h+off+hs/2,     dir:'s'},
    ];
    for(const{cx,cy,dir}of handles){
        if(Math.hypot(x-cx,y-cy)<tol+hs/2)return dir;
    }
    return false;
}

canvas.addEventListener('mousedown',e=>{
    const{x,y}=_canvasPos(e);

    // Check resize handles first — works on already-selected caption
    const rh=selectedCapId&&_isResizeHandle(selectedCapId,x,y);
    if(rh){
        const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);
        // For all caption types, actual rendered height comes from _bbox
        const bboxH  = cap._bbox ? cap._bbox.h : _capBoxH(cap);
        const storedH= parseInt(cap.rotation)||0;
        // origBaseH = natural text height without extra padding (for text caps)
        // For image caps rotation IS the height, so origBaseH == storedH
        const extraVPad = cap._extraVPad ?? (cap.caption_type==='image' ? 0 : storedH);
        const baseH  = cap.caption_type==='image' ? storedH : Math.max(20, bboxH - extraVPad);
        _resize={active:true,dir:rh,capId:selectedCapId,
                 startX:x,startY:y,
                 origW:   parseInt(cap.width)      || 120,
                 origH:   bboxH,
                 origBaseH: baseH,
                 origPX:  parseInt(cap.position_x) || 0,
                 origPY:  parseInt(cap.position_y) || 0,
                 origExtraVPad: extraVPad};
        e.preventDefault();return;
    }

    // Then check if clicking body of a caption (drag or select)
    const hit=_hitCap(x,y);
    if(hit){
        selectCaption(hit);
        const cap=sceneCaptions.find(c=>+c.id===+hit);
        _drag={active:true,capId:hit,startX:x,startY:y,origX:parseInt(cap.position_x)||50,origY:parseInt(cap.position_y)||400};
    } else {
        selectedCapId=null;
        showCaptionEditor(false);
        renderCaptionTabs();
    }
    e.preventDefault();
});
canvas.addEventListener('mousemove',e=>{
    const{x,y}=_canvasPos(e);
    if(_resize.active){
        const cap=sceneCaptions.find(c=>+c.id===+_resize.capId);
        if(cap){
            const dx=x-_resize.startX, dy=y-_resize.startY;
            const dir=_resize.dir;

            // --- Width changes (e=right, ne, se) — cap right edge at CW-CAP_MARGIN ---
            if(dir==='e'||dir==='ne'||dir==='se'){
                const maxW = CW - CAP_MARGIN - (parseFloat(cap.position_x)||0);
                cap.width=Math.max(40, Math.min(maxW, _resize.origW+dx));
            }
            // --- Width changes from left (nw, sw) — also shift x, keep right edge fixed ---
            if(dir==='nw'||dir==='sw'){
                const nw=Math.max(40, _resize.origW-dx);
                const nx=Math.max(CAP_MARGIN, _resize.origPX+(_resize.origW-nw));
                cap.position_x=nx;
                cap.width=Math.min(nw, CW - CAP_MARGIN - nx);
            }
            // --- Height changes from bottom (s, se, sw) — cap at CH ---
            if(dir==='s'||dir==='se'||dir==='sw'){
                const nh=Math.max(20, Math.min(CH - (parseFloat(cap.position_y)||0), _resize.origH+dy));
                cap.rotation=nh;
                cap._extraVPad=Math.max(0,nh-_resize.origBaseH);
            }
            // --- Height changes from top (ne, nw) — also shift y, stay within canvas ---
            if(dir==='ne'||dir==='nw'){
                const nh=Math.max(20,_resize.origH-dy);
                const ny=Math.max(0, _resize.origPY+(_resize.origH-nh));
                cap.position_y=Math.min(ny, CH-20);
                cap.rotation=nh;
                cap._extraVPad=Math.max(0,nh-_resize.origBaseH);
            }

            ['width','rotation','position_x','position_y'].forEach(f=>{
                if(!_capDirty[cap.id])_capDirty[cap.id]={};
                _capDirty[cap.id][f]=Math.round(parseFloat(cap[f])||0);
            });
            clearTimeout(_capSaveTimers[cap.id]);
            _capSaveTimers[cap.id]=setTimeout(()=>_saveCaption(cap.id),400);
            syncPosInputs(cap);
            capFieldChanged('width',Math.round(cap.width));
        }
        return;
    }
    if(_drag.active){
        const cap=sceneCaptions.find(c=>+c.id===+_drag.capId);
        if(cap){
            const w   = parseFloat(cap.width) || 120;
            const bh  = _capBoxH(cap);
            cap.position_x = Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w,  _drag.origX + (x - _drag.startX)));
            cap.position_y = Math.max(0,           Math.min(CH - Math.max(20,bh), _drag.origY + (y - _drag.startY)));
            syncPosInputs(cap);
            capFieldChanged('position_x',Math.round(cap.position_x));
            capFieldChanged('position_y',Math.round(cap.position_y));
        }
        return;
    }
    const rdir=selectedCapId&&_isResizeHandle(selectedCapId,x,y);
    if(rdir==='e')               {canvas.style.cursor='ew-resize';  return;}
    if(rdir==='s')               {canvas.style.cursor='ns-resize';  return;}
    if(rdir==='nw'||rdir==='se') {canvas.style.cursor='nwse-resize';return;}
    if(rdir==='ne'||rdir==='sw') {canvas.style.cursor='nesw-resize';return;}
    canvas.style.cursor=_hitCap(x,y)?'grab':'default';
});
canvas.addEventListener('mouseup',()=>{ _drag.active=false; _resize.active=false; });
canvas.addEventListener('mouseleave',()=>{ _drag.active=false; _resize.active=false; });

// Touch
canvas.addEventListener('touchstart',e=>{if(e.touches[0])canvas.dispatchEvent(new MouseEvent('mousedown',{clientX:e.touches[0].clientX,clientY:e.touches[0].clientY,bubbles:true}));},{passive:false});
canvas.addEventListener('touchmove', e=>{if(e.touches[0])canvas.dispatchEvent(new MouseEvent('mousemove',{clientX:e.touches[0].clientX,clientY:e.touches[0].clientY,bubbles:true}));e.preventDefault();},{passive:false});
canvas.addEventListener('touchend', ()=>canvas.dispatchEvent(new MouseEvent('mouseup',{})));

// ── Caption panel UI ───────────────────────────────────────────────
function renderCaptionTabs() {
    const tabs=document.getElementById('captionTabs');
    if(!tabs)return;
    if(!sceneCaptions.length){tabs.innerHTML='<span style="font-size:10px;color:var(--muted);">No captions</span>';return;}
    tabs.innerHTML=sceneCaptions.map(c=>{
        const isMain=(c.caption_name||'').toLowerCase()==='main';
        const isSel=+c.id===+selectedCapId;
        return `<button class="cap-tab${isSel?' active':''}" onclick="selectCaption(${c.id})">
            ${isMain?'🔒 ':''}${c.caption_name||'cap'}
            ${!c.is_visible?'<span style="opacity:.5;font-size:9px;">🚫</span>':''}
        </button>`;
    }).join('');
}

function selectCaption(capId) {
    selectedCapId = capId;
    renderCaptionTabs();
    const cap = sceneCaptions.find(c => +c.id === +capId);
    if (!cap) return;
    showCaptionEditor(true);
    populateCaptionEditor(cap);
    // Only open caption panel if NO panel is currently open.
    // Never forcibly close other panels (image, audio) or open caption
    // panel during playback/recording — just update the editor state silently.
    const anyOpen = document.querySelector('.panel.open');
    const capOpen = document.getElementById('pCaption').classList.contains('open');
    if (!anyOpen && !capOpen && !isPlaying && !isRecording) {
        togglePanel('pCaption', 'ibCaption');
    }
}

let _forceCapTab = 0; // 0 = no override, 1 = caption tab, 2 = font tab

function showCaptionEditor(show) {
    const ed = document.getElementById('captionEditor');
    const ns = document.getElementById('captionNoSel');
    if (ed) ed.style.display = show ? 'block' : 'none';
    if (ns) ns.style.display = show ? 'none'  : 'block';
    if (show) switchCapSubTab(_forceCapTab === 2 ? 2 : 1);
}



// ── Image caption editor helpers ───────────────────────────────────
function _populateCapImageEditor(cap) {
    const prev = document.getElementById('capImgPreview');
    if (prev) {
        const fn = cap.text_content || '';
        prev.innerHTML = fn
            ? `<img src="${getFileFolder(fn)+fn}?t=${Date.now()}" style="width:100%;height:100%;object-fit:contain;">`
            : `<span style="color:var(--muted);font-size:24px;">🖼️</span>`;
    }
    const iw = document.getElementById('capImgW');
    const ih = document.getElementById('capImgH');
    if (iw) iw.value = parseInt(cap.width)    || 120;
    if (ih) ih.value = parseInt(cap.rotation) || 120;
}

function capImgResize(dim, val) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    if (dim === 'width')  { cap.width    = parseInt(val)||120; capFieldChanged('width',    cap.width);    }
    if (dim === 'height') { cap.rotation = parseInt(val)||120; capFieldChanged('rotation', cap.rotation); }
}

async function replaceCapImage(source) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    const filename = source === 'upload' ? await _imgCapUpload() : await _imgCapFromLibrary();
    if (!filename) return;
    cap.text_content = filename;
    await _preloadCapImage(cap);
    capFieldChanged('text_content', filename);
    _populateCapImageEditor(cap);
    L('Image replaced', 'ok');
}

function _toHex(color) {
    if (!color) return '#ffffff';
    color = color.trim();
    if (/^#[0-9a-f]{6}$/i.test(color)) return color;
    if (/^#[0-9a-f]{3}$/i.test(color))
        return '#'+color[1]+color[1]+color[2]+color[2]+color[3]+color[3];
    try {
        const c = document.createElement('canvas');
        c.width = c.height = 1;
        const x = c.getContext('2d');
        x.fillStyle = color;
        x.fillRect(0,0,1,1);
        const d = x.getImageData(0,0,1,1).data;
        return '#'+[d[0],d[1],d[2]].map(v=>v.toString(16).padStart(2,'0')).join('');
    } catch(e) { return '#ffffff'; }
}
function populateCaptionEditor(cap) {
    const isMain  = (cap.caption_name || '').toLowerCase() === 'main';
    const isImage = cap.caption_type === 'image';

    // ── Show/hide sections based on caption type ──────────────
    const textSec  = document.getElementById('capTextSection');
    const fontSec  = document.getElementById('capFontSection');
    const styleSec = document.getElementById('capStyleSection');
    const imgSec   = document.getElementById('capImageSection');
    if (textSec)  textSec.style.display  = isImage ? 'none' : 'block';
    if (fontSec)  fontSec.style.display  = isImage ? 'none' : 'block';
    if (styleSec) styleSec.style.display = isImage ? 'none' : 'block';
    if (imgSec)   imgSec.style.display   = isImage ? 'block' : 'none';

    // ── Visibility button (both types) ────────────────────────
    _updateVisBtn(cap);

    // ── Delete button (both types, hidden for main) ───────────
    const dw = document.getElementById('capDeleteWrap');
    if (dw) dw.style.display = isMain ? 'none' : 'block';

    if (isImage) {
        _populateCapImageEditor(cap);
        syncPosInputs(cap);
        return;
    }

    // ── Text caption fields ───────────────────────────────────
    const ta = document.getElementById('capText');
    if (ta) ta.value = cap.text_content || '';

    // Font family — sync custom picker
    const ff = document.getElementById('capFont');
    if (ff) {
        ff.value = cap.fontfamily || 'Arial,sans-serif';
        const lbl = document.getElementById('fontPickerLabel');
        if (lbl) {
            const opt = document.querySelector(`.fp-opt[data-val="${CSS.escape(cap.fontfamily || 'Arial,sans-serif')}"]`);
            lbl.textContent      = opt ? opt.textContent.trim() : (cap.fontfamily || 'Arial').split(',')[0].replace(/'/g,'');
            lbl.style.fontFamily = cap.fontfamily || 'Arial,sans-serif';
        }
        document.querySelectorAll('.fp-opt').forEach(o =>
            o.classList.toggle('selected', o.dataset.val === (cap.fontfamily || 'Arial,sans-serif'))
        );
    }

    // Font size
    const fs = document.getElementById('capSize');
    if (fs) {
        let matched = false;
        Array.from(fs.options).forEach(o => {
            o.selected = (o.value == cap.fontsize);
            if (o.selected) matched = true;
        });
        if (!matched) {
            // Select closest available size
            const target = parseInt(cap.fontsize) || 28;
            let closest = null, closestDiff = Infinity;
            Array.from(fs.options).forEach(o => {
                const diff = Math.abs(parseInt(o.value) - target);
                if (diff < closestDiff) { closestDiff = diff; closest = o; }
            });
            if (closest) closest.selected = true;
        }
    }

    // Colors
    const cc = document.getElementById('capColor');
    const bc = document.getElementById('capBgColor');
    if (cc) cc.value = _toHex(cap.fontcolor || '#ffffff');
    if (bc) bc.value = _toHex(cap.bg_color  || '#000000');

    // BG Enable checkbox
    const bgChk     = document.getElementById('capBgEnabled');
    const bgLbl     = document.getElementById('capBgEnableLabel');
    const bgEnabled = (cap.bg_enabled === 1 || cap.bg_enabled === '1' || cap.bg_enabled === true);
    if (bgChk) bgChk.checked = bgEnabled;
    if (bgLbl) bgLbl.style.color = bgEnabled ? 'var(--info)' : 'var(--muted)';

    // BG Opacity
    const ba = document.getElementById('capBgAlpha');
    const bv = document.getElementById('capBgAlphaVal');
    if (ba) {
        ba.value = Math.round((parseFloat(cap.bg_opacity) || 0.7) * 100);
        if (bv) bv.textContent = ba.value + '%';
    }

    // Style toggles
    document.getElementById('capBold')     ?.classList.toggle('on', cap.fontweight === 'bold' || cap.fontweight === '700');
    document.getElementById('capItalic')   ?.classList.toggle('on', cap.fontstyle === 'italic');
    document.getElementById('capUnderline')?.classList.toggle('on', !!+cap.underline);

    // Text align
    ['left','center','right','justify'].forEach(a => {
        document.getElementById('capTa' + a.charAt(0).toUpperCase() + a.slice(1))
            ?.classList.toggle('on', a === (cap.text_align || 'center'));
    });

    // Animation
    const ca = document.getElementById('capAnim');
    if (ca) Array.from(ca.options).forEach(o => o.selected = o.value === (cap.animation_style || 'none'));

    const cas = document.getElementById('capAnimSpeed');
    if (cas) {
        const spd = parseFloat(cap.animation_speed) || 1;
        cas.value = Math.min(4, Math.max(0.2, spd));
        const sv = document.getElementById('capAnimSpeedVal');
        if (sv) sv.textContent = parseFloat(cas.value).toFixed(1) + 'x';
    }

    // Position inputs
    // ── Border fields ──────────────────────────────────────────────
		const _bcol  = document.getElementById('capBorderColor');
		const _bthk  = document.getElementById('capBorderThick');
		const _bthkv = document.getElementById('capBorderThickVal');
		const _bprev = document.getElementById('capBorderPreview');
		const _borderColor = _toHex(cap.caption_box_border_color || '#ffffff');
		const _borderThick = parseInt(cap.caption_box_border_thickness) || 0;
		if (_bcol)  _bcol.value        = _borderColor;
		if (_bthk)  _bthk.value        = _borderThick;
		if (_bthkv) _bthkv.textContent = _borderThick + 'px';
		if (_bprev) {
			_bprev.style.borderWidth = _borderThick + 'px';
			_bprev.style.borderColor = _borderColor;
			_bprev.style.borderStyle = _borderThick > 0 ? 'solid' : 'none';
		}

		// Position inputs
		syncPosInputs(cap);
		_applyToAllScenes = GLOBAL_CAP_NAMES.includes((cap.caption_name || '').toLowerCase().trim());
		_updateGlobalLabel();
	}


function syncPosInputs(cap) {
    const px=document.getElementById('capPosX');const py=document.getElementById('capPosY');const pw=document.getElementById('capWidth');
    const xl=document.getElementById('capPosXLbl');const yl=document.getElementById('capPosYLbl');
    const rx=Math.round(cap.position_x||0), ry=Math.round(cap.position_y||0);
    if(px)px.value=rx;
    if(py)py.value=ry;
    if(pw)pw.value=Math.round(cap.width||320);
    if(xl)xl.textContent=rx;
    if(yl)yl.textContent=ry;
}

function _updateVisBtn(cap) {
    const vb=document.getElementById('capVisBtn');
    if(!vb)return;
    vb.textContent=cap.is_visible?'👁 Visible':'🚫 Hidden';
    vb.style.background=cap.is_visible?'var(--success)':'var(--muted)';
}

function setCapTextColor(hex){
    const inp=document.getElementById('capColor');if(inp)inp.value=hex;
    capFieldChanged('fontcolor',hex);
}

function toggleCapStyle(s){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    if(s==='bold'){
        const now=(cap.fontweight==='bold'||cap.fontweight==='700');
        cap.fontweight=now?'normal':'bold';
        document.getElementById('capBold')?.classList.toggle('on',!now);
        capFieldChanged('fontweight',cap.fontweight);
    }else if(s==='italic'){
        const now=cap.fontstyle==='italic';
        cap.fontstyle=now?'normal':'italic';
        document.getElementById('capItalic')?.classList.toggle('on',!now);
        capFieldChanged('fontstyle',cap.fontstyle);
    }else if(s==='underline'){
        cap.underline=cap.underline?0:1;
        document.getElementById('capUnderline')?.classList.toggle('on',!!cap.underline);
        capFieldChanged('underline',cap.underline);
    }
}

function setCapTA(a){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap.text_align=a;
    ['left','center','right'].forEach(n=>document.getElementById('capTa'+n.charAt(0).toUpperCase()+n.slice(1))?.classList.toggle('on',n===a));
    capFieldChanged('text_align',a);
}

function capEffectChanged(val){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap._uiEffect=val;
	logActivity('effect_change', val, currentIndex);
    cap.stroke_enabled=0;cap.outline_enabled=0;
    if(val==='stroke'){cap.stroke_enabled=1;cap.stroke_width=cap.stroke_width||2;capFieldChanged('stroke_enabled',1);capFieldChanged('stroke_width',cap.stroke_width);}
    else if(val==='outline'){cap.outline_enabled=1;cap.outline_width=cap.outline_width||2;capFieldChanged('outline_enabled',1);capFieldChanged('outline_width',cap.outline_width);}
    else{capFieldChanged('stroke_enabled',0);capFieldChanged('outline_enabled',0);}
    document.getElementById('capStrokeColorField').style.display=(val==='outline'||val==='stroke')?'flex':'none';
}

function toggleCapVisible(){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap.is_visible=cap.is_visible?0:1;
    const fd=new FormData();
    fd.append('ajax_action','toggle_caption_visible');
    fd.append('caption_id',cap.id);fd.append('is_visible',cap.is_visible);
    fetch(location.href,{method:'POST',body:fd});
    _updateVisBtn(cap);
    renderCaptionTabs();
}

function capPosInput(field,val){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap[field]=parseFloat(val)||0;
    syncPosInputs(cap);
    capFieldChanged(field,cap[field]);
}

function moveCapArrow(dx,dy){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const w  = parseFloat(cap.width) || 120;
    const bh = _capBoxH(cap);
    cap.position_x=Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w,  (parseFloat(cap.position_x)||0)+dx));
    cap.position_y=Math.max(0,          Math.min(CH - Math.max(20,bh), (parseFloat(cap.position_y)||0)+dy));
    syncPosInputs(cap);
    capFieldChanged('position_x',Math.round(cap.position_x));
    capFieldChanged('position_y',Math.round(cap.position_y));
}

function _capBoxH(cap){
    const bw=parseInt(cap.width)||320,fs=parseInt(cap.fontsize)||22,lh=fs+7;
    const extraVPad=cap._extraVPad??parseInt(cap.rotation)??0;
    const pad=10+Math.round(extraVPad/2);
    const words=(cap.text_content||'').split(' ');
    const _fn={'Arial':'Arial,sans-serif','Helvetica':'Helvetica,sans-serif','Verdana':'Verdana,sans-serif','Georgia':'Georgia,serif','Impact':'Impact,fantasy',"Courier New":"'Courier New',monospace","Times New Roman":"'Times New Roman',serif","Segoe UI":"'Segoe UI',sans-serif",'Inter':"'Inter',sans-serif",'Poppins':"'Poppins',sans-serif",'Montserrat':"'Montserrat',sans-serif",'Raleway':"'Raleway',sans-serif",'Oswald':"'Oswald',sans-serif",'Anton':"'Anton',sans-serif",'Righteous':"'Righteous',sans-serif",'Black Han Sans':"'Black Han Sans',sans-serif",'Josefin Sans':"'Josefin Sans',sans-serif",'Barlow Condensed':"'Barlow Condensed',sans-serif",'DM Sans':"'DM Sans',sans-serif",'Jost':"'Jost',sans-serif",'Space Grotesk':"'Space Grotesk',sans-serif",'Syne':"'Syne',sans-serif",'Tenor Sans':"'Tenor Sans',sans-serif",'Playfair Display':"'Playfair Display',serif",'Lora':"'Lora',serif",'Libre Baskerville':"'Libre Baskerville',serif",'Crimson Pro':"'Crimson Pro',serif",'EB Garamond':"'EB Garamond',serif",'Cormorant Garamond':"'Cormorant Garamond',serif",'Cormorant SC':"'Cormorant SC',serif",'Roboto Slab':"'Roboto Slab',serif",'DM Serif Display':"'DM Serif Display',serif",'Alfa Slab One':"'Alfa Slab One',serif",'Cinzel':"'Cinzel',serif",'Italiana':"'Italiana',serif",'Bebas Neue':"'Bebas Neue',sans-serif",'Bangers':"'Bangers',cursive",'Luckiest Guy':"'Luckiest Guy',cursive",'Black Ops One':"'Black Ops One',cursive",'Russo One':"'Russo One',sans-serif",'Teko':"'Teko',sans-serif",'Boogaloo':"'Boogaloo',cursive",'Fredoka One':"'Fredoka One',cursive",'Lilita One':"'Lilita One',cursive",'Poiret One':"'Poiret One',cursive",'Dancing Script':"'Dancing Script',cursive",'Pacifico':"'Pacifico',cursive",'Lobster':"'Lobster',cursive",'Permanent Marker':"'Permanent Marker',cursive",'Caveat':"'Caveat',cursive",'Great Vibes':"'Great Vibes',cursive",'Alex Brush':"'Alex Brush',cursive",'Pinyon Script':"'Pinyon Script',cursive",'Sacramento':"'Sacramento',cursive",'Satisfy':"'Satisfy',cursive",'Kaushan Script':"'Kaushan Script',cursive",'Yellowtail':"'Yellowtail',cursive",'Allura':"'Allura',cursive",'Marck Script':"'Marck Script',cursive",'Italianno':"'Italianno',cursive",'Mr Dafoe':"'Mr Dafoe',cursive",'Euphoria Script':"'Euphoria Script',cursive",'NotoNastaliqUrdu':"'NotoNastaliqUrdu',serif",'AttariQuraanWord':"'AttariQuraanWord',serif"};
    const rawFam=cap.fontfamily||'';
    const family=_fn[rawFam]||rawFam||'Arial,sans-serif';
    const bold=(cap.fontweight==='bold'||cap.fontweight==='700')?'bold ':'';
    const italic=cap.fontstyle==='italic'?'italic ':'';
    ctx.font=italic+bold+fs+'px '+family;
    let lines=1,ln='';
    words.forEach(w=>{const t=ln?ln+' '+w:w;if(ctx.measureText(t).width>bw&&ln){lines++;ln=w;}else ln=t;});
    return lines*lh+pad*2;
}

function centreCaption(){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const bw=parseInt(cap.width)||320;
    cap.position_x=Math.round((CW-bw)/2);
    cap.position_y=Math.round((CH-_capBoxH(cap))/2);
    syncPosInputs(cap);
    capFieldChanged('position_x',cap.position_x);
    capFieldChanged('position_y',cap.position_y);
}
// ── syncPodcastThumbnail — fire-and-forget, called on play + record start ───
// Reads scene 1 image_file, updates hdb_podcasts.thumbnail if different.
function syncPodcastThumbnail() {
    const fd = new FormData();
    fd.append('ajax_action', 'update_podcast_thumbnail');
    fd.append('podcast_id',  PODCAST_ID);
    fetch(location.href, { method:'POST', body:fd, credentials:'include' })
        .then(r => r.json())
        .then(d => {
            if (d.updated) console.log('[Thumb] Updated to:', d.thumbnail, '| was:', d.was);
            else           console.log('[Thumb] In sync —', d.reason || 'no change needed');
        })
        .catch(e => console.warn('[Thumb] sync failed:', e.message));
}

function stopRecording(){
    isRecording = false;
    if(bgAudio) bgAudio.pause();
    if(currentAudio){ currentAudio.pause(); currentAudio=null; }
    if(mr && mr.state !== 'inactive'){
        mr.stop();
        L('Stopping recording…', 'inf');
    }
}
function snapCaption(preset){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const bw=parseInt(cap.width)||320;
    const bh=_capBoxH(cap);
    if(preset==='top')          cap.position_y=10;
    else if(preset==='middle')  cap.position_y=Math.round((CH-bh)/2);
    else if(preset==='bottom')  cap.position_y=Math.round(CH-bh-14);
    else if(preset==='centre-h')cap.position_x=Math.round((CW-bw)/2);
    syncPosInputs(cap);
    capFieldChanged('position_x',Math.round(cap.position_x));
    capFieldChanged('position_y',Math.round(cap.position_y));
}

// ── Field change → debounced save ─────────────────────────────────
let currentPlaybackSpeed = AUDIO_SPEED;
let sampleAudio = null;


function updatePlaybackSpeed(speed) {
    currentPlaybackSpeed = parseFloat(speed);

    // Update all speed displays
    document.querySelectorAll('#speedValue,#speedValue2').forEach(el => {
        el.textContent = currentPlaybackSpeed.toFixed(2) + 'x';
    });
    // Sync all sliders
    document.querySelectorAll('#playbackSpeedSlider,#playbackSpeedSlider2').forEach(el => {
        el.value = currentPlaybackSpeed;
    });

    // Update active preset button
    document.querySelectorAll('.speed-preset').forEach(btn => {
        btn.classList.toggle('active', Math.abs(parseFloat(btn.dataset.speed) - currentPlaybackSpeed) < 0.01);
    });

    // Apply speed to currently playing audio
    if (currentAudio && !currentAudio.paused) currentAudio.playbackRate = currentPlaybackSpeed;
    if (_voicePreviewAudio && !_voicePreviewAudio.paused) _voicePreviewAudio.playbackRate = currentPlaybackSpeed;

    // Save to DB (debounced)
    clearTimeout(window._speedSaveTimer);
    window._speedSaveTimer = setTimeout(() => {
        const fd = new FormData();
        fd.append('ajax_action', 'save_audio_speed');
        fd.append('speed',       currentPlaybackSpeed);
        fetch(location.href, { method:'POST', body:fd }).catch(() => {});
    }, 800);

    L(`Voiceover speed set to ${currentPlaybackSpeed.toFixed(2)}x`, 'inf');
}

function setPlaybackSpeed(speed) {
    const slider = document.getElementById('playbackSpeedSlider');
    if (slider) slider.value = speed;
    updatePlaybackSpeed(speed);
}

function previewSampleSpeed() {
    // Stop any existing sample
    if (sampleAudio) {
        sampleAudio.pause();
        sampleAudio = null;
    }
    
    // Create a sample audio with a simple phrase
    const sampleText = "This is a sample of playback speed. Listen to how the voice changes.";
    const voiceId = _hostVoiceId || 'openai:alloy';
    
    const status = document.getElementById('sampleSpeedStatus');
    status.textContent = 'Generating sample...';
    status.style.color = 'var(--info)';
    
    // Generate a sample voice with current speed
    const fd = new FormData();
    fd.append('text', sampleText);
    fd.append('voice_id', voiceId);
    fd.append('lang_code', 'en');
    fd.append('rate', '1.0');
    fd.append('speed', currentPlaybackSpeed);
    
    fetch('generate_voice_sample.php', { method: 'POST', body: fd })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.audio_url) {
                sampleAudio = new Audio(data.audio_url);
                sampleAudio.playbackRate = currentPlaybackSpeed;
                sampleAudio.onended = () => {
                    status.textContent = 'Sample finished. Click again to replay.';
                    status.style.color = 'var(--muted)';
                };
                sampleAudio.play();
                status.textContent = `Playing at ${currentPlaybackSpeed.toFixed(2)}x speed...`;
                status.style.color = 'var(--success)';
            } else {
                // Fallback: use Web Audio API to simulate speed change
                simulateSpeedSample();
            }
        })
        .catch(() => {
            simulateSpeedSample();
        });
}

function simulateSpeedSample() {
    // Fallback: create a simple beep with Web Audio API to demonstrate speed
    const status = document.getElementById('sampleSpeedStatus');
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    const audioCtx = new AudioContext();
    const now = audioCtx.currentTime;
    
    status.textContent = `Playing beep at ${currentPlaybackSpeed.toFixed(2)}x speed...`;
    
    const duration = 0.5 / currentPlaybackSpeed;
    
    for (let i = 0; i < 3; i++) {
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        oscillator.frequency.value = 440;
        gainNode.gain.value = 0.3;
        
        oscillator.start(now + (i * duration));
        oscillator.stop(now + ((i + 1) * duration));
        
        gainNode.gain.exponentialRampToValueAtTime(0.0001, now + ((i + 1) * duration));
    }
    
    setTimeout(() => {
        status.textContent = 'Sample finished. Click to test again.';
        status.style.color = 'var(--muted)';
    }, duration * 3 * 1000);
}

async function _saveCaption(capId){
    const dirty=_capDirty[capId];if(!dirty||!Object.keys(dirty).length)return;
    const fd=new FormData();
    fd.append('ajax_action','save_caption');
    fd.append('caption_id',capId);
    Object.entries(dirty).forEach(([k,v])=>fd.append(k,v));
    _capDirty[capId]={};
    await fetch(location.href,{method:'POST',body:fd});
    L('Caption saved','ok');
}

// ── Add / Delete ───────────────────────────────────────────────────
async function addCaption(){
    const sc = SCENES[currentIndex];
    const name = 'cap' + (sceneCaptions.length + 1);
    const fd = new FormData();
    fd.append('ajax_action',   'add_caption');
    fd.append('story_id',      sc.id);
    fd.append('caption_name',  name);
    fd.append('text_content',  'New caption');
    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (data.success && data.caption) {
            const newCap = data.caption;
            // Push into the global array so loadSceneCaptions can find it
            ALL_CAPTIONS.push(newCap);
            // Seed the animation state BEFORE loadSceneCaptions runs
            captionStates[newCap.id] = {
                show: 'New caption', full: 'New caption',
                words: ['New','caption'], karIdx: 0, timer: null
            };
            // Pre-set selectedCapId so loadSceneCaptions doesn't clear the editor
            selectedCapId = parseInt(newCap.id);
            loadSceneCaptions(sc.id);
            selectCaption(newCap.id);
            L('Caption added', 'ok');
        } else {
            L('Add caption failed: ' + (data.message || 'unknown'), 'err');
        }
    } catch(e) {
        L('Add caption error: ' + e.message, 'err');
    }
}

async function deleteCaption(){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    if((cap.caption_name||'').toLowerCase()==='main'){alert('Cannot delete the main caption.');return;}
    if(!confirm('Delete caption "'+cap.caption_name+'"?'))return;
    const fd=new FormData();
    fd.append('ajax_action','delete_caption');
    fd.append('caption_id',cap.id);
    try{
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(data.success){
            const idx=ALL_CAPTIONS.findIndex(c=>+c.id===+cap.id);
            if(idx>=0)ALL_CAPTIONS.splice(idx,1);
            selectedCapId=null;
            showCaptionEditor(false);
            loadSceneCaptions(SCENES[currentIndex].id);
            L('Caption deleted','ok');
        }else{L('Delete failed: '+(data.message||'unknown'),'err');}
    }catch(e){L('Delete error: '+e.message,'err');}
}
// ══════════════════════════════════════════════════════════════════
// END MULTI-CAPTION SYSTEM
// ══════════════════════════════════════════════════════════════════

// ── Log ────────────────────────────────────────────────────────────────────
function L(m,c=''){
    const el=document.getElementById('log');
    el.style.display='block';
    const p=document.createElement('p');if(c)p.className=c;p.textContent=m;
    el.appendChild(p);el.scrollTop=el.scrollHeight;
}

// ── Panel toggle ───────────────────────────────────────────────────────────
function togglePanel(panelId,btnId){
    const panel=document.getElementById(panelId);
    const btn=document.getElementById(btnId);
    const isOpen=panel.classList.contains('open');
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('open'));
    document.querySelectorAll('.icon-btn').forEach(b=>{
        if(!b.classList.contains('play-btn')&&!b.classList.contains('rec-btn'))b.classList.remove('active');
    });
    if(!isOpen){panel.classList.add('open');btn.classList.add('active');}
}
function closePanel(panelId,btnId){
    document.getElementById(panelId).classList.remove('open');
    document.getElementById(btnId).classList.remove('active');
    // When closing the image panel, restore canvas to show the normal active slot
    if (panelId === 'pImage') {
        showScene(currentIndex, true);
    }
}

// ── Render ─────────────────────────────────────────────────────────────────
function startRender(){
    if(renderRaf)return;
    (function frame(){drawFrame();framesDrawn++;renderRaf=requestAnimationFrame(frame);})();
}
// Update drawFrame to ensure consistent rendering
function drawFrame() {
    const now = performance.now();
    
    // Clear canvas to black
    ctx.fillStyle = '#000000';
    ctx.fillRect(0, 0, CW, CH);
    
    // Draw the outgoing image if any (fading out)
    if (S.imgOut && S.alphaOut > 0.01) {
        ctx.save();
        ctx.globalAlpha = S.alphaOut;
        drawCover(S.imgOut, null);
        ctx.restore();
    }
    
    // Draw the current media (fading in)
    if (S.alpha > 0.01) {
        ctx.save();
        ctx.globalAlpha = S.alpha;
        
        if (S.txOffset) {
            if (S.txOffset.x != null) ctx.translate(S.txOffset.x, 0);
            if (S.txOffset.scale) {
                ctx.translate(CW / 2, CH / 2);
                ctx.scale(S.txOffset.scale, S.txOffset.scale);
                ctx.translate(-CW / 2, -CH / 2);
            }
        }
        
        if (S.type === 'image' && S.img) {
            drawCover(S.img, S.kbEffect === 'none' ? null : kbXform(S.kbEffect, S.kbStart, S.img));
        } else if (S.type === 'video' && S.vidEl) {
            try {
                // Only draw if video has valid dimensions
                if (S.vidEl.videoWidth && S.vidEl.videoHeight && S.vidEl.readyState >= 2) {
                    ctx.drawImage(S.vidEl, 0, 0, CW, CH);
                }
            } catch(_) {}
        }
        
        ctx.restore();
    }
    
    // Draw captions on top
    drawAllCaptions();
    
    // Mirror to HD canvas for recording
    ctxHD.drawImage(canvas, 0, 0, 1080, 1920);
    
    lastFrameTime = now;
    frameCount++;
}

function kbXform(ef,t0,img){
    if(ef==='none'||!img)return null;
    const p=Math.min((performance.now()-t0)/KB_DUR,1);
    const e=p<.5?2*p*p:1-Math.pow(-2*p+2,2)/2;
    const base=Math.max(CW/img.naturalWidth,CH/img.naturalHeight);
    const zoom=base*1.18,off=Math.max(CW,CH)*0.055;
    const M={'zoom-in':{ss:base,es:zoom,sox:0,eox:0},'zoom-out':{ss:zoom,es:base,sox:0,eox:0},'pan-left':{ss:zoom,es:zoom,sox:off,eox:-off},'pan-right':{ss:zoom,es:zoom,sox:-off,eox:off}}[ef]||{ss:base,es:zoom,sox:0,eox:0};
    const s=M.ss+(M.es-M.ss)*e,ox=M.sox+(M.eox-M.sox)*e;
    return{s,ox,oy:0};
}
function drawCover(img,kb){
    const base=Math.max(CW/img.naturalWidth,CH/img.naturalHeight);
    const s=kb?kb.s:base,w=img.naturalWidth*s,h=img.naturalHeight*s;
    ctx.drawImage(img,(CW-w)/2+(kb?kb.ox:0),(CH-h)/2+(kb?kb.oy:0),w,h);
}
function rrect(c,x,y,w,h,r){c.beginPath();c.moveTo(x+r,y);c.lineTo(x+w-r,y);c.quadraticCurveTo(x+w,y,x+w,y+r);c.lineTo(x+w,y+h-r);c.quadraticCurveTo(x+w,y+h,x+w-r,y+h);c.lineTo(x+r,y+h);c.quadraticCurveTo(x,y+h,x,y+h-r);c.lineTo(x,y+r);c.quadraticCurveTo(x,y,x+r,y);c.closePath();}

// ── Preload ────────────────────────────────────────────────────────────────
async function preloadAll() {
    // First, determine which slots are checked globally (same for all scenes)
    const enabledSlots = SLOTS.filter(k => {
        const chk = document.getElementById('slotChk_' + k);
        return chk && chk.checked;
    });
    
    // If no slots checked, default to main slot
    if (enabledSlots.length === 0) {
        const mainChk = document.getElementById('slotChk_image_file');
        if (mainChk) {
            mainChk.checked = true;
            enabledSlots.push('image_file');
            L('Auto-enabled main image slot', 'inf');
        } else {
            L('No slots enabled', 'err');
            return false;
        }
    }
    
    L(`Preloading ALL scenes using checked slots: ${enabledSlots.join(', ')}`, 'inf');
    
    // Collect files from ALL scenes but ONLY from checked slots
    const allImgFiles = [];
    const allVidFiles = [];
    const sceneFiles = []; // Track which scenes have which files
    
    for (let i = 0; i < SCENES.length; i++) {
        const scene = SCENES[i];
        const filesInScene = { index: i, images: [], videos: [] };
        
        for (const slot of enabledSlots) {
            const fn = (scene[slot] || '').trim();
            if (!fn) continue;
            
            if (/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) {
                if (!allVidFiles.includes(fn)) allVidFiles.push(fn);
                filesInScene.videos.push(fn);
            } else {
                if (!allImgFiles.includes(fn)) allImgFiles.push(fn);
                filesInScene.images.push(fn);
            }
        }
        
        if (filesInScene.images.length || filesInScene.videos.length) {
            sceneFiles.push(filesInScene);
        }
    }
    
    const total = allImgFiles.length + allVidFiles.length;
    if (total === 0) {
        L('No media files found in checked slots across all scenes', 'wrn');
        return false;
    }
    
    const bar = document.getElementById('preloadBar');
    const msg = document.getElementById('preloadMsg');
    let loaded = 0;
    let failed = 0;
    
    const updateProgress = () => {
        const percent = Math.round((loaded + failed) / total * 100);
        if (bar) bar.style.width = percent + '%';
        if (msg) msg.textContent = `Loading ${percent}% (${loaded + failed}/${total} files from ${SCENES.length} scenes)`;
    };
    
    // Preload images in parallel
    await Promise.all(allImgFiles.map(fn => new Promise(resolve => {
        if (imgCache[fn]) {
            loaded++;
            updateProgress();
            resolve();
            return;
        }
        
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        const timeout = setTimeout(() => {
            console.warn(`Timeout loading image: ${fn}`);
            failed++;
            updateProgress();
            resolve();
        }, 8000);
        
        img.onload = () => {
            clearTimeout(timeout);
            imgCache[fn] = img;
            loaded++;
            updateProgress();
            resolve();
        };
        
        img.onerror = () => {
            clearTimeout(timeout);
            console.warn(`Failed to load image: ${fn}`);
            failed++;
            updateProgress();
            resolve();
        };
        
        img.src = fn.startsWith('logo_') ? 'podcast_logos/'+fn : getFileFolder(fn)+fn;
    })));
    
    // Preload videos sequentially
    for (const fn of allVidFiles) {
        await new Promise(resolve => {
            let video = vidEls[fn];
            
            if (!video) {
                video = document.createElement('video');
                video.muted = true;
                // Detect talking head by filename being in user_videos folder
                // (FILE_FOLDER is populated during scene scan above)
                const isThVid = (FILE_FOLDER[fn] || '').replace(/\/$/,'') === 'user_videos';
                video.loop = !isThVid;
                video.playsInline = true;
                video.crossOrigin = 'anonymous';
                video.preload = 'auto';
                document.getElementById('vidPool').appendChild(video);
                vidEls[fn] = video;
                video.src = getFileFolder(fn) + fn;
            }
            
            if (video.readyState >= 3) {
                loaded++;
                updateProgress();
                resolve();
                return;
            }
            
            const timeout = setTimeout(() => {
                console.warn(`Timeout loading video: ${fn}`);
                failed++;
                updateProgress();
                resolve();
            }, 15000);
            
            const onCanPlay = () => {
                clearTimeout(timeout);
                video.removeEventListener('canplaythrough', onCanPlay);
                video.removeEventListener('error', onError);
                loaded++;
                updateProgress();
                resolve();
            };
            
            const onError = () => {
                clearTimeout(timeout);
                video.removeEventListener('canplaythrough', onCanPlay);
                video.removeEventListener('error', onError);
                console.warn(`Error loading video: ${fn}`);
                failed++;
                updateProgress();
                resolve();
            };
            
            video.addEventListener('canplaythrough', onCanPlay, { once: true });
            video.addEventListener('error', onError, { once: true });
            video.load();
        });
    }
    
    // Log summary of what was loaded per scene
    console.log('=== Preload Summary ===');
    for (const scene of sceneFiles) {
        console.log(`Scene ${scene.index + 1}: ${scene.images.length} images, ${scene.videos.length} videos`);
    }
    
    L(`Preload complete: ${loaded}/${total} files loaded from checked slots across ${SCENES.length} scenes`, loaded === total ? 'ok' : 'wrn');
    return loaded === total;
}

const SLOTS=['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];

function sceneMedia(sc, slotOverride) {
    if (slotOverride) {
        var v = (sc[slotOverride] || '').trim();
        return { fn: v || null, isVideo: /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(v), slot: slotOverride };
    }

    // Prefer activeSlot if it is checked and has media
    if (activeSlot) {
        var chk = document.getElementById('slotChk_' + activeSlot);
        if (chk && chk.checked) {
            var af = (sc[activeSlot] || '').trim();
            if (af) return { fn: af, isVideo: /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(af), slot: activeSlot };
        }
    }

    // Fall through to first checked slot that has a file
    var enabledSlots = getEnabledSlots();
    if (enabledSlots.length === 0) {
        return { fn: null, isVideo: false, slot: 'image_file' };
    }
    for (var i = 0; i < enabledSlots.length; i++) {
        var slot = enabledSlots[i];
        var fn = (sc[slot] || '').trim();
        if (fn) {
            return { fn: fn, isVideo: /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn), slot: slot };
        }
    }

    return { fn: null, isVideo: false, slot: 'image_file' };
}

function doTransition(type, dur) {
    return new Promise(res => {
        if (type === 'none') {
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            S.txOffset = null;
            res();
            return;
        }
        
        const startTime = performance.now();
        const duration = dur || T_DUR;
        
        function step(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Use linear interpolation for fade (simplest and smoothest)
            const alpha = progress;
            
            // Simple cross-fade: old fades out, new fades in
            S.alpha = alpha;
            S.alphaOut = 1 - alpha;
            
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                // Transition complete
                S.alpha = 1;
                S.alphaOut = 0;
                S.imgOut = null;
                res();
            }
        }
        
        requestAnimationFrame(step);
    });
}

async function showScene(index, instant) {
    if (index < 0 || index >= SCENES.length || S.isTransitioning) return;
    
    S.isTransitioning = true;
    
    const sc = SCENES[index];
    const { fn, isVideo, slot } = sceneMedia(sc);
    
    // For instant switch (no transition) - used for first scene and slot cycling
    if (instant) {
        // Directly set the new media without any transition
        if (isVideo && fn && vidEls[fn]) {
            if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
            S.type = 'video';
            S.vidEl = vidEls[fn];
            S.img = null;
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            S.txOffset = null;
            // Only seek+play if the video is NOT already playing (i.e. was pre-rolled).
            // Resetting currentTime on a playing video causes a black frame.
            if (vidEls[fn].paused) {
                try { vidEls[fn].currentTime = 0; vidEls[fn].play(); } catch(e) {}
            }
        } else if (fn && imgCache[fn]) {
            if (S.vidEl) S.vidEl.pause();
            S.type = 'image';
            S.img = imgCache[fn];
            S.vidEl = null;
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            S.txOffset = null;
            S.kbEffect = sceneKB[index];
            S.kbStart = performance.now();
        } else {
            S.type = 'blank';
            S.img = null;
            S.vidEl = null;
            S.alpha = 1;
        }
        
        // Update UI immediately
        currentIndex = index;
        loadSceneCaptions(sc.id);
        updateSlotThumbs(sc);
        applySceneSlots(sc);
        updatePanelSceneNumbers();  // <-- ADDED HERE
        
        const ta = document.getElementById('slotPrompt');
        if (ta) ta.value = sc[SLOT_PROMPT_MAP[activeSlot]] || sc.prompt || '';
        if (document.getElementById('pAudio').classList.contains('open')) loadAudioPanel();
        
        document.getElementById('sceneNum').textContent = (index + 1) + ' / ' + SCENES.length;
        updateDots(index);
        updateNavButtons();
        
        S.isTransitioning = false;
        
        // Preload next scene in background
        setTimeout(() => prepareNextScene(index + 1), 100);
        return;
    }
    
    // For smooth transition - atomic swap with no flicker
    // Capture current frame as a frozen image to prevent flicker
    let oldFrameImg = null;
    
    // Capture current canvas state as an image for smooth transition
    try {
        oldFrameImg = new Image();
        const dataUrl = canvas.toDataURL('image/png');
        await new Promise(resolve => {
            oldFrameImg.onload = resolve;
            oldFrameImg.src = dataUrl;
        });
    } catch(e) {
        oldFrameImg = S.img;
    }
    
    // Set the new media as the current (but keep old as imgOut for transition)
    if (isVideo && fn && vidEls[fn]) {
        const video = vidEls[fn];
        
        // Ensure video is ready
        if (video.readyState < 2) {
            await new Promise(resolve => {
                const timeout = setTimeout(resolve, 500);
                video.addEventListener('canplay', resolve, { once: true });
                video.load();
            });
        }
        
        // Stop old video
        if (S.vidEl && S.vidEl !== video) S.vidEl.pause();
        
        // Set new video as current (but alpha=0 initially for cross-fade)
        S.vidEl = video;
        S.type = 'video';
        S.img = null;
        S.alpha = 0;  // Start invisible — will fade in
        // Only seek+play if not already playing (video may have been pre-rolled).
        // Calling currentTime=0 on a playing video causes a black frame.
        if (video.paused) {
            video.currentTime = 0;
            try { video.play(); } catch(e) {}
        }
        
    } else if (fn && imgCache[fn]) {
        if (S.vidEl) S.vidEl.pause();
        S.type = 'image';
        S.img = imgCache[fn];
        S.vidEl = null;
        S.alpha = 0;  // Start invisible
        S.kbEffect = sceneKB[index];
        S.kbStart = performance.now();
        
    } else {
        S.type = 'blank';
        S.img = null;
        S.vidEl = null;
        S.alpha = 0;
    }
    
    // Set the frozen old frame as the outgoing image
    S.imgOut = oldFrameImg || S.imgOut;
    S.alphaOut = 1;  // Old frame fully visible
    S.txOffset = null;
    
    // Force a frame render immediately to show the frozen old frame
    drawFrame();
    
    // Now perform the transition (old fades out, new fades in)
    await doTransition('fade', T_DUR);
    
    // Transition complete - clean up
    S.alpha = 1;
    S.alphaOut = 0;
    S.imgOut = null;
    S.txOffset = null;
    
    // Update UI
    currentIndex = index;
    loadSceneCaptions(sc.id);
    updateSlotThumbs(sc);
    applySceneSlots(sc);
    updatePanelSceneNumbers();  // <-- ADDED HERE
    
    const ta = document.getElementById('slotPrompt');
    if (ta) ta.value = sc[SLOT_PROMPT_MAP[activeSlot]] || sc.prompt || '';
    if (document.getElementById('pAudio').classList.contains('open')) loadAudioPanel();
    
    document.getElementById('sceneNum').textContent = (index + 1) + ' / ' + SCENES.length;
    updateDots(index);
    updateNavButtons();
    
    logActivity('scene_view', 'scene:' + (index + 1), index);
    
    S.isTransitioning = false;
    
    // Preload next scene in background
    setTimeout(() => prepareNextScene(index + 1), 100);
}

async function ensureVideoReady(fn) {
    if (!fn) return false;

    let video = vidEls[fn];
    if (!video) {
        video = document.createElement('video');
        video.muted = true;
        const isThVid = (FILE_FOLDER[fn] || '').replace(/\/$/, '') === 'user_videos';
        video.loop = !isThVid;
        video.playsInline = true;
        video.crossOrigin = 'anonymous';
        video.preload = 'auto';
        video.src = getFileFolder(fn) + fn;
        document.getElementById('vidPool').appendChild(video);
        vidEls[fn] = video;
    }

    // Already ready — no need to load again
    if (video.readyState >= 3) return true;

    // Already has a src and is loading — just wait, don't call load() again
    // as that interrupts any in-progress play() and causes AbortError
    return new Promise(resolve => {
        const timeout = setTimeout(() => {
            console.warn(`Timeout waiting for video: ${fn}`);
            resolve(false);
        }, 8000);

        const onReady = () => {
            clearTimeout(timeout);
            video.removeEventListener('canplaythrough', onReady);
            video.removeEventListener('error', onError);
            resolve(true);
        };
        const onError = () => {
            clearTimeout(timeout);
            video.removeEventListener('canplaythrough', onReady);
            video.removeEventListener('error', onError);
            resolve(false);
        };

        video.addEventListener('canplaythrough', onReady, { once: true });
        video.addEventListener('error', onError, { once: true });

        // Only call load() if the video has no src set yet
        if (!video.src || video.networkState === HTMLMediaElement.NETWORK_EMPTY) {
            video.load();
        }
    });
}
function updatePanelSceneNumbers() {
    const sceneNum = currentIndex + 1;  // currentIndex is 0-based
    const captionSpan = document.getElementById('captionSceneNumber');
    const imageSpan = document.getElementById('imageSceneNumber');
    const audioSpan = document.getElementById('audioSceneNumber');
    
    if (captionSpan) captionSpan.textContent = sceneNum;
    if (imageSpan) imageSpan.textContent = sceneNum;
    if (audioSpan) audioSpan.textContent = sceneNum;
}
function updateDots(i){document.querySelectorAll('.dot').forEach((d,j)=>d.className='dot'+(j===i?' active':''));}
function navigate(dir){if(!isPlaying&&!transitioning&&!isRecording)showScene(currentIndex+dir,false);}
function updateNavButtons(){
    const b=isPlaying||isRecording;
    const btns=document.querySelectorAll('.nav-btn');
    if(btns[0])btns[0].disabled=b||currentIndex===0;
    if(btns[1])btns[1].disabled=b||currentIndex===SCENES.length-1;
}
// Play a sequence of videos for a scene (b-roll style)
async function playVideoSequence(scene, enabledSlots, audioEndSignal, isRecordingMode = false) {
    console.log(`\n🎬 Starting VIDEO SEQUENCE for scene ${SCENES.indexOf(scene) + 1}`);
    console.log(`   Available slots: ${enabledSlots.length}`);

    // Collect video files from checked slots in order
    const videoSlots = [];
    for (const slot of enabledSlots) {
        const fn = (scene[slot] || '').trim();
        if (fn && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) {
            videoSlots.push({ slot, filename: fn });
        }
    }

    if (videoSlots.length === 0) {
        console.log(`   ⚠️ No videos found in checked slots`);
        return false;
    }

    console.log(`   Videos (will loop in order): ${videoSlots.map(v => v.filename).join(', ')}`);

    // Preload all videos if not already ready — skip if already loaded/playing
    // to avoid AbortError from interrupting an in-progress play()
    for (const vs of videoSlots) {
        const existing = vidEls[vs.filename];
        if (!existing) {
            await ensureVideoReady(vs.filename);
        } else if (existing.readyState < 2 && existing.networkState !== HTMLMediaElement.NETWORK_LOADING) {
            await ensureVideoReady(vs.filename);
        }
        // readyState >= 2 or already loading — do nothing, it's fine
    }

    let audioDone = false;
    audioEndSignal.then(() => { audioDone = true; });

    let slotIndex = 0;

    while (!audioDone) {
        const currentVideo = videoSlots[slotIndex % videoSlots.length];
        const videoEl = vidEls[currentVideo.filename];

        if (!videoEl) {
            console.log(`   ⚠️ Video element missing: ${currentVideo.filename}`);
            slotIndex++;
            continue;
        }

        // Switch canvas to this video
        if (S.vidEl && S.vidEl !== videoEl) S.vidEl.pause();
        S.type     = 'video';
        S.vidEl    = videoEl;
        S.img      = null;
        S.alpha    = 1;
        S.alphaOut = 0;
        S.imgOut   = null;
        highlightPlayingSlot(currentVideo.slot);

        console.log(`   ▶️ Playing video ${(slotIndex % videoSlots.length) + 1}/${videoSlots.length}: ${currentVideo.filename}`);

        // On the first iteration the video was pre-rolled by playSceneWithDynamicSlots
        // so it may already be playing — don't seek/restart or we get a black frame.
        // On subsequent iterations (slotIndex > 0) always restart from the beginning.
        if (slotIndex > 0 || videoEl.paused) {
            videoEl.currentTime = 0;
            try { await videoEl.play(); } catch(e) {
                // AbortError is expected when load() interrupts play() — safe to ignore
                if (e.name !== 'AbortError') console.warn(`   ⚠️ Could not play: ${e.message}`);
            }
        }

        // Wait for this video to end naturally OR audio to finish — whichever comes first
        await new Promise(resolve => {
            let done = false;
            const finish = () => { if (!done) { done = true; resolve(); } };
            videoEl.addEventListener('ended', finish, { once: true });
            audioEndSignal.then(finish);
        });

        slotIndex++;
        console.log(`   ${audioDone ? '⏹ Audio ended — stopping video loop.' : '↩️ Video ended — next video.'}`);
    }

    console.log(`   ✅ Video sequence complete for scene ${SCENES.indexOf(scene) + 1}\n`);
    return true;
}

// Play a sequence of images for a scene
// audioDurationMs = actual audio length so we divide it equally across images
// audioEndSignal  = the audioPromise — resolves when audio finishes
async function playImageSequence(scene, enabledSlots, audioDurationMs, audioEndSignal, isRecordingMode = false) {
    console.log(`\n🖼️ Starting IMAGE SEQUENCE for scene ${SCENES.indexOf(scene) + 1}`);
    console.log(`   Audio duration: ${(audioDurationMs / 1000).toFixed(1)}s`);
    console.log(`   Available slots: ${enabledSlots.length}`);

    // Collect image files from checked slots in order
    const imageSlots = [];
    for (const slot of enabledSlots) {
        const fn = (scene[slot] || '').trim();
        if (fn && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) {
            imageSlots.push({ slot, filename: fn });
        }
    }

    if (imageSlots.length === 0) {
        console.log(`   ⚠️ No images found in checked slots`);
        return false;
    }

    // Each image gets equal share of the audio duration — minimum 1 second per image
    const perImageMs = Math.max(1000, Math.floor(audioDurationMs / imageSlots.length));
    console.log(`   Images: ${imageSlots.length} — time per image: ${(perImageMs / 1000).toFixed(1)}s`);

    let audioDone = false;
    audioEndSignal.then(() => { audioDone = true; });

    let imgIndex = 0;

    while (!audioDone) {
        const currentImage = imageSlots[imgIndex % imageSlots.length];

        if (imgCache[currentImage.filename]) {
            if (S.vidEl) S.vidEl.pause();
            S.type     = 'image';
            S.img      = imgCache[currentImage.filename];
            S.vidEl    = null;
            S.alpha    = 1;
            S.alphaOut = 0;
            S.imgOut   = null;
            S.kbEffect = sceneKB[SCENES.indexOf(scene)];
            S.kbStart  = performance.now();
        }

        highlightPlayingSlot(currentImage.slot);
        console.log(`   🖼️ Image ${(imgIndex % imageSlots.length) + 1}/${imageSlots.length}: ${currentImage.filename} for ${(perImageMs / 1000).toFixed(1)}s`);

        // Wait perImageMs OR until audio ends — whichever is first
        await new Promise(resolve => {
            let done = false;
            const finish = () => { if (!done) { done = true; resolve(); } };
            const t = setTimeout(finish, perImageMs);
            audioEndSignal.then(() => { clearTimeout(t); finish(); });
        });

        imgIndex++;
        console.log(`   ${audioDone ? '⏹ Audio ended — stopping.' : '↩️ Next image.'}`);
    }

    console.log(`   ✅ Image sequence complete\n`);
    return true;
}

// Helper to highlight which slot is currently playing
function highlightPlayingSlot(slot) {
    // Remove highlight from all slots
    SLOTS.forEach(k => {
        const thumb = document.getElementById('slotThumb_' + k);
        if (thumb) {
            thumb.style.borderColor = 'var(--border)';
            thumb.style.borderWidth = '2px';
        }
    });
    
    // Highlight the active slot
    const activeThumb = document.getElementById('slotThumb_' + slot);
    if (activeThumb) {
        activeThumb.style.borderColor = '#f59e0b';
        activeThumb.style.borderWidth = '3px';
        activeThumb.style.transition = 'border-color 0.2s';
    }
}

// Check if a scene is B-roll type
// Modified scene playback function
async function playSceneWithDynamicSlots(scene, index, isRecordingMode = false) {
    const enabledSlots = getEnabledSlots();
    const slotsWithMedia = enabledSlots.filter(slot => {
        const fn = (scene[slot] || '').trim();
        return fn !== '';
    });

    console.log(`\n========== SCENE ${index + 1} — slots with media: ${slotsWithMedia.length} ==========`);

    const videoSlots = slotsWithMedia.filter(slot => {
        const fn = (scene[slot] || '').trim();
        return fn && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    });
    const imageSlots = slotsWithMedia.filter(slot => {
        const fn = (scene[slot] || '').trim();
        return fn && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    });

    if (slotsWithMedia.length === 0) {
        loadSceneCaptions(scene.id);
        updateSlotThumbs(scene);
        applySceneSlots(scene);
        updatePanelSceneNumbers();
        const audioPromise = playSceneAudio(scene, isTalkingHeadScene(scene) ? vidEls[(scene.image_file || '').trim()] : null);
        await showScene(index, isRecordingMode ? true : false);
        await audioPromise;
        return;
    }

    if (isRecordingMode && videoSlots.length > 0) {
        // ── RECORDING MODE: YouTube-style ───────────────────────────────────
        // Step 1: Seek video to frame 0, keep PAUSED — paint still frame on canvas
        // Step 2: Draw captions on top of the still frame
        // Step 3: Start audio  →  this is the cue to begin the video
        // Step 4: Play video — audio and video start together, zero black frames

        const firstSlot = videoSlots[0];
        const firstFn   = (scene[firstSlot] || '').trim();
        const firstVid  = vidEls[firstFn];

        if (firstVid) {
            // Pause and seek to frame 0 — DO NOT call play()
            firstVid.pause();
            firstVid.currentTime = 0;

            // Wait for the first frame to be decoded (HAVE_CURRENT_DATA or better)
            if (firstVid.readyState < 2) {
                await new Promise(resolve => {
                    const onReady = () => { firstVid.removeEventListener('loadeddata', onReady); resolve(); };
                    firstVid.addEventListener('loadeddata', onReady);
                    setTimeout(resolve, 1500); // safety
                });
            }

            // Put the still frame on canvas — this is the "thumbnail" shown before audio
            S.type     = 'video';
            S.vidEl    = firstVid;
            S.img      = null;
            S.alpha    = 1;
            S.alphaOut = 0;
            S.imgOut   = null;
            S.txOffset = null;
            drawFrame(); // explicit draw so canvas has the still before recording starts
        }

        // Load captions — they render on top of the still frame
        loadSceneCaptions(scene.id);
        updateSlotThumbs(scene);
        applySceneSlots(scene);
        updatePanelSceneNumbers();
        currentIndex = index;

        // Two rAF ticks: let canvas paint captions over the still
        await new Promise(res => requestAnimationFrame(res));
        await new Promise(res => requestAnimationFrame(res));

        // Start audio — audio beginning is the signal to release the video
        const audioPromise = playSceneAudio(
            scene,
            isTalkingHeadScene(scene) ? vidEls[(scene.image_file || '').trim()] : null
        );

        // NOW play the video — perfectly in sync with audio, no head-start
        if (firstVid) {
            firstVid.currentTime = 0;
            if (window._recActx && window._recDest) {
                try {
                    const src = window._recActx.createMediaElementSource(firstVid);
                    src.connect(window._recDest);
                    src.connect(window._recActx.destination);
                } catch(_) {}
            }
            firstVid.play().catch(() => {});
        }

        await Promise.all([
            playVideoSequence(scene, videoSlots, audioPromise, true),
            audioPromise
        ]);

    } else {
        // ── PREVIEW MODE: normal cross-fade flow ────────────────────────────
        loadSceneCaptions(scene.id);
        updateSlotThumbs(scene);
        applySceneSlots(scene);
        updatePanelSceneNumbers();

        const audioPromise = playSceneAudio(
            scene,
            isTalkingHeadScene(scene) ? vidEls[(scene.image_file || '').trim()] : null
        );
        await showScene(index, false);

        if (videoSlots.length > 0) {
            await Promise.all([
                playVideoSequence(scene, videoSlots, audioPromise, false),
                audioPromise
            ]);
        } else if (imageSlots.length > 0) {
            const audioDurationMs = await new Promise(resolve => {
                const a = currentAudio;
                if (a && isFinite(a.duration) && a.duration > 0) { resolve(Math.ceil(a.duration * 1000)); return; }
                const fallback = (parseInt(scene.duration) || 5) * 1000;
                if (!a) { resolve(fallback); return; }
                let done = false;
                const finish = (ms) => { if (!done) { done = true; resolve(ms); } };
                a.addEventListener('loadedmetadata', () => finish(Math.ceil(a.duration * 1000)), { once: true });
                setTimeout(() => finish(fallback), 3000);
            });
            console.log(`Scene ${index + 1}: ${imageSlots.length} images, ${(audioDurationMs/1000).toFixed(1)}s audio`);
            await Promise.all([
                playImageSequence(scene, imageSlots, audioDurationMs, audioPromise, false),
                audioPromise
            ]);
        } else {
            await audioPromise;
        }
    }

    // Reset slot highlight after scene
    setTimeout(() => {
        SLOTS.forEach(k => {
            const thumb = document.getElementById('slotThumb_' + k);
            if (thumb) {
                thumb.style.borderColor = 'var(--border)';
                thumb.style.borderWidth = '2px';
            }
        });
    }, 500);
}

// Update the togglePlay function to use the new logic
async function togglePlay() {
    if (isPlaying) {
        stopPlay();
        return;
    }
    
    // Show preload overlay
    const ov = document.getElementById('preloadOverlay');
    if (ov) {
        ov.style.opacity = '1';
        ov.classList.remove('gone');
    }
    
    const msg = document.getElementById('preloadMsg');
    if (msg) msg.textContent = 'Loading all media...';
    
    // Preload ALL scenes at once
    await preloadAll();
    
    // Preload the first scene's media
    await prepareNextScene(0);
    
    // Hide overlay
    if (ov) {
        ov.style.transition = 'opacity .4s';
        ov.style.opacity = '0';
        setTimeout(() => ov.classList.add('gone'), 450);
    }
    
    await sleep(100);
    
    isPlaying = true;
    syncPodcastThumbnail();
    logActivity('play_start', 'from_scene:' + (currentIndex + 1), currentIndex);
    currentIndex = 0;
    
    document.getElementById('playIco').textContent = '⏹';
    document.getElementById('playLbl').textContent = 'Stop';
    
    if (bgAudio) {
        bgAudio.currentTime = 0;
        bgAudio.play().catch(() => {});
    }
    
    // Play all scenes with new logic
    for (let i = 0; i < SCENES.length; i++) {
        if (!isPlaying) break;
        await playSceneWithDynamicSlots(SCENES[i], i, false);
    }
    
    stopPlay();
}

function playAudio(src) {
    return new Promise(res => {
        if (currentAudio) {
            currentAudio.pause();
            currentAudio = null;
        }
        const a = new Audio();

        currentAudio = a;
        a.volume = voiceVolume;
        a.playbackRate = currentPlaybackSpeed;

        // Set src BEFORE connecting to Web Audio — required by some browsers
        a.src = src + '?t=' + Date.now();

        // Ensure speed is applied after metadata loads (some browsers reset it)
        a.onloadedmetadata = () => {
            a.playbackRate = currentPlaybackSpeed;
        };

        // Connect to recorder Web Audio graph after src is set
        if (window._recDest) {
            try {
                const s = window._recActx.createMediaElementSource(a);
                s.connect(window._recDest);
                s.connect(window._recActx.destination);
                // Disconnect when done to avoid leaking nodes in AudioContext graph
                a.addEventListener('ended', () => { try { s.disconnect(); } catch(_) {} }, { once: true });
            } catch (_) {}
        }

        // Resume AudioContext if suspended (can happen on mobile after stop/record)
        if (window._recActx && window._recActx.state === 'suspended') {
            window._recActx.resume().catch(() => {});
        }

        let resolved = false;
        const done = () => { if (!resolved) { resolved = true; res(); } };

        a.onended = done;
        a.onerror = () => sleep(200).then(done);
        a.play().catch(() => sleep(200).then(done));

        // Safety timeout: 10 minutes — only to prevent infinite hang if browser
        // never fires 'ended'. Should never trigger for normal audio files.
        setTimeout(done, 600000);
    });
}

// ── Talking Head / Podcast scene detection ────────────────────────────────
// Returns true when the cron has replaced the scene image with an MP4
// (image_folder is set to 'user_videos' by the cron after sadtalker runs).
function isTalkingHeadScene(sc) {
    const folder = (sc.image_folder || '').trim().replace(/\/+$/, '');
    const fn     = (sc.image_file  || '').trim();
    return folder === 'user_videos' && /\.(mp4|webm|mov|m4v)$/i.test(fn);
}

// ── playSceneAudio ────────────────────────────────────────────────────────
// For talking head scenes: the video element already carries the lip-synced
// audio baked in by sadtalker. We silence the MP3 and instead route the
// video element's audio into the recorder (if recording), then await the
// video's natural end.
// For all other scenes: falls back to the standard playAudio(mp3) path.
function playSceneAudio(sc, vidEl) {
    if (isTalkingHeadScene(sc) && vidEl) {
        return new Promise(res => {
            // Stop any stale MP3 that may be running
            if (currentAudio) { currentAudio.pause(); currentAudio.volume = 0; currentAudio = null; }

            // Talking head video has its own baked-in audio — always play at
            // full volume regardless of the voiceVolume slider (which controls
            // the separate MP3 voice track used by other reel types).
            vidEl.muted  = false;
            vidEl.volume = 1.0;

            // Restart from beginning
            vidEl.currentTime = 0;
            vidEl.play().catch(() => {});

            const cleanup = () => {
                vidEl.removeEventListener('ended', onEnded);
                vidEl.removeEventListener('error', onError);
                clearTimeout(guard);
                // Re-mute so preload / canvas loops stay silent when not playing
                vidEl.muted = true;
                res();
            };
            const onEnded = () => cleanup();
            const onError = () => cleanup();

            const guard = setTimeout(cleanup, 300000); // 5 min safety
            vidEl.addEventListener('ended', onEnded, { once: true });
            vidEl.addEventListener('error', onError,  { once: true });
        });
    }
    // Normal scene — play the MP3
    const af = sc.audio_file;
    return af ? playAudio(AUD_BASE + af) : sleep((parseInt(sc.duration) || 5) * 1000);
}

function stopPlay(){
    isPlaying=false;
    if(currentAudio){currentAudio.pause();currentAudio=null;}
    if(S.vidEl)S.vidEl.pause();
    if(bgAudio)bgAudio.pause();
    document.getElementById('playIco').textContent='▶';
    document.getElementById('playLbl').textContent='Play';
	logActivity('play_stop', 'at_scene:'+(currentIndex+1));
    updateNavButtons();
}

// ── Recording ──────────────────────────────────────────────────────────────
let mr=null,recChunks=[],recBlob=null,recURL=null;

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

    // START RECORDING AND PLAY SCENES
    await new Promise(res => requestAnimationFrame(res));

    mr.start(500);
    isRecording = true;
    canvas.classList.add('recording');
    document.getElementById('recBar').classList.add('on');
    document.getElementById('dlPanel').classList.remove('on');
    document.getElementById('ibRecord').innerHTML = '<span class="ico">⏹</span>Stop';
    updateNavButtons();
    L('Recording…', 'inf');
    syncPodcastThumbnail();
    logActivity('record_start', 'scenes:' + SCENES.length);
    
    if (bgAudio) {
        bgAudio.currentTime = 0;
        bgAudio.play().catch(() => {});
    }
    
    console.log('\n========== RECORDING START ==========');
    
    // THE MAIN SCENE LOOP
    for (let i = 0; i < SCENES.length; i++) {
        if (!isRecording) break;
        await playSceneWithDynamicSlots(SCENES[i], i, true);
        if (!isRecording) break;
    }
    
    if (bgAudio) bgAudio.pause();
    if (currentAudio) {
        currentAudio.pause();
        currentAudio = null;
    }
    if (mr && mr.state !== 'inactive') {
        mr.stop();
        L('Stopping recording…', 'inf');
    } else {
        L('mr state: ' + (mr ? mr.state : 'null'), 'err');
    }
}








// ========== HELPER FUNCTIONS (place OUTSIDE startRecording) ==========




// Update video status to RECORDED



// Show MP4 download modal





function discardRec(){
    if(recURL){URL.revokeObjectURL(recURL);recURL=null;}
    recBlob=null;recChunks=[];
    document.getElementById('dlPanel').classList.remove('on');
}

// ── Image panel ────────────────────────────────────────────────────────────
const SLOT_PROMPT_MAP={image_file:'prompt',image_file_1:'prompt_1',image_file_2:'prompt_2',image_file_3:'prompt_3',image_file_4:'prompt_4'};
const SLOT_LABELS={image_file:'Main',image_file_1:'V1',image_file_2:'V2',image_file_3:'V3',image_file_4:'V4'};
// ── Slot checkbox helpers ──────────────────────────────────────────
function getEnabledSlots() {
    var enabled = [];
    var slots = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4'];
    for (var i = 0; i < slots.length; i++) {
        var chk = document.getElementById('slotChk_' + slots[i]);
        if (chk && chk.checked) enabled.push(slots[i]);
    }
    return enabled;
}
async function selectSlot(slot) {
    activeSlot = slot;

    // Highlight the active slot thumb
    SLOTS.forEach(k => {
        const t = document.getElementById('slotThumb_' + k);
        if (t) t.classList.toggle('active-slot', k === slot);
    });

    const lbl = document.getElementById('selSlotName');
    if (lbl) lbl.textContent = SLOT_LABELS[slot] || slot;

    const sc = SCENES[currentIndex];
    const ta = document.getElementById('slotPrompt');
    if (ta) ta.value = sc[SLOT_PROMPT_MAP[slot]] || sc.prompt || '';

    // Refresh all slot thumbnails so the strip always shows current media
    updateSlotThumbs(sc);

    // Radio behaviour: select only this slot, deselect all others, save to DB
    // Use _slotSelectInProgress to prevent onSlotChkChange firing during this
    window._slotSelectInProgress = true;
    SLOTS.forEach(k => {
        const chk = document.getElementById('slotChk_' + k);
        if (chk) chk.checked = (k === slot);
    });
    window._slotSelectInProgress = false;
    saveSlotSelections(sc.id);

    // Show this slot's image on canvas immediately — load if not yet cached
    const fn = (sc[slot] || '').trim();
    if (fn) {
        const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
        if (isVid && !vidEls[fn]) {
            const v = document.createElement('video');
            v.src = getSceneFolder(sc, slot) + fn;
            v.muted = true; v.loop = true; v.playsInline = true;
            v.crossOrigin = 'anonymous'; v.preload = 'auto';
            document.getElementById('vidPool').appendChild(v);
            vidEls[fn] = v;
            await new Promise(res => {
                v.addEventListener('canplaythrough', res, { once: true });
                v.addEventListener('error', res, { once: true });
                setTimeout(res, 8000); v.load();
            });
        } else if (!isVid && !imgCache[fn]) {
            await new Promise(res => {
                const i = new Image();
                i.crossOrigin = 'anonymous';
                i.onload  = () => { imgCache[fn] = i; res(); };
                i.onerror = () => res();
                setTimeout(res, 8000);
                i.src = getSceneFolder(sc, slot) + fn + '?t=' + Date.now();
            });
        }
        showSlotOnCanvas(sc, slot);
        // Update the outside scene strip thumbnail to match selected slot media
        // Now handles both images AND videos
        ssUpdateThumb(currentIndex, getSceneFolder(sc, slot) + fn);
        updateSlotThumbs(sc);
    }
}

// Save current slot checkbox states to database
async function saveSlotSelections(sceneId) {
    const sc = SCENES.find(s => s.id === sceneId);
    if (!sc) return;

    const fd = new FormData();
    fd.append('ajax_action', 'save_enabled_slots');
    fd.append('scene_id', sc.id);

    SLOTS.forEach(k => {
        const chk = document.getElementById('slotChk_' + k);
        const col = SLOT_COL_MAP[k];
        const val = (chk && chk.checked) ? 1 : 0;
        if (!SCENE_SAVED_SLOTS[sc.id]) SCENE_SAVED_SLOTS[sc.id] = {};
        SCENE_SAVED_SLOTS[sc.id][col] = val;
        fd.append(col, val);
    });

    try {
        await fetch(location.href, { method: 'POST', body: fd });
    } catch(e) {}
}

function onSlotChkChange() {
    if (!bootComplete) return; // ignore browser-restored checkbox events during boot
    if (window._slotSelectInProgress) return; // ignore programmatic changes from selectSlot
    window._checkboxClickOverride = true;

    const sc = SCENES[currentIndex];
    if (!sc) return;

    const changedCheckbox = event ? event.target : null;
    let changedSlot = null;
    if (changedCheckbox) {
        for (let i = 0; i < SLOTS.length; i++) {
            if (changedCheckbox.id === 'slotChk_' + SLOTS[i]) {
                changedSlot = SLOTS[i];
                break;
            }
        }
    }

    // If unchecked and nothing else is checked, restore main slot
    if (changedSlot && !changedCheckbox.checked) {
        let anyChecked = false;
        for (let i = 0; i < SLOTS.length; i++) {
            const chk = document.getElementById('slotChk_' + SLOTS[i]);
            if (chk && chk.checked) { anyChecked = true; break; }
        }
        if (!anyChecked) {
            const mainChk = document.getElementById('slotChk_image_file');
            if (mainChk) mainChk.checked = true;
        }
    }

    // Save all current checkbox states
    saveSlotSelections(sc.id);

    // Show the newly checked slot's image on canvas immediately
    if (changedSlot && changedCheckbox.checked) {
        const fn = (sc[changedSlot] || '').trim();
        if (fn && !imgCache[fn] && !imgCache['_loading_' + fn]) {
            // Load image first then show
            imgCache['_loading_' + fn] = true;
            const i = new Image();
            i.crossOrigin = 'anonymous';
            i.onload  = () => { imgCache[fn] = i; delete imgCache['_loading_' + fn]; showSlotOnCanvas(sc, changedSlot); };
            i.onerror = () => { imgCache[fn] = null; delete imgCache['_loading_' + fn]; };
            i.src = getSceneFolder(sc, changedSlot) + fn + '?t=' + Date.now();
        } else {
            showSlotOnCanvas(sc, changedSlot);
        }
    } else {
        // Slot was unchecked — show first remaining checked slot
        updateCurrentDisplayOnly();
    }

    setTimeout(() => { window._checkboxClickOverride = false; }, 100);
}

// Update the slot selection when changing scenes
function applySceneSlots(sc) {
    if (!sc) return;
    
    const saved = SCENE_SAVED_SLOTS[sc.id];
    
    for (let i = 0; i < SLOTS.length; i++) {
        const k = SLOTS[i];
        const chk = document.getElementById('slotChk_' + k);
        if (chk) {
            const col = SLOT_COL_MAP[k];
            if (saved && saved[col] !== undefined) {
                chk.checked = saved[col] === 1;
            } else {
                // Default: only main slot checked
                chk.checked = (k === 'image_file');
            }
        }
    }
    
    // Ensure at least one slot is checked
    let anyChecked = false;
    for (let i = 0; i < SLOTS.length; i++) {
        const chk = document.getElementById('slotChk_' + SLOTS[i]);
        if (chk && chk.checked) {
            anyChecked = true;
            break;
        }
    }
    
    if (!anyChecked) {
        const mainChk = document.getElementById('slotChk_image_file');
        if (mainChk) mainChk.checked = true;
    }
}

// Show a specific slot's media on canvas immediately.
// Called after any assignment (upload, library, generate, selectSlot).
// Media must already be in imgCache/vidEls before calling — callers load it first.
function showSlotOnCanvas(sc, slot) {
    const fn = (sc[slot] || '').trim();
    if (!fn) return;
    const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    if (isVid && vidEls[fn]) {
        if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
        S.type = 'video'; S.vidEl = vidEls[fn];
        S.img = null; S.alpha = 1; S.alphaOut = 0; S.imgOut = null;
        try { vidEls[fn].currentTime = 0; vidEls[fn].play(); } catch(e) {}
    } else if (!isVid && imgCache[fn]) {
        if (S.vidEl) S.vidEl.pause();
        S.type = 'image'; S.img = imgCache[fn];
        S.vidEl = null; S.alpha = 1; S.alphaOut = 0; S.imgOut = null;
        S.kbEffect = sceneKB[currentIndex];
        S.kbStart = performance.now();
    }
}

function updateCurrentDisplayOnly() {
    var sc = SCENES[currentIndex];
    if (!sc) return;
    
    var enabledSlots = getEnabledSlots();
    var foundFile = false;
    
    // Find first checked slot that has media
    for (var i = 0; i < enabledSlots.length; i++) {
        var slot = enabledSlots[i];
        var fn = (sc[slot] || '').trim();
        if (fn) {
            foundFile = true;
            var isVideo = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
            
            if (isVideo && vidEls[fn]) {
                if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
                S.type = 'video';
                S.vidEl = vidEls[fn];
                S.img = null;
                try { vidEls[fn].currentTime = 0; vidEls[fn].play(); } catch(e) {}
            } else if (imgCache[fn]) {
                if (S.vidEl) S.vidEl.pause();
                S.type = 'image';
                S.img = imgCache[fn];
                S.vidEl = null;
                S.kbEffect = sceneKB[currentIndex];
                S.kbStart = performance.now();
            }
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            break;
        }
    }
    
    // Show warning if no media found in checked slots
    if (!foundFile && enabledSlots.length > 0) {
        console.warn('No media files found in checked slots:', enabledSlots);
        // Optional: show a toast notification
        if (typeof L === 'function') {
            L('⚠️ Checked slots have no media files. Upload images/videos to these slots.', 'wrn');
        }
    }
}
function updateSlotThumbs(sc) {
    SLOTS.forEach(k => {
        const fn = (sc[k] || '').trim();
        const img = document.getElementById('slotImg_' + k);
        const ph  = document.getElementById('slotPh_'  + k);
        const th  = document.getElementById('slotThumb_' + k);

        if (fn) {
            // Populate FILE_FOLDER for this slot by calling getSceneFolder
            const folder = getSceneFolder(sc, k); // e.g. "podcast_images/"
            const isVid  = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);

            if (img) {
                if (isVid) {
                    // Videos: render a <video> element as thumbnail inside the slot-thumb div
                    const th2 = document.getElementById('slotThumb_' + k);
                    if (th2) {
                        // Remove any existing video element first
                        const oldVid = th2.querySelector('video.slot-vid-thumb');
                        if (oldVid) oldVid.remove();

                        // Hide the img placeholder, show video instead
                        img.style.display = 'none';
                        if (ph) ph.style.display = 'none';

                        const vidEl = document.createElement('video');
                        vidEl.className     = 'slot-vid-thumb';
                        vidEl.src           = folder + fn;
                        vidEl.muted         = true;
                        vidEl.loop          = false;
                        vidEl.playsInline   = true;
                        vidEl.preload       = 'metadata';
                        vidEl.currentTime   = 0.5;  // seek to 0.5s to get a frame
                        vidEl.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit;';
                        vidEl.onerror = function() {
                            this.remove();
                            img.style.display = 'none';
                            if (ph) { ph.textContent = '🎬'; ph.style.display = 'flex'; }
                        };
                        // Insert before the img element
                        th2.insertBefore(vidEl, img);
                    }
                } else {
                    // Images: use actual folder path directly — no guessing
                    const imgSrc = folder + fn;
                    img.onerror = function() {
                        // One fallback: try podcast_thumbnails
                        if (this.src.indexOf('podcast_thumbnails') === -1) {
                            this.src = 'podcast_thumbnails/' + fn;
                        } else {
                            this.style.display = 'none';
                            if (ph) { ph.textContent = '🖼️'; ph.style.display = 'flex'; }
                        }
                    };
                    img.src           = imgSrc + '?t=' + Date.now();
                    img.style.display = 'block';
                    if (ph) ph.style.display = 'none';
                }
            }
        } else {
            if (img) { img.src = ''; img.style.display = 'none'; }
            // Remove any leftover video thumbnail
            const th3 = document.getElementById('slotThumb_' + k);
            if (th3) { const ov = th3.querySelector('video.slot-vid-thumb'); if (ov) ov.remove(); }
            if (ph)  { ph.textContent = '🖼️'; ph.style.display = 'flex'; }
        }

        if (th) th.classList.toggle('active-slot', k === activeSlot);
    });
}


async function saveSlotPrompt(){
    const sc=SCENES[currentIndex];
    const ta=document.getElementById('slotPrompt');if(!ta)return;
    const val=ta.value.trim(),field=SLOT_PROMPT_MAP[activeSlot];
    sc[field]=val;
    const fd=new FormData();fd.append('ajax_action','save_prompt');
    fd.append('scene_id',sc.id);fd.append('prompt_field',field);fd.append('prompt',val);
    await fetch(location.href,{method:'POST',body:fd});
}

function uploadForSlot(){const inp=document.getElementById('slotFileInput');if(inp){inp.value='';inp.click();}}

async function handleSlotUpload(input){
    if(!input.files||!input.files[0])return;
    const file=input.files[0];
    const sc=SCENES[currentIndex];
    const isVid=file.type.startsWith('video/');
    L('Uploading '+(isVid?'video':'image')+': '+file.name+' ('+Math.round(file.size/1024/1024,1)+' MB)…','inf');
    const fd=new FormData();
    fd.append('ajax_action','upload_scene_image');fd.append('scene_id',sc.id);
    fd.append('image_field',activeSlot);fd.append('media_type',isVid?'video':'image');fd.append('scene_image',file);
    try{
        const r=await fetch(location.href,{method:'POST',body:fd});
        const text=await r.text();
        let data;
        try{ data=JSON.parse(text); }
        catch(e){
            // Server returned HTML (likely php.ini upload limit exceeded)
            L('Upload failed: Server rejected file — likely exceeds upload_max_filesize in php.ini. Current file: '+Math.round(file.size/1024/1024)+'MB','err');
            return;
        }
        if(!data.success)throw new Error(data.message||'Upload failed');
        sc[activeSlot]=data.filename;
        // Update the per-slot folder column on the scene object
        const folderCol = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
        if(data.image_folder){ sc[folderCol]=data.image_folder; FILE_FOLDER[data.filename]=data.image_folder.replace(/\/?$/,'/');}
        if(!isVid){
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[data.filename]=img;showSlotOnCanvas(sc,activeSlot);};
            img.onerror=()=>{};
            img.src=getSceneFolder(sc,activeSlot)+data.filename+'?t='+Date.now();
        } else { showSlotOnCanvas(sc,activeSlot); }
        updateSlotThumbs(sc);
        // Update scene strip thumbnail for both images and videos
        ssUpdateThumb(currentIndex, getSceneFolder(sc, activeSlot) + data.filename);
        L('Uploaded to your folder: '+data.filename,'ok');
        logActivity('image_uploaded', 'slot:'+activeSlot+' file:'+data.filename, currentIndex);
    }catch(e){L('Upload failed: '+e.message,'err');}
}

// ── Library Modal ──────────────────────────────────────────────────────────
let _libImgs=[],_libVids=[],_libMine=[],_libSelectedFile=null,_libTab='all';



function openLibraryModal() {
    const modal = document.getElementById('libModal');
    if (modal) modal.style.display = 'flex';
    const lbl = document.getElementById('libSlotLabel');
    if (lbl) lbl.textContent = SLOT_LABELS[activeSlot] || activeSlot;
    _libSelectedFile = null;
    _resetLibSel();
    
    const sc = SCENES[currentIndex];
    let searchQuery = '';
    
    // Use natural_language_tags as search query
    if (sc.natural_language_tags && sc.natural_language_tags.trim()) {
        searchQuery = sc.natural_language_tags.trim().split('|')[0].trim();
    } else if (sc.hashtags && sc.hashtags.trim()) {
        searchQuery = sc.hashtags.trim().split(/\s+/)[0].replace(/^#/, '');
    }
    
    const searchInput = document.getElementById('libSearch');
    if (searchInput) searchInput.value = searchQuery;
    
    const status = document.getElementById('libSearchStatus');
    if (status) {
        status.style.display = 'block';
        status.textContent = searchQuery ? `🔍 Searching: "${searchQuery.substring(0, 50)}"…` : '📂 Loading media…';
    }
    
    // AUTOMATICALLY SEARCH based on scene NL tags
    if (searchQuery) {
        performLibSearch();  // This will use the search_media_nl action
    } else {
        _loadRecentLibFiles();
    }
}


function closeLibraryModal(){const modal=document.getElementById('libModal');if(modal)modal.style.display='none';_libSelectedFile=null;}
function _resetLibSel(){
    const useBtn=document.getElementById('libUseBtn');if(useBtn){useBtn.disabled=true;useBtn.style.opacity='.4';}
    const info=document.getElementById('libSelInfo');if(info)info.textContent='No file selected';
}

function _updateLibCounts(){
    const all=document.getElementById('libCountAll');const img=document.getElementById('libCountImg');const vid=document.getElementById('libCountVid');const mine=document.getElementById('libCountMine');
    const total=_libImgs.length+_libVids.length;
    if(all)all.textContent=total;if(img)img.textContent=_libImgs.length;if(vid)vid.textContent=_libVids.length;if(mine)mine.textContent=_libMine.length;
}
// Replace the performLibSearch function 



// Update setLibTab to pass the tab type
// Replace the entire library modal section with this:

async function _loadRecentLibFiles() {
    const grid = document.getElementById('libGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading…</div>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_library_files');
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        const files = data.files || [];
        _libImgs = files.filter(f => f.media_type !== 'video').map(f => ({ filename: f.filename, media_type: 'image', nl_tags: f.natural_language_tags || '', matched_line: '', matched_segment: '', score: 0, thumbnail: '' }));
        _libVids = files.filter(f => f.media_type === 'video').map(f => ({ filename: f.filename, media_type: 'video', nl_tags: f.natural_language_tags || '', matched_line: '', matched_segment: '', score: 0, thumbnail: '' }));
        _updateLibCounts();
        _renderLibGrid();
    } catch (e) {
        if (grid) grid.innerHTML = '<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Failed to load files</div>';
    }
}

async function _loadMyUploads() {
    const grid = document.getElementById('libGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading your media…</div>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_media');
        const r    = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        if (!data.has_folder || !data.files || data.files.length === 0) {
            _libMine = [];
            const mine = document.getElementById('libCountMine');
            if (mine) mine.textContent = '0';
            if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);"><div style="font-size:36px;margin-bottom:10px;">🗂️</div><div style="font-weight:600;margin-bottom:6px;">No media files found</div><div style="font-size:11px;line-height:1.5;">Use <strong>📤 Upload</strong> to save files to your personal folder.</div></div>';
            return;
        }
        window._userMediaFolder = (data.folder || USER_MEDIA_FOLDER).replace(/\/?$/, '/');
        _libMine = data.files.map(function(f){ return {filename:f.filename, media_type:f.media_type||'image', nl_tags:'', score:0, thumbnail:'', is_user_media:true}; });
        const mine = document.getElementById('libCountMine');
        if (mine) mine.textContent = _libMine.length;
        _renderLibGrid();
    } catch (e) {
        if (grid) grid.innerHTML = '<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Failed to load your media</div>';
    }
}

// SINGLE VERSION - KEEP ONLY THIS ONE
async function _performLibSearchWithQuery(query, tabType = _libTab) {
    const grid = document.getElementById('libGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">🔍 Searching…</div>';
    const status = document.getElementById('libSearchStatus');
    if (status) { status.style.display = 'block'; status.textContent = `Searching: "${query.substring(0, 60)}"…`; }
    
    try {
        let mediaTypeFilter = '';
        if (tabType === 'image') mediaTypeFilter = 'image';
        else if (tabType === 'video') mediaTypeFilter = 'video';
        
        let tabTypeParam = tabType === 'mine' ? 'mine' : 'all';
        
        const fd = new FormData();
        fd.append('ajax_action', 'search_media_nl');
        fd.append('query', query);
        fd.append('media_type_filter', mediaTypeFilter);
        fd.append('tab_type', tabTypeParam);
        
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const results = await r.json();
        
        _libImgs = (Array.isArray(results) ? results : []).filter(r => r.type !== 'video');
        _libVids = (Array.isArray(results) ? results : []).filter(r => r.type === 'video');
        
        _updateLibCounts();
        _renderLibGrid();
        
        const total = _libImgs.length + _libVids.length;
        if (status) {
            status.style.display = 'block';
            status.textContent = total ? `✅ ${_libImgs.length} images · ${_libVids.length} videos` : '❌ No results found';
        }
    } catch (e) {
        console.error('Library search error:', e);
        L('Library search error: ' + e.message, 'err');
        _loadRecentLibFiles();
    }
} 

async function performLibSearch() {
    const query = (document.getElementById('libSearch')?.value || '').trim();
    if (!query) { _loadRecentLibFiles(); return; }
    await _performLibSearchWithQuery(query, _libTab);
}

function setLibTab(type) {
    _libTab = type;
    ['All', 'Img', 'Vid', 'Mine'].forEach(n => {
        const btn = document.getElementById('libTab' + n);
        if (!btn) return;
        const isOn = (n === 'All' && type === 'all') || 
                     (n === 'Img' && type === 'image') || 
                     (n === 'Vid' && type === 'video') || 
                     (n === 'Mine' && type === 'mine');
        btn.style.background = isOn ? 'var(--primary)' : 'var(--surface2)';
        btn.style.borderColor = isOn ? 'var(--primary)' : 'var(--border)';
        btn.style.color = isOn ? '#fff' : 'var(--muted)';
    });
    
    if (type === 'mine') {
        _loadMyUploads();
    } else {
        const query = document.getElementById('libSearch')?.value || '';
        if (query.trim()) {
            _performLibSearchWithQuery(query, type);
        } else {
            _loadRecentLibFiles();
        }
    }
}





function _renderLibGrid(){
    const grid=document.getElementById('libGrid');if(!grid)return;
    const files=_libTab==='video'?_libVids:_libTab==='image'?_libImgs:_libTab==='mine'?_libMine:[..._libImgs,..._libVids];
    if(!files.length){
        const emptyIcon=_libTab==='video'?'🎬':_libTab==='mine'?'🗂️':'🖼️';
        const emptyMsg=_libTab==='mine'?'No media in your folder — use 📤 Upload to add files':'No '+(_libTab==='all'?'media':_libTab+'s')+' found';
        grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);"><div style="font-size:36px;margin-bottom:10px;">${emptyIcon}</div><div>${emptyMsg}</div></div>`;return;
    }
    grid.style.gridTemplateColumns='repeat(2,1fr)';
    grid.innerHTML=files.map(f=>{
        const isVid=f.media_type==='video';const score=f.score||0;
        let borderC,scoreBg,scoreClr,qlabel;
        if(score>=0.5){borderC='#10b981';scoreBg='#dcfce7';scoreClr='#166534';qlabel='🟢';}
        else if(score>=0.35){borderC='#f59e0b';scoreBg='#fef9c3';scoreClr='#854d0e';qlabel='🟡';}
        else if(score>0){borderC='#ef4444';scoreBg='#fee2e2';scoreClr='#991b1b';qlabel='🔴';}
        else{borderC='#e2e8f0';scoreBg='#f1f5f9';scoreClr='#64748b';qlabel='';}
        const scoreBadge=score>0?`<div style="position:absolute;top:5px;right:5px;background:${scoreBg};color:${scoreClr};padding:2px 6px;border-radius:8px;font-size:10px;font-weight:700;z-index:10;">${qlabel} ${Math.round(score*100)}%</div>`:'';
        const vidBadge=isVid?`<div style="position:absolute;top:5px;left:5px;background:rgba(0,0,0,.65);color:#fff;padding:2px 6px;border-radius:8px;font-size:9px;font-weight:600;">🎬</div>`:'';
        const thumb=(f.thumbnail||'').trim();
        const fileBaseFolder = f.is_user_media ? (window._userMediaFolder||USER_MEDIA_FOLDER) : getFileFolder(f.filename);
        let mediaHtml;
        if(isVid){mediaHtml=thumb?`<div style="position:relative;width:100%;padding-top:177.78%;overflow:hidden;"><img src="podcast_thumbnails/${thumb}" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.style.display='none';this.nextSibling.style.display='flex'"><div style="display:none;position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(135deg,#0f172a,#1e3a5f);align-items:center;justify-content:center;font-size:30px;">🎬</div></div>`:` <div style="position:relative;width:100%;padding-top:177.78%;background:linear-gradient(135deg,#0f172a,#1e3a5f);"><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:30px;">🎬</div></div>`;}
        else{const src=thumb?`podcast_thumbnails/${thumb}`:`${fileBaseFolder}${f.filename}`;mediaHtml=`<div style="position:relative;width:100%;padding-top:177.78%;overflow:hidden;"><img src="${src}" data-orig="${fileBaseFolder}${f.filename}" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;" loading="lazy" onerror="if(this.src.indexOf('podcast_thumbnails')!==-1){this.src=this.dataset.orig;}else{this.style.display='none';this.nextSibling.style.display='flex';}"><div style="display:none;position:absolute;top:0;left:0;width:100%;height:100%;background:#e2e8f0;align-items:center;justify-content:center;font-size:22px;color:#94a3b8;">🖼️</div></div>`;}
        const seg=(f.matched_segment||'').trim();const line=(f.matched_line||'').trim();
        const tagHtml=(seg||line)?`<div style="padding:4px 6px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:9px;color:#475569;line-height:1.4;overflow:hidden;max-height:36px;">${seg?`<span style="color:#0369a1;font-weight:600;">${seg.substring(0,44)}</span>`:''}${line&&line!==seg?`<br><span style="color:#64748b;">${line.substring(0,48)}</span>`:''}</div>`:`<div style="padding:4px 6px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:9px;color:#94a3b8;">${f.is_user_media?'👤 ':''}${f.filename.substring(0,28)}</div>`;
        return `<div onclick="pickLibFile(this,'${f.filename}','${f.media_type}')" data-folder="${fileBaseFolder}" style="position:relative;border:2px solid ${borderC};border-radius:10px;cursor:pointer;background:white;transition:border-color .15s,box-shadow .15s;overflow:hidden;">${mediaHtml}${scoreBadge}${vidBadge}<div class="media-check" style="position:absolute;top:5px;left:5px;background:#10b981;color:white;width:22px;height:22px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:13px;font-weight:700;z-index:20;">✓</div>${tagHtml}</div>`;
    }).join('');
}
function pickLibFile(el,filename,type){
    document.querySelectorAll('#libGrid > div').forEach(d=>{d.style.borderColor='var(--border)';const chk=d.querySelector('.media-check');if(chk)chk.style.display='none';});
    el.style.borderColor='var(--info)';const chk=el.querySelector('.media-check');if(chk)chk.style.display='flex';
    _libSelectedFile={filename,type,folder:el.dataset.folder||null};
    const info=document.getElementById('libSelInfo');if(info)info.textContent=filename;
    const btn=document.getElementById('libUseBtn');if(btn){btn.disabled=false;btn.style.opacity='1';}
}
async function useLibraryFile(){
	
	
	// ── Image caption library pick mode ──
    if (window._imgCapLibMode) {
        window._imgCapLibMode = false;
        if (_libSelectedFile) {
            closeLibraryModal();
            if (window._imgCapLibResolve) {
                window._imgCapLibResolve(_libSelectedFile.filename);
                window._imgCapLibResolve = null;
            }
        }
        return;
    }
    if(!_libSelectedFile)return;
	
	
	
	
    const{filename,type}=_libSelectedFile;
    const sc=SCENES[currentIndex];
    const fd=new FormData();
    fd.append('ajax_action','assign_image');
    fd.append('scene_id',sc.id);
    fd.append('filename',filename);
    fd.append('image_field',activeSlot);
    fd.append('media_type',type);

    // ── Determine the correct image_folder to save ────────────────────────
    // Mine tab  → user_id_X_company_id_Y  (strip 'user_media/' prefix)
    // image tab → podcast_images
    // video tab → podcast_videos
    // all tab   → detect from file extension
    const isVidFile = type==='video' || /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);
    let folderToSave;
    if (_libTab === 'mine' || (_libSelectedFile.folder && _libSelectedFile.folder.indexOf('user_media') !== -1)) {
        // Strip 'user_media/' prefix — DB stores only the subfolder name
        const rawFolder = (_libSelectedFile.folder || USER_MEDIA_FOLDER).replace(/\/?$/, '');
        folderToSave = rawFolder.replace(/^user_media\//, '');
    } else {
        folderToSave = isVidFile ? 'podcast_videos' : 'podcast_images';
    }
    fd.append('folder_override', folderToSave);
    const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
    if(data.success){
        sc[activeSlot]=filename;
        // Update the per-slot folder column on the scene object
        const folderCol2 = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
        if(data.image_folder){sc[folderCol2]=data.image_folder;FILE_FOLDER[filename]=data.image_folder.replace(/\/?$/,'/');}
        const isVid=type==='video'||/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);
        if(isVid){
            if(!vidEls[filename]){
                const v=document.createElement('video');v.src=getSceneFolder(sc,activeSlot)+filename+'?t='+Date.now();v.muted=true;v.loop=true;v.playsInline=true;v.crossOrigin='anonymous';v.preload='auto';
                document.getElementById('vidPool').appendChild(v);vidEls[filename]=v;
                await new Promise(res=>{v.addEventListener('canplaythrough',res,{once:true});v.addEventListener('error',res,{once:true});setTimeout(res,15000);v.load();});
            }
            showSlotOnCanvas(sc,activeSlot);
        } else {
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[filename]=img;showSlotOnCanvas(sc,activeSlot);};img.onerror=()=>{};
            img.src=getSceneFolder(sc,activeSlot)+filename+'?t='+Date.now();
        }
        updateSlotThumbs(sc);closeLibraryModal();L('Assigned: '+filename,'ok');
		logActivity('image_from_library', 'slot:'+activeSlot+' file:'+filename, currentIndex);
    }else{L('Assign failed','err');}
}

// ── Generate image / video ──────────────────────────────────────────────────
async function generateForSlot(genType) {
    genType = genType || 'image'; // 'image' or 'video'
    const sc = SCENES[currentIndex];
    const ta = document.getElementById('slotPrompt');
    const prompt = (ta?.value.trim()) || sc[SLOT_PROMPT_MAP[activeSlot]] || sc.prompt || '';
    
    if (!prompt) {
        alert('No prompt for this slot.');
        return;
    }
    
    const btnId = genType === 'video' ? 'btnGenerateVideo' : 'btnGenerateImage';
    const btn = document.getElementById(btnId);
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.classList.add('btn-generate-loading');
        btn.innerHTML = genType === 'video'
            ? '<span style="font-size:15px;">⏳</span><span>Wait…</span><span style="font-size:9px;">$0.50</span>'
            : '<span style="font-size:15px;">⏳</span><span>Wait…</span><span style="font-size:9px;">$0.08</span>';
        btn.disabled = true;
    }
    
    // Show spinner on the slot thumbnail
    const slotThumb = document.getElementById('slotThumb_' + activeSlot);
    if (slotThumb) {
        slotThumb.classList.add('slot-thumb-loading');
        slotThumb._originalContent = slotThumb.innerHTML;
    }
    
    L('Generating ' + genType + '…', 'inf');
    
    const fd = new FormData();
    fd.append('ajax_action', genType === 'video' ? 'generate_video' : 'generate_image');
    fd.append('podcast_id', PODCAST_ID);
    fd.append('scene_id', sc.id);
    fd.append('image_field', activeSlot);
    fd.append('enhanced_prompt', prompt);
    fd.append('hashtags', sc.hashtags || '');
    
    try {
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();

        if (!data.success) throw new Error(data.message || 'Generation failed');

        // ── Video: queued — no file yet ───────────────────────────────────────
        if (genType === 'video') {
            const msg = data.message || `Your video will be ready in approximately ${data.minutes || 50} minutes.`;
            L('🎬 Queued: ' + msg, 'ok');
            alert(msg);
            logActivity('video_gen_queued', 'slot:' + activeSlot + ' pos:' + (data.position || '?'), currentIndex);
            return;
        }

        // ── Image: file returned immediately ─────────────────────────────────
        const fn = data.image_name || data.filename;
        if (!fn) throw new Error('No filename returned');

        sc[activeSlot] = fn;
        const folderCol = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
        sc[folderCol]   = 'podcast_images';
        FILE_FOLDER[fn] = 'podcast_images/';

        await new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            const timeout = setTimeout(() => reject(new Error('Image load timeout')), 30000);
            img.onload  = () => { clearTimeout(timeout); imgCache[fn] = img; resolve(); };
            img.onerror = () => { clearTimeout(timeout); reject(new Error('Failed to load generated image')); };
            img.src = getSceneFolder(sc, activeSlot) + fn + '?nocache=' + Date.now();
        });

        showSlotOnCanvas(sc, activeSlot);
        updateSlotThumbs(sc);
        ssUpdateThumb(currentIndex, 'podcast_images/' + fn);
        L('✅ Generated image: ' + fn, 'ok');
        logActivity('image_generated', 'slot:' + activeSlot, currentIndex);

    } catch (e) {
        L('❌ Generate failed: ' + e.message, 'err');
        console.error('Generation error:', e);
    } finally {
        if (btn) {
            btn.classList.remove('btn-generate-loading');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
        if (slotThumb) {
            slotThumb.classList.remove('slot-thumb-loading');
        }
    }
}

let loadingToast = null;

function showLoadingToast(message) {
    // Remove existing toast
    if (loadingToast) {
        loadingToast.remove();
    }
    
    loadingToast = document.createElement('div');
    loadingToast.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--primary);
        color: white;
        padding: 10px 20px;
        border-radius: 30px;
        font-size: 12px;
        font-weight: 600;
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    `;
    
    loadingToast.innerHTML = `
        <div style="width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3);
                    border-top-color: white; border-radius: 50%; animation: spin 0.7s linear infinite;"></div>
        <span>${message}</span>
    `;
    
    document.body.appendChild(loadingToast);
}

function hideLoadingToast() {
    if (loadingToast) {
        loadingToast.remove();
        loadingToast = null;
    }
}

let generationStartTime = null;
let generationTimer = null;

function startGenerationTimer() {
    generationStartTime = Date.now();
    const timerDisplay = document.createElement('div');
    timerDisplay.id = 'genTimer';
    timerDisplay.style.cssText = `
        position: fixed;
        top: 10px;
        right: 10px;
        background: rgba(0,0,0,0.7);
        color: #fff;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-family: monospace;
        z-index: 10001;
    `;
    document.body.appendChild(timerDisplay);
    
    generationTimer = setInterval(() => {
        const elapsed = Math.floor((Date.now() - generationStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        timerDisplay.textContent = `⏱️ Generating... ${minutes}:${seconds.toString().padStart(2,'0')}`;
    }, 1000);
}

function stopGenerationTimer() {
    if (generationTimer) {
        clearInterval(generationTimer);
        generationTimer = null;
    }
    const timerDisplay = document.getElementById('genTimer');
    if (timerDisplay) timerDisplay.remove();
}
// ── Audio panel ────────────────────────────────────────────────────────────
let _currentPodcastMusic='<?= addslashes($podcast_music) ?>';
let _hostVoiceId='<?= addslashes($host_voice_id) ?>';
let _guestVoiceId='<?= addslashes($guest_voice_id) ?>';
let _audioSaveTimer=null;
let _musicPreviewAudio=null;
let _musicLibFiles=[];
let _selectedMusicFile=null;
// ── Voice panel state ──────────────────────────────────────────────
let _allVoices        = [];   // full list from server
let _voiceGenderFilter = 'all';
let _voiceTarget       = 'host';   // 'host' | 'guest'
let _selectedVoiceKey  = '';
let _voicePreviewAudio = null;
let _audCapId          = null;   // caption id being edited in audio tab
let _audCapSaveTimer   = null;

function showAudioSub(id) {
    // Tab switching no longer needed — Voice + Music are merged into one view.
    // Still call data loaders so existing code paths work.
    if (id === 'audSub2') _renderCurrentMusic();
    if (id === 'audSub3') _loadVoicePanel();
}
function onMusicVolChange(val) {
    bgMusicVolume = parseFloat(val) / 100;
    const lbl = document.getElementById('musicVolLbl');
    if (lbl) lbl.textContent = Math.round(val) + '%';
    if (bgAudio) bgAudio.volume = bgMusicVolume;
    if (_musicPreviewAudio) _musicPreviewAudio.volume = bgMusicVolume;
}

function onVoiceVolChange(val) {
    voiceVolume = parseFloat(val) / 100;
    const lbl = document.getElementById('voiceVolLbl');
    if (lbl) lbl.textContent = Math.round(val) + '%';
    // Apply to all possible audio elements
    if (currentAudio) currentAudio.volume = voiceVolume;
    const apEl = document.getElementById('apEl');
    if (apEl) apEl.volume = voiceVolume;
    if (_voicePreviewAudio) _voicePreviewAudio.volume = voiceVolume;
}
async function loadAudioPanel() {
    // Voice + Music merged view — load both data sets
    _renderCurrentMusic();
    _loadVoicePanel();

    // Load voice IDs if not already fetched
    if (!_hostVoiceId && !_guestVoiceId) {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'get_podcast_info');
            const r    = await fetch(location.href, { method:'POST', body:fd });
            const data = await r.json();
            if (data.success && data.row) {
                const row = data.row;
                _hostVoiceId  = row.host_voice_id  || row.host_voice  || row.voice_id       || row.voice       || '';
                _guestVoiceId = row.guest_voice_id || row.guest_voice || row.voice_id_guest || row.voice_guest || '';
                const hn = document.getElementById('audHostVoiceName');
                const gn = document.getElementById('audGuestVoiceName');
                if (hn) hn.textContent = _hostVoiceId  || '— not set —';
                if (gn) gn.textContent = _guestVoiceId || '— not set —';
            }
        } catch(e) { L('Could not load voice info: ' + e.message, 'wrn'); }
    }
}

// ── Save caption text (debounced) ──────────────────────────────────
function saveCaptionTextDebounced() {
    clearTimeout(_audCapSaveTimer);
    _audCapSaveTimer = setTimeout(async () => {
        const ta  = document.getElementById('audioSceneText');
        if (!ta) return;
        const txt = ta.value;

        if (_audCapId) {
            // Save to hdb_captions
            const fd = new FormData();
            fd.append('ajax_action',  'save_caption_text');
            fd.append('caption_id',   _audCapId);
            fd.append('text_content', txt);
            await fetch(location.href, { method:'POST', body:fd });
            // Also update in-memory
            const cap = ALL_CAPTIONS.find(c => +c.id === +_audCapId);
            if (cap) {
                cap.text_content = txt;
                startCaptionAnim(cap);
            }
            L('Caption text saved', 'ok');
        } else {
            // Fallback: save to scene text_contents
            const sc = SCENES[currentIndex];
            sc.text_contents = txt;
            const fd = new FormData();
            fd.append('ajax_action',   'save_scene_text');
            fd.append('scene_id',      sc.id);
            fd.append('text_contents', txt);
            await fetch(location.href, { method:'POST', body:fd });
        }
    }, 900);
}

// ── Voice panel ────────────────────────────────────────────────────

// ── Voice panel state ──────────────────────────────────────────────




// Track which gender dropdown "owns" the current selection
let _selectedGender    = '';       // 'male' | 'female' | ''

// Called when Change Voice tab opens
async function _loadVoicePanel() {
    _updateVcCurrentDisplay();
    if (_allVoices.length) { _populateVoiceDropdowns(); return; }

    const vSel = document.getElementById('vcVoiceSelect');
    if (vSel) vSel.innerHTML = '<option>Loading voices…</option>';

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_voices_by_language');
        fd.append('lang_code',   '<?= addslashes($lang_code) ?>');
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const raw  = await r.text();
        console.log('RAW voice response:', raw.substring(0, 500));
        const data = JSON.parse(raw);
        console.log('voices:', data.voices?.length, 'isFree:', _isFreeTrialUser);
		
        _allVoices = data.voices || [];
        console.log('after filter:', _allVoices.length);
        _populateVoiceDropdowns();
    } catch(e) {
        console.error('_loadVoicePanel error:', e.message);
        L('Failed to load voices: ' + e.message, 'err');
    }
}

// Filter: allow AI/OpenAI voices for free trial, block Azure for free trial
// Adjust this logic to match your subscription field name
function _isVoiceAllowed(v) {
    if (!_isFreeTrialUser) return true;
    return (v.voice_source || '').toLowerCase() !== 'azure';
}

function _populateVoiceDropdowns() {
    const sel = document.getElementById('vcVoiceSelect');
    if (!sel) return;

    const currentKey = _voiceTarget === 'guest' ? _guestVoiceId : _hostVoiceId;
    _selectedVoiceKey = currentKey || '';

    // Build combined list: females first (or sort by name), with gender label
    const genderIcon = { male: '👨', female: '👩' };
    sel.innerHTML = '<option value="">— Select a voice —</option>' +
        _allVoices.map(v => {
            const gender  = _voiceGenderOf(v);
            const icon    = genderIcon[gender] || '🎙️';
            const label   = `${icon} ${v.voice_name || v.voice_key} (${gender})${v.voice_description ? ' — ' + v.voice_description : ''}`;
            const blocked = _isFreeTrialUser && (v.voice_source || '').toLowerCase() === 'azure';
            return `<option value="${v.voice_key}"
                data-gender="${gender}"
                data-sample="${v.sample_voice || ''}"
                data-lang="${v.lang_code || ''}"
                data-voice-text="${(v.voice_text || '').replace(/"/g, '&quot;')}"
                ${blocked ? 'disabled style="color:#ccc;"' : ''}
                ${v.voice_key === currentKey ? 'selected' : ''}>
                ${label}${blocked ? ' 🔒' : ''}
            </option>`;
        }).join('');

    _updateVcCurrentDisplay();
    _updateVoiceDescSingle();
}

function _voiceGenderOf(v) {
    return (v.gender || '').toLowerCase() === 'female' ? 'female' : 'male';
}

function _fillSelect(selectId, voices, placeholder) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    sel.innerHTML = `<option value="">${placeholder}</option>` +
        voices.map(v => {
            const label = (v.voice_name || v.voice_key) +
                          (v.voice_description ? ' — ' + v.voice_description : '');
            const blocked = _isFreeTrialUser && (v.voice_source || '').toLowerCase() === 'azure';
            return `<option value="${v.voice_key}" 
                data-sample="${v.sample_voice||''}"
                data-lang="${v.lang_code || ''}"
                data-voice-text="${(v.voice_text || '').replace(/"/g, '&quot;')}"
                ${blocked ? 'disabled style="color:#ccc;"' : ''}>
                ${label}${blocked ? ' 🔒' : ''}
            </option>`;
        }).join('');
}

function _preselectDropdown(selectId, key) {
    if (!key) return;
    const sel = document.getElementById(selectId);
    if (!sel) return;
    const opt = Array.from(sel.options).find(o => o.value === key);
    if (opt) sel.value = key;
}

// Called when user changes the voice dropdown
function onVoiceSelectChange() {
    const sel = document.getElementById('vcVoiceSelect');
    if (!sel || !sel.value) return;
    _selectedVoiceKey = sel.value;
    const selOpt = sel.options[sel.selectedIndex];
    _selectedGender = selOpt ? (selOpt.dataset.gender || 'male') : 'male';
    _updateVoiceDescSingle();
    _updateVcCurrentDisplay();
    _stopVoicePreview();
}

// _updateVoiceDesc(gender) removed — replaced by _updateVoiceDescSingle()

function _updateVoiceDescSingle() {
    const sel  = document.getElementById('vcVoiceSelect');
    const desc = document.getElementById('vcVoiceDesc');
    if (!sel || !desc) return;
    const opt = sel.options[sel.selectedIndex];
    desc.textContent = (opt && opt.value) ? (opt.dataset.description || '') : '';
}

function _updateVcCurrentDisplay() {
    const span = document.getElementById('vcCurrentName');
    if (!span) return;
    const currentKey = _voiceTarget === 'guest' ? _guestVoiceId : _hostVoiceId;
    const voice = _allVoices.find(v => v.voice_key === currentKey);
    // Seed vcSampleText with DB voice_text only if the field is still empty
    const ta = document.getElementById('vcSampleText');
    if (ta && !ta.value.trim() && voice && voice.voice_text) ta.value = voice.voice_text;
    const label = voice
        ? (voice.voice_name || voice.voice_key) + (voice.voice_description ? ' (' + voice.voice_description + ')' : '')
        : (currentKey || '— none —');
    span.textContent = (_voiceTarget === 'guest' ? '🎙 Guest: ' : '🎙 Host: ') + label;
}

// Preview the currently selected voice — generates TTS on-the-fly via generate_voice.php
function previewSelectedVoice() {
    const sel = document.getElementById('vcVoiceSelect');
    const btn = document.getElementById('vcPlayBtn');
    if (!sel || !sel.value) { L('Select a voice first', 'wrn'); return; }

    // Toggle off if already playing
    if (_voicePreviewAudio && !_voicePreviewAudio.paused) {
        _stopVoicePreview();
        return;
    }
    _stopVoicePreview();

    const opt      = sel.options[sel.selectedIndex];
    const voiceKey = sel.value;
    const langCode = opt?.getAttribute('data-lang') || 'en';

    // Text: use whatever is in the textarea; fall back to data-voice-text from DB
    const ta       = document.getElementById('vcSampleText');
    const text     = (ta && ta.value.trim()) ? ta.value.trim()
                   : (opt?.getAttribute('data-voice-text') || 'Hello, this is a sample of my voice.');

    if (!text) { L('Enter a preview sentence first', 'wrn'); return; }

    // Show loading state
    if (btn) { btn.textContent = '…'; btn.disabled = true; }
    L('Generating voice preview…', 'inf');

    const fd = new FormData();
    fd.append('text',     text);
    fd.append('voice_id', voiceKey);
    fd.append('lang_code',langCode);
    fd.append('row_id',   '0');
    fd.append('rate',     currentPlaybackSpeed || '1.0');
    fd.append('filename', 'preview_' + voiceKey.replace(/[^a-zA-Z0-9_]/g,'_') + '.mp3');

    fetch('generate_voice.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(d => {
            if (btn) { btn.textContent = '▶'; btn.disabled = false; }
            if (!d.success) { L('Voice preview failed: ' + (d.message || 'unknown error'), 'wrn'); return; }
            const src = 'podcast_audios/' + d.filename + '?t=' + Date.now();
            _voicePreviewAudio = new Audio(src);
            _voicePreviewAudio.playbackRate = currentPlaybackSpeed;
            _voicePreviewAudio.onended = () => {
                if (btn) btn.textContent = '▶';
                _voicePreviewAudio = null;
            };
            _voicePreviewAudio.onerror = () => {
                L('Could not play preview', 'wrn');
                if (btn) btn.textContent = '▶';
                _voicePreviewAudio = null;
            };
            _voicePreviewAudio.play().catch(() => L('Preview playback blocked', 'wrn'));
            if (btn) btn.textContent = '⏹';
        })
        .catch(e => {
            if (btn) { btn.textContent = '▶'; btn.disabled = false; }
            L('Voice preview error: ' + e.message, 'wrn');
        });
}

function _stopVoicePreview() {
    if (_voicePreviewAudio) {
        _voicePreviewAudio.pause();
        _voicePreviewAudio = null;
    }
    const pb = document.getElementById('vcPlayBtn');
    if (pb) pb.textContent = '▶';
}

function setVoiceTarget(target) {
    _voiceTarget = target;
    _stopVoicePreview();
    ['host','guest'].forEach(t => {
        const btn = document.getElementById('vt' + t.charAt(0).toUpperCase() + t.slice(1));
        if (!btn) return;
        const active = t === target;
        btn.style.background  = active ? 'var(--info)' : 'var(--surface2)';
        btn.style.borderColor = active ? 'var(--info)' : 'var(--border)';
        btn.style.color       = active ? '#fff' : 'var(--muted)';
    });
    // Re-preselect single dropdown for this target's current voice
    const currentKey = target === 'guest' ? _guestVoiceId : _hostVoiceId;
    _selectedVoiceKey = currentKey || '';
    _preselectDropdown('vcVoiceSelect', currentKey);
    const vSel = document.getElementById('vcVoiceSelect');
    const selOpt = vSel ? vSel.options[vSel.selectedIndex] : null;
    _selectedGender = selOpt ? (selOpt.dataset.gender || 'male') : 'male';
    _updateVoiceDescSingle();
    _updateVcCurrentDisplay();
}

async function saveSelectedVoice() {
    // Read directly from dropdown
    const vSel = document.getElementById('vcVoiceSelect');
    if (vSel && vSel.value) {
        _selectedVoiceKey = vSel.value;
        const selOpt = vSel.options[vSel.selectedIndex];
        _selectedGender = selOpt ? (selOpt.dataset.gender || 'male') : 'male';
    }
    if (!_selectedVoiceKey) { alert('Please select a voice first.'); return; }
    // Store with openai: prefix so audio generation routes correctly
    const prefixedKey = _ensureVoicePrefix(_selectedVoiceKey);
    if (_voiceTarget === 'guest') _guestVoiceId = prefixedKey;
    else                          _hostVoiceId  = prefixedKey;

    // 1. Save voice to hdb_podcasts
    const fd = new FormData();
    fd.append('ajax_action',    'save_podcast_voices');
    fd.append('host_voice_id',  _hostVoiceId);
    fd.append('guest_voice_id', _guestVoiceId);
    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (!data.success) {
            console.error('save_podcast_voices failed:', data);
            L('Failed to save voice: ' + (data.error || 'unknown error'), 'err');
            return;
        }
        const hn = document.getElementById('audHostVoiceName');
        const gn = document.getElementById('audGuestVoiceName');
        if (hn) hn.textContent = _hostVoiceId  || '— not set —';
        if (gn) gn.textContent = _guestVoiceId || '— not set —';
        _updateVcCurrentDisplay();
        _stopVoicePreview();
        // DON'T switch tabs yet — wait until all audio is generated
    } catch(e) { L('Save voice error: ' + e.message, 'err'); return; }

    // 2. Regenerate audio for all scenes — stays on voice tab until done
    await regenAllScenesAudio();

    // 3. Only switch back to audio overview tab after everything is done
    showAudioSub('audSub1');
}

// Regenerate audio for every scene with its correct voice
async function regenAllScenesAudio() {
    const total = SCENES.length;

    // Build a full-panel progress overlay that stays visible
    const voicePanel = document.getElementById('audSub3');
    const origContent = voicePanel ? voicePanel.innerHTML : '';
    if (voicePanel) {
        voicePanel.innerHTML = `
        <div style="padding:16px;">
            <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;">
                <div style="width:20px;height:20px;border:3px solid rgba(var(--info-rgb,2,132,199),.25);
                    border-top-color:var(--info);border-radius:50%;
                    animation:spin 1s linear infinite;flex-shrink:0;"></div>
                <div style="font-size:14px;font-weight:700;color:var(--info);">
                    Generating voiceovers for all scenes
                </div>
            </div>
            <div style="background:var(--border);border-radius:20px;height:10px;overflow:hidden;margin-bottom:10px;">
                <div id="regenBar" style="height:100%;background:var(--info);width:0%;transition:width .4s;border-radius:20px;"></div>
            </div>
            <div style="text-align:center;font-size:13px;font-weight:700;color:var(--text);margin-bottom:6px;">
                <span id="regenDone">0</span> of ${total} scenes done
            </div>
            <div id="regenStatus" style="text-align:center;font-size:11px;color:var(--muted);min-height:18px;"></div>
            <div id="regenLog" style="margin-top:12px;max-height:200px;overflow-y:auto;font-size:11px;
                background:var(--surface2);border-radius:8px;padding:8px;border:1px solid var(--border);
                font-family:monospace;color:var(--muted);line-height:1.7;"></div>
        </div>`;
    }

    let _regenLogLineId = 0;
    const addLog = (msg, id = null) => {
        const log = document.getElementById('regenLog');
        if (!log) return;
        if (id) {
            const existing = log.querySelector('[data-lid="' + id + '"]');
            if (existing) { existing.innerHTML = msg; log.scrollTop = log.scrollHeight; return; }
            log.innerHTML += '<span data-lid="' + id + '">' + msg + '</span><br>';
        } else {
            log.innerHTML += msg + '<br>';
        }
        log.scrollTop = log.scrollHeight;
    };

    let done = 0;
    for (const sc of SCENES) {
        const text = (sc.text_contents || '').replace(/<break[^>]*>/gi, '').trim();

        const actor    = (sc.actor || '').toLowerCase();
        const rawVoice = (actor === 'guest' && _guestVoiceId) ? _guestVoiceId : _hostVoiceId;
        const voiceId  = _ensureVoicePrefix(rawVoice);

        // Update progress
        const statusEl = document.getElementById('regenStatus');
        if (statusEl) statusEl.textContent = `Processing scene ${done + 1} of ${total}…`;

        if (!text || !voiceId) {
            addLog(`⏭ Scene ${done + 1}: skipped (${!text ? 'no text' : 'no voice'})`);
            done++;
            _updateRegenProgress(done, total);
            continue;
        }

        try {
            const lineId = 'sc' + sc.id;
            addLog(`<span style="display:inline-flex;align-items:center;gap:5px;">
                <span style="display:inline-block;width:11px;height:11px;border:2px solid #ccc;border-top-color:var(--info);border-radius:50%;animation:spin 1s linear infinite;"></span>
                Scene ${done + 1}/${total}: generating…</span>`, lineId);
            const fd = new FormData();
            fd.append('ajax_action', 'generate_scene_audio');
            fd.append('text',        text);
            fd.append('voice_id',    voiceId);
            fd.append('lang_code',   '<?= addslashes($lang_code) ?>');
            fd.append('rate',        (typeof AUDIO_SPEED !== 'undefined' ? AUDIO_SPEED : 1.0));
            fd.append('scene_id',    sc.id);
            fd.append('podcast_id',  PODCAST_ID);
            fd.append('admin_id',    <?= (int)$admin_id ?>);

            const r    = await fetch('wizard_step2.php', { method:'POST', body:fd });
            const raw  = await r.text();
            let data;
            try { data = JSON.parse(raw); }
            catch(e) {
                addLog(`❌ Scene ${done + 1}/${total}: bad response (HTTP ${r.status}): ${raw.substring(0, 200)}`, lineId);
                done++; _updateRegenProgress(done, total); continue;
            }
            if (data.success) {
                sc.audio_file = data.filename;
                addLog(`✅ Scene ${done + 1}/${total}: done`, lineId);
            } else {
                addLog(`⚠️ Scene ${done + 1}/${total}: failed — ${data.error || data.message || 'unknown'}`, lineId);
            }
        } catch(e) {
            addLog(`❌ Scene ${done + 1}: error — ${e.message}`);
        }

        done++;
        _updateRegenProgress(done, total);
    }

    // Show completion message briefly then hand off to caller
    const statusEl = document.getElementById('regenStatus');
    if (statusEl) statusEl.textContent = `✅ All ${total} scenes complete!`;
    addLog(`
✅ All done! Switching back…`);
    await new Promise(r => setTimeout(r, 1200)); // brief pause so user sees completion

    L(`✅ All ${total} scenes regenerated with new voice!`, 'ok');
    logActivity('voice_regen_all', _voiceTarget + ':' + _selectedVoiceKey);

    // Restore voice panel HTML so next open shows the selector
    if (voicePanel && origContent) voicePanel.innerHTML = origContent;
    // Reset _allVoices so _loadVoicePanel re-fetches and re-populates
    _allVoices = [];

    // Restore audio player for current scene
    const sc = SCENES[currentIndex];
    if (sc && sc.audio_file) _renderAudioPlayer(sc.audio_file);
}

// Ensure voice_id has correct TTS prefix (e.g. 'openai:nova', 'en-US-GuyNeural')
// If no prefix and voice exists in _allVoices with source='openai', add 'openai:'
function _ensureVoicePrefix(voiceId) {
    if (!voiceId) return voiceId;
    // Already has a prefix
    if (voiceId.includes(':')) return voiceId;
    // Look up in loaded voices list
    const found = _allVoices.find(v => v.voice_key === voiceId);
    if (found && (found.voice_source || '').toLowerCase() === 'openai') {
        return 'openai:' + voiceId;
    }
    // Default: assume openai if no source info (since we only load openai voices)
    return 'openai:' + voiceId;
}

function _updateRegenProgress(done, total) {
    const pct    = Math.round((done / total) * 100);
    const barEl  = document.getElementById('regenBar');
    const doneEl = document.getElementById('regenDone');
    if (barEl)  barEl.style.width  = pct + '%';
    if (doneEl) doneEl.textContent = done;
}




function saveSceneTextDebounced(){
    clearTimeout(_audioSaveTimer);
    _audioSaveTimer=setTimeout(async()=>{
        const sc=SCENES[currentIndex];const ta=document.getElementById('audioSceneText');if(!ta)return;
        sc.text_contents=ta.value;
        const fd=new FormData();fd.append('ajax_action','save_scene_text');fd.append('scene_id',sc.id);fd.append('text_contents',ta.value);
        await fetch(location.href,{method:'POST',body:fd});
    },900);
}

function _renderAudioPlayer(filename){
    const wrap=document.getElementById('audioPlayerWrap');if(!wrap)return;
    if(!filename){wrap.innerHTML=`<div style="text-align:center;padding:10px;background:var(--surface2);border-radius:8px;font-size:11px;color:var(--muted);">🎵 No voiceover yet</div>`;return;}
    wrap.innerHTML=`<div style="display:flex;align-items:center;gap:8px;background:var(--surface2);border-radius:30px;padding:6px 12px;border:1.5px solid var(--border);">
        <button id="apPlayBtn" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--info);color:#fff;cursor:pointer;font-size:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
        <div style="flex:1;height:4px;background:var(--border);border-radius:2px;cursor:pointer;" id="apBar"><div id="apFill" style="height:100%;background:var(--info);border-radius:2px;width:0%;"></div></div>
        <span id="apTime" style="font-size:10px;color:var(--muted);white-space:nowrap;min-width:60px;text-align:right;">0:00</span></div>
    <div style="font-size:10px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${filename}">📄 ${filename}</div>`;
    const audio=new Audio(AUD_BASE+filename+'?t='+Date.now());
	audio.volume = voiceVolume;   // ← add this line
	audio.id='apEl';audio.preload='metadata';
	wrap.appendChild(audio);
    const btn=document.getElementById('apPlayBtn'),fill=document.getElementById('apFill'),time=document.getElementById('apTime'),bar=document.getElementById('apBar');
    audio.addEventListener('loadedmetadata',()=>{if(time)time.textContent='0:00 / '+_fmt(audio.duration);});
    audio.addEventListener('timeupdate',()=>{const pct=(audio.currentTime/audio.duration)*100||0;if(fill)fill.style.width=pct+'%';if(time)time.textContent=_fmt(audio.currentTime)+' / '+_fmt(audio.duration);});
    audio.addEventListener('ended',()=>{if(btn)btn.textContent='▶';if(fill)fill.style.width='0%';});
    if(btn)btn.addEventListener('click',()=>{if(audio.paused){audio.play().catch(e=>L('Audio: '+e.message,'wrn'));btn.textContent='⏸';}else{audio.pause();btn.textContent='▶';}});
    if(bar)bar.addEventListener('click',e=>{const r=bar.getBoundingClientRect();audio.currentTime=((e.clientX-r.left)/r.width)*audio.duration;});
}
function _fmt(s){if(!s||isNaN(s))return'0:00';const m=Math.floor(s/60),sec=Math.floor(s%60);return m+':'+(sec<10?'0':'')+sec;}

async function generateSceneAudio(){
    const sc  = SCENES[currentIndex];
    const ta  = document.getElementById('audioSceneText');
    const text = (ta?.value || '').replace(/<break[^>]*>/gi,'').trim();
    if (!text) { alert('No text for this scene.'); return; }

    // Determine voice: guest detection from raw caption text
    const isGuest = /^GUEST\s*:/i.test(text.trim());
    const voiceId = (isGuest && _guestVoiceId) ? _guestVoiceId : _hostVoiceId;
    if (!voiceId) { alert('No voice set for this podcast.'); return; }

    const btn = document.getElementById('btnGenAudio');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Generating…'; }
    L('Generating voiceover…', 'inf');

    const fd = new FormData();
    fd.append('ajax_action', 'generate_scene_audio');
    fd.append('text',        text);
    fd.append('voice_id',    voiceId);
    fd.append('lang_code',   '<?= addslashes($lang_code) ?>');
    fd.append('rate',        (typeof AUDIO_SPEED !== 'undefined' ? AUDIO_SPEED : 1.0));
    fd.append('scene_id',    sc.id);
    fd.append('podcast_id',  PODCAST_ID);

    try {
        const r = await fetch('wizard_step2.php', { method:'POST', body:fd });
        const raw = await r.text();
        let data;
        try { data = JSON.parse(raw); } catch(e) { throw new Error('Server error (503) — try again'); }
        if (!data.success) throw new Error(data.message || 'TTS failed');
        sc.audio_file = data.filename;
        _renderAudioPlayer(data.filename);
        L('Voiceover ready: ' + data.filename, 'ok');
        logActivity('audio_generated', 'voice:' + voiceId, currentIndex);
    } catch(e) {
        L('Generate failed: ' + e.message, 'err');
        alert('Voiceover failed: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '🔄 Generate Voiceover'; }
    }
}

function _renderCurrentMusic(){
    const wrap=document.getElementById('musicCurrentWrap');if(!wrap)return;
    if(_currentPodcastMusic){
        wrap.innerHTML=`<div style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1.5px solid var(--success);border-radius:8px;padding:7px 10px;">
            <span style="font-size:16px;">🎵</span>
            <span style="flex:1;font-size:11px;font-weight:600;color:var(--success);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${_currentPodcastMusic}">${_currentPodcastMusic}</span>
            <button onclick="_previewCurrentMusic(this)" style="width:24px;height:24px;border-radius:50%;border:none;background:var(--success);color:#fff;font-size:10px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button></div>`;
    }else{wrap.innerHTML=`<div style="font-size:11px;color:var(--muted);padding:4px 0;">No background music selected</div>`;}
}
function _previewCurrentMusic(btn){
    if(_musicPreviewAudio&&!_musicPreviewAudio.paused){
        _musicPreviewAudio.pause();btn.textContent='▶';return;
    }
    _musicPreviewAudio=new Audio(MUS_BASE+_currentPodcastMusic+'?t='+Date.now());
    _musicPreviewAudio.volume = bgMusicVolume;  // ← add this
    _musicPreviewAudio.onended=()=>{btn.textContent='▶';};
    _musicPreviewAudio.play().catch(()=>{});
    btn.textContent='⏹';
}
function uploadMusicClick(){const inp=document.getElementById('musicFileInput');if(inp){inp.value='';inp.click();}}
async function handleMusicUpload(input){
    if(!input.files||!input.files[0])return;
    const fd=new FormData();fd.append('ajax_action','upload_podcast_music');fd.append('music_file',input.files[0]);
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();if(!data.success)throw new Error(data.message);_currentPodcastMusic=data.filename;_applyBgAudio();_renderCurrentMusic();L('Music uploaded: '+data.filename,'ok');}
    catch(e){L('Upload failed: '+e.message,'err');}
}
function _applyBgAudio(){
    if(bgAudio)bgAudio.pause();
    if(_currentPodcastMusic){
        bgAudio=new Audio(MUS_BASE+_currentPodcastMusic+'?t='+Date.now());
        bgAudio.loop=true;
        bgAudio.volume=bgMusicVolume;  // ← uses current slider value, not hardcoded 0.3
    } else {
        bgAudio=null;
    }
}
async function clearPodcastMusic(){
    const fd=new FormData();fd.append('ajax_action','update_podcast_music');fd.append('music_file','');
    await fetch(location.href,{method:'POST',body:fd});_currentPodcastMusic='';_applyBgAudio();_renderCurrentMusic();L('Music removed','ok');
}

async function openMusicLibModal(){
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;}
    _selectedMusicFile=null;
    const useBtn=document.getElementById('musicLibUseBtn');if(useBtn){useBtn.disabled=true;useBtn.style.opacity='.4';}
    const info=document.getElementById('musicLibSelInfo');if(info)info.textContent='No file selected';
    const modal=document.getElementById('musicLibModal');if(modal)modal.style.display='flex';
    await _loadMusicLibGrid();
}
function closeMusicLibModal(){if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;}const modal=document.getElementById('musicLibModal');if(modal)modal.style.display='none';_selectedMusicFile=null;}
async function _loadMusicLibGrid(){
    const grid=document.getElementById('musicLibGrid');if(grid)grid.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);">Loading…</div>';
    const fd=new FormData();fd.append('ajax_action','get_music_library');
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();_musicLibFiles=data.files||[];_renderMusicLibGrid(_musicLibFiles);}
    catch(e){if(grid)grid.innerHTML='<div style="color:var(--danger);text-align:center;padding:20px;">Error loading files</div>';}
}
function filterMusicLibGrid(){const q=(document.getElementById('musicLibSearch')?.value||'').toLowerCase();_renderMusicLibGrid(q?_musicLibFiles.filter(f=>f.filename.toLowerCase().includes(q)):_musicLibFiles);}
function _renderMusicLibGrid(files){
    const grid=document.getElementById('musicLibGrid');if(!grid)return;
    if(!files.length){grid.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);">No music files found</div>';return;}
    grid.innerHTML=files.map(f=>{
        const isCur=f.filename===_currentPodcastMusic;
        return `<div onclick="_pickMusicFile(this,'${f.filename}')" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;border:1.5px solid ${isCur?'var(--success)':'var(--border)'};background:${isCur?'#f0fdf4':'var(--surface)'};cursor:pointer;transition:border-color .13s;">
            <span style="font-size:20px;flex-shrink:0;">🎵</span>
            <div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:600;color:${isCur?'var(--success)':'var(--text)'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${f.filename}">${f.filename}</div><div style="font-size:9px;color:var(--muted);">${(f.size/1024).toFixed(0)} KB</div></div>
            <button onclick="event.stopPropagation();_prevMusicLib('${MUS_BASE+f.filename}',this)" style="width:24px;height:24px;border-radius:50%;border:none;background:var(--info);color:#fff;font-size:9px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
            ${isCur?'<span style="font-size:11px;">✓</span>':''}
        </div>`;
    }).join('');
}
function _pickMusicFile(el,filename){
    document.querySelectorAll('#musicLibGrid > div').forEach(d=>{d.style.borderColor='var(--border)';d.style.background='var(--surface)';});
    el.style.borderColor='var(--info)';el.style.background='#eff6ff';
    _selectedMusicFile=filename;
    const info=document.getElementById('musicLibSelInfo');if(info)info.textContent=filename;
    const btn=document.getElementById('musicLibUseBtn');if(btn){btn.disabled=false;btn.style.opacity='1';}
}
function _prevMusicLib(src,btn){
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;document.querySelectorAll('#musicLibGrid button').forEach(b=>{if(b.textContent==='⏹')b.textContent='▶';});}
    if(btn.textContent==='⏹'){btn.textContent='▶';return;}
    _musicPreviewAudio=new Audio(src+'?t='+Date.now());_musicPreviewAudio.onended=()=>{btn.textContent='▶';};_musicPreviewAudio.play().catch(()=>{});btn.textContent='⏹';
}
async function useMusicLibFile(){
    if(!_selectedMusicFile)return;
    const fd=new FormData();fd.append('ajax_action','update_podcast_music');fd.append('music_file',_selectedMusicFile);
    const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
    if(data.success){_currentPodcastMusic=_selectedMusicFile;_applyBgAudio();_renderCurrentMusic();closeMusicLibModal();L('Music set: '+_selectedMusicFile,'ok');}
    else{L('Failed to set music','err');}
}

function sleep(ms){return new Promise(r=>setTimeout(r,ms));}

function openCaptionPanel(){
    if(!selectedCapId && !sceneCaptions.length){
        alert('No captions on this scene yet. Click "+ Add Caption" after opening the panel.');
        togglePanel('pCaption','ibCaption');
        renderCaptionTabs();
        return;
    }
    if(!selectedCapId){
        togglePanel('pCaption','ibCaption');
        renderCaptionTabs();
        // Auto-select first caption
        if(sceneCaptions.length>0) selectCaption(sceneCaptions[0].id); 
        return;
    }
    togglePanel('pCaption','ibCaption');
    renderCaptionTabs();
}

(async function boot() {
    startRender();

    // Restore slot checkboxes for the first scene
    applySceneSlots(SCENES[0]);

    // On page load, only load the first scene — no full preload.
    // Full preloadAll() runs only when Play or Record is clicked.
    const ov = document.getElementById('preloadOverlay');
    if (ov) {
        ov.classList.remove('gone');
        ov.style.opacity = '1';
    }

    const msg = document.getElementById('preloadMsg');
    if (msg) msg.textContent = 'Loading first scene...';

    // Load only the current (first) scene's media
    await prepareNextScene(0);

    // Show first scene instantly
    await showScene(0, true);
	updatePanelSceneNumbers();  // ADD THIS LINE
    // Hide overlay
    if (ov) {
        ov.style.transition = 'opacity .5s ease';
        ov.style.opacity = '0';
        setTimeout(() => ov.classList.add('gone'), 550);
    }

    updateNavButtons();

    const mvs = document.getElementById('musicVolSlider');
    const vvs = document.getElementById('voiceVolSlider');
    if (mvs) onMusicVolChange(mvs.value);
    if (vvs) onVoiceVolChange(vvs.value);

    bootComplete = true; // now safe to handle checkbox changes
})();


// ── Scheduler ──────────────────────────────────────────────────────────────

// ── State ─────────────────────────────────────────────────────────
let _vsRecURL     = null;   // object URL for download
let _vsRecFname   = null;   // filename e.g. podcast_6.webm
let _vsPodcastId  = PODCAST_ID;
// Show download bar with WebM info




// ── Open scheduler after recording ────────────────────────────────
// Track conversion state to prevent duplicates

let _conversionCompleted = false;

// Track conversion state
let _conversionInProgress = false;
let _conversionPollInterval = null;

async function startMp4Convert(podcastId) {
    if (_conversionInProgress) return;
    _conversionInProgress = true;
    
    const overlay = document.getElementById('uploadOverlay');
    if (overlay) overlay.style.display = 'flex';
    
    L('🎬 Starting MP4 conversion…', 'ok');
    
    let jobId = null;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'start_mp4_convert');
        fd.append('podcast_id', podcastId);
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        
        // If VPS is unavailable, offer WebM download directly
        if (data.fallback || !data.success) {
            if (overlay) overlay.style.display = 'none';
            _conversionInProgress = false;
            L('⚠ MP4 conversion not available. WebM version is ready.', 'wrn');
            showWebmDownloadOnly(podcastId);
            return;
        }
        
        if (!data.job_id) {
            throw new Error(data.message || 'No job ID returned');
        }
        
        jobId = data.job_id;
        L('⏳ Conversion started, waiting…', 'ok');
        
    } catch (e) {
        if (overlay) overlay.style.display = 'none';
        _conversionInProgress = false;
        L('⚠ Conversion error: ' + e.message, 'wrn');
        showWebmDownloadOnly(podcastId);
        return;
    }
    
    // Poll for completion
    let attempts = 0;
    const maxAttempts = 60;
    
    _conversionPollInterval = setInterval(async () => {
        attempts++;
        
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'poll_mp4_convert');
            fd.append('job_id', jobId);
            fd.append('podcast_id', podcastId);
            const r = await fetch(location.href, { method: 'POST', body: fd });
            const data = await r.json();
            
            if (data.status === 'done') {
                clearInterval(_conversionPollInterval);
                if (overlay) overlay.style.display = 'none';
                _conversionInProgress = false;
                L('✅ MP4 ready!', 'ok');
                
                // Show MP4 download
                const mp4Url = 'published_videos/podcast_' + podcastId + '.mp4';
                showMp4DownloadOnly(mp4Url, 'podcast_' + podcastId + '.mp4');
            }
            
            if (data.status === 'failed' || data.status === 'error') {
                clearInterval(_conversionPollInterval);
                if (overlay) overlay.style.display = 'none';
                _conversionInProgress = false;
                L('⚠ MP4 conversion failed', 'wrn');
                showWebmDownloadOnly(podcastId);
            }
            
            if (attempts >= maxAttempts) {
                clearInterval(_conversionPollInterval);
                if (overlay) overlay.style.display = 'none';
                _conversionInProgress = false;
                L('⚠ Conversion timeout', 'wrn');
                showWebmDownloadOnly(podcastId);
            }
        } catch(e) {
            console.warn('Poll error:', e);
        }
    }, 5000);
}

function showMp4DownloadOnly(mp4Url, filename) {
    const dlPanel = document.getElementById('dlPanel');
    dlPanel.innerHTML = `
        <h3>✅ MP4 Ready!</h3>
        <p id="dlMeta">MP4 format - Ready for social media</p>
        <p style="font-size:11px;color:var(--muted);">Click below to save your video.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="dlLink" class="btn success" href="${mp4Url}" download="${filename}" style="background:var(--success);color:#fff;">⬇ Download MP4</a>
            <button class="btn" onclick="closeDownloadPanel()">✕ Close</button>
        </div>
    `;
    dlPanel.classList.add('on');
}

function showWebmDownloadOnly(podcastId, blobUrl) {
    const webmUrl = blobUrl || 'published_videos/podcast_' + podcastId + '.webm';
    const dlPanel = document.getElementById('dlPanel');
    dlPanel.innerHTML = `
        <h3>⚠️ WebM Only</h3>
        <p id="dlMeta">WebM format - Use VLC or Chrome to play</p>
        <p style="font-size:11px;color:var(--muted);">MP4 conversion unavailable.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="dlLink" class="btn" href="${webmUrl}" download="podcast_${podcastId}.webm">⬇ Download WebM</a>
            <button class="btn" onclick="closeDownloadPanel()">✕ Close</button>
        </div>
    `;
    dlPanel.classList.add('on');
}



// Show scheduler with MP4 option (replaces the old WebM modal)
function showSchedulerWithMp4(podcastId, filename, sizeMb) {
    const mp4Url = 'published_videos/' + filename;
    const mp4Filename = filename;
    
    // Close any existing download panel
    const existingPanel = document.getElementById('dlPanel');
    if (existingPanel) {
        existingPanel.classList.remove('on');
        existingPanel.style.display = 'none';
    }
    
    // Update the scheduler modal to show MP4 is ready
    const vsFilenameDisplay = document.getElementById('vsFilenameDisplay');
    if (vsFilenameDisplay) {
        vsFilenameDisplay.textContent = mp4Filename + ' ✅ MP4';
        vsFilenameDisplay.style.color = '#10b981';
    }
    
    // Store MP4 info for download
    window._mp4Ready = true;
    window._mp4Url = mp4Url;
    window._mp4Filename = mp4Filename;
    
    // Open the scheduler modal
    openSchedModalWithMp4(mp4Url, mp4Filename, sizeMb);
}

// Open scheduler with MP4 download option
function openSchedModalWithMp4(mp4Url, filename, sizeMb) {
    // Close any existing modals first
    closeSchedModal();
    
    // Update the scheduler modal content to show MP4 is ready
    const vsMain = document.getElementById('vsMain');
    const vsConfirm = document.getElementById('vsConfirm');
    
    if (vsMain) vsMain.style.display = 'block';
    if (vsConfirm) vsConfirm.style.display = 'none';
    
    const filenameSpan = document.getElementById('vsFilenameDisplay');
    if (filenameSpan) {
        filenameSpan.innerHTML = filename + ' <span style="color:#10b981;">✅ MP4 Ready</span>';
    }
    
    const subTitle = document.getElementById('vsSubTitle');
    if (subTitle) {
        subTitle.innerHTML = `MP4 ready (${sizeMb || '?'} MB) — Ready for Instagram, TikTok, YouTube`;
    }
    
    // Add MP4 download button to scheduler if not exists
    let dlBtnContainer = document.getElementById('vsMp4DownloadContainer');
    if (!dlBtnContainer) {
        const footer = document.querySelector('.vs-footer');
        if (footer) {
            dlBtnContainer = document.createElement('div');
            dlBtnContainer.id = 'vsMp4DownloadContainer';
            dlBtnContainer.style.gridColumn = 'span 2';
            dlBtnContainer.style.marginBottom = '6px';
            dlBtnContainer.innerHTML = `
                <button onclick="downloadMp4Directly()" style="width:100%;padding:9px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:9px;font-size:12px;font-weight:700;cursor:pointer;margin-bottom:4px;">
                    ⬇ Download MP4 Video
                </button>
            `;
            footer.insertBefore(dlBtnContainer, footer.firstChild);
        }
    }
    
    // Open the modal
    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.add('open');
}

// Direct MP4 download function
function downloadMp4Directly() {
    if (window._mp4Url && window._mp4Filename) {
        const a = document.createElement('a');
        a.href = window._mp4Url;
        a.download = window._mp4Filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        L('⬇ MP4 download started', 'ok');
    } else {
        // Try to get from podcast ID
        const mp4Url = 'published_videos/podcast_' + PODCAST_ID + '.mp4';
        const a = document.createElement('a');
        a.href = mp4Url;
        a.download = 'podcast_' + PODCAST_ID + '.mp4';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
}

// Show WebM download options as fallback
function showWebmDownloadOptions(podcastId) {
    const webmUrl = 'published_videos/podcast_' + podcastId + '.webm';
    const webmFilename = 'podcast_' + podcastId + '.webm';
    
    // Update scheduler to show WebM option
    const filenameSpan = document.getElementById('vsFilenameDisplay');
    if (filenameSpan) {
        filenameSpan.innerHTML = webmFilename + ' <span style="color:#f59e0b;">⚠️ WebM only</span>';
    }
    
    const subTitle = document.getElementById('vsSubTitle');
    if (subTitle) {
        subTitle.innerHTML = 'WebM format — Use VLC player or Chrome to play';
    }
    
    // Open scheduler
    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.add('open');
}

// Override the original openSchedModal to prevent duplicate calls
let _schedulerOpening = false;

function openSchedModal(blob, url, fname) {
    // Prevent multiple scheduler openings
    if (_schedulerOpening) return;
    _schedulerOpening = true;
    

    _vsRecURL   = url;
    _vsRecFname = fname;

    const filenameSpan = document.getElementById('vsFilenameDisplay');
    if (filenameSpan) filenameSpan.textContent = fname;
    
    const subTitle = document.getElementById('vsSubTitle');
    if (subTitle) subTitle.textContent = '<?= addslashes($podcast_title ?: 'Your Video') ?>';
    
    const vsMain = document.getElementById('vsMain');
    const vsConfirm = document.getElementById('vsConfirm');
    if (vsMain) vsMain.style.display = 'block';
    if (vsConfirm) vsConfirm.style.display = 'none';
    
    const warnEl = document.getElementById('vsWarn');
    if (warnEl) warnEl.style.display = 'none';

    // Pre-populate caption
    _vsPopulateCaption();

    // Default to Tomorrow
    const tomorrowBtn = document.querySelectorAll('.vs-qpill')[2];
    if (tomorrowBtn) vsQuick(tomorrowBtn, 24);

    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.add('open');
    
    // Reset flag after a delay
    setTimeout(() => { _schedulerOpening = false; }, 1000);
}

// Also fix the closeSchedModal to clean up
const originalCloseSchedModal = closeSchedModal;
window.closeSchedModal = function() {
    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.remove('open');
    _schedulerOpening = false;
};

function closeSchedModal() {
    document.getElementById('vsOverlay').classList.remove('open');
}

// Close on backdrop click
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.addEventListener('click', function(e) {
        if (e.target === this) closeSchedModal();
    });

    // ── Initialise audio speed UI from podcast's saved audio_speed ──
    if (typeof AUDIO_SPEED !== 'undefined' && AUDIO_SPEED !== 1.0) {
        // Update all speed sliders and labels on the page
        document.querySelectorAll('#playbackSpeedSlider').forEach(el => {
            el.value = AUDIO_SPEED;
        });
        document.querySelectorAll('#speedValue').forEach(el => {
            el.textContent = AUDIO_SPEED.toFixed(2) + 'x';
        });
        // Move active class to the matching preset button (if exact match)
        document.querySelectorAll('.speed-preset').forEach(btn => {
            const btnSpeed = parseFloat(btn.dataset.speed);
            btn.classList.toggle('active', Math.abs(btnSpeed - AUDIO_SPEED) < 0.01);
        });
    }
});

// ── Pre-populate caption from podcast data ─────────────────────────
function _vsPopulateCaption() {
    // Load hashtags, keywords and caption_text directly from hdb_podcasts
    const fd = new FormData();
    fd.append('ajax_action', 'get_podcast_caption_data');
    fd.append('podcast_id',  PODCAST_ID);

    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            document.getElementById('vsCaption').value  = d.caption_text || '';
            document.getElementById('vsKeywords').value = d.keywords     || '';
            document.getElementById('vsHashtags').value = d.hashtags     || '';
        })
        .catch(() => {});
}

// ── Platform toggle ────────────────────────────────────────────────
function vsSwitchTab(tab, btn) {
    // Deactivate all tabs and panels
    document.querySelectorAll('.vs-ctab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.vs-ctab-panel').forEach(p => p.classList.remove('active'));
    // Activate selected
    btn.classList.add('active');
    document.getElementById('vs-tab-' + tab).classList.add('active');
}

function vsTogglePlat(el) {
    el.classList.toggle('sel');
    document.getElementById('vsWarn').style.display = 'none';
}

function vsGetPlats() {
    return [...document.querySelectorAll('.vs-plat.sel:not(.disconnected)')].map(el => el.dataset.p);
}

// ── Quick date pills ───────────────────────────────────────────────
function vsQuick(btn, hrs) {
    document.querySelectorAll('.vs-qpill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const d = new Date();
    d.setHours(d.getHours() + hrs);
    document.getElementById('vsDate').value = d.toISOString().split('T')[0];
    document.getElementById('vsTime').value = d.toTimeString().slice(0, 5);
}

// ── Download only ──────────────────────────────────────────────────
function vsDownload() {
    const a = document.createElement('a');
    if (window._mp4Ready && window._mp4Url && window._mp4Filename) {
        // MP4 is ready on server — download it
        a.href     = window._mp4Url;
        a.download = window._mp4Filename;
        L('⬇ Downloading MP4…', 'ok');
    } else if (_vsRecURL) {
        // Fallback: download WebM blob
        a.href     = _vsRecURL;
        a.download = _vsRecFname || ('podcast_' + PODCAST_ID + '.webm');
        L('⬇ Downloading WebM…', 'ok');
    } else {
        L('No recording available', 'err');
        return;
    }
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    closeSchedModal();
}

// ── Post now ───────────────────────────────────────────────────────
function vsPostNow() {
    const plats = vsGetPlats();
    if (!plats.length) { document.getElementById('vsWarn').style.display = 'block'; return; }
    _vsSave('now', plats, null);
}

// ── Schedule ───────────────────────────────────────────────────────
function vsSchedule() {
    const plats = vsGetPlats();
    if (!plats.length) { document.getElementById('vsWarn').style.display = 'block'; return; }
    const date = document.getElementById('vsDate').value;
    const time = document.getElementById('vsTime').value;
    if (!date || !time) { alert('Please select a date and time'); return; }
    _vsSave('scheduled', plats, new Date(date + 'T' + time));
}

// ── Save to backend ────────────────────────────────────────────────
async function _vsSave(type, plats, dt) {
    const payload = {
        podcast_id:  _vsPodcastId,
        platforms:   plats,
        caption:   document.getElementById('vsCaption').value,
		keywords:  document.getElementById('vsKeywords').value,
		hashtags:  document.getElementById('vsHashtags').value,
        sched_date:  dt ? dt.toISOString().split('T')[0]  : new Date().toISOString().split('T')[0],
        sched_time:  dt ? dt.toTimeString().slice(0, 5)    : new Date().toTimeString().slice(0, 5),
        post_type:   type,
        video_filename: _vsRecFname,
    };

    try {
        const r    = await fetch('social_schedule.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await r.json();
        if (data.success) {
            _vsShowConfirm(type, plats, dt);
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
        }
    } catch(e) {
        // social_schedule.php not yet wired — show confirm anyway
        console.warn('social_schedule.php not wired:', e.message);
        _vsShowConfirm(type, plats, dt);
    }
}
async function addImageCaption() {
    // Show a quick choice: Upload or Library
    const choice = await _imgCapChoiceModal();
    if (!choice) return;

    let filename = null;

    if (choice === 'upload') {
        filename = await _imgCapUpload();
    } else {
        filename = await _imgCapFromLibrary();
    }

    if (!filename) return;

    const sc = SCENES[currentIndex];
    const name = 'img' + (sceneCaptions.length + 1);
    const fd = new FormData();
    fd.append('ajax_action',  'add_image_caption');
    fd.append('story_id',     sc.id);
    fd.append('caption_name', name);
    fd.append('filename',     filename);
    fd.append('position_x',   20);
    fd.append('position_y',   20);
    fd.append('width',        120);
    fd.append('height',       120);

    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (data.success && data.caption) {
            const newCap = data.caption;
            ALL_CAPTIONS.push(newCap);
            // Pre-load the image into cache
            await _preloadCapImage(newCap);
            captionStates[newCap.id] = { show: filename, full: filename, words: [], karIdx: 0, timer: null };
            selectedCapId = parseInt(newCap.id);
            loadSceneCaptions(sc.id);
            selectCaption(newCap.id);
            L('Image caption added', 'ok');
        } else {
            L('Add image caption failed: ' + (data.message || ''), 'err');
        }
    } catch(e) {
        L('Image caption error: ' + e.message, 'err');
    }
}

// ── Preload image for caption ──────────────────────────────────
function _preloadCapImage(cap) {
    return new Promise(res => {
        const fn = cap.text_content || '';
        if (!fn || imgCache[fn]) { res(); return; }
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload  = () => { imgCache[fn] = img; res(); };
        img.onerror = () => res();
        setTimeout(res, 8000);
        img.src = (fn.startsWith('logo_') ? 'podcast_logos/' : getFileFolder(fn)) + fn + '?t=' + Date.now();
    });
}
// ── Font Picker ────────────────────────────────────────────────────

// ── Caption sub-tab switcher ───────────────────────────────────────
function switchCapSubTab(n) {
    document.getElementById('capSubTab1').style.display = n === 1 ? 'block' : 'none';
    document.getElementById('capSubTab2').style.display = n === 2 ? 'block' : 'none';
    const b1 = document.getElementById('capSubBtn1');
    const b2 = document.getElementById('capSubBtn2');
    if (b1) {
        b1.style.background = n === 1 ? '#ffffff' : '#5fc3ff';
        b1.style.color      = '#0f2a44';
        b1.style.opacity    = n === 1 ? '1' : '0.75';
    }
    if (b2) {
        b2.style.background = n === 2 ? '#ffffff' : '#5fc3ff';
        b2.style.color      = '#0f2a44';
        b2.style.opacity    = n === 2 ? '1' : '0.75';
    }
}

// ── Border live update ─────────────────────────────────────────────
function capBorderChanged() {
    const colorEl = document.getElementById('capBorderColor');
    const thickEl = document.getElementById('capBorderThick');
    const preview = document.getElementById('capBorderPreview');
    const color   = colorEl ? colorEl.value : '#ffffff';
    const thick   = thickEl ? parseInt(thickEl.value) : 0;
    if (preview) {
        preview.style.borderWidth = thick + 'px';
        preview.style.borderColor = color;
        preview.style.borderStyle = thick > 0 ? 'solid' : 'none';
    }
    capFieldChanged('caption_box_border_color',     color);
    capFieldChanged('caption_box_border_thickness', thick);
}

function toggleFontPicker() {
    const dd = document.getElementById('fontPickerDropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
function toggleBgEnabled(checked) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    cap.bg_enabled = checked ? 1 : 0;
    const lbl = document.getElementById('capBgEnableLabel');
    if (lbl) lbl.style.color = checked ? 'var(--info)' : 'var(--muted)';
    capFieldChanged('bg_enabled', cap.bg_enabled);
}
function selectFont(val, label, el) {
    // Update hidden input
    document.getElementById('capFont').value = val;
    // Update button label in its own font
    const lbl = document.getElementById('fontPickerLabel');
    lbl.textContent    = label;
    lbl.style.fontFamily = val;
    // Mark selected
    document.querySelectorAll('.fp-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    // Close dropdown
    document.getElementById('fontPickerDropdown').style.display = 'none';
    // Fire caption change — updates cap.fontfamily in memory + saves to DB
    capFieldChanged('fontfamily', val);
    // Reset font log flag so next render re-evaluates the family string
    if (selectedCapId) {
        const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
        if (cap) cap._fontLogged = false;
    }
	logActivity('font_change', val, currentIndex);
}

// Close font picker when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('fontPickerWrap');
    if (wrap && !wrap.contains(e.target)) {
        const dd = document.getElementById('fontPickerDropdown');
        if (dd) dd.style.display = 'none';
    }
});

// ── Delegated click for font picker options ───────────────────────
// fp-opt divs have no inline onclick — this handler catches all of them,
// including any new fonts added later, without touching the HTML.
document.addEventListener('click', function(e) {
    const opt = e.target.closest('.fp-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (!val) return;
    // Build label: text content minus any child <span> (used for subtitle in Arabic fonts)
    const clone = opt.cloneNode(true);
    clone.querySelectorAll('span').forEach(s => s.remove());
    const label = clone.textContent.trim() || val.split(',')[0].replace(/'/g, '');
    selectFont(val, label, opt);
});
// ── Choice modal (Upload vs Library) ──────────────────────────
function _imgCapChoiceModal() {
    return new Promise(res => {
        const overlay = document.createElement('div');
        overlay.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;
            display:flex;align-items:center;justify-content:center;`;
        overlay.innerHTML = `
            <div style="background:#fff;border-radius:14px;padding:24px;width:280px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4);">
                <div style="font-size:20px;margin-bottom:8px;">🖼️</div>
                <div style="font-size:14px;font-weight:700;color:#0f2a44;margin-bottom:6px;">Add Image Caption</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:18px;">Choose an image to place on the canvas</div>
                <div style="display:flex;gap:8px;">
                    <button id="_icUpload" style="flex:1;padding:10px;border-radius:9px;border:none;background:var(--success);color:#fff;font-size:12px;font-weight:700;cursor:pointer;">📤 Upload</button>
                    <button id="_icLib"    style="flex:1;padding:10px;border-radius:9px;border:none;background:#7c3aed;color:#fff;font-size:12px;font-weight:700;cursor:pointer;">📚 Library</button>
                </div>
                <button id="_icCancel" style="margin-top:10px;width:100%;padding:8px;border:none;background:none;color:#94a3b8;font-size:11px;cursor:pointer;">Cancel</button>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#_icUpload').onclick  = () => { document.body.removeChild(overlay); res('upload'); };
        overlay.querySelector('#_icLib').onclick     = () => { document.body.removeChild(overlay); res('library'); };
        overlay.querySelector('#_icCancel').onclick  = () => { document.body.removeChild(overlay); res(null); };
        overlay.addEventListener('click', e => { if (e.target === overlay) { document.body.removeChild(overlay); res(null); } });
    });
}

// ── Upload flow ────────────────────────────────────────────────
function _imgCapUpload() {
    return new Promise(res => {
        const inp = document.createElement('input');
        inp.type   = 'file';
        inp.accept = 'image/*';
        inp.onchange = async () => {
            if (!inp.files || !inp.files[0]) { res(null); return; }
            const file = inp.files[0];
            const sc   = SCENES[currentIndex];
            const fd   = new FormData();
            fd.append('ajax_action', 'upload_scene_image');
            fd.append('scene_id',    sc.id);
            fd.append('image_field', 'image_file');   // slot doesn't matter, just needs valid field
            fd.append('media_type',  'image');
            fd.append('scene_image', file);
            L('Uploading image…', 'inf');
            try {
                const r    = await fetch(location.href, { method:'POST', body:fd });
                const data = await r.json();
                if (data.success) { res(data.filename); }
                else { L('Upload failed: ' + data.message, 'err'); res(null); }
            } catch(e) { L('Upload error: ' + e.message, 'err'); res(null); }
        };
        inp.click();
    });
}

// ── Library pick flow ──────────────────────────────────────────
function _imgCapFromLibrary() {
    return new Promise(res => {
        // Reuse existing library modal but resolve with filename on use
        window._imgCapLibResolve = res;
        window._imgCapLibMode    = true;
        openLibraryModal();
    });
}

// ── Helper: show/hide global checkbox ────────────────────────────
function _updateGlobalLabel() {
    const wrap = document.getElementById('capGlobalWrap');
    if (!wrap) return;
    const chk = document.getElementById('capGlobalChk');
    if (!selectedCapId) { wrap.style.display = 'none'; return; }
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) { wrap.style.display = 'none'; return; }
    const name = (cap.caption_name || '').toLowerCase().trim();
    if (GLOBAL_CAP_NAMES.includes(name)) {
        wrap.style.display = 'flex';
        if (chk) chk.checked = _applyToAllScenes;
    } else {
        wrap.style.display = 'none';
    }
}

// ── Field change → debounced save (with global propagation) ──────
function capFieldChanged(field, value) { 
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;

    // Clamp position and width to canvas bounds before applying
    if (field === 'width') {
        const x  = parseFloat(cap.position_x) || 0;
        value = Math.max(40, Math.min(CW - CAP_MARGIN - x, parseFloat(value) || 40));
    } else if (field === 'position_x') {
        const w  = parseFloat(cap.width) || 120;
        value = Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w, parseFloat(value) || 0));
    } else if (field === 'position_y') {
        const bh = _capBoxH(cap);
        value = Math.max(0, Math.min(CH - Math.max(20, bh), parseFloat(value) || 0));
    }

    // Update current caption in memory
    cap[field] = value;
    if (!_capDirty[cap.id]) _capDirty[cap.id] = {};
    _capDirty[cap.id][field] = value;
    clearTimeout(_capSaveTimers[cap.id]);
    _capSaveTimers[cap.id] = setTimeout(() => _saveCaption(cap.id), 600);

    // Trigger animation restart if relevant
    if (field === 'text_content' || field === 'animation_style' || field === 'animation_speed')
        startCaptionAnim(cap);

    // ── Global propagation ─────────────────────────────────────
    if (!_applyToAllScenes) return;
    const capName = (cap.caption_name || '').toLowerCase().trim();
    if (!GLOBAL_CAP_NAMES.includes(capName)) return;

    // Find all OTHER captions with same name across all scenes
    ALL_CAPTIONS.forEach(other => {
        if (+other.id === +cap.id) return; // skip self
        const otherName = (other.caption_name || '').toLowerCase().trim();
        if (otherName !== capName) return;

        // Update in memory
        other[field] = value;

        // Mark dirty and debounce save
        if (!_capDirty[other.id]) _capDirty[other.id] = {};
        _capDirty[other.id][field] = value;
        clearTimeout(_capSaveTimers[other.id]);
        _capSaveTimers[other.id] = setTimeout(() => _saveCaption(other.id), 600);

        // If it's the current scene's caption (different id, same name), restart anim
        const sceneMatch = sceneCaptions.find(sc => +sc.id === +other.id);
        if (sceneMatch && (field === 'text_content' || field === 'animation_style' || field === 'animation_speed'))
            startCaptionAnim(sceneMatch);
    });
}

// ── Confirm screen ─────────────────────────────────────────────────
function _vsShowConfirm(type, plats, dt) {
    document.getElementById('vsMain').style.display    = 'none';
    document.getElementById('vsConfirm').style.display = 'block';

    const labels = {
        instagram:'📸 Instagram', tiktok:'🎵 TikTok', youtube:'▶️ YouTube',
        facebook:'📘 Facebook',   twitter:'🐦 X',      linkedin:'💼 LinkedIn',
    };

    if (type === 'now') {
        document.getElementById('vsConfirmIcon').textContent  = '🎉';
        document.getElementById('vsConfirmTitle').textContent = 'Posted!';
        document.getElementById('vsConfirmSub').textContent   = 'Going live now';
    } else {
        const ds = dt.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' });
        const ts = dt.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
        document.getElementById('vsConfirmIcon').textContent  = '🗓';
        document.getElementById('vsConfirmTitle').textContent = 'Scheduled!';
        document.getElementById('vsConfirmSub').textContent   = `Posts ${ds} at ${ts}`;
    }

    document.getElementById('vsConfirmPills').innerHTML =
        plats.map(p => `<span class="vs-confirm-pill">${labels[p] || p}</span>`).join('');

    L('✅ ' + (type === 'now' ? 'Posted' : 'Scheduled') + ' to: ' + plats.join(', '), 'ok');
}


console.log('script fully parsed');


// Helper function to update video status
async function updateVideoStatusToRecorded(podcastId) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'update_video_status');
        fd.append('podcast_id', podcastId);
        fd.append('status', 'RECORDED');
        fd.append('company_id', COMPANY_ID);
        const response = await fetch(location.href, { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success) {
            L('✅ Video status updated to RECORDED', 'ok');
            console.log('Video status updated to RECORDED for podcast:', podcastId);
        } else {
            console.warn('Failed to update video status:', data);
        }
    } catch(e) {
        console.warn('Status update error:', e);
    }
}

// Show MP4 download modal
function showMp4DownloadModal(mp4Url, filename, sizeMb) {
    // Close any existing download panel first
    const existingPanel = document.getElementById('dlPanel');
    if (existingPanel) {
        existingPanel.classList.remove('on');
    }
    
    // Create or update download panel
    let dlPanel = document.getElementById('dlPanel');
    if (!dlPanel) {
        dlPanel = document.createElement('div');
        dlPanel.id = 'dlPanel';
        dlPanel.style.cssText = 'width:100%;max-width:360px;background:var(--surface);border:1.5px solid #bbf7d0;border-radius:12px;padding:14px 16px;display:none;flex-direction:column;gap:10px;margin-top:10px;';
        const rightCol = document.querySelector('.right-col');
        if (rightCol) rightCol.appendChild(dlPanel);
    }
    
    dlPanel.innerHTML = `
        <h3 style="font-size:13px;font-weight:700;color:var(--success);">✅ MP4 Ready!</h3>
        <p id="dlMeta" style="font-size:11px;color:var(--text);font-weight:600;">${sizeMb} MB · MP4 format ✅<br>Ready for Instagram, TikTok, YouTube</p>
        <p style="font-size:11px;color:var(--muted);">Click below to save your video.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="dlLink" class="btn success" href="${mp4Url}" download="${filename}" style="background:var(--success);color:#fff;border:none;">⬇ Download MP4</a>
            <button class="btn" onclick="closeDownloadPanel()">✕ Close</button>
        </div>
    `;
    
    dlPanel.classList.add('on');
    dlPanel.style.display = 'flex';
    
    // Also update the scheduler modal if it's open
    if (typeof _vsRecFname !== 'undefined') {
        document.getElementById('vsFilenameDisplay').textContent = filename;
    }
}

function closeDownloadPanel() {
    const panel = document.getElementById('dlPanel');
    if (panel) {
        panel.classList.remove('on');
        panel.style.display = 'none';
    }
}
function showDownloadPanel(url, filename, format, sizeMb = null) {
    const dlPanel = document.getElementById('dlPanel');
    const dlMeta = document.getElementById('dlMeta');
    const dlLink = document.getElementById('dlLink');
    
    if (format === 'mp4') {
        dlMeta.innerHTML = `${sizeMb || '?'} MB · MP4 format ✅<br>Ready for Instagram, TikTok, YouTube`;
        dlLink.style.background = 'var(--success)';
    } else {
        dlMeta.innerHTML = `${sizeMb || '?'} MB · WebM format (open with VLC or Chrome)`;
        dlLink.style.background = '';
    }
    
    dlLink.href = url;
    dlLink.download = filename;
    dlPanel.classList.add('on');
}

function showMp4Toast(data) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:30px;right:30px;z-index:99999;' +
        'background:#052e16;border:1px solid #16a34a;color:#fff;' +
        'border-radius:14px;padding:20px 24px;max-width:320px;' +
        'box-shadow:0 10px 40px rgba(0,0,0,.4);';
    t.innerHTML = '<div style="font-weight:700;color:#4ade80;margin-bottom:6px;">✅ MP4 Ready!</div>' +
        '<div style="font-size:12px;color:#86efac;margin-bottom:4px;line-height:1.5;">' +
        data.filename + ' · ' + data.mp4_size_mb + ' MB<br>Saved to server. WebM deleted. ✓</div>' +
        '<span onclick="this.parentElement.remove()" ' +
        'style="position:absolute;top:10px;right:14px;cursor:pointer;color:#4ade80;font-size:20px;">×</span>';
    document.body.appendChild(t);
    setTimeout(() => t?.remove(), 20000);
}
// ─────────────────────────────────────────────────────────────────────────────

// ══════════════════════════════════════════════════════════════════════════════
// SCENE STRIP — scene list with thumbnails + per-scene action icons
// ══════════════════════════════════════════════════════════════════════════════

let _ssVisible = true;

function ssToggleStrip() {
    _ssVisible = !_ssVisible;
    const scroll = document.getElementById('sceneStripScroll');
    const btn    = document.getElementById('sceneStripToggle');
    if (scroll) scroll.style.display = _ssVisible ? '' : 'none';
    if (btn)    btn.textContent      = _ssVisible ? 'Hide ▲' : 'Show ▼';
}

/** Navigate to a scene via the strip row click */
function ssSelectScene(index) {
    if (index === currentIndex) return;
    showScene(index, false);
    ssHighlightCard(index);
}

/** Highlight the active row in the scene list */
function ssHighlightCard(index) {
    for (let i = 0; i < SCENES.length; i++) {
        const row = document.getElementById('ssRow' + i);
        if (!row) continue;
        if (i === index) {
            row.style.background = '#eff6ff';
            row.style.borderLeft = '3px solid var(--info)';
        } else {
            row.style.background = '';
            row.style.borderLeft = '';
        }
    }
    const activeRow = document.getElementById('ssRow' + index);
    const scroller  = document.getElementById('sceneStripScroll');
    if (activeRow && scroller) {
        const rowTop    = activeRow.offsetTop;
        const rowBottom = rowTop + activeRow.offsetHeight;
        const visTop    = scroller.scrollTop;
        const visBottom = visTop + scroller.clientHeight;
        if (rowTop < visTop || rowBottom > visBottom) {
            scroller.scrollTop = rowTop - scroller.clientHeight / 2;
        }
    }
}

// Intercept updateDots so scene strip stays in sync with all navigation methods
const _ssOrigUpdateDots = updateDots;
updateDots = function(i) {
    _ssOrigUpdateDots(i);
    ssHighlightCard(i);
};

// ── Open panels from strip icons ──────────────────────────────────

/** Navigate to scene (instant) then run callback after brief delay */
function _ssGoTo(index, cb) {
    if (currentIndex !== index) {
        showScene(index, true);
        setTimeout(cb, 120);
    } else {
        cb();
    }
}

// ── Save caption text from scene list textarea ────────────────────
async function ssSaveCaptionText(index) {
    const sc  = SCENES[index];
    const ta  = document.getElementById('ssCap' + index);
    if (!ta || !sc) return;
    const txt = ta.value;

    // Find the main caption for this scene in ALL_CAPTIONS
    const scCaps  = ALL_CAPTIONS.filter(c => +c.story_id === +sc.id);
    const mainCap = scCaps.find(c => (c.caption_name||'').toLowerCase() === 'main') || scCaps[0] || null;

    const btn = document.querySelector(`#ssRow${index} button[onclick*="ssSaveCaptionText"]`);
    if (btn) { btn.textContent = '⏳ Saving…'; btn.disabled = true; }

    try {
        if (mainCap) {
            // Save to hdb_captions
            const fd = new FormData();
            fd.append('ajax_action',  'save_caption_text');
            fd.append('caption_id',   mainCap.id);
            fd.append('text_content', txt);
            await fetch(location.href, { method:'POST', body:fd });
            // Update in-memory + redraw canvas caption
            mainCap.text_content = txt;
            if (typeof startCaptionAnim === 'function') startCaptionAnim(mainCap);
        } else {
            // Fallback: save to scene text_contents
            sc.text_contents = txt;
            const fd = new FormData();
            fd.append('ajax_action',   'save_scene_text');
            fd.append('scene_id',      sc.id);
            fd.append('text_contents', txt);
            await fetch(location.href, { method:'POST', body:fd });
        }
        // Also sync the audioSceneText textarea if audio panel is open on same scene
        if (index === currentIndex) {
            const audTa = document.getElementById('audioSceneText');
            if (audTa) audTa.value = txt;
        }
        if (btn) { btn.textContent = '✅ Saved!'; setTimeout(() => { btn.textContent = '💾 Save'; btn.disabled = false; }, 1500); }
    } catch(e) {
        if (btn) { btn.textContent = '❌ Error'; setTimeout(() => { btn.textContent = '💾 Save'; btn.disabled = false; }, 1500); }
    }
}

// ── Caption button: open caption panel (tab 1 only) ───────────────
let _capBodyParent = null;

function ssOpenCaption(index) {
    _ssGoTo(index, () => {
        _forceCapTab = 1;
        renderCaptionTabs();
        // Pre-mark pCaption as open so selectCaption() won't call togglePanel()
        document.getElementById('pCaption').classList.add('open');
        if (!selectedCapId && sceneCaptions.length > 0) {
            selectCaption(sceneCaptions[0].id);
        }
        // Immediately remove so the panel never renders visibly
        document.getElementById('pCaption').classList.remove('open');
        setTimeout(() => {
            _forceCapTab = 0;
            const ed      = document.getElementById('captionEditor');
            const tab1    = document.getElementById('capSubTab1');
            const tab2    = document.getElementById('capSubTab2');
            const source  = document.getElementById('pCaptionBody');
            const dest    = document.getElementById('captionOverlayBody');
            const numEl   = document.getElementById('captionOverlayNum');
            const overlay = document.getElementById('pCaptionOverlay');
            if (!source || !dest || !overlay) return;
            if (ed)   ed.style.display   = 'block';
            if (tab1) tab1.style.display = 'block';
            if (tab2) tab2.style.display = 'none';
            _capBodyParent = source.parentNode;
            dest.appendChild(source);
            source.style.display = 'block';
            if (numEl) numEl.textContent = index + 1;
            overlay.style.display = 'flex';
            overlay.onclick = (e) => { if (e.target === overlay) closeCaptionOverlay(); };
        }, 180);
    });
}

function closeCaptionOverlay() {
    const overlay = document.getElementById('pCaptionOverlay');
    const source  = document.getElementById('pCaptionBody');
    if (source && _capBodyParent) _capBodyParent.appendChild(source);
    document.getElementById('pCaption').classList.remove('open');
    if (overlay) overlay.style.display = 'none';
}

// ── Font button: open font overlay (capSubTab2 in its own modal) ──
let _fontOverlayOpen = false;
let _fontTab2Parent  = null; // original parent to restore to

function ssOpenFont(index) {
    // Must have a caption selected first
    if (!selectedCapId) {
        alert('Please select a text caption on the canvas first before opening the font editor.');
        return;
    }
    _ssGoTo(index, () => {
        _forceCapTab = 2;
        // Pre-mark pCaption as open so selectCaption() won't call togglePanel()
        document.getElementById('pCaption').classList.add('open');
        renderCaptionTabs();
        document.getElementById('pCaption').classList.remove('open');
        setTimeout(() => {
            _forceCapTab = 0;
            openFontOverlay(index);
        }, 150);
    });
}

function openFontOverlay(index) {
    const panel  = document.getElementById('pFontPanel');
    const body   = document.getElementById('fontOverlayBody');
    const numEl  = document.getElementById('fontOverlaySceneNum');
    const tab2   = document.getElementById('capSubTab2');
    if (!panel || !body || !tab2) return;

    // Move capSubTab2 into the overlay body
    _fontTab2Parent = tab2.parentNode;
    body.appendChild(tab2);
    tab2.style.display = 'block';

    document.getElementById('capSubTab1').style.display = 'none';

    if (numEl) numEl.textContent = index + 1;

    // Reset to centered position each open
    panel.style.left      = '50%';
    panel.style.top       = '40px';
    panel.style.transform = 'translateX(-50%)';
    panel.style.display   = 'flex';
    _fontOverlayOpen = true;
}

function closeFontOverlay() {
    const panel  = document.getElementById('pFontPanel');
    const body   = document.getElementById('fontOverlayBody');
    const tab2   = document.getElementById('capSubTab2');
    if (!panel || !tab2) return;

    if (_fontTab2Parent) _fontTab2Parent.appendChild(tab2);
    tab2.style.display = 'none';

    document.getElementById('pCaption').classList.remove('open');
    document.getElementById('capSubTab1').style.display = 'block';

    panel.style.display = 'none';
    _fontOverlayOpen = false;
}

// ── Drag-to-move for font overlay panel ──────────────────────────────────
(function() {
    let dragging = false, startX = 0, startY = 0, origLeft = 0, origTop = 0;

    function getPanel() { return document.getElementById('pFontPanel'); }

    function onDown(e) {
        if (e.target.closest('button')) return;
        const panel = getPanel();
        if (!panel) return;
        dragging = true;
        const rect = panel.getBoundingClientRect();
        origLeft = rect.left;
        origTop  = rect.top;
        startX = e.clientX || e.touches[0].clientX;
        startY = e.clientY || e.touches[0].clientY;
        panel.style.transform = 'none';
        panel.style.left = origLeft + 'px';
        panel.style.top  = origTop  + 'px';
        panel.style.cursor = 'grabbing';
        e.preventDefault();
    }

    function onMove(e) {
        if (!dragging) return;
        const cx = e.clientX || (e.touches && e.touches[0].clientX);
        const cy = e.clientY || (e.touches && e.touches[0].clientY);
        const panel = getPanel();
        if (!panel) return;
        const dx = cx - startX, dy = cy - startY;
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const newLeft = Math.max(0, Math.min(window.innerWidth  - pw, origLeft + dx));
        const newTop  = Math.max(0, Math.min(window.innerHeight - ph, origTop  + dy));
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
    }

    function onUp() {
        if (!dragging) return;
        dragging = false;
        const panel = getPanel();
        if (panel) panel.style.cursor = '';
    }

    document.addEventListener('mousedown', function(e) {
        const h = document.getElementById('pFontDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    });
    document.addEventListener('touchstart', function(e) {
        const h = document.getElementById('pFontDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    }, { passive: false });
    document.addEventListener('mousemove',  onMove);
    document.addEventListener('touchmove',  onMove, { passive: false });
    document.addEventListener('mouseup',    onUp);
    document.addEventListener('touchend',   onUp);
})();

let _imgBodyParent = null;
function ssOpenImage(index) {
    _ssGoTo(index, () => {
        updateSlotThumbs(SCENES[index]);
        selectSlot(activeSlot);
        const source  = document.getElementById('pImageBody');
        const dest    = document.getElementById('imageOverlayBody');
        const numEl   = document.getElementById('imageOverlayNum');
        const panel   = document.getElementById('pImagePanel');
        if (!source || !dest || !panel) return;
        _imgBodyParent = source.parentNode;
        dest.appendChild(source);
        if (numEl) numEl.textContent = index + 1;
        // Reset to default centered position each open
        panel.style.left      = '50%';
        panel.style.top       = '60px';
        panel.style.transform = 'translateX(-50%)';
        panel.style.display   = 'flex';
    });
}
function closeImageOverlay() {
    const panel  = document.getElementById('pImagePanel');
    const source = document.getElementById('pImageBody');
    if (source && _imgBodyParent) _imgBodyParent.appendChild(source);
    if (panel) panel.style.display = 'none';
}

// ── Drag-to-move for image overlay panel ──────────────────────────────────
(function() {
    let dragging = false, startX = 0, startY = 0, origLeft = 0, origTop = 0;

    function getPanel() { return document.getElementById('pImagePanel'); }

    function onDown(e) {
        if (e.target.closest('button')) return; // don't drag when clicking close
        const panel = getPanel();
        if (!panel) return;
        dragging = true;
        // Resolve current left/top in px (strip transform after first drag)
        const rect = panel.getBoundingClientRect();
        origLeft = rect.left;
        origTop  = rect.top;
        startX = e.clientX || e.touches[0].clientX;
        startY = e.clientY || e.touches[0].clientY;
        // Switch from transform-based centering to px positioning
        panel.style.transform = 'none';
        panel.style.left = origLeft + 'px';
        panel.style.top  = origTop  + 'px';
        panel.style.cursor = 'grabbing';
        e.preventDefault();
    }

    function onMove(e) {
        if (!dragging) return;
        const cx = e.clientX || (e.touches && e.touches[0].clientX);
        const cy = e.clientY || (e.touches && e.touches[0].clientY);
        const panel = getPanel();
        if (!panel) return;
        const dx = cx - startX;
        const dy = cy - startY;
        // Clamp inside viewport
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const newLeft = Math.max(0, Math.min(window.innerWidth  - pw, origLeft + dx));
        const newTop  = Math.max(0, Math.min(window.innerHeight - ph, origTop  + dy));
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
    }

    function onUp() {
        if (!dragging) return;
        dragging = false;
        const panel = getPanel();
        if (panel) panel.style.cursor = '';
    }

    document.addEventListener('mousedown',  function(e) {
        const h = document.getElementById('pImageDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    });
    document.addEventListener('touchstart', function(e) {
        const h = document.getElementById('pImageDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    }, { passive: false });
    document.addEventListener('mousemove',  onMove);
    document.addEventListener('touchmove',  onMove, { passive: false });
    document.addEventListener('mouseup',    onUp);
    document.addEventListener('touchend',   onUp);
})();

let _audBodyParent = null;
function ssOpenAudio(index) {
    _ssGoTo(index, () => {
        const source = document.getElementById('pAudioBody');
        const dest   = document.getElementById('audioOverlayBody');
        const numEl  = document.getElementById('audioOverlayNum');
        const panel  = document.getElementById('pAudioPanel');
        if (!source || !dest || !panel) return;
        _audBodyParent = source.parentNode;
        dest.appendChild(source);
        if (numEl) numEl.textContent = index + 1;
        panel.style.left      = '50%';
        panel.style.top       = '40px';
        panel.style.transform = 'translateX(-50%)';
        panel.style.display   = 'flex';
        loadAudioPanel();
    });
}
function closeAudioOverlay() {
    const panel  = document.getElementById('pAudioPanel');
    const source = document.getElementById('pAudioBody');
    if (source && _audBodyParent) _audBodyParent.appendChild(source);
    if (panel) panel.style.display = 'none';
}

// ── Drag-to-move for audio overlay panel ─────────────────────────────────
(function() {
    let dragging = false, startX = 0, startY = 0, origLeft = 0, origTop = 0;

    function getPanel() { return document.getElementById('pAudioPanel'); }

    function onDown(e) {
        if (e.target.closest('button')) return;
        const panel = getPanel();
        if (!panel) return;
        dragging = true;
        const rect = panel.getBoundingClientRect();
        origLeft = rect.left;
        origTop  = rect.top;
        startX = e.clientX || e.touches[0].clientX;
        startY = e.clientY || e.touches[0].clientY;
        panel.style.transform = 'none';
        panel.style.left = origLeft + 'px';
        panel.style.top  = origTop  + 'px';
        panel.style.cursor = 'grabbing';
        e.preventDefault();
    }

    function onMove(e) {
        if (!dragging) return;
        const cx = e.clientX || (e.touches && e.touches[0].clientX);
        const cy = e.clientY || (e.touches && e.touches[0].clientY);
        const panel = getPanel();
        if (!panel) return;
        const dx = cx - startX, dy = cy - startY;
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const newLeft = Math.max(0, Math.min(window.innerWidth  - pw, origLeft + dx));
        const newTop  = Math.max(0, Math.min(window.innerHeight - ph, origTop  + dy));
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
    }

    function onUp() {
        if (!dragging) return;
        dragging = false;
        const panel = getPanel();
        if (panel) panel.style.cursor = '';
    }

    document.addEventListener('mousedown', function(e) {
        const h = document.getElementById('pAudioDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    });
    document.addEventListener('touchstart', function(e) {
        const h = document.getElementById('pAudioDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    }, { passive: false });
    document.addEventListener('mousemove',  onMove);
    document.addEventListener('touchmove',  onMove, { passive: false });
    document.addEventListener('mouseup',    onUp);
    document.addEventListener('touchend',   onUp);
})();

// ── Update strip thumb whenever an image is assigned ─────────────

/**
 * Call this after any image assignment to refresh the strip thumbnail.
 * @param {number} sceneIndex  - 0-based index into SCENES
 * @param {string|null} src    - full URL / relative path of new image, or null to clear
 */
function ssUpdateThumb(sceneIndex, src) {
    const img  = document.getElementById('ssThumbImg'  + sceneIndex);
    const ph   = document.getElementById('ssThumbPh'   + sceneIndex);
    const wrap = document.getElementById('ssThumbWrap' + sceneIndex);
    const icon = document.getElementById('ssIconImg'   + sceneIndex);

    if (!src) {
        if (img)  { img.src = ''; img.style.display = 'none'; }
        // Remove any existing video thumb
        if (wrap) { const ov = wrap.querySelector('video.ss-vid-thumb'); if (ov) ov.remove(); }
        if (ph)   ph.style.display = 'flex';
        if (wrap) wrap.style.borderColor = 'var(--border)';
        if (icon) { icon.style.borderColor = 'var(--border)'; icon.style.background = 'var(--surface)'; icon.style.color = ''; }
        return;
    }

    const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(src);

    // Remove any existing video thumb first
    if (wrap) { const ov = wrap.querySelector('video.ss-vid-thumb'); if (ov) ov.remove(); }

    if (isVid) {
        // Show video element as thumbnail
        if (img) { img.src = ''; img.style.display = 'none'; }
        if (ph)  ph.style.display = 'none';
        if (wrap) {
            const vidEl = document.createElement('video');
            vidEl.className   = 'ss-vid-thumb';
            vidEl.src         = src;
            vidEl.muted       = true;
            vidEl.playsInline = true;
            vidEl.preload     = 'metadata';
            vidEl.currentTime = 0.5;
            vidEl.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;position:absolute;top:0;left:0;border-radius:inherit;';
            vidEl.onerror = function() {
                this.remove();
                if (ph) { ph.textContent = '🎬'; ph.style.display = 'flex'; }
            };
            wrap.style.position = 'relative';
            wrap.appendChild(vidEl);
        }
    } else {
        // Show image thumbnail
        if (img) {
            img.onerror = () => { img.style.display = 'none'; if (ph) ph.style.display = 'flex'; };
            img.src = src + '?t=' + Date.now();
            img.style.display = 'block';
        }
        if (ph) ph.style.display = 'none';
    }

    if (wrap) wrap.style.borderColor = '#16a34a';
    if (icon) { icon.style.borderColor = '#16a34a'; icon.style.background = '#dcfce7'; icon.style.color = '#15803d'; }
}

/** Call after audio is generated/removed for a scene */
function ssMarkAudio(sceneIndex, hasAudio) {
    const icon  = document.getElementById('ssIconAud'   + sceneIndex);
    const badge = document.getElementById('ssAudBadge'  + sceneIndex);
    if (icon)  { icon.style.borderColor = hasAudio ? '#16a34a' : 'var(--border)'; icon.style.background = hasAudio ? '#dcfce7' : 'var(--surface)'; icon.style.color = hasAudio ? '#15803d' : ''; }
    if (badge) badge.style.display = hasAudio ? 'block' : 'none';
}

// ── Auto-sync strip when images or audio are assigned via existing UI ─

// Patch useLibraryFile so strip thumb updates after library picks
(function patchLibraryAssign() {
    const orig = window.useLibraryFile;
    if (typeof orig !== 'function') return;
    window.useLibraryFile = async function() {
        const result = await orig.apply(this, arguments);
        // After assign, read updated SCENES[currentIndex] to refresh thumb
        setTimeout(() => {
            const sc = SCENES[currentIndex];
            if (!sc) return;
            const fn = (sc[activeSlot] || '').trim();
            if (fn) {
                const folderCol = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
                const folder = (sc[folderCol] || sc.image_folder || 'podcast_images').replace(/\/?$/, '/');
                // Update strip thumb for both images AND videos
                ssUpdateThumb(currentIndex, folder + fn);
                // Also update slot thumbs panel
                updateSlotThumbs(sc);
            }
        }, 300);
        return result;
    };
})();

// Patch generate_scene_audio response to mark audio icon green
(function patchAudioGen() {
    // We hook the generate_scene_audio AJAX result by watching the audSub1 panel button
    // The actual hook point is after the fetch in loadAudioPanel / generateVoice flow.
    // Since we can't easily monkey-patch anonymous fetches, we observe DOM changes
    // on the audio status element instead.
    const target = document.getElementById('audCapInfo');
    if (!target) return;
    const obs = new MutationObserver(() => {
        const sc = SCENES[currentIndex];
        if (sc && sc.audio_file) ssMarkAudio(currentIndex, true);
    });
    obs.observe(target, { childList: true, characterData: true, subtree: true });
})();

// Expose globally so other parts of the codebase can call them
window.ssUpdateThumb = ssUpdateThumb;
window.ssMarkAudio   = ssMarkAudio;

function ssPlayFromScene(index) {
    // Navigate to the scene first, then start playback
    if (currentIndex !== index) {
        showScene(index, true);
        setTimeout(() => togglePlay(), 120);
    } else {
        togglePlay();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// END SCENE STRIP
// ══════════════════════════════════════════════════════════════════════════════
