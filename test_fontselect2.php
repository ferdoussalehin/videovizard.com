<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Font Selector Demo - Standalone</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&family=Open+Sans:wght@400;600;700&family=Montserrat:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&family=Lato:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            color: #1e293b;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .demo-container {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            padding: 24px;
        }
        
        h1 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #0f2a44;
        }
        
        .demo-box {
            background: #f8fafc;
            border: 2px dashed #94a3b8;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            margin-bottom: 24px;
        }
        
        .demo-text {
            font-size: 24px;
            margin-bottom: 10px;
            transition: font-family 0.3s;
        }
        
        .current-font {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 20px;
        }
        
        .btn {
            background: #0f2a44;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background: #1e3a5f;
            transform: scale(1.02);
        }
        
        .btn-icon {
            font-size: 20px;
        }
        
        /* Font Selector Overlay */
        .font-selector-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .font-selector-panel {
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            background: #1a1a1f;
            border-radius: 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .font-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .font-panel-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .font-back-btn, .font-close-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .font-back-btn:hover, .font-close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }
        
        .font-panel-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        .font-search-container {
            padding: 16px 20px;
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .font-search-icon {
            position: absolute;
            left: 32px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.5);
            font-size: 16px;
        }
        
        .font-search-input {
            width: 100%;
            padding: 14px 14px 14px 45px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 40px;
            color: white;
            font-size: 15px;
            outline: none;
        }
        
        .font-search-input:focus {
            border-color: #5fd1ff;
            background: rgba(255, 255, 255, 0.15);
        }
        
        .font-categories {
            display: flex;
            gap: 8px;
            padding: 12px 20px;
            overflow-x: auto;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .font-category {
            padding: 8px 16px;
            border-radius: 30px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .font-category:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .font-category.active {
            background: #2563eb;
            border-color: white;
        }
        
        .font-list-container {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            max-height: 400px;
        }
        
        .font-item {
            padding: 16px;
            border-radius: 16px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid transparent;
        }
        
        .font-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(4px);
        }
        
        .font-item.selected {
            background: rgba(37, 99, 235, 0.2);
            border-left: 4px solid #5fd1ff;
        }
        
        .font-preview {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 8px;
        }
        
        .font-sample {
            font-size: 36px;
            width: 60px;
            text-align: center;
            color: white;
        }
        
        .font-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .font-name {
            font-size: 16px;
            font-weight: 600;
            color: white;
        }
        
        .font-category-badge {
            font-size: 11px;
            padding: 4px 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            color: rgba(255, 255, 255, 0.7);
            display: inline-block;
            width: fit-content;
        }
        
        .font-preview-text {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.6);
            padding: 8px 0 0 0;
            border-top: 1px dashed rgba(255, 255, 255, 0.1);
            margin-top: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .font-preview-footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.3);
        }
        
        .current-font-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .current-font-display {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        #currentFontName {
            font-size: 16px;
            font-weight: 600;
            color: white;
        }
        
        #currentFontExample {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .font-list-container::-webkit-scrollbar {
            width: 4px;
        }
        
        .font-list-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .font-selector-panel {
                width: 95%;
                max-height: 90vh;
            }
            
            .font-sample {
                font-size: 32px;
                width: 50px;
            }
        }
    </style>
