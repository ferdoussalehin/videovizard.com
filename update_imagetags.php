<?php
session_start();
require_once 'dbconnect_hdb.php';

// 1. Fetch tagged media
$existing_media = [];
$res = mysqli_query($conn, "SELECT image_name FROM hdb_image_data");
if($res) {
    while ($row = mysqli_fetch_assoc($res)) { $existing_media[] = trim($row['image_name']); }
}

// 2. Scan folders
function getUntagged($dir, $exts, $existing) {
    $out = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts) && !in_array(trim($f), $existing)) {
                $out[] = $f;
            }
        }
    }
    return $out; 
}

$images = getUntagged('podcast_images/', ['jpg','jpeg','png','webp'], $existing_media);
$videos = getUntagged('podcast_videos/', ['mp4','webm','mov'], $existing_media);

// 3. Load tags
$tags_file = 'tags.txt';
$available_tags = file_exists($tags_file) ? file($tags_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
sort($available_tags, SORT_STRING | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>9:16 Media Tagger</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f4f7f6; padding: 20px; font-size: 0.85rem; }
        .open-btn { background: #0f2a44; color: white; padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); }
        .modal-content { background: #fff; margin: 2vh auto; width: 98%; max-width: 1400px; border-radius: 12px; height: 96vh; display: flex; flex-direction: column; overflow: hidden; }
        
        .tab-bar { display: flex; background: #f8fafc; border-bottom: 1px solid #ddd; flex-shrink: 0; }
        .tab { padding: 15px; cursor: pointer; flex: 1; text-align: center; font-weight: bold; color: #64748b; }
        .tab.active { background: #fff; color: #0f2a44; border-bottom: 4px solid #0f2a44; }

        /* LIST VIEW: Fixed height issue and name visibility */
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; padding: 20px; overflow-y: auto; flex-grow: 1; }
        .item { 
            display: flex; 
            align-items: center; 
            min-height: 60px; /* Increased height */
            padding: 12px; 
            background: #fff; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: 0.2s; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .item:hover { background: #f0f9ff; border-color: #0f2a44; transform: translateY(-1px); }
        .item p { 
            margin: 0; 
            font-size: 13px; 
            line-height: 1.4;
            word-break: break-all; /* Allows name to wrap if it is one long string */
            color: #334155;
            font-weight: 500;
        }

        /* TAGGER VIEW - CUSTOM SPLIT */
        #taggerView { display: none; flex-grow: 1; height: 100%; overflow: hidden; }
        .split-container { display: flex; height: 100%; width: 100%; background: #000; }
        
        /* 9:16 FIXED AREA */
        .media-preview-side { 
            height: 100%;
            aspect-ratio: 9/16; 
            background: #000;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0; 
        }
        #previewArea { width: 100%; height: 100%; }
        #previewArea img, #previewArea video { width: 100%; height: 100%; object-fit: cover; }

        /* TAGS EXPANDABLE AREA */
        .tags-side { 
            flex-grow: 1; 
            padding: 30px; 
            display: flex; 
            flex-direction: column; 
            background: #fff; 
            overflow: hidden; /* Keeps side contained */
        }
        
        .tag-scroll-area { 
            flex-grow: 1; 
            overflow-y: auto; /* SCROLLING ENABLED HERE */
            margin: 20px 0; 
            padding: 15px;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            background: #fafbfc;
        }

        .tag-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); 
            gap: 10px; 
        }
        .tag-lbl { 
            background: #fff; 
            border: 1px solid #e2e8f0; 
            padding: 12px; 
            border-radius: 6px; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            cursor: pointer; 
            font-size: 13px; 
            transition: 0.2s;
        }
        .tag-lbl:hover { border-color: #0f2a44; background: #f0f9ff; }

        .custom-tag-box { display: flex; flex-direction: column; gap: 8px; padding-top: 20px; border-top: 2px solid #f1f5f9; }
        .custom-tag-input { width: 100%; padding: 14px; border: 2px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem; }
        
        .tag-hint {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }
        .tag-hint i {
            font-style: normal;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            color: #0f2a44;
        }

        .footer { 
            padding: 15px 25px; 
            background: #f8fafc; 
            text-align: right; 
            border-top: 1px solid #ddd; 
            flex-shrink: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .footer-left { display: flex; gap: 10px; }
        .footer-right { display: flex; gap: 10px; }
        
        .btn { padding: 12px 28px; border-radius: 6px; cursor: pointer; border: none; font-weight: bold; }
        .btn-save { background: #0f2a44; color: white; }
        .btn-cancel { background: #e2e8f0; color: #475569; }
        .btn-delete { background: #dc2626; color: white; }
        .btn-delete:hover { background: #b91c1c; }
        
        .confirm-delete {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            z-index: 2000;
            text-align: center;
            min-width: 300px;
        }
        
        .confirm-delete p { font-size: 16px; margin-bottom: 20px; color: #1e293b; }
        .confirm-delete .btn { margin: 0 5px; }
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1999;
        }
    </style>
</head>
<body>

    <button class="open-btn" onclick="openModal()">📁 Media Library Assets</button>

    <!-- Delete Confirmation Modal -->
    <div class="overlay" id="overlay"></div>
    <div class="confirm-delete" id="confirmDelete">
        <p>Are you sure you want to delete <strong id="deleteFileName"></strong>?</p>
        <p style="font-size: 14px; color: #ef4444; margin-bottom: 20px;">This action cannot be undone!</p>
        <div>
            <button class="btn btn-cancel" onclick="hideDeleteConfirm()">Cancel</button>
            <button class="btn btn-delete" onclick="confirmDelete()">Delete Permanently</button>
        </div>
    </div>

    <div id="mediaModal" class="modal">
        <div class="modal-content">
            <div class="tab-bar" id="tabBar">
                <div class="tab active" onclick="switchTab('image')">Images (<?= count($images) ?>)</div>
                <div class="tab" onclick="switchTab('video')">Videos (<?= count($videos) ?>)</div>
            </div>

            <div id="imageGrid" class="grid">
                <?php foreach($images as $img): ?>
                    <div class="item" data-name="<?= htmlspecialchars($img) ?>" data-type="image" onclick="openTagger(this)">
                        <p>🖼️ <?= htmlspecialchars($img) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="videoGrid" class="grid" style="display:none;">
                <?php foreach($videos as $vid): ?>
                    <div class="item" data-name="<?= htmlspecialchars($vid) ?>" data-type="video" onclick="openTagger(this)">
                        <p>🎬 <?= htmlspecialchars($vid) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="taggerView">
                <div class="split-container">
                    <div class="media-preview-side">
                        <div id="previewArea"></div>
                    </div>
                    
                    <div class="tags-side">
                        <h2 id="taggingTitle" style="margin:0; color:#0f2a44; font-size: 1.4rem;">Filename</h2>
                        
                        <div class="tag-scroll-area">
                            <div class="tag-grid">
                                <?php foreach($available_tags as $t): ?>
                                    <label class="tag-lbl">
                                        <input type="checkbox" class="tag-check" value="<?= htmlspecialchars($t) ?>"> <?= htmlspecialchars($t) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="custom-tag-box">
                            <label style="font-weight:bold; color:#64748b;">Add Brand New Tag(s):</label>
                            <input type="text" id="newTagInput" class="custom-tag-input" placeholder="Enter tags separated by commas (e.g., nature, sunset, 4k)">
                            <div class="tag-hint">💡 You can enter multiple tags separated by commas. They will be saved as separate tags.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer">
                <div class="footer-left">
                    <button class="btn btn-delete" id="deleteBtn" style="display:none;" onclick="showDeleteConfirm()">🗑️ Delete File</button>
                </div>
                <div class="footer-right">
                    <button class="btn btn-cancel" onclick="closeModal()">Close</button>
                    <button class="btn btn-save" id="saveBtn" style="display:none;" onclick="saveMedia()">Save & Update</button>
                </div>
            </div>
        </div>
    </div>

<script>
    let activeFile = '', activeType = 'image';
    let deleteCallback = null;

    function openModal() { document.getElementById('mediaModal').style.display='block'; }
    
    function closeModal() { 
        if(document.getElementById('taggerView').style.display === 'flex') { 
            showListView(); 
        } else { 
            document.getElementById('mediaModal').style.display='none';
        }
        hideDeleteConfirm();
    }

    function switchTab(type) {
        activeType = type;
        document.querySelectorAll('.tab').forEach((t, i) => t.classList.toggle('active', (type === 'image' && i === 0) || (type === 'video' && i === 1)));
        document.getElementById('imageGrid').style.display = type === 'image' ? 'grid' : 'none';
        document.getElementById('videoGrid').style.display = type === 'video' ? 'grid' : 'none';
    }

    function openTagger(el) {
        activeFile = el.dataset.name;
        activeType = el.dataset.type;
        document.getElementById('imageGrid').style.display = 'none';
        document.getElementById('videoGrid').style.display = 'none';
        document.getElementById('tabBar').style.display = 'none';
        document.getElementById('taggerView').style.display = 'flex';
        document.getElementById('saveBtn').style.display = 'inline-block';
        document.getElementById('deleteBtn').style.display = 'inline-block';
        document.getElementById('taggingTitle').innerText = activeFile;
        
        const path = (activeType === 'image' ? 'podcast_images/' : 'podcast_videos/') + encodeURIComponent(activeFile);
        document.getElementById('previewArea').innerHTML = activeType === 'image' 
            ? `<img src="${path}?v=${Date.now()}">` 
            : `<video src="${path}?v=${Date.now()}" controls autoplay muted loop></video>`;
    }

    function showListView() {
        document.getElementById('taggerView').style.display = 'none';
        document.getElementById('tabBar').style.display = 'flex';
        document.getElementById('saveBtn').style.display = 'none';
        document.getElementById('deleteBtn').style.display = 'none';
        document.getElementById('previewArea').innerHTML = '';
        switchTab(activeType);
        hideDeleteConfirm();
    }

    function showDeleteConfirm() {
        document.getElementById('deleteFileName').innerText = activeFile;
        document.getElementById('overlay').style.display = 'block';
        document.getElementById('confirmDelete').style.display = 'block';
    }

    function hideDeleteConfirm() {
        document.getElementById('overlay').style.display = 'none';
        document.getElementById('confirmDelete').style.display = 'none';
    }

    function confirmDelete() {
        const fd = new FormData();
        fd.append('filename', activeFile);
        fd.append('type', activeType);

        fetch('delete_media_file.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { 
            if(data.success) {
                location.reload(); // Refresh to update the lists
            } else {
                alert('Error: ' + data.message);
            }
            hideDeleteConfirm();
        })
        .catch(err => {
            alert('Error deleting file');
            hideDeleteConfirm();
        });
    }

    function saveMedia() {
        // Get selected tags from checkboxes
        const checks = Array.from(document.querySelectorAll('.tag-check:checked')).map(c => c.value);
        
        // Get new tag input and split by commas
        const newTagInput = document.getElementById('newTagInput').value.trim();
        let newTags = [];
        
        if (newTagInput) {
            // Split by comma and trim each tag
            newTags = newTagInput.split(',').map(tag => tag.trim()).filter(tag => tag !== '');
        }
        
        // Combine selected tags with new tags (new tags will be processed on server)
        const allTags = [...checks];
        
        const fd = new FormData();
        fd.append('image_name', activeFile);
        fd.append('media_type', activeType);
        fd.append('tags', allTags.join(','));
        
        // Send new tags as JSON array to handle multiple tags
        fd.append('new_tags', JSON.stringify(newTags));

        fetch('save_image_data.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => { 
            if(data.success) {
                // Clear the new tag input after successful save
                document.getElementById('newTagInput').value = '';
                location.reload();
            } else {
                alert('Error saving tags: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            alert('Error saving tags: ' + err.message);
        });
    }
</script>
</body>
</html>