// ========== TRANSLATE VIDEO MODAL FUNCTIONS ==========
let translateModal = null;
let translateLanguages = [];
let translateVoices = [];
let selectedTranslateLang = '';
let selectedHostVoice = '';
let selectedGuestVoice = '';
let isTranslating = false;

// Initialize translate modal HTML
function initTranslateModal() {
    // Create modal if it doesn't exist
    if (document.getElementById('translateModal')) return;
    
    const modalHTML = `
    <div id="translateModal" class="modal-overlay" style="z-index: 10001;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>🌐 Translate Project</h3>
                <button class="modal-close" onclick="closeTranslateModal()">✕</button>
            </div>
            
            <div class="modal-body" style="padding: 24px;">
                <!-- Current Project Info -->
                <div style="background: #f1f5f9; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                    <div style="font-size: 14px; font-weight: 600; color: var(--dark-blue); margin-bottom: 8px;">
                        📋 Current Project
                    </div>
                    <div style="font-size: 13px; color: var(--text);">
                        <strong>Title:</strong> <?= htmlspecialchars($podcast_title ?: 'Untitled') ?><br>
                        <strong>Language:</strong> <span id="currentProjectLang"><?= strtoupper($podcast_lang_code ?: 'EN') ?></span>
                    </div>
                </div>
                
                <!-- Step 1: Select Target Language -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; color: var(--dark-blue); margin-bottom: 8px;">
                        1. Select Target Language
                    </label>
                    <select id="translateLangSelect" class="panel-select" style="width: 100%;" onchange="onLanguageSelect(this.value)">
                        <option value="">-- Choose language --</option>
                    </select>
                </div>
                
                <!-- Step 2: Voice Selection (shows after language selected) -->
                <div id="voiceSelectionSection" style="display: none; margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; color: var(--dark-blue); margin-bottom: 8px;">
                        2. Select Voices
                    </label>
                    
                    <?php if ($video_type === 'podcast'): ?>
                    <!-- Podcast: Show Host and Guest voices -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid var(--border);">
                        <div style="margin-bottom: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <span style="font-weight: 600; color: var(--dark-blue);">🎙️ Host Voice</span>
                                <button class="panel-btn" onclick="playVoiceSample('host')" style="background: var(--purple); color: white; border: none; padding: 6px 12px; border-radius: 20px; font-size: 12px; cursor: pointer;">
                                    🔊 Play Sample
                                </button>
                            </div>
                            <select id="hostVoiceSelect" class="panel-select" style="width: 100%;">
                                <option value="">Select host voice</option>
                            </select>
                        </div>
                        
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <span style="font-weight: 600; color: var(--dark-blue);">👤 Guest Voice</span>
                                <button class="panel-btn" onclick="playVoiceSample('guest')" style="background: var(--purple); color: white; border: none; padding: 6px 12px; border-radius: 20px; font-size: 12px; cursor: pointer;">
                                    🔊 Play Sample
                                </button>
                            </div>
                            <select id="guestVoiceSelect" class="panel-select" style="width: 100%;">
                                <option value="">Select guest voice</option>
                            </select>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Single video: Show one voice selection -->
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid var(--border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600; color: var(--dark-blue);">🎤 Narrator Voice</span>
                            <button class="panel-btn" onclick="playVoiceSample('narrator')" style="background: var(--purple); color: white; border: none; padding: 6px 12px; border-radius: 20px; font-size: 12px; cursor: pointer;">
                                🔊 Play Sample
                            </button>
                        </div>
                        <select id="narratorVoiceSelect" class="panel-select" style="width: 100%;">
                            <option value="">Select narrator voice</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Progress Bar (shown during translation) -->
                <div id="translateProgress" style="display: none; margin: 20px 0;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; font-weight: 600; color: var(--text);">Translating...</span>
                        <span id="translateProgressPercent" style="font-size: 13px; color: var(--info);">0%</span>
                    </div>
                    <div class="progress-bar" style="margin: 0;">
                        <div id="translateProgressFill" class="progress-fill" style="width: 0%;"></div>
                    </div>
                    <div id="translateStatus" style="font-size: 12px; color: var(--muted); margin-top: 8px; text-align: center;">
                        Preparing...
                    </div>
                </div>
                
                <!-- Warning Message -->
                <div id="translateWarning" style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 8px; font-size: 13px; color: #92400e; display: none;">
                    ⚠️ A version in this language already exists. It will be replaced.
                </div>
            </div>
            
            <div class="modal-footer" style="justify-content: space-between;">
                <div id="translateInfo" style="font-size: 13px; color: var(--muted);">
                    All scenes will be translated
                </div>
                <div style="display: flex; gap: 12px;">
                    <button class="panel-btn" onclick="closeTranslateModal()" style="background: #f1f5f9; color: var(--text); border: 1px solid var(--border);">
                        Cancel
                    </button>
                    <button id="translateBtn" class="panel-btn" onclick="startTranslation()" style="background: var(--success); color: white;" disabled>
                        🌐 Start Translation
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hidden audio element for voice samples -->
    <audio id="sampleAudioPlayerTranslate" style="display:none;"></audio>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    translateModal = document.getElementById('translateModal');
    
    // Load languages on init
    loadTranslateLanguages();
}

// Load available languages from database (excluding current)
async function loadTranslateLanguages() {
    try {
        const currentLang = '<?= $podcast_lang_code ?>';
        const fd = new FormData();
        fd.append('ajax_action', 'get_translate_languages');
        fd.append('exclude_lang', currentLang);
        
        const {data} = await safeFetch(fd);
        
        if (data.success && data.languages) {
            translateLanguages = data.languages;
            
            const select = document.getElementById('translateLangSelect');
            select.innerHTML = '<option value="">-- Choose language --</option>';
            
            translateLanguages.forEach(lang => {
                select.innerHTML += `<option value="${lang.lang_code}">${lang.lang_name} (${lang.lang_code})</option>`;
            });
        }
    } catch(e) {
        console.error('Error loading languages:', e);
        // Fallback languages
        const select = document.getElementById('translateLangSelect');
        select.innerHTML = `
            <option value="">-- Choose language --</option>
            <option value="ur">🇵🇰 Urdu (ur)</option>
            <option value="ar">🇸🇦 Arabic (ar)</option>
            <option value="hi">🇮🇳 Hindi (hi)</option>
            <option value="fr">🇫🇷 French (fr)</option>
            <option value="es">🇪🇸 Spanish (es)</option>
            <option value="de">🇩🇪 German (de)</option>
            <option value="zh">🇨🇳 Chinese (zh)</option>
        `;
    }
}

// Load voices for selected language
async function loadVoicesForLanguage(langCode) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_voices_by_language');
        fd.append('lang_code', langCode);
        
        const {data} = await safeFetch(fd);
        
        if (data.success && data.voices) {
            translateVoices = data.voices;
            
            // Update voice dropdowns
            <?php if ($video_type === 'podcast'): ?>
            // Podcast: Update both host and guest dropdowns
            const hostSelect = document.getElementById('hostVoiceSelect');
            const guestSelect = document.getElementById('guestVoiceSelect');
            
            hostSelect.innerHTML = '<option value="">Select host voice</option>';
            guestSelect.innerHTML = '<option value="">Select guest voice</option>';
            
            data.voices.forEach(voice => {
                const option = `<option value="${voice.voice_key}" data-sample="${voice.sample_voice || ''}">${voice.voice_name} - ${voice.voice_description || ''}</option>`;
                hostSelect.innerHTML += option;
                guestSelect.innerHTML += option;
            });
            
            // Auto-select first voice for host if available
            if (data.voices.length > 0) {
                hostSelect.value = data.voices[0].voice_key;
                selectedHostVoice = data.voices[0].voice_key;
                
                // Select second voice for guest if available, otherwise same as host
                if (data.voices.length > 1) {
                    guestSelect.value = data.voices[1].voice_key;
                    selectedGuestVoice = data.voices[1].voice_key;
                } else {
                    guestSelect.value = data.voices[0].voice_key;
                    selectedGuestVoice = data.voices[0].voice_key;
                }
            }
            <?php else: ?>
            // Single video: Update narrator dropdown
            const narratorSelect = document.getElementById('narratorVoiceSelect');
            narratorSelect.innerHTML = '<option value="">Select narrator voice</option>';
            
            data.voices.forEach(voice => {
                narratorSelect.innerHTML += `<option value="${voice.voice_key}" data-sample="${voice.sample_voice || ''}">${voice.voice_name} - ${voice.voice_description || ''}</option>`;
            });
            
            // Auto-select first voice if available
            if (data.voices.length > 0) {
                narratorSelect.value = data.voices[0].voice_key;
            }
            <?php endif; ?>
            
            return true;
        } else {
            // Fallback to hardcoded voices
            <?php if ($video_type === 'podcast'): ?>
            const hostSelect = document.getElementById('hostVoiceSelect');
            const guestSelect = document.getElementById('guestVoiceSelect');
            
            const fallbackVoices = getFallbackVoices(langCode);
            
            hostSelect.innerHTML = '<option value="">Select host voice</option>';
            guestSelect.innerHTML = '<option value="">Select guest voice</option>';
            
            fallbackVoices.forEach((voice, index) => {
                const option = `<option value="${voice.key}" data-sample="${voice.sample || ''}">${voice.name}</option>`;
                hostSelect.innerHTML += option;
                guestSelect.innerHTML += option;
            });
            
            if (fallbackVoices.length > 0) {
                hostSelect.value = fallbackVoices[0].key;
                if (fallbackVoices.length > 1) {
                    guestSelect.value = fallbackVoices[1].key;
                } else {
                    guestSelect.value = fallbackVoices[0].key;
                }
            }
            <?php else: ?>
            const narratorSelect = document.getElementById('narratorVoiceSelect');
            const fallbackVoices = getFallbackVoices(langCode);
            
            narratorSelect.innerHTML = '<option value="">Select narrator voice</option>';
            fallbackVoices.forEach(voice => {
                narratorSelect.innerHTML += `<option value="${voice.key}" data-sample="${voice.sample || ''}">${voice.name}</option>`;
            });
            
            if (fallbackVoices.length > 0) {
                narratorSelect.value = fallbackVoices[0].key;
            }
            <?php endif; ?>
            
            return true;
        }
    } catch(e) {
        console.error('Error loading voices:', e);
        return false;
    }
}

// Get fallback voices based on language
function getFallbackVoices(langCode) {
    const voices = {
        'ur': [
            { key: 'ur-PK-AsadNeural', name: 'Asad - Male', sample: '' },
            { key: 'ur-PK-UzmaNeural', name: 'Uzma - Female', sample: '' }
        ],
        'ar': [
            { key: 'ar-SA-HamedNeural', name: 'Hamed - Male', sample: '' },
            { key: 'ar-SA-ZariyahNeural', name: 'Zariyah - Female', sample: '' }
        ],
        'hi': [
            { key: 'hi-IN-MadhurNeural', name: 'Madhur - Male', sample: '' },
            { key: 'hi-IN-SwaraNeural', name: 'Swara - Female', sample: '' }
        ],
        'fr': [
            { key: 'fr-FR-HenriNeural', name: 'Henri - Male', sample: '' },
            { key: 'fr-FR-DeniseNeural', name: 'Denise - Female', sample: '' }
        ],
        'es': [
            { key: 'es-ES-AlvaroNeural', name: 'Alvaro - Male', sample: '' },
            { key: 'es-ES-ElviraNeural', name: 'Elvira - Female', sample: '' }
        ],
        'de': [
            { key: 'de-DE-ConradNeural', name: 'Conrad - Male', sample: '' },
            { key: 'de-DE-KatjaNeural', name: 'Katja - Female', sample: '' }
        ],
        'zh': [
            { key: 'zh-CN-YunxiNeural', name: 'Yunxi - Male', sample: '' },
            { key: 'zh-CN-XiaoxiaoNeural', name: 'Xiaoxiao - Female', sample: '' }
        ]
    };
    
    return voices[langCode] || [
        { key: 'en-US-GuyNeural', name: 'Guy - Male', sample: '' },
        { key: 'en-US-JennyNeural', name: 'Jenny - Female', sample: '' }
    ];
}

// Handle language selection
async function onLanguageSelect(langCode) {
    selectedTranslateLang = langCode;
    
    if (!langCode) {
        document.getElementById('voiceSelectionSection').style.display = 'none';
        document.getElementById('translateBtn').disabled = true;
        return;
    }
    
    // Show voice selection
    document.getElementById('voiceSelectionSection').style.display = 'block';
    document.getElementById('translateBtn').disabled = true; // Disable until voices are selected
    
    // Check if language already exists
    checkIfLanguageExists(langCode);
    
    // Load voices
    await loadVoicesForLanguage(langCode);
    
    // Enable translate button based on voice selection
    updateTranslateButtonState();
}

// Check if a version in this language already exists
async function checkIfLanguageExists(langCode) {
    try {
        const podcastTitle = '<?= addslashes($podcast_title) ?>';
        const fd = new FormData();
        fd.append('ajax_action', 'check_language_exists');
        fd.append('title', podcastTitle);
        fd.append('lang_code', langCode);
        
        const {data} = await safeFetch(fd);
        
        const warningDiv = document.getElementById('translateWarning');
        if (data.exists) {
            warningDiv.style.display = 'block';
        } else {
            warningDiv.style.display = 'none';
        }
    } catch(e) {
        console.error('Error checking language:', e);
    }
}

// Update translate button state based on voice selections
function updateTranslateButtonState() {
    <?php if ($video_type === 'podcast'): ?>
    const hostVoice = document.getElementById('hostVoiceSelect').value;
    const guestVoice = document.getElementById('guestVoiceSelect').value;
    
    if (hostVoice && guestVoice) {
        selectedHostVoice = hostVoice;
        selectedGuestVoice = guestVoice;
        document.getElementById('translateBtn').disabled = false;
    } else {
        document.getElementById('translateBtn').disabled = true;
    }
    <?php else: ?>
    const narratorVoice = document.getElementById('narratorVoiceSelect').value;
    
    if (narratorVoice) {
        document.getElementById('translateBtn').disabled = false;
    } else {
        document.getElementById('translateBtn').disabled = true;
    }
    <?php endif; ?>
}

// Play voice sample
function playVoiceSample(type) {
    let select;
    <?php if ($video_type === 'podcast'): ?>
    if (type === 'host') {
        select = document.getElementById('hostVoiceSelect');
    } else {
        select = document.getElementById('guestVoiceSelect');
    }
    <?php else: ?>
    select = document.getElementById('narratorVoiceSelect');
    <?php endif; ?>
    
    if (!select || !select.value) {
        alert('Please select a voice first');
        return;
    }
    
    const selectedOption = select.options[select.selectedIndex];
    const sampleUrl = selectedOption.getAttribute('data-sample');
    
    if (!sampleUrl) {
        alert('No sample available for this voice');
        return;
    }
    
    const audio = document.getElementById('sampleAudioPlayerTranslate');
    audio.src = sampleUrl;
    audio.play()
        .then(() => L(`🔊 Playing ${type} voice sample`))
        .catch(err => L(`❌ Playback error: ${err.message}`));
}

// Open translate modal
function openTranslateModal() {
    initTranslateModal();
    
    if (translateModal) {
        translateModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Reset selections
        selectedTranslateLang = '';
        selectedHostVoice = '';
        selectedGuestVoice = '';
        document.getElementById('translateLangSelect').value = '';
        document.getElementById('voiceSelectionSection').style.display = 'none';
        document.getElementById('translateBtn').disabled = true;
        document.getElementById('translateWarning').style.display = 'none';
        
        // Hide progress if visible
        document.getElementById('translateProgress').style.display = 'none';
        
        L('🌐 Opening translate modal');
    }
}

// Close translate modal
function closeTranslateModal() {
    if (translateModal) {
        translateModal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Start translation process
async function startTranslation() {
    if (isTranslating) return;
    
    // Get selected voices
    <?php if ($video_type === 'podcast'): ?>
    const hostVoice = document.getElementById('hostVoiceSelect').value;
    const guestVoice = document.getElementById('guestVoiceSelect').value;
    
    if (!hostVoice || !guestVoice) {
        alert('Please select both host and guest voices');
        return;
    }
    <?php else: ?>
    const narratorVoice = document.getElementById('narratorVoiceSelect').value;
    
    if (!narratorVoice) {
        alert('Please select a narrator voice');
        return;
    }
    <?php endif; ?>
    
    if (!selectedTranslateLang) {
        alert('Please select a target language');
        return;
    }
    
    const podcastId = <?= $url_podcast_id ?: 0 ?>;
    if (!podcastId) {
        alert('Invalid podcast ID');
        return;
    }
    
    // Confirm with user
    const warning = document.getElementById('translateWarning');
    if (warning.style.display === 'block') {
        if (!confirm('A version in this language already exists. It will be replaced. Continue?')) {
            return;
        }
    }
    
    isTranslating = true;
    
    // Show progress
    document.getElementById('translateProgress').style.display = 'block';
    document.getElementById('translateBtn').disabled = true;
    
    const totalScenes = scenes.length;
    let completed = 0;
    
    updateTranslateProgress(0, `Starting translation to ${selectedTranslateLang}...`);
    
    try {
        // Step 1: Clone podcast with translations
        const formData = new FormData();
        formData.append('action', 'clone_podcast');
        formData.append('podcast_id', podcastId);
        formData.append('target_lang', selectedTranslateLang);
        
        updateTranslateProgress(10, 'Creating translated project...');
        
        const response = await fetch('trans_gen.php', {
            method: 'POST',
            body: formData
        });
        
        const rawText = await response.text();
        let data;
        
        try {
            data = JSON.parse(rawText);
        } catch(e) {
            throw new Error('Server returned invalid JSON');
        }
        
        if (!data.success || !data.results || data.results.length === 0) {
            throw new Error(data.error || 'Translation failed');
        }
        
        const result = data.results[0];
        if (result.status !== 'success') {
            throw new Error(result.error || 'Translation failed');
        }
        
        const newPodcastId = result.podcast_id;
        
        updateTranslateProgress(30, 'Project created. Generating audio...');
        
        // Step 2: Generate audio for all scenes
        <?php if ($video_type === 'podcast'): ?>
        // Podcast: Generate with host and guest voices alternating
        const scenes = await getScenesForPodcast(newPodcastId);
        
        for (let i = 0; i < scenes.length; i++) {
            const scene = scenes[i];
            const progress = 30 + Math.floor((i / scenes.length) * 60);
            
            updateTranslateProgress(progress, `Generating audio ${i+1}/${scenes.length}...`);
            
            // Alternate between host and guest voices
            const voiceToUse = (i % 2 === 0) ? hostVoice : guestVoice;
            
            const audioFormData = new FormData();
            audioFormData.append('row_id', scene.id);
            audioFormData.append('text', scene.text_contents || '');
            audioFormData.append('lang_code', selectedTranslateLang);
            audioFormData.append('voice_id', voiceToUse);
            audioFormData.append('rate', '1.0');
            
            const audioResponse = await fetch('generate_voice.php', {
                method: 'POST',
                body: audioFormData
            });
            
            const audioData = await audioResponse.json();
            
            if (audioData.success) {
                // Update scene with audio file
                let filename = audioData.filename;
                if (!filename && audioData.file) {
                    const parts = audioData.file.split('/');
                    filename = parts[parts.length - 1].split('?')[0];
                }
                
                if (filename) {
                    await updateSceneAudio(scene.id, filename);
                }
            }
            
            // Small delay to avoid overwhelming the server
            await new Promise(r => setTimeout(r, 300));
        }
        <?php else: ?>
        // Single video: Generate with single voice
        const scenes = await getScenesForPodcast(newPodcastId);
        
        for (let i = 0; i < scenes.length; i++) {
            const scene = scenes[i];
            const progress = 30 + Math.floor((i / scenes.length) * 60);
            
            updateTranslateProgress(progress, `Generating audio ${i+1}/${scenes.length}...`);
            
            const audioFormData = new FormData();
            audioFormData.append('row_id', scene.id);
            audioFormData.append('text', scene.text_contents || '');
            audioFormData.append('lang_code', selectedTranslateLang);
            audioFormData.append('voice_id', narratorVoice);
            audioFormData.append('rate', '1.0');
            
            const audioResponse = await fetch('generate_voice.php', {
                method: 'POST',
                body: audioFormData
            });
            
            const audioData = await audioResponse.json();
            
            if (audioData.success) {
                let filename = audioData.filename;
                if (!filename && audioData.file) {
                    const parts = audioData.file.split('/');
                    filename = parts[parts.length - 1].split('?')[0];
                }
                
                if (filename) {
                    await updateSceneAudio(scene.id, filename);
                }
            }
            
            await new Promise(r => setTimeout(r, 300));
        }
        <?php endif; ?>
        
        updateTranslateProgress(100, '✅ Translation complete!');
        
        // Success message
        setTimeout(() => {
            L(`✅ Translation to ${selectedTranslateLang} complete! New podcast ID: ${newPodcastId}`);
            alert(`Translation complete! The new ${selectedTranslateLang} version has been created.`);
            
            // Option to go to the new project
            if (confirm('Go to the translated project now?')) {
                window.location.href = `videomaker.php?podcast_id=${newPodcastId}`;
            } else {
                closeTranslateModal();
            }
        }, 500);
        
    } catch(error) {
        console.error('Translation error:', error);
        updateTranslateProgress(0, `❌ Error: ${error.message}`);
        alert(`Translation failed: ${error.message}`);
    } finally {
        isTranslating = false;
        document.getElementById('translateBtn').disabled = false;
    }
}

// Update progress bar
function updateTranslateProgress(percent, status) {
    document.getElementById('translateProgressFill').style.width = percent + '%';
    document.getElementById('translateProgressPercent').innerText = percent + '%';
    document.getElementById('translateStatus').innerText = status;
}

// Get scenes for a podcast
async function getScenesForPodcast(podcastId) {
    return new Promise((resolve) => {
        // We can use the existing scenes array or fetch from server
        // For simplicity, we'll use the fact that the new podcast has the same number of scenes
        // and we can fetch them via AJAX if needed
        const fd = new FormData();
        fd.append('ajax_action', 'get_podcast_scenes');
        fd.append('podcast_id', podcastId);
        
        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    resolve(data.scenes);
                } else {
                    // Fallback: create dummy scenes with same count
                    const dummyScenes = [];
                    for (let i = 0; i < scenes.length; i++) {
                        dummyScenes.push({
                            id: podcastId * 1000 + i, // Dummy ID
                            text_contents: scenes[i].text_contents || ''
                        });
                    }
                    resolve(dummyScenes);
                }
            })
            .catch(() => {
                // Fallback
                const dummyScenes = [];
                for (let i = 0; i < scenes.length; i++) {
                    dummyScenes.push({
                        id: podcastId * 1000 + i,
                        text_contents: scenes[i].text_contents || ''
                    });
                }
                resolve(dummyScenes);
            });
    });
}

// Update scene audio file
async function updateSceneAudio(sceneId, audioFile) {
    const fd = new FormData();
    fd.append('ajax_action', 'save_scene_settings');
    fd.append('scene_id', sceneId);
    fd.append('audio_file', audioFile);
    
    try {
        await safeFetch(fd);
    } catch(e) {
        console.error('Error updating scene audio:', e);
    }
}

// Add voice selection change listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add listeners for voice select changes
    setTimeout(() => {
        <?php if ($video_type === 'podcast'): ?>
        const hostSelect = document.getElementById('hostVoiceSelect');
        const guestSelect = document.getElementById('guestVoiceSelect');
        
        if (hostSelect) {
            hostSelect.addEventListener('change', updateTranslateButtonState);
        }
        if (guestSelect) {
            guestSelect.addEventListener('change', updateTranslateButtonState);
        }
        <?php else: ?>
        const narratorSelect = document.getElementById('narratorVoiceSelect');
        if (narratorSelect) {
            narratorSelect.addEventListener('change', updateTranslateButtonState);
        }
        <?php endif; ?>
    }, 1000);
});

// Modify the existing TranslateVideo function
function TranslateVideo() {
    openTranslateModal();
}