// ═══════════════════════════════════════════════════════════════
//  DROP-IN REPLACEMENT for the campaign podcast list section
//  in vizard_browser.php
//  Replace the entire viewCampaignPodcasts() function and the
//  openDraftModal / startDraftPipeline section with this.
// ═══════════════════════════════════════════════════════════════

// ── View podcasts for a campaign ─────────────────────────────
function viewCampaignPodcasts(campaignId, campaignName) {
    const tableView = document.getElementById('campaignTableView');
    const listView  = document.getElementById('campaignPodcastView');

    tableView.style.display = 'none';
    listView.style.display  = 'block';
    listView.innerHTML = `
        <div class="camp-podcast-header">
            <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
            <h3>🚀 ${escHtml(campaignName)}</h3>
        </div>
        <div class="loading-spinner"><div class="spinner"></div><p style="margin-top:14px;">Loading podcasts…</p></div>`;

    fetch(`ajax_load_campaign_podcasts.php?campaign_id=${campaignId}&admin_id=${ADMIN_ID}&company_id=${COMPANY_ID}`)
        .then(r => r.json())
        .then(data => {
            const podcasts = data.podcasts || [];
            if (!podcasts.length) {
                listView.innerHTML = `
                    <div class="camp-podcast-header">
                        <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
                        <h3>🚀 ${escHtml(campaignName)}</h3>
                    </div>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <p>No podcasts in this campaign yet</p>
                        <div class="empty-hint">Generate scripts from the Wizard to populate this campaign</div>
                    </div>`;
                return;
            }

            const rows = podcasts.map(p => {
                const isDraft  = (p.internal_status === 'draft');
                const langCode = p.lang_code || 'en';
                const date     = p.created_date || '';

                let sc = 'prs-active', st = 'In Progress';
                if (isDraft)                            { sc = 'prs-draft';     st = '⚡ Ready to Build'; }
                else if (p.video_status === 'RECORDED') { sc = 'prs-completed'; st = 'Completed'; }
                else if (p.video_status === 'POSTED')   { sc = 'prs-posted';    st = 'Posted'; }

                const thumbHtml = p.thumbnail
                    ? `<div class="podcast-row-thumb"><img src="${escAttr(p.thumbnail)}" onerror="this.parentNode.innerHTML='🎬'"></div>`
                    : `<div class="podcast-row-thumb">${isDraft ? '⚡' : '🎬'}</div>`;

                // ONE-CLICK BUILD — no modal, reads settings from DB
                const actionBtn = isDraft
                    ? `<button class="podcast-row-action build" id="build-btn-${p.id}"
                            onclick="event.stopPropagation(); startBuild(${p.id}, this)">
                            ⚡ Build</button>`
                    : `<button class="podcast-row-action open"
                            onclick="event.stopPropagation(); window.location.href='videomaker.php?podcast_id=${p.id}'">
                            ▶ Open</button>`;

                const rowClick = isDraft ? '' : `window.location.href='videomaker.php?podcast_id=${p.id}'`;

                return `<div class="podcast-row${isDraft ? ' is-draft' : ''}"
                            id="podcast-row-${p.id}"
                            ${rowClick ? `onclick="${rowClick}"` : ''}>
                    ${thumbHtml}
                    <div class="podcast-row-info">
                        <div class="podcast-row-title">${escHtml(p.title || 'Untitled')}</div>
                        <div class="podcast-row-meta">
                            <span class="podcast-row-lang">🌐 ${langCode.toUpperCase()}</span>
                            <span class="podcast-row-status ${sc}" id="status-${p.id}">${st}</span>
                            <span class="podcast-row-date">📅 ${date}</span>
                        </div>
                        <div class="build-log" id="log-${p.id}" style="display:none;"></div>
                    </div>
                    ${actionBtn}
                </div>`;
            }).join('');

            listView.innerHTML = `
                <div class="camp-podcast-header">
                    <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
                    <h3>🚀 ${escHtml(campaignName)}</h3>
                    <span class="camp-podcast-meta">${podcasts.length} podcast${podcasts.length !== 1 ? 's' : ''}</span>
                </div>
                <div class="podcast-list">${rows}</div>`;
        })
        .catch(err => {
            console.error(err);
            listView.innerHTML = `
                <div class="camp-podcast-header">
                    <button class="camp-back-btn" onclick="backToCampaignTable()">← Campaigns</button>
                    <h3>🚀 ${escHtml(campaignName)}</h3>
                </div>
                <div class="empty-state" style="border:none;"><div class="empty-icon">⚠️</div><p>Error loading podcasts.</p></div>`;
        });
}

// ── One-click build using SSE stream from build_podcast.php ──
function startBuild(podcastId, btn) {
    const logEl    = document.getElementById(`log-${podcastId}`);
    const statusEl = document.getElementById(`status-${podcastId}`);
    const rowEl    = document.getElementById(`podcast-row-${podcastId}`);

    btn.disabled   = true;
    btn.textContent = '⏳ Building…';
    logEl.style.display = 'block';
    logEl.innerHTML = '';

    function addLog(msg, type) {
        const p = document.createElement('p');
        p.className = `bl-${type}`;
        p.textContent = msg;
        logEl.appendChild(p);
        logEl.scrollTop = logEl.scrollHeight;
    }

    statusEl.textContent = '⏳ Building…';
    statusEl.className   = 'podcast-row-status prs-active';

    const es = new EventSource(`build_podcast.php?podcast_id=${podcastId}&mode=stream`);

    es.onmessage = e => {
        const d = JSON.parse(e.data);

        if (d.log) {
            addLog(d.log, d.type || 'info');
        }

        if (d.done) {
            es.close();
            if (d.success) {
                statusEl.textContent = '✅ Built';
                statusEl.className   = 'podcast-row-status prs-completed';
                btn.textContent      = '▶ Open';
                btn.className        = 'podcast-row-action open';
                btn.disabled         = false;
                btn.onclick          = e => { e.stopPropagation(); window.location.href = 'videomaker.php?podcast_id=' + podcastId; };
                rowEl.onclick        = () => window.location.href = 'videomaker.php?podcast_id=' + podcastId;
                addLog('🎉 Done! Click Open to continue.', 'success');
            } else {
                statusEl.textContent = '⚠ Failed';
                statusEl.className   = 'podcast-row-status prs-active';
                btn.textContent      = '⚡ Retry';
                btn.disabled         = false;
                addLog('Build finished with errors. Check log above.', 'error');
            }
        }
    };

    es.onerror = () => {
        es.close();
        addLog('❌ Connection lost. Check server logs.', 'error');
        btn.disabled    = false;
        btn.textContent = '⚡ Retry';
    };
}
