// Current state
let currentMainTab = 'data';
let selectedImage = null;
let selectedVideo = null;
let imageFiles = [];
let videoFiles = [];

// Initialize modal when opening
function openEditModal(rowData) {
    // Reset state
    selectedImage = null;
    selectedVideo = null;
    currentMainTab = 'data';
    
    // Load row data into form
    document.getElementById('edit_row_id').value = rowData.id;
    document.getElementById('modalSceneId').innerText = rowData.id;
    document.getElementById('edit_text').value = rowData.text_contents || '';
    document.getElementById('edit_text_display').value = rowData.text_display || '';
    document.getElementById('edit_prompt').value = rowData.image_prompt || rowData.prompt || '';
    document.getElementById('edit_logo_flag').value = rowData.logo_flag || '1';
    
    // Clear selected server files
    document.getElementById('selected_server_image').value = '';
    document.getElementById('selected_server_video').value = '';
    
    // Show current files
    const imgContainer = document.getElementById('image_current');
    if (rowData.image_file) {
        imgContainer.innerHTML = `<div style="display:flex; align-items:center; gap:10px;">
            <img src="podcast_images/${rowData.image_file}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
            <span style="color:#2563eb;">${rowData.image_file}</span>
        </div>`;
    } else {
        imgContainer.innerHTML = '<span style="color:#999;">No image assigned</span>';
    }
    
    const vidContainer = document.getElementById('video_current');
    if (rowData.video_file) {
        vidContainer.innerHTML = `<div style="display:flex; align-items:center; gap:10px;">
            <video src="podcast_videos/${rowData.video_file}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" muted></video>
            <span style="color:#7c3aed;">${rowData.video_file}</span>
        </div>`;
    } else {
        vidContainer.innerHTML = '<span style="color:#999;">No video assigned</span>';
    }
    
    // Load media libraries
    loadMediaLibraries();
    
    // Show modal
    document.getElementById('editModal').style.display = 'block';
    switchMainTab('data');
}

// Close modal
function closeModal() {
    document.getElementById('editModal').style.display = 'none';
    document.getElementById('editForm').reset();
}

// Switch between main tabs
function switchMainTab(tab) {
    currentMainTab = tab;
    
    // Update tab styles
    document.querySelectorAll('.main-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');
    
    // Show/hide tab content
    document.querySelectorAll('.tab-content').forEach(content => content.style.display = 'none');
    document.getElementById(`${tab}-tab-content`).style.display = 'block';
    
    // Load media if needed
    if (tab === 'images' && imageFiles.length === 0) {
        loadMedia('images');
    } else if (tab === 'videos' && videoFiles.length === 0) {
        loadMedia('videos');
    } else if (tab === 'images') {
        renderImageGrid(imageFiles);
    } else if (tab === 'videos') {
        renderVideoGrid(videoFiles);
    }
}

// Load both media libraries
async function loadMediaLibraries() {
    await Promise.all([loadMedia('images'), loadMedia('videos')]);
}

// Load media by type
async function loadMedia(type) {
    try {
        const formData = new FormData();
        formData.append('action', 'get_media');
        formData.append('type', type);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (type === 'images') {
                imageFiles = data.data.images || [];
                if (currentMainTab === 'images') {
                    renderImageGrid(imageFiles);
                }
            } else if (type === 'videos') {
                videoFiles = data.data.videos || [];
                if (currentMainTab === 'videos') {
                    renderVideoGrid(videoFiles);
                }
            }
        }
    } catch (error) {
        console.error(`Error loading ${type}:`, error);
    }
}

