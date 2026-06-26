<?php
session_start();
require_once 'dbconnect_hdb.php';

// Get all images from podcast_images folder
$image_dir = 'podcast_images/';
$images = [];
if (is_dir($image_dir)) {
    $files = scandir($image_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
            $images[] = $file;
        }
    }
}

// Get all videos from podcast_videos folder
$video_dir = 'podcast_videos/';
$videos = [];
if (is_dir($video_dir)) {
    $files = scandir($video_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && preg_match('/\.(mp4|webm|mov|avi|mkv)$/i', $file)) {
            $videos[] = $file;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Library Modal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 30px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Button to open modal */
        .open-modal-btn {
            background: linear-gradient(135deg, #0f2a44, #143b63);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        
        .open-modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        /* Modal Background */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
            overflow: auto;
        }
        
        /* Modal Content - Wide */
        .modal-content {
            background: #fff;
            margin: 3% auto;
            width: 90%;
            max-width: 1200px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Modal Header */
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 12px 12px 0 0;
        }
        
        .modal-header h2 {
            color: #0f2a44;
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }
        
        .close-btn {
            font-size: 32px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }
        
        .close-btn:hover {
            color: #dc2626;
        }
        
        /* Tab Container */
        .tab-container {
            display: flex;
            gap: 5px;
            padding: 0 25px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tab {
            padding: 15px 25px;
            font-size: 16px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: #0f2a44;
        }
        
        .tab.active {
            color: #0f2a44;
        }
        
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #0f2a44, #5fd1ff);
            border-radius: 3px 3px 0 0;
        }
        
        /* Search Bar */
        .search-container {
            padding: 15px 25px;
            background: #fff;
        }
        
        .search-box {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .search-box:focus {
            outline: none;
            border-color: #0f2a44;
        }
        
        /* Media Grid */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 20px;
            padding: 25px;
            max-height: 500px;
            overflow-y: auto;
            background: #f8fafc;
        }
        
        /* Media Item */
        .media-item {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 2px solid transparent;
            transition: all 0.2s;
            cursor: pointer;
            position: relative;
        }
        
        .media-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-color: #0f2a44;
        }
        
        .media-item.selected {
            border-color: #059669;
            background: #f0fdf4;
        }
        
        .media-preview {
            width: 100%;
            height: 150px;
            object-fit: cover;
            display: block;
            background: #f1f5f9;
        }
        
        video.media-preview {
            background: #000;
        }
        
        .media-info {
            padding: 10px;
            border-top: 1px solid #e2e8f0;
        }
        
        .media-name {
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .media-size {
            font-size: 10px;
            color: #64748b;
        }
        
        .select-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 24px;
            height: 24px;
            background: #059669;
            color: white;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
        }
        
        .media-item.selected .select-indicator {
            display: flex;
        }
        
        /* Modal Footer */
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding: 20px 25px;
            border-top: 2px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0 0 12px 12px;
        }
        
        .footer-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .cancel-btn {
            background: #e2e8f0;
            color: #475569;
        }
        
        .cancel-btn:hover {
            background: #cbd5e1;
        }
        
        .select-btn {
            background: linear-gradient(135deg, #0f2a44, #143b63);
            color: white;
        }
        
        .select-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(15,42,68,0.3);
        }
        
        .select-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px;
            color: #94a3b8;
            font-size: 16px;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }
        
        /* Loading Spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #0f2a44;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 50px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Selection Counter */
        .selection-counter {
            flex: 1;
            color: #059669;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <button class="open-modal-btn" onclick="openMediaModal()">📁 Open Media Library</button>
        <p style="margin-top: 20px; color: #475569;">Selected file: <span id="selected-file-display">None</span></p>
    </div>
    
    <!-- Media Modal -->
    <div id="mediaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>🎬 Media Library</h2>
                <span class="close-btn" onclick="closeModal()">&times;</span>
            </div>
            
            <div class="tab-container">
                <button class="tab active" id="imagesTab" onclick="switchTab('images')">🖼️ Images</button>
                <button class="tab" id="videosTab" onclick="switchTab('videos')">🎥 Videos</button>
            </div>
            
            <div class="search-container">
                <input type="text" class="search-box" id="searchInput" placeholder="Search files..." onkeyup="filterMedia()">
            </div>
            
            <!-- Images Grid -->
            <div id="imagesGrid" class="media-grid">
                <?php if (empty($images)): ?>
                    <div class="empty-state">
                        <i>🖼️</i>
                        No images found in podcast_images folder
                    </div>
                <?php else: ?>
                    <?php foreach ($images as $image): ?>
                        <?php
                        $filepath = 'podcast_images/' . $image;
                        $filesize = filesize($filepath);
                        $size_formatted = $filesize < 1024 * 1024 ? round($filesize / 1024, 1) . ' KB' : round($filesize / (1024 * 1024), 1) . ' MB';
                        ?>
                        <div class="media-item" data-type="image" data-file="<?= htmlspecialchars($image) ?>" data-path="<?= $filepath ?>" onclick="selectMedia(this)">
                            <img src="<?= $filepath ?>" class="media-preview" alt="<?= htmlspecialchars($image) ?>" loading="lazy">
                            <div class="media-info">
                                <div class="media-name"><?= htmlspecialchars($image) ?></div>
                                <div class="media-size"><?= $size_formatted ?></div>
                            </div>
                            <div class="select-indicator">✓</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Videos Grid (Hidden by default) -->
            <div id="videosGrid" class="media-grid" style="display: none;">
                <?php if (empty($videos)): ?>
                    <div class="empty-state">
                        <i>🎥</i>
                        No videos found in podcast_videos folder
                    </div>
                <?php else: ?>
                    <?php foreach ($videos as $video): ?>
                        <?php
                        $filepath = 'podcast_videos/' . $video;
                        $filesize = filesize($filepath);
                        $size_formatted = $filesize < 1024 * 1024 ? round($filesize / 1024, 1) . ' KB' : round($filesize / (1024 * 1024), 1) . ' MB';
                        ?>
                        <div class="media-item" data-type="video" data-file="<?= htmlspecialchars($video) ?>" data-path="<?= $filepath ?>" onclick="selectMedia(this)">
                            <video class="media-preview" src="<?= $filepath ?>" muted preload="metadata"></video>
                            <div class="media-info">
                                <div class="media-name"><?= htmlspecialchars($video) ?></div>
                                <div class="media-size"><?= $size_formatted ?></div>
                            </div>
                            <div class="select-indicator">✓</div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="modal-footer">
                <div class="selection-counter" id="selectionCounter">No file selected</div>
                <button class="footer-btn cancel-btn" onclick="closeModal()">Cancel</button>
                <button class="footer-btn select-btn" id="selectBtn" onclick="confirmSelection()" disabled>Select File</button>
            </div>
        </div>
    </div>
    
    <script>
        let currentTab = 'images';
        let selectedMedia = null;
        
        // Open modal
        function openMediaModal() {
            document.getElementById('mediaModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            resetSelection();
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('mediaModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Switch between tabs
        function switchTab(tab) {
            currentTab = tab;
            
            // Update tab styles
            document.getElementById('imagesTab').classList.remove('active');
            document.getElementById('videosTab').classList.remove('active');
            
            // Show/hide grids
            if (tab === 'images') {
                document.getElementById('imagesTab').classList.add('active');
                document.getElementById('imagesGrid').style.display = 'grid';
                document.getElementById('videosGrid').style.display = 'none';
            } else {
                document.getElementById('videosTab').classList.add('active');
                document.getElementById('videosGrid').style.display = 'grid';
                document.getElementById('imagesGrid').style.display = 'none';
            }
            
            // Reset selection when switching tabs
            resetSelection();
            
            // Re-apply search filter
            filterMedia();
        }
        
        // Select/deselect media
        function selectMedia(element) {
            // Remove selection from all items in current tab
            const currentGrid = currentTab === 'images' ? 'imagesGrid' : 'videosGrid';
            document.querySelectorAll(`#${currentGrid} .media-item`).forEach(item => {
                item.classList.remove('selected');
            });
            
            // Select current item
            element.classList.add('selected');
            
            // Update selected media
            selectedMedia = {
                type: element.dataset.type,
                file: element.dataset.file,
                path: element.dataset.path
            };
            
            // Update UI
            document.getElementById('selectionCounter').innerHTML = `Selected: ${element.dataset.file}`;
            document.getElementById('selectBtn').disabled = false;
        }
        
        // Reset selection
        function resetSelection() {
            selectedMedia = null;
            document.getElementById('selectionCounter').innerHTML = 'No file selected';
            document.getElementById('selectBtn').disabled = true;
            
            // Remove selection from all items
            document.querySelectorAll('#imagesGrid .media-item, #videosGrid .media-item').forEach(item => {
                item.classList.remove('selected');
            });
        }
        
        // Filter media based on search input
        function filterMedia() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const currentGrid = currentTab === 'images' ? 'imagesGrid' : 'videosGrid';
            
            document.querySelectorAll(`#${currentGrid} .media-item`).forEach(item => {
                const fileName = item.dataset.file.toLowerCase();
                if (fileName.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        // Confirm selection and return to parent
        function confirmSelection() {
            if (selectedMedia) {
                // Update display
                document.getElementById('selected-file-display').innerHTML = 
                    `${selectedMedia.type}: ${selectedMedia.file}`;
                
                // Here you can add custom logic to handle the selected file
                console.log('Selected media:', selectedMedia);
                
                // You can trigger a custom event or callback
                const event = new CustomEvent('mediaSelected', { detail: selectedMedia });
                document.dispatchEvent(event);
                
                // Show success message
                alert(`Selected: ${selectedMedia.file}`);
                
                // Close modal
                closeModal();
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('mediaModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Handle ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && document.getElementById('mediaModal').style.display === 'block') {
                closeModal();
            }
        });
        
        // Add hover play/pause for videos
        document.querySelectorAll('video.media-preview').forEach(video => {
            video.addEventListener('mouseenter', function() {
                this.play();
            });
            video.addEventListener('mouseleave', function() {
                this.pause();
                this.currentTime = 0;
            });
        });
    </script>
</body>
</html>