</head>
<body>
    <div class="demo-container">
        <h1>🎨 Font Selector Demo</h1>
        
        <div class="demo-box">
            <div class="demo-text" id="demoText" style="font-family: Inter;">
                The quick brown fox jumps over the lazy dog
            </div>
            <div class="current-font" id="currentFontDisplay">
                Current font: <strong id="currentFontName">Inter</strong>
            </div>
            <button class="btn" onclick="showFontSelector()">
                <span class="btn-icon">🔤</span>
                Change Font
            </button>
        </div>
        
        <p style="color: #64748b; font-size: 14px; text-align: center;">
            Click the button above to open the font selector. This is a standalone demo that works without any backend.
        </p>
    </div>

    <!-- Font Selector Panel (Hidden by default) -->
    <div class="font-selector-overlay" id="fontSelectorOverlay" onclick="closeFontSelector()">
        <div class="font-selector-panel" onclick="event.stopPropagation()">
            <!-- Header -->
            <div class="font-panel-header">
                <div class="font-panel-header-left">
                    <button class="font-back-btn" onclick="closeFontSelector()">←</button>
                    <h3 class="font-panel-title">Font Family</h3>
                </div>
                <button class="font-close-btn" onclick="closeFontSelector()">✕</button>
            </div>
            
            <!-- Search -->
            <div class="font-search-container">
                <span class="font-search-icon">🔍</span>
                <input type="text" class="font-search-input" id="fontSearchInput" placeholder="Search fonts..." onkeyup="filterFonts()">
            </div>
            
            <!-- Categories -->
            <div class="font-categories">
                <button class="font-category active" data-category="all" onclick="filterFontsByCategory('all')">All</button>
                <button class="font-category" data-category="Sans Serif" onclick="filterFontsByCategory('Sans Serif')">Sans Serif</button>
                <button class="font-category" data-category="Serif" onclick="filterFontsByCategory('Serif')">Serif</button>
                <button class="font-category" data-category="Monospace" onclick="filterFontsByCategory('Monospace')">Monospace</button>
                <button class="font-category" data-category="Display" onclick="filterFontsByCategory('Display')">Display</button>
            </div>
            
            <!-- Font List -->
            <div class="font-list-container" id="fontListContainer">
                <!-- Font items will be populated by JavaScript -->
            </div>
            
            <!-- Footer Preview -->
            <div class="font-preview-footer">
                <div class="current-font-label">Currently selected</div>
                <div class="current-font-display">
                    <span id="previewFontName">Inter</span>
                    <span id="previewFontExample" style="font-family: Inter;">The quick brown fox</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Font data
        const fonts = [
            { name: 'Inter', family: 'Inter, sans-serif', category: 'Sans Serif' },
            { name: 'Arial', family: 'Arial, sans-serif', category: 'Sans Serif' },
            { name: 'Helvetica', family: 'Helvetica, sans-serif', category: 'Sans Serif' },
            { name: 'Roboto', family: 'Roboto, sans-serif', category: 'Sans Serif' },
            { name: 'Open Sans', family: 'Open Sans, sans-serif', category: 'Sans Serif' },
            { name: 'Montserrat', family: 'Montserrat, sans-serif', category: 'Sans Serif' },
            { name: 'Poppins', family: 'Poppins, sans-serif', category: 'Sans Serif' },
            { name: 'Lato', family: 'Lato, sans-serif', category: 'Sans Serif' },
            { name: 'Times New Roman', family: 'Times New Roman, serif', category: 'Serif' },
            { name: 'Georgia', family: 'Georgia, serif', category: 'Serif' },
            { name: 'Garamond', family: 'Garamond, serif', category: 'Serif' },
            { name: 'Courier New', family: 'Courier New, monospace', category: 'Monospace' },
            { name: 'Consolas', family: 'Consolas, monospace', category: 'Monospace' },
            { name: 'Impact', family: 'Impact, sans-serif', category: 'Display' },
            { name: 'Comic Sans MS', family: 'Comic Sans MS, cursive', category: 'Cursive' }
        ];

        let activeCategory = 'all';
        let currentFont = 'Inter, sans-serif';
        let currentFontName = 'Inter';

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            renderFontList(fonts);
        });

        // Show font selector
        function showFontSelector() {
            document.getElementById('fontSearchInput').value = '';
            activeCategory = 'all';
            updateCategoryButtons();
            renderFontList(fonts);
            document.getElementById('fontSelectorOverlay').style.display = 'flex';
            highlightCurrentFont(currentFont);
        }

        // Close font selector
        function closeFontSelector() {
            document.getElementById('fontSelectorOverlay').style.display = 'none';
        }

        // Render font list
        function renderFontList(fontArray) {
            const container = document.getElementById('fontListContainer');
            let html = '';
            
            fontArray.forEach(font => {
                const isSelected = font.family === currentFont;
                html += `
                    <div class="font-item ${isSelected ? 'selected' : ''}" 
                         data-font-name="${font.name}"
                         data-font-family="${font.family}"
                         data-font-category="${font.category}"
                         onclick="selectFont('${font.family}', '${font.name}')">
                        <div class="font-preview">
                            <span class="font-sample" style="font-family: ${font.family}">Aa</span>
                            <div class="font-info">
                                <span class="font-name">${font.name}</span>
                                <span class="font-category-badge">${font.category}</span>
                            </div>
                        </div>
                        <div class="font-preview-text" style="font-family: ${font.family}">
                            The quick brown fox jumps over the lazy dog
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Filter fonts by search
        function filterFonts() {
            const searchTerm = document.getElementById('fontSearchInput').value.toLowerCase();
            
            const filtered = fonts.filter(font => {
                const matchesSearch = font.name.toLowerCase().includes(searchTerm);
                const matchesCategory = activeCategory === 'all' || font.category === activeCategory;
                return matchesSearch && matchesCategory;
            });
            
            renderFontList(filtered);
        }

        // Filter by category
        function filterFontsByCategory(category) {
            activeCategory = category;
            updateCategoryButtons();
            filterFonts();
        }

        // Update category buttons
        function updateCategoryButtons() {
            document.querySelectorAll('.font-category').forEach(btn => {
                const btnCategory = btn.getAttribute('data-category');
                if (btnCategory === activeCategory) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }

        // Select a font
        function selectFont(fontFamily, fontName) {
            // Update current font
            currentFont = fontFamily;
            currentFontName = fontName;
            
            // Update demo text
            document.getElementById('demoText').style.fontFamily = fontFamily;
            document.getElementById('currentFontName').innerText = fontName;
            
            // Update preview in selector
            document.getElementById('previewFontName').innerText = fontName;
            document.getElementById('previewFontExample').style.fontFamily = fontFamily;
            
            // Highlight selected
            highlightCurrentFont(fontFamily);
            
            // Auto-close after selection (optional)
            setTimeout(closeFontSelector, 300);
        }

        // Highlight current font
        function highlightCurrentFont(fontFamily) {
            document.querySelectorAll('.font-item').forEach(item => {
                if (item.dataset.fontFamily === fontFamily) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        }

        // Click outside to close
        document.addEventListener('click', function(e) {
            const overlay = document.getElementById('fontSelectorOverlay');
            if (overlay && overlay.style.display === 'flex') {
                if (!e.target.closest('.font-selector-panel')) {
                    closeFontSelector();
                }
            }
        });

        // Escape key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeFontSelector();
            }
        });
    </script>
</body>
</html>