// Render image grid
function renderImageGrid(images) {
    const grid = document.getElementById('image-grid');
    
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><span style="font-size:48px; display:block; margin-bottom:15px;">🖼️</span>No images found</div>';
        return;
    }
    
    let html = '';
    images.forEach(img => {
        const isSelected = selectedImage && selectedImage.name === img.name;
        html += `
            <div class="media-item ${isSelected ? 'selected' : ''}" onclick="selectMedia('image', '${img.name.replace(/'/g, "\\'")}', '${img.path.replace(/'/g, "\\'")}')">
                <img src="${img.path}" class="media-preview" alt="${img.name}" loading="lazy">
                <div class="media-info">
                    <div class="media-name">${img.name}</div>
                    <div class="media-size">${img.formatted_size}</div>
                </div>
                <div class="select-indicator">✓</div>
            </div>
        `;
    });
    
    grid.innerHTML = html + getSelectionStatus('image');
}

// Render video grid
function renderVideoGrid(videos) {
    const grid = document.getElementById('video-grid');
    
    if (!videos || videos.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><span style="font-size:48px; display:block; margin-bottom:15px;">🎥</span>No videos found</div>';
        return;
    }
    
    let html = '';
    videos.forEach(vid => {
        const isSelected = selectedVideo && selectedVideo.name === vid.name;
        html += `
            <div class="media-item ${isSelected ? 'selected' : ''}" onclick="selectMedia('video', '${vid.name.replace(/'/g, "\\'")}', '${vid.path.replace(/'/g, "\\'")}')">
                <video class="media-preview" src="${vid.path}" muted preload="metadata" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0;"></video>
                <div class="media-info">
                    <div class="media-name">${vid.name}</div>
                    <div class="media-size">${vid.formatted_size}</div>
                </div>
                <div class="select-indicator">✓</div>
            </div>
        `;
    });
    
    grid.innerHTML = html + getSelectionStatus('video');
}

// Get selection status HTML
function getSelectionStatus(type) {
    const selected = type === 'image' ? selectedImage : selectedVideo;
    if (!selected) return '';
    
    return `
        <div class="selection-status" style="grid-column:1/-1;">
            <span>✅ Selected: ${selected.name}</span>
            <button onclick="useSelectedMedia('${type}')">Use This File</button>
        </div>
    `;
}

// Select media
function selectMedia(type, name, path) {
    if (type === 'image') {
        selectedImage = { name, path };
        renderImageGrid(imageFiles);
    } else {
        selectedVideo = { name, path };
        renderVideoGrid(videoFiles);
    }
}

// Use selected media in form
function useSelectedMedia(type) {
    if (type === 'image' && selectedImage) {
        document.getElementById('selected_server_image').value = selectedImage.name;
        document.getElementById('image_current').innerHTML = `
            <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px;">
                <img src="${selectedImage.path}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                <span style="color:#059669; font-weight:600;">${selectedImage.name} (selected)</span>
            </div>
        `;
        // Switch back to data tab
        switchMainTab('data');
    } else if (type === 'video' && selectedVideo) {
        document.getElementById('selected_server_video').value = selectedVideo.name;
        document.getElementById('video_current').innerHTML = `
            <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px;">
                <video src="${selectedVideo.path}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" muted></video>
                <span style="color:#059669; font-weight:600;">${selectedVideo.name} (selected)</span>
            </div>
        `;
        // Switch back to data tab
        switchMainTab('data');
    }
}

// Filter media by search
function filterMedia(type) {
    const searchTerm = document.getElementById(`${type}-search`).value.toLowerCase();
    
    if (type === 'image') {
        const filtered = imageFiles.filter(img => img.name.toLowerCase().includes(searchTerm));
        renderImageGrid(filtered);
    } else {
        const filtered = videoFiles.filter(vid => vid.name.toLowerCase().includes(searchTerm));
        renderVideoGrid(filtered);
    }
}

// Clear server selection
function clearServerSelection(type) {
    if (type === 'image') {
        document.getElementById('selected_server_image').value = '';
        selectedImage = null;
    } else {
        document.getElementById('selected_server_video').value = '';
        selectedVideo = null;
    }
}

// Handle form submission
document.getElementById('editForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('update_scene.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Scene updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error occurred');
    });
});

// Click outside to close
window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeModal();
    }
}