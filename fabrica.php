<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cinematic Zoom & Smooth Pan</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 10px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a1a;
            color: white;
            min-height: 100vh;
        }
        .container { max-width: 500px; margin: 0 auto; }
        h1 { text-align: center; margin: 10px 0; font-size: 1.3rem; color: #4CAF50; }

        .canvas-wrapper {
            border: 2px solid #444;
            background: #222;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            width: 100%;
            aspect-ratio: 9 / 16;
            position: relative;
            touch-action: none;
        }
        #canvas { display: block; width: 100%; height: 100%; }

        /* Control Panel Overlay */
        .control-panel {
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 100;
            pointer-events: none;
        }
        
        .control-row {
            display: flex;
            justify-content: space-between;
            pointer-events: auto;
        }
        
        .control-group {
            background: rgba(0,0,0,0.8);
            border-radius: 30px;
            padding: 5px;
            display: flex;
            gap: 5px;
            border: 1px solid #4CAF50;
            backdrop-filter: blur(5px);
        }
        
        .control-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #333;
            color: white;
            border: 1px solid #666;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .control-btn:hover { background: #4CAF50; }
        .control-btn.active { background: #f44336; }

        /* Speed Control Panels */
        .speed-control-panel {
            background: rgba(0,0,0,0.8);
            border-radius: 30px;
            padding: 12px 20px;
            border: 1px solid #4CAF50;
            backdrop-filter: blur(5px);
            pointer-events: auto;
            width: 100%;
            margin-bottom: 5px;
        }
        
        .speed-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            color: #4CAF50;
            font-weight: bold;
        }
        
        .speed-slider-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .speed-slider-container input {
            flex: 1;
            height: 6px;
            -webkit-appearance: none;
            background: linear-gradient(90deg, #4CAF50, #f44336);
            border-radius: 3px;
            outline: none;
            cursor: pointer;
        }
        
        .speed-slider-container input::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            border: 2px solid #4CAF50;
            cursor: pointer;
        }
        
        .speed-value {
            background: #333;
            padding: 5px 15px;
            border-radius: 20px;
            border: 1px solid #4CAF50;
            min-width: 70px;
            text-align: center;
            font-weight: bold;
            color: #4CAF50;
        }

        .status-bar {
            background: #333;
            border: 1px solid #4CAF50;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            font-size: 0.9rem;
        }
        .status-item { display: flex; align-items: center; gap: 5px; }
        .status-led {
            width: 12px; height: 12px; border-radius: 50%;
            background: #f44336;
        }
        .status-led.on { background: #4CAF50; box-shadow: 0 0 10px #4CAF50; }

        .button-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        button {
            padding: 12px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: transform 0.1s;
        }
        button:active { transform: scale(0.98); }
        .pan-btn { background: #2196F3; }
        .reset-btn { background: #FF9800; }
        .stop-btn { background: #f44336; }
        
        .log-panel {
            background: #1e1e1e;
            padding: 12px;
            border-radius: 8px;
            max-height: 150px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.8rem;
            border: 1px solid #444;
        }
        .log-entry { padding: 4px 0; border-bottom: 1px solid #333; color: #4CAF50; }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
</head>
<body>
<div class="container">
    <h1>🎬 Cinematic Zoom & Smooth Pan</h1>

    <div class="canvas-wrapper">
        <canvas id="canvas" width="720" height="1280"></canvas>
        <div class="control-panel">
            <!-- Top row with mode buttons -->
            <div class="control-row">
                <div class="control-group">
                    <button class="control-btn" onclick="togglePanMode()" id="panModeBtn" title="Toggle Pan Mode">✋</button>
                    <button class="control-btn" onclick="resetView()" title="Reset View">↺</button>
                </div>
                <div class="control-group">
                    <button class="control-btn" onclick="startZoomIn()" title="Start Zoom In">➕</button>
                    <button class="control-btn" onclick="stopZoom()" title="Stop">⏹️</button>
                    <button class="control-btn" onclick="startZoomOut()" title="Start Zoom Out">➖</button>
                </div>
            </div>
            
            <!-- ZOOM SPEED CONTROL -->
            <div class="speed-control-panel">
                <div class="speed-label">
                    <span>🔍 Zoom Speed</span>
                    <span>🎬 Cinematic</span>
                </div>
                <div class="speed-slider-container">
                    <span style="color: #4CAF50;">Slow</span>
                    <input type="range" id="zoomSpeed" min="0.0005" max="0.01" step="0.0001" value="0.002">
                    <span style="color: #f44336;">Fast</span>
                    <span class="speed-value" id="zoomSpeedDisplay">0.0020</span>
                </div>
            </div>

            <!-- PAN SPEED CONTROL (NEW) -->
            <div class="speed-control-panel">
                <div class="speed-label">
                    <span>✋ Pan Speed</span>
                    <span>🎬 Smooth Motion</span>
                </div>
                <div class="speed-slider-container">
                    <span style="color: #4CAF50;">Slow</span>
                    <input type="range" id="panSpeed" min="1" max="50" step="1" value="20">
                    <span style="color: #f44336;">Fast</span>
                    <span class="speed-value" id="panSpeedDisplay">20px</span>
                </div>
            </div>
        </div>
    </div>

    <div class="status-bar">
        <div class="status-item"><span>Pan:</span> <span class="status-led" id="panStatusLed"></span><span id="panStatusText">OFF</span></div>
        <div class="status-item"><span>Zoom:</span> <span id="zoomStatus">100%</span></div>
        <div class="status-item"><span>Pos:</span> <span id="positionStatus">0,0</span></div>
    </div>

    <div class="button-grid">
        <button onclick="startZoomIn()">🔍 Zoom In</button>
        <button onclick="startZoomOut()">🔍 Zoom Out</button>
        <button onclick="stopZoom()" class="stop-btn">⏹️ Stop</button>
        <button onclick="resetView()" class="reset-btn">🔄 Reset</button>
        <button onclick="panLeft()" class="pan-btn">⬅️ Pan Left</button>
        <button onclick="panRight()" class="pan-btn">➡️ Pan Right</button>
        <button onclick="panUp()" class="pan-btn">⬆️ Pan Up</button>
        <button onclick="panDown()" class="pan-btn">⬇️ Pan Down</button>
        <button onclick="togglePanMode()" class="pan-btn" id="togglePanBtn">✋ Toggle Pan</button>
        <button onclick="loadSampleImage()">🖼️ Load Image</button>
        <button onclick="smoothPanTest()">🎯 Test Smooth Pan</button>
        <button onclick="clearLog()">🗑️ Clear Log</button>
    </div>

    <div class="log-panel" id="logPanel">
        <div class="log-entry">✅ Ready. Both Zoom and Pan speed sliders are now visible!</div>
    </div>
</div>

<script>
    // --- Configuration ---
    const canvas = new fabric.Canvas('canvas', {
        width: 720, height: 1280, backgroundColor: '#1a1a1a',
        enableRetinaScaling: true, allowTouchScrolling: false, selection: true
    });

    // --- State ---
    let isPanMode = false;
    let isDragging = false;
    let lastPosX = 0, lastPosY = 0;
    let zoomAnimationFrame = null;
    let zoomDirection = 0; // 1 for in, -1 for out, 0 for stop

    // --- Speed Control Displays ---
    const zoomSlider = document.getElementById('zoomSpeed');
    const zoomDisplay = document.getElementById('zoomSpeedDisplay');
    const panSlider = document.getElementById('panSpeed');
    const panDisplay = document.getElementById('panSpeedDisplay');
    
    zoomSlider.addEventListener('input', (e) => {
        zoomDisplay.textContent = parseFloat(e.target.value).toFixed(4);
    });
    
    panSlider.addEventListener('input', (e) => {
        panDisplay.textContent = e.target.value + 'px';
    });

    // --- Load Initial Image ---
    function loadSampleImage() {
        const imgUrl = prompt("Enter image URL:", "https://videovizard.com/podcast_images/1029241379.png");
        if (!imgUrl) return;
        
        fabric.Image.fromURL(imgUrl, (img) => {
            canvas.clear();
            canvas.backgroundColor = '#1a1a1a';
            
            // Scale image to fit canvas initially
            const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
            img.scale(scale);
            img.set({
                left: (canvas.width - img.width * scale) / 2,
                top: (canvas.height - img.height * scale) / 2,
                selectable: false,
                evented: false,
                hasControls: false,
                hasBorders: false
            });
            
            canvas.add(img);
            canvas.sendToBack(img);
            
            // Add border
            const border = new fabric.Rect({
                left: 0, top: 0, width: 720, height: 1280,
                fill: 'transparent', stroke: '#4CAF50', strokeWidth: 2, strokeDashArray: [5, 5],
                evented: false, selectable: false
            });
            canvas.add(border);
            
            canvas.renderAll();
            log("Image loaded and fitted.");
            updateUI();
        }, { crossOrigin: 'anonymous' });
    }

    // --- Cinematic Zoom Animation ---
    function animateZoom() {
        if (zoomDirection === 0) return;

        const speed = parseFloat(zoomSlider.value);
        let zoom = canvas.getZoom();
        const newZoom = zoom + (zoomDirection * speed);
        
        // Limit zoom range
        if (newZoom >= 0.5 && newZoom <= 3.0) {
            canvas.zoomToPoint({ x: canvas.width / 2, y: canvas.height / 2 }, newZoom);
            canvas.renderAll();
            updateUI();
            
            zoomAnimationFrame = requestAnimationFrame(animateZoom);
        } else {
            stopZoom();
        }
    }

    function startZoomIn() {
        zoomDirection = 1;
        if (zoomAnimationFrame) cancelAnimationFrame(zoomAnimationFrame);
        animateZoom();
        log("Started zoom IN at speed: " + zoomSlider.value);
    }

    function startZoomOut() {
        zoomDirection = -1;
        if (zoomAnimationFrame) cancelAnimationFrame(zoomAnimationFrame);
        animateZoom();
        log("Started zoom OUT at speed: " + zoomSlider.value);
    }

    function stopZoom() {
        zoomDirection = 0;
        if (zoomAnimationFrame) {
            cancelAnimationFrame(zoomAnimationFrame);
            zoomAnimationFrame = null;
        }
        log("Zoom stopped");
    }

    // --- Smooth Pan Controls with Speed Control ---
    function panLeft() {
        const panSpeed = parseInt(panSlider.value);
        const vpt = canvas.viewportTransform;
        vpt[4] += panSpeed;
        canvas.setViewportTransform(vpt);
        canvas.renderAll();
        updateUI();
        log(`Panned left by ${panSpeed}px`);
    }

    function panRight() {
        const panSpeed = parseInt(panSlider.value);
        const vpt = canvas.viewportTransform;
        vpt[4] -= panSpeed;
        canvas.setViewportTransform(vpt);
        canvas.renderAll();
        updateUI();
        log(`Panned right by ${panSpeed}px`);
    }

    function panUp() {
        const panSpeed = parseInt(panSlider.value);
        const vpt = canvas.viewportTransform;
        vpt[5] += panSpeed;
        canvas.setViewportTransform(vpt);
        canvas.renderAll();
        updateUI();
        log(`Panned up by ${panSpeed}px`);
    }

    function panDown() {
        const panSpeed = parseInt(panSlider.value);
        const vpt = canvas.viewportTransform;
        vpt[5] -= panSpeed;
        canvas.setViewportTransform(vpt);
        canvas.renderAll();
        updateUI();
        log(`Panned down by ${panSpeed}px`);
    }

    // Test smooth continuous pan with speed control
    function smoothPanTest() {
        const panSpeed = parseInt(panSlider.value);
        log(`Starting smooth pan test at speed: ${panSpeed}px per step...`);
        let panOffset = 0;
        const panInterval = setInterval(() => {
            const vpt = canvas.viewportTransform;
            vpt[4] -= 5; // Pan right slowly at fixed rate for smoothness
            canvas.setViewportTransform(vpt);
            canvas.renderAll();
            updateUI();
            
            panOffset += 5;
            if (panOffset >= 200) {
                clearInterval(panInterval);
                log("Smooth pan test complete");
            }
        }, 50);
    }

    // --- Manual Pan Mode (Smooth Drag) ---
    function togglePanMode() {
        isPanMode = !isPanMode;
        updateUI();
        log(`Manual pan mode ${isPanMode ? 'enabled' : 'disabled'}`);
    }

    function resetView() {
        stopZoom();
        canvas.setZoom(1);
        canvas.setViewportTransform([1, 0, 0, 1, 0, 0]);
        canvas.renderAll();
        updateUI();
        log("View reset");
    }

    // --- UI Update ---
    function updateUI() {
        const zoom = Math.round(canvas.getZoom() * 100);
        document.getElementById('zoomStatus').innerHTML = zoom + '%';
        
        const vpt = canvas.viewportTransform;
        const panX = Math.round(vpt[4]);
        const panY = Math.round(vpt[5]);
        document.getElementById('positionStatus').innerHTML = `${panX},${panY}`;
        
        const panLed = document.getElementById('panStatusLed');
        const panText = document.getElementById('panStatusText');
        const panBtn = document.getElementById('togglePanBtn');
        const panModeBtn = document.getElementById('panModeBtn');
        
        if (isPanMode) {
            panLed.className = 'status-led on';
            panText.innerHTML = 'ON';
            panBtn.innerHTML = '✋ Pan: ON';
            panModeBtn.style.background = '#f44336';
            canvas.defaultCursor = 'grab';
            canvas.hoverCursor = 'grab';
            canvas.selection = false;
        } else {
            panLed.className = 'status-led';
            panText.innerHTML = 'OFF';
            panBtn.innerHTML = '✋ Pan: OFF';
            panModeBtn.style.background = '#333';
            canvas.defaultCursor = 'default';
            canvas.hoverCursor = 'move';
            canvas.selection = true;
        }
    }

    // --- Smooth Drag-to-Pan Event Handlers ---
    const canvasElement = canvas.getElement();
    
    canvasElement.addEventListener('mousedown', (e) => {
        if (!isPanMode) return;
        isDragging = true;
        lastPosX = e.clientX;
        lastPosY = e.clientY;
        canvasElement.style.cursor = 'grabbing';
        e.preventDefault();
    });

    canvasElement.addEventListener('mousemove', (e) => {
        if (!isPanMode || !isDragging) return;
        
        requestAnimationFrame(() => {
            const deltaX = e.clientX - lastPosX;
            const deltaY = e.clientY - lastPosY;
            
            const vpt = canvas.viewportTransform;
            vpt[4] += deltaX;
            vpt[5] += deltaY;
            
            canvas.setViewportTransform(vpt);
            canvas.renderAll();
            
            lastPosX = e.clientX;
            lastPosY = e.clientY;
            updateUI();
        });
        
        e.preventDefault();
    });

    canvasElement.addEventListener('mouseup', () => {
        isDragging = false;
        if (isPanMode) canvasElement.style.cursor = 'grab';
    });

    canvasElement.addEventListener('mouseleave', () => {
        isDragging = false;
        if (isPanMode) canvasElement.style.cursor = 'grab';
    });

    // Touch events with smooth handling
    canvasElement.addEventListener('touchstart', (e) => {
        if (!isPanMode || e.touches.length !== 1) return;
        isDragging = true;
        lastPosX = e.touches[0].clientX;
        lastPosY = e.touches[0].clientY;
        e.preventDefault();
    });

    canvasElement.addEventListener('touchmove', (e) => {
        if (!isPanMode || !isDragging || e.touches.length !== 1) return;
        
        requestAnimationFrame(() => {
            const deltaX = e.touches[0].clientX - lastPosX;
            const deltaY = e.touches[0].clientY - lastPosY;
            
            const vpt = canvas.viewportTransform;
            vpt[4] += deltaX;
            vpt[5] += deltaY;
            
            canvas.setViewportTransform(vpt);
            canvas.renderAll();
            
            lastPosX = e.touches[0].clientX;
            lastPosY = e.touches[0].clientY;
            updateUI();
        });
        
        e.preventDefault();
    });

    canvasElement.addEventListener('touchend', () => { isDragging = false; });

    // Disable wheel zoom to prevent conflicts
    canvasElement.addEventListener('wheel', (e) => {
        e.preventDefault();
    }, { passive: false });

    // --- Logging ---
    function log(message) {
        const logPanel = document.getElementById('logPanel');
        const entry = document.createElement('div');
        entry.className = 'log-entry';
        entry.innerHTML = `📌 ${message}`;
        logPanel.appendChild(entry);
        logPanel.scrollTop = logPanel.scrollHeight;
        console.log(message);
    }

    function clearLog() {
        document.getElementById('logPanel').innerHTML = '<div class="log-entry">✅ Log cleared</div>';
    }

    // --- Initial Setup ---
    // Add a border
    const border = new fabric.Rect({
        left: 0, top: 0, width: 720, height: 1280,
        fill: 'transparent', stroke: '#4CAF50', strokeWidth: 2, strokeDashArray: [5, 5],
        evented: false, selectable: false
    });
    canvas.add(border);
    
    // Add grid lines to show panning clearly
    for (let i = 0; i <= 720; i += 100) {
        const line = new fabric.Line([i, 0, i, 1280], {
            stroke: '#333', strokeWidth: 1, evented: false, selectable: false
        });
        canvas.add(line);
    }
    for (let i = 0; i <= 1280; i += 100) {
        const line = new fabric.Line([0, i, 720, i], {
            stroke: '#333', strokeWidth: 1, evented: false, selectable: false
        });
        canvas.add(line);
    }
    
    // Add some test objects
    const testObj = new fabric.Text('TEST', {
        left: 360, top: 640, fontSize: 40, fill: '#4CAF50',
        fontWeight: 'bold', selectable: true
    });
    canvas.add(testObj);
    
    canvas.renderAll();
    updateUI();
    log("✅ Ready! Both Zoom and Pan speed sliders are now available.");
    
    // Load a sample image after 1 second
    setTimeout(() => {
        loadSampleImage();
    }, 1000);
</script>
</body>
</html>