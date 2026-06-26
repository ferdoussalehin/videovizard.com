
  let currentSelections = { image: null, video: null };

       function openEditModal(data) {
			console.group("Step 1: openEditModal Triggered");
			console.log("Incoming Scene Data:", data);

			// Show Modal
			const modal = document.getElementById('editModal');
			if (!modal) {
				console.error("CRITICAL: Modal element '#editModal' not found in DOM.");
			} else {
				modal.classList.remove('hidden');
				console.log("Modal visibility: Shown (removed 'hidden')");
			}

			// 1. Populate basic form fields
			const idField = document.getElementById('edit_row_id');
			const textField = document.getElementById('edit_text');
			const promptField = document.getElementById('edit_prompt');

			if (idField) idField.value = data.id;
			if (textField) textField.value = data.text_contents || "";
			if (promptField) promptField.value = data.image_prompt || data.prompt || "";

			console.log("Form fields populated.");

			// 2. Set internal selection state
			currentSelections.image = data.image_file;
			currentSelections.video = data.video_file;
			console.log("Current Selections State:", currentSelections);

			// 3. Update the "Current" previews
			updateCurrentPreviews();

			// 4. Fetch library and highlight current
			fetchMediaLibrary();
			console.groupEnd();
		}

		function updateCurrentPreviews() {
			console.group("Step 2: updateCurrentPreviews");
			const imgContainer = document.getElementById('image_current');
			const vidContainer = document.getElementById('video_current');

			if (!imgContainer || !vidContainer) {
				console.error("Preview containers not found in DOM:", { imgContainer, vidContainer });
			}

			if (currentSelections.image) {
				console.log("Setting current image preview:", currentSelections.image);
				imgContainer.innerHTML = `
					<div class="p-2 border rounded-lg bg-white shadow-sm">
						<img src="podcast_images/${currentSelections.image}" class="w-full h-16 object-cover rounded" onerror="console.error('Image preview failed to load: ' + this.src)">
						<div class="text-[10px] text-blue-600 font-bold mt-1 truncate">${currentSelections.image}</div>
					</div>`;
			} else {
				imgContainer.innerHTML = '<p class="text-gray-400 text-[10px] italic">No image assigned</p>';
			}

			if (currentSelections.video) {
				console.log("Setting current video preview:", currentSelections.video);
				vidContainer.innerHTML = `
					<div class="p-2 border rounded-lg bg-white shadow-sm">
						<video src="podcast_videos/${currentSelections.video}" class="w-full h-16 object-cover rounded" muted></video>
						<div class="text-[10px] text-purple-600 font-bold mt-1 truncate">${currentSelections.video}</div>
					</div>`;
			} else {
				vidContainer.innerHTML = '<p class="text-gray-400 text-[10px] italic">No video assigned</p>';
			}
			console.groupEnd();
		}

		async function fetchMediaLibrary() {
			console.group("Step 3: fetchMediaLibrary");
			try {
				console.log("Fetching from get_media_list.php...");
				const response = await fetch('get_media_list.php');
				
				if (!response.ok) {
					throw new Error(`Server responded with status: ${response.status}`);
				}

				const data = await response.json();
				console.log("Data received from server:");
				console.table(data.images ? data.images.map(f => ({images: f})) : []);
				console.table(data.videos ? data.videos.map(f => ({videos: f})) : []);
				
				renderLibrary('image-grid', data.images || [], 'image');
				renderLibrary('video-grid', data.videos || [], 'video');
			} catch (e) {
				console.error("Library load error:", e);
			}
			console.groupEnd();
		}

		function renderLibrary(containerId, items, type) {
			console.log(`Rendering ${type} library into #${containerId}...`);
			const container = document.getElementById(containerId);
			
			if (!container) {
				console.error(`Container #${containerId} not found in DOM!`);
				return;
			}

			if (!items || items.length === 0) {
				console.warn(`No ${type} items to render (array is empty).`);
				container.innerHTML = `<p class="col-span-full text-center py-4 text-gray-400">No ${type}s found in folder.</p>`;
				return;
			}

			container.innerHTML = items.map(file => {
				const isSelected = currentSelections[type] === file ? 'selected' : '';
				return `
					<div class="media-item ${isSelected}" onclick="handleLibrarySelect(this, '${file}', '${type}')">
						${type === 'image' 
							? `<img src="podcast_images/${file}" onerror="console.warn('Grid image failed: ' + this.src)">` 
							: `<video muted src="podcast_videos/${file}#t=0.5" onloadedmetadata="console.log('Video loaded: ' + file)"></video>`
						}
						<div class="text-[10px] mt-1 truncate px-1">${file}</div>
					</div>
				`;
			}).join('');
			
			console.log(`Successfully rendered ${items.length} ${type}(s).`);
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
 /**
 * Global functions for the Media Library interface
 * (Required for HTML onclick="switchTab(...)" to work)
 */
window.switchTab = function(type) {
    const imageGrid = document.getElementById('image-grid');
    const videoGrid = document.getElementById('video-grid');
    const tabs = document.querySelectorAll('.media-tab');

    if (!imageGrid || !videoGrid) return;

    if (type === 'images') {
        imageGrid.style.display = 'grid';
        videoGrid.style.display = 'none';
        tabs[0]?.classList.add('active');
        tabs[1]?.classList.remove('active');
    } else {
        imageGrid.style.display = 'none';
        videoGrid.style.display = 'grid';
        tabs[0]?.classList.remove('active');
        tabs[1]?.classList.add('active');
    }
};

window.handleLibrarySelect = function(element, filename, type) {
    const grid = element.parentElement;
    grid.querySelectorAll('.media-item').forEach(item => item.classList.remove('selected'));
    element.classList.add('selected');

    const statusDiv = document.getElementById('selection-status');
    if (statusDiv) statusDiv.innerText = `Selected ${type}: ${filename}`;

    const inputId = type === 'images' ? 'selected_server_image' : 'selected_server_video';
    const hiddenInput = document.getElementById(inputId);
    if (hiddenInput) hiddenInput.value = filename;
};
