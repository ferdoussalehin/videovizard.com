<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>B-Roll Pro Studio | Max Editor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400&family=Cairo:wght@400;700;900&family=Almarai:wght@400;700;800&family=Tajawal:wght@400;700;900&family=Aref+Ruqaa:wght@400;700&family=Playfair+Display:ital,wght@0,400;0,900;1,400;1,900&family=Inter:wght@400;700;900&family=Space+Mono:ital,wght@0,400;0,700;1,400;1,700&family=Bebas+Neue&family=Montserrat:wght@900&family=Libre+Baskerville:ital@1&family=Syncopate:wght@700&family=Bangers&family=Lobster&display=swap" rel="stylesheet">
    <style>
        body { background-color: #020617; color: #f8fafc; font-family: 'Inter', sans-serif; overflow-x: hidden; }
        
        .iphone-frame {
            width: 320px; height: 568px;
            background: #000; border-radius: 40px;
            overflow: hidden; border: 8px solid #1e293b;
            position: relative; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        #preview-surface {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            justify-content: flex-start; pointer-events: none; z-index: 10;
        }

        #logo-preview {
            position: absolute;
            z-index: 15;
            pointer-events: none;
        }

        .btn-primary { @apply bg-emerald-500 hover:bg-emerald-400 text-black font-black py-3 px-6 rounded-xl transition-all active:scale-95 disabled:opacity-50; }
        .btn-stop { @apply bg-red-500 hover:bg-red-400 text-white font-black py-3 px-6 rounded-xl transition-all active:scale-95; }
        .glass { @apply bg-slate-900/80 backdrop-blur-md border border-slate-800 p-5 rounded-3xl; }
        
        input[type="range"] { @apply w-full accent-emerald-500 bg-slate-800 rounded-lg h-1.5 appearance-none; }
        select { @apply bg-slate-800 border border-slate-700 rounded-lg px-2 py-2 text-xs outline-none focus:ring-1 focus:ring-emerald-500 w-full; }
        select option, select optgroup { background-color: #1e293b; color: white; }

        #capture-canvas { display: none; }
        
        .bg-thumbnail {
            @apply aspect-square rounded-lg bg-cover bg-center cursor-pointer border-2 border-transparent hover:border-emerald-500 transition-all relative overflow-hidden;
        }
        .bg-thumbnail.active { @apply border-emerald-500 scale-95; }
        
        .upload-btn {
            @apply flex flex-col items-center justify-center border-2 border-dashed border-slate-700 rounded-lg hover:border-emerald-500 hover:bg-emerald-500/10 transition-all cursor-pointer text-slate-500 hover:text-emerald-500;
        }

        #bgGrid { display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)) !important; }
        #bgGrid::-webkit-scrollbar { width: 6px; }
        #bgGrid::-webkit-scrollbar-track { background: #0f172a; }
        #bgGrid::-webkit-scrollbar-thumb { background: #475569; border-radius: 3px; }

        .media-toggle-btn { @apply flex-1 py-2 text-[10px] font-black uppercase tracking-widest transition-all; }
        .media-toggle-btn.active { @apply bg-emerald-500 text-black; }
        .media-toggle-btn:not(.active) { @apply bg-slate-800 text-slate-500 hover:bg-slate-700; }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center p-4 lg:p-8">

    <div class="w-full max-w-7xl grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Sidebar Controls -->
        <div class="lg:col-span-3 space-y-4">
            <div class="glass">
                <div class="flex justify-between items-center mb-4">
                    <h1 class="text-lg font-black tracking-tighter italic text-emerald-500">B-ROLL PRO MAX</h1>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Script</label>
                            <button id="resetScrollerBtn" class="text-[9px] bg-slate-800 hover:bg-slate-700 px-2 py-1 rounded text-emerald-400 font-bold uppercase transition-all">Reset Pos</button>
                        </div>
                        <textarea id="scriptInput" rows="4" class="w-full bg-black/50 border border-slate-700 rounded-xl p-3 text-sm focus:ring-1 focus:ring-emerald-500 outline-none">STORYTELLING
REDEFINED.

Visual perfection
in every frame.

THE END.</textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Display Mode</label>
                            <select id="displayMode">
                                <option value="scroll">Smooth Scroll</option>
                                <option value="word">Word by Word</option>
                                <option value="word-stay">Word by Word (Stay)</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Text Color</label>
                            <input type="color" id="textColor" value="#ffffff" class="w-full h-[34px] bg-slate-800 border border-slate-700 rounded-lg cursor-pointer">
                        </div>
                    </div>

                    <div class="border-t border-slate-800 pt-4 mt-4">
                        <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest block mb-2">Logo Overlay</label>
                        <input type="file" id="logoUpload" class="hidden" accept="image/png,image/svg+xml,image/jpg,image/jpeg">
                        <button onclick="document.getElementById('logoUpload').click()" class="w-full bg-slate-800 hover:bg-slate-700 text-[10px] font-bold py-2 rounded-lg border border-slate-700 text-slate-300 uppercase transition-all">
                            Upload Custom Logo
                        </button>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Logo Size</label>
                                <div class="flex items-center gap-1">
                                    <button id="logoSizeDec" class="bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm w-8 h-8 rounded-lg border border-slate-700 transition-all active:scale-95">−</button>
                                    <input type="number" id="logoSizeCtrl" min="20" max="150" step="5" value="60" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1 text-center text-xs font-bold text-white outline-none focus:ring-1 focus:ring-emerald-500 w-12">
                                    <button id="logoSizeInc" class="bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm w-8 h-8 rounded-lg border border-slate-700 transition-all active:scale-95">+</button>
                                </div>
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Position</label>
                                <select id="logoPosition">
                                    <option value="top">Top Center</option>
                                    <option value="bottom">Bottom Center</option>
                                    <option value="top-left">Top Left</option>
                                    <option value="top-right">Top Right</option>
                                </select>
                            </div>
                        </div>
                        <p id="logoStatus" class="text-[9px] text-emerald-500 mt-2 italic">✓ StressReleasor Logo Active</p>
                    </div>

                    <div>
						<label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Speed / Timing</label>
						<div class="flex items-center gap-2">
							<button id="speedDec" class="bg-slate-800 hover:bg-slate-700 text-white font-bold text-lg w-10 h-10 rounded-lg border border-slate-700 transition-all active:scale-95">−</button>
							<input type="number" id="speedCtrl" min="0.1" max="5" step="0.1" value="0.5" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-center text-sm font-bold text-white outline-none focus:ring-1 focus:ring-emerald-500">
							<button id="speedInc" class="bg-slate-800 hover:bg-slate-700 text-white font-bold text-lg w-10 h-10 rounded-lg border border-slate-700 transition-all active:scale-95">+</button>
						</div>
						<div class="flex justify-between mt-1 px-1">
							<span class="text-[8px] text-slate-500">Min: 0.1</span>
							<span class="text-[8px] text-slate-500">Max: 5.0</span>
						</div>
					</div>

                    <div>
                        <label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Font Family</label>
                        <select id="fontFamily">
                            <optgroup label="English Modern">
                                <option value="'Inter', sans-serif">Inter (Modern Sans)</option>
                                <option value="'Montserrat', sans-serif">Montserrat (Heavy)</option>
                                <option value="'Bebas Neue', cursive">Bebas Neue (Impact)</option>
                                <option value="'Syncopate', sans-serif">Syncopate (Wide)</option>
                            </optgroup>
                            <optgroup label="English Classic & Creative">
                                <option value="'Playfair Display', serif">Playfair Display (Serif)</option>
                                <option value="'Libre Baskerville', serif">Libre Italic (Classic)</option>
                                <option value="'Space Mono', monospace">Space Mono (Tech)</option>
                                <option value="'Bangers', system-ui">Bangers (Comic)</option>
                                <option value="'Lobster', cursive">Lobster (Script)</option>
                            </optgroup>
                            <optgroup label="Arabic Styles">
                                <option value="'Cairo', sans-serif">Cairo (Modern Arabic)</option>
                                <option value="'Almarai', sans-serif">Almarai (Clean Arabic)</option>
                                <option value="'Tajawal', sans-serif">Tajawal (Geometric Arabic)</option>
                                <option value="'Amiri', serif">Amiri (Classic Arabic)</option>
                                <option value="'Aref Ruqaa', serif">Aref Ruqaa (Calligraphic)</option>
                            </optgroup>
                        </select>
                    </div>

                   <div class="grid grid-cols-2 gap-2">
						<div>
							<label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Size</label>
							<div class="flex items-center gap-1">
								<button id="fontSizeDec" class="bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm w-8 h-8 rounded-lg border border-slate-700 transition-all active:scale-95">−</button>
								<input type="number" id="fontSizeCtrl" min="10" max="100" step="1" value="42" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1 text-center text-xs font-bold text-white outline-none focus:ring-1 focus:ring-emerald-500 w-12">
								<button id="fontSizeInc" class="bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm w-8 h-8 rounded-lg border border-slate-700 transition-all active:scale-95">+</button>
							</div>
						</div>
						<div>
							<label class="text-[10px] font-bold text-slate-500 uppercase block mb-1">Style</label>
							<select id="fontStyle">
								<option value="italic font-black">Italic Black</option>
								<option value="font-black">Normal Black</option>
								<option value="italic font-normal">Italic Thin</option>
								<option value="font-normal">Normal Thin</option>
							</select>
						</div>
					</div>
                </div>
            </div>

            <div class="glass space-y-3">
                <button id="exportBtn" class="btn-primary w-full flex items-center justify-center gap-2">START RECORDING</button>
                <button id="stopBtn" class="btn-stop hidden w-full">STOP & SAVE VIDEO</button>
                <p id="statusMsg" class="text-[10px] text-center text-slate-400 font-medium uppercase tracking-tighter">System Idle</p>
            </div>
        </div>

        <!-- Preview Column -->
        <div class="lg:col-span-5 flex flex-col items-center">
            <div class="iphone-frame" id="frame">
                <video id="preview-video" class="absolute inset-0 w-full h-full object-cover hidden transition-all duration-300 scale-110" loop muted playsinline crossorigin="anonymous"></video>
                <div id="bg-layer" class="absolute inset-0 bg-cover bg-center transition-all duration-300 scale-110"></div>
                <div class="absolute inset-0 bg-gradient-to-b from-black/50 via-transparent to-black/70 z-0"></div>
                <div id="logo-preview" style="filter: drop-shadow(0 10px 20px rgba(0,0,0,0.8));"></div>
                <div id="preview-surface">
                    <div id="text-scroller" class="text-center px-6 whitespace-pre-wrap drop-shadow-2xl"></div>
                </div>
            </div>
            <p class="mt-4 text-[10px] text-slate-500 font-mono uppercase">Render Engine: Canvas 640x1136 (9:16)</p>
        </div>

        <!-- Media Library Column -->
        <div class="lg:col-span-4 space-y-4">
            <div class="glass">
				<label class="text-[10px] font-bold text-emerald-500 uppercase tracking-widest block mb-3">Database Content</label>
				<div class="space-y-3">
					<select id="langPicker" class="w-full">
						<option value="contents">English (Default)</option>
						<option value="contents_ur">Urdu</option>
						<option value="contents_ar">Arabic</option>
						<option value="contents_hi">Hindi</option>
						<option value="contents_es">Spanish</option>
						<option value="contents_fr">French</option>
					</select>
					<div class="flex gap-2">
						<button onclick="fetchFromDB()" class="flex-1 bg-slate-800 hover:bg-slate-700 text-[10px] font-bold py-2 rounded-lg border border-slate-700 text-white uppercase transition-all">
							📥 Load Random Row
						</button>
						<button id="updateStatusBtn" onclick="markAsRecorded()" class="hidden flex-1 bg-emerald-500/20 hover:bg-emerald-500/40 text-[10px] font-bold py-2 rounded-lg border border-emerald-500/50 text-emerald-400 uppercase transition-all">
							✅ Mark Done
						</button>
					</div>
					<div id="dbInfo" class="text-[9px] text-slate-500 font-mono text-center">No record loaded</div>
				</div>
			</div>

            <div class="glass h-[400px] flex flex-col">
                <label class="text-[10px] font-bold text-slate-500 uppercase tracking-widest block mb-3">Media Library</label>
                <div class="flex rounded-lg overflow-hidden border border-slate-700 mb-4">
                    <button id="showImages" class="media-toggle-btn active">Images</button>
                    <button id="showVideos" class="media-toggle-btn">Videos</button>
                </div>
                <div id="bgGrid" class="grid grid-cols-4 gap-2 overflow-y-auto pr-2 flex-grow">
                    <label class="bg-thumbnail upload-btn" id="upload-wrapper">
                        <input type="file" id="mediaUpload" class="hidden" accept="image/*,video/*">
                        <span class="text-xl font-bold">+</span>
                        <span class="text-[8px] uppercase font-bold">Upload</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden Capture Canvas -->
    <canvas id="capture-canvas" width="640" height="1136"></canvas>

    <script>
        const imgLibrary = [
            { url: 'https://images.unsplash.com/photo-1470790376778-a9fbc86d70e2?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1519681393784-d120267933ba?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1477346611705-65d1883cee1e?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1506318137071-a8e063b4bcc0?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1502134249126-9f3755a50d78?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1493246507139-91e8bef99c02?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1506744038136-46273834b3fb?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1475113548554-5a36f1f523d6?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1532274402911-5a3b027c98b1?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1550684848-fac1c5b4e853?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1518111389851-bc7489ba115e?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1490730141103-6cac27aaab94?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1534067783941-51c9c23ecefd?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1513407030348-c983a97b98d8?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1439405326854-014607f694d7?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1419242902214-272b3f66ee7a?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1514810771018-276192729582?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1505506819244-7d5aa6e8385e?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1483728642387-6c3bdd6c93e5?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1516339901600-2e3a8ad0f1d8?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1478760329108-5c3ed9d495a0?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1531297484001-80022131f5a1?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1504333638930-c8787321eba0?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1533134486753-c833f0ed4866?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1510519133417-2ad7c30eeecf?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1470219556762-1771e7f9427d?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1557683316-973673baf926?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1550684376-efcbd6e3f031?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1494438639946-1ebd1d20bf85?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1485470733090-0aae1788d5af?q=80&w=1080' },
			{ url: 'https://images.unsplash.com/photo-1511447333015-45b65e60f6d5?q=80&w=1080' }
		];

        const vidLibrary = [
		  // 🌌 Sky & Space
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-stars-in-the-night-sky-11640-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-galaxy-with-stars-1590-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-night-sky-with-stars-3971-large.mp4' },

		  // 🚗 Motion & Travel
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-driving-in-a-dark-tunnel-with-bright-lights-40046-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-driving-through-a-tunnel-at-night-38759-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-road-trip-through-mountains-20038-large.mp4' },

		  // 🌊 Water & Ocean
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-waves-in-the-ocean-1164-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-sea-waves-crashing-on-rocks-1188-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-waterfall-in-forest-2213-large.mp4' },

		  // 🌲 Nature & Forest
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-sunlight-coming-through-trees-4006-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-path-in-the-middle-of-a-forest-1109-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-misty-forest-in-the-mountains-1117-large.mp4' },

		  // ☁️ Clouds & Sky
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-clouds-moving-over-mountains-1987-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-time-lapse-of-clouds-1424-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-sky-with-moving-clouds-3158-large.mp4' },

		  // 🌄 Mountains & Landscapes
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-mountain-landscape-at-sunset-3306-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-mountain-range-under-clouds-4510-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-sunrise-over-mountains-2283-large.mp4' },

		  // 🔥 Abstract & Aesthetic
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-abstract-light-moving-1244-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-colorful-particles-moving-1997-large.mp4' },
		  { url: 'https://assets.mixkit.co/videos/preview/mixkit-smoke-background-1184-large.mp4' }
		];


        let state = { 
            text: "", words: [], currentWordIndex: 0, lastWordUpdate: 0,
            bg: imgLibrary[0].url, speed: 0.5, fontSize: 42, color: '#ffffff', mode: 'scroll',
            fontFamily: "'Inter', sans-serif", fontStyleClasses: "italic font-black",
            brightness: 100, blur: 0, posY: 568, isRecording: false, 
            isVideo: false, currentMode: 'img',
            bgImgObj: new Image(), bgVidObj: document.getElementById('preview-video'),
            logoImg: null, logoSize: 60, logoPosition: 'top', showDefaultLogo: true
        };

        const scroller = document.getElementById('text-scroller');
        const bgLayer = document.getElementById('bg-layer');
        const logoPreview = document.getElementById('logo-preview');
        const canvas = document.getElementById('capture-canvas');
        const ctx = canvas.getContext('2d');
        let recorder = null, chunks = [];

        // ========== COOKIE MANAGEMENT ==========
        function saveToCookie() {
            const settings = {
                fontSize: state.fontSize,
                fontFamily: state.fontFamily,
                fontStyleClasses: state.fontStyleClasses,
                color: state.color,
                mode: state.mode,
                speed: state.speed,
                logoSize: state.logoSize,
                logoPosition: state.logoPosition,
                brightness: state.brightness,
                blur: state.blur
            };
            document.cookie = `broll_settings=${encodeURIComponent(JSON.stringify(settings))}; path=/; max-age=31536000`;
        }

        function loadFromCookie() {
            const cookies = document.cookie.split(';');
            for (let cookie of cookies) {
                const [name, value] = cookie.trim().split('=');
                if (name === 'broll_settings') {
                    try {
                        const settings = JSON.parse(decodeURIComponent(value));
                        // Apply loaded settings
                        state.fontSize = settings.fontSize || 42;
                        state.fontFamily = settings.fontFamily || "'Inter', sans-serif";
                        state.fontStyleClasses = settings.fontStyleClasses || "italic font-black";
                        state.color = settings.color || '#ffffff';
                        state.mode = settings.mode || 'scroll';
                        state.speed = settings.speed || 0.5;
                        state.logoSize = settings.logoSize || 60;
                        state.logoPosition = settings.logoPosition || 'top';
                        state.brightness = settings.brightness || 100;
                        state.blur = settings.blur || 0;

                        // Update UI elements
                        document.getElementById('fontSizeCtrl').value = state.fontSize;
                        document.getElementById('fontFamily').value = state.fontFamily;
                        document.getElementById('fontStyle').value = state.fontStyleClasses;
                        document.getElementById('textColor').value = state.color;
                        document.getElementById('displayMode').value = state.mode;
                        document.getElementById('speedCtrl').value = state.speed;
                        document.getElementById('logoSizeCtrl').value = state.logoSize;
                        document.getElementById('logoPosition').value = state.logoPosition;
                        document.getElementById('brightCtrl').value = state.brightness;
                        document.getElementById('blurCtrl').value = state.blur;
                    } catch (e) {
                        console.error('Failed to load settings:', e);
                    }
                    break;
                }
            }
        }

        // ========== BUTTON CONTROLS ==========
        // Font Size Controls
        document.getElementById('fontSizeDec').onclick = () => {
            const input = document.getElementById('fontSizeCtrl');
            let val = parseInt(input.value) || 42;
            val = Math.max(10, val - 1);
            input.value = val;
            state.fontSize = val;
            saveToCookie();
            update();
        };

        document.getElementById('fontSizeInc').onclick = () => {
            const input = document.getElementById('fontSizeCtrl');
            let val = parseInt(input.value) || 42;
            val = Math.min(100, val + 1);
            input.value = val;
            state.fontSize = val;
            saveToCookie();
            update();
        };

        // Logo Size Controls
        document.getElementById('logoSizeDec').onclick = () => {
            const input = document.getElementById('logoSizeCtrl');
            let val = parseInt(input.value) || 60;
            val = Math.max(20, val - 5);
            input.value = val;
            state.logoSize = val;
            saveToCookie();
            updateLogoPreview();
        };

        document.getElementById('logoSizeInc').onclick = () => {
            const input = document.getElementById('logoSizeCtrl');
            let val = parseInt(input.value) || 60;
            val = Math.min(150, val + 5);
            input.value = val;
            state.logoSize = val;
            saveToCookie();
            updateLogoPreview();
        };

        // Speed Controls
        document.getElementById('speedDec').onclick = () => {
            const input = document.getElementById('speedCtrl');
            let val = parseFloat(input.value) || 0.5;
            val = Math.max(0.1, (val - 0.1)).toFixed(1);
            input.value = val;
            state.speed = parseFloat(val);
            saveToCookie();
        };

        document.getElementById('speedInc').onclick = () => {
            const input = document.getElementById('speedCtrl');
            let val = parseFloat(input.value) || 0.5;
            val = Math.min(5.0, (parseFloat(val) + 0.1)).toFixed(1);
            input.value = val;
            state.speed = parseFloat(val);
            saveToCookie();
        };

        // Logo Upload Handler
        document.getElementById('logoUpload').onchange = (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (f) => {
                    state.logoImg = new Image();
                    state.logoImg.src = f.target.result;
                    state.logoImg.onload = () => {
                        state.showDefaultLogo = false;
                        document.getElementById('logoStatus').innerText = "✓ Custom: " + file.name;
                        updateLogoPreview();
                    };
                };
                reader.readAsDataURL(file);
            }
        };

        document.getElementById('logoSizeCtrl').oninput = (e) => {
            state.logoSize = parseInt(e.target.value);
            saveToCookie();
            updateLogoPreview();
        };

        document.getElementById('logoPosition').onchange = (e) => {
            state.logoPosition = e.target.value;
            saveToCookie();
            updateLogoPreview();
        };

        document.getElementById('resetScrollerBtn').onclick = () => {
            state.posY = 568;
        };

        document.getElementById('showImages').onclick = () => {
            state.currentMode = 'img';
            document.getElementById('showImages').classList.add('active');
            document.getElementById('showVideos').classList.remove('active');
            renderLibrary();
        };

        document.getElementById('showVideos').onclick = () => {
            state.currentMode = 'vid';
            document.getElementById('showVideos').classList.add('active');
            document.getElementById('showImages').classList.remove('active');
            renderLibrary();
        };

        document.getElementById('mediaUpload').onchange = (e) => {
            const file = e.target.files[0];
            if (file) {
                const url = URL.createObjectURL(file);
                if (file.type.startsWith('image')) {
                    imgLibrary.unshift({url});
                    state.currentMode = 'img';
                } else {
                    vidLibrary.unshift({url});
                    state.currentMode = 'vid';
                }
                state.bg = url;
                state.isVideo = file.type.startsWith('video');
                if (state.isVideo) {
                    state.bgVidObj.src = url;
                    state.bgVidObj.play();
                } else {
                    state.bgImgObj.src = url;
                }
                renderLibrary();
                update();
            }
        };

        // Logic to link UI to state
        const binders = {
            'scriptInput': 'text',
            'speedCtrl': 'speed',
            'fontSizeCtrl': 'fontSize',
            'textColor': 'color',
            'displayMode': 'mode',
            'fontFamily': 'fontFamily',
            'fontStyle': 'fontStyleClasses',
            'brightCtrl': 'brightness',
            'blurCtrl': 'blur'
        };

        Object.entries(binders).forEach(([id, key]) => {
            const el = document.getElementById(id);
            el.oninput = (e) => {
                state[key] = id.includes('Ctrl') ? parseFloat(e.target.value) : e.target.value;
                if (key === 'text') state.words = state.text.split(/\s+/);
                if (key === 'mode') {
                    state.currentWordIndex = 0;
                    state.posY = 568;
                }
                saveToCookie();
                update();
            };
        });

        // Initialize
        function init() {
            loadFromCookie(); // Load saved settings first
            state.text = document.getElementById('scriptInput').value;
            state.words = state.text.split(/\s+/);
            state.bgImgObj.crossOrigin = "anonymous";
            state.bgImgObj.src = state.bg;
            renderLibrary();
            update();
            updateLogoPreview();
            requestAnimationFrame(animate);
        }

        function renderLibrary() {
            const grid = document.getElementById('bgGrid');
            const thumbs = grid.querySelectorAll('.bg-thumb-item');
            thumbs.forEach(t => t.remove());
            const list = state.currentMode === 'img' ? imgLibrary : vidLibrary;
            list.forEach(item => {
                const div = document.createElement('div');
                div.className = "bg-thumbnail bg-thumb-item" + (state.bg === item.url ? " active" : "");
                if (state.currentMode === 'img') div.style.backgroundImage = `url(${item.url})`;
                else div.innerHTML = `<video src="${item.url}" class="w-full h-full object-cover pointer-events-none" crossorigin="anonymous"></video>`;
                div.onclick = () => {
                    state.bg = item.url;
                    state.isVideo = (state.currentMode === 'vid');
                    if (state.isVideo) { state.bgVidObj.src = item.url; state.bgVidObj.play(); } 
                    else { state.bgImgObj.src = item.url; }
                    renderLibrary();
                    update();
                };
                grid.appendChild(div);
            });
        }

        function update() {
            scroller.style.fontSize = state.fontSize + 'px';
            scroller.style.fontFamily = state.fontFamily;
            scroller.style.color = state.color;
            scroller.className = `text-center px-6 whitespace-pre-wrap drop-shadow-2xl ${state.fontStyleClasses}`;
            
            const filterStr = `brightness(${state.brightness}%) blur(${state.blur}px)`;
            if (state.isVideo) {
                bgLayer.style.display = 'none'; state.bgVidObj.style.display = 'block';
                state.bgVidObj.style.filter = filterStr;
            } else {
                bgLayer.style.display = 'block'; state.bgVidObj.style.display = 'none';
                bgLayer.style.backgroundImage = `url(${state.bg})`;
                bgLayer.style.filter = filterStr;
            }
            document.getElementById('brightVal').innerText = state.brightness + '%';
            document.getElementById('blurVal').innerText = state.blur + 'px';
        }

        function updateLogoPreview() {
            if (state.showDefaultLogo) {
                logoPreview.innerHTML = `
                    <div style="text-align: center; font-family: 'Inter', sans-serif;">
                        <div style="font-size: ${state.logoSize * 0.4}px; line-height: 1;">🛟</div>
                        <div style="font-size: ${state.logoSize * 0.3}px; font-weight: 900; color: #10b981; margin-top: 5px; line-height: 1;">StressReleasor</div>
                        <div style="font-size: ${state.logoSize * 0.15}px; color: white; margin-top: 3px; line-height: 1;">Always here to help & support</div>
                    </div>
                `;
            } else if (state.logoImg && state.logoImg.complete) {
                const h = state.logoSize;
                const w = (state.logoImg.width / state.logoImg.height) * h;
                logoPreview.innerHTML = `<img src="${state.logoImg.src}" style="width:${w}px;height:${h}px;"/>`;
            }

            // Position the logo preview
            const pos = state.logoPosition;
            logoPreview.style.top = pos.includes('top') ? '20px' : 'auto';
            logoPreview.style.bottom = pos.includes('bottom') ? '20px' : 'auto';
            logoPreview.style.left = pos.includes('left') ? '20px' : (pos === 'top' || pos === 'bottom' ? '50%' : 'auto');
            logoPreview.style.right = pos.includes('right') ? '20px' : 'auto';
            logoPreview.style.transform = (pos === 'top' || pos === 'bottom') ? 'translateX(-50%)' : 'none';
        }

        function animate(time) {
            if (state.mode === 'scroll') {
                state.posY -= state.speed;
                if (state.posY < -(scroller.offsetHeight + 100)) state.posY = 568;
                scroller.style.transform = `translateY(${state.posY}px)`;
                scroller.innerText = state.text;
                document.getElementById('preview-surface').style.justifyContent = "flex-start";
            } else {
                const interval = 1000 / (state.speed * 2 || 0.1);
                if (time - state.lastWordUpdate > interval) {
                    state.currentWordIndex = (state.currentWordIndex + 1) % (state.words.length || 1);
                    if (state.mode === 'word') scroller.innerText = state.words[state.currentWordIndex] || "";
                    else scroller.innerText = state.words.slice(0, state.currentWordIndex + 1).join(" ");
                    state.lastWordUpdate = time;
                }
                scroller.style.transform = `translateY(0px)`;
                document.getElementById('preview-surface').style.justifyContent = "center";
            }
            drawToCanvas();
            requestAnimationFrame(animate);
        }

        function drawToCanvas() {
            const scale = canvas.width / 320;
            ctx.clearRect(0,0,canvas.width, canvas.height);
            
            // 1. Draw Background
            ctx.save();
            ctx.filter = `brightness(${state.brightness}%) blur(${state.blur * scale}px)`;
            const canvasAspect = canvas.width / canvas.height;
            let dw, dh, dx, dy;

            const media = state.isVideo ? state.bgVidObj : state.bgImgObj;
            if (media && (state.isVideo ? media.readyState >= 2 : media.complete)) {
                const mw = state.isVideo ? media.videoWidth : media.width;
                const mh = state.isVideo ? media.videoHeight : media.height;
                const aspect = mw / mh;
                if (aspect > canvasAspect) { dh = canvas.height; dw = canvas.height * aspect; dx = (canvas.width - dw) / 2; dy = 0; }
                else { dw = canvas.width; dh = canvas.width / aspect; dx = 0; dy = (canvas.height - dh) / 2; }
                ctx.drawImage(media, dx, dy, dw, dh);
            }
            ctx.restore();

            // 2. Overlay Gradient
            const grad = ctx.createLinearGradient(0, 0, 0, canvas.height);
            grad.addColorStop(0, 'rgba(0,0,0,0.5)'); grad.addColorStop(0.5, 'transparent'); grad.addColorStop(1, 'rgba(0,0,0,0.7)');
            ctx.fillStyle = grad; ctx.fillRect(0, 0, canvas.width, canvas.height);

            // 3. Draw Logo
            if (state.showDefaultLogo || (state.logoImg && state.logoImg.complete)) {
                const lSize = state.logoSize * scale;
                const pos = state.logoPosition;
                let lx, ly;

                ctx.save();
                ctx.shadowColor = "rgba(0,0,0,0.8)";
                ctx.shadowBlur = 20;
                
                if (state.showDefaultLogo) {
                    if (pos.includes('top')) {
                        ly = 40;
                    } else {
                        ly = canvas.height - (lSize * 1.2) - 40;
                    }
                    
                    if (pos === 'top' || pos === 'bottom') {
                        lx = canvas.width / 2;
                    } else if (pos.includes('left')) {
                        lx = lSize * 0.6;
                    } else {
                        lx = canvas.width - (lSize * 0.6);
                    }

                    ctx.textAlign = (pos === 'top' || pos === 'bottom') ? 'center' : (pos.includes('left') ? 'left' : 'right');
                    ctx.textBaseline = 'top';
                    
                    ctx.font = `${lSize * 0.4}px Arial`;
                    ctx.fillStyle = "#ffffff";
                    ctx.fillText("🛟", lx, ly);
                    
                    ctx.font = `900 ${lSize * 0.3}px 'Inter', sans-serif`;
                    ctx.fillStyle = "#10b981";
                    ctx.fillText("StressReleasor", lx, ly + (lSize * 0.45));
                    
                    ctx.font = `${lSize * 0.15}px 'Inter', sans-serif`;
                    ctx.fillStyle = "white";
                    ctx.fillText("Always here to help & support", lx, ly + (lSize * 0.8));
                    
                } else {
                    const aspect = state.logoImg.width / state.logoImg.height;
                    const lw = lSize * aspect;
                    const lh = lSize;
                    
                    if (pos.includes('top')) {
                        ly = 40;
                    } else {
                        ly = canvas.height - lh - 40;
                    }
                    
                    if (pos === 'top' || pos === 'bottom') {
                        lx = (canvas.width - lw) / 2;
                    } else if (pos.includes('left')) {
                        lx = 40;
                    } else {
                        lx = canvas.width - lw - 40;
                    }
                    
                    ctx.drawImage(state.logoImg, lx, ly, lw, lh);
                }
                ctx.restore();
            }

            // 4. Draw Text
            ctx.save();
            const fontStyle = state.fontStyleClasses.includes('italic') ? 'italic ' : '';
            const fontWeight = state.fontStyleClasses.includes('black') ? '900 ' : '400 ';
            ctx.font = `${fontStyle}${fontWeight}${state.fontSize * scale}px ${state.fontFamily}`;
            ctx.fillStyle = state.color;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.shadowColor = 'rgba(0,0,0,0.8)';
            ctx.shadowBlur = 20 * scale;

            const lines = (state.mode === 'scroll' ? state.text : scroller.innerText).split('\n');
            const lineHeight = state.fontSize * 1.2 * scale;
            
            let startY;
            if (state.mode === 'scroll') {
                startY = (state.posY * scale) + (lineHeight / 2);
            } else {
                startY = (canvas.height / 2) - ((lines.length - 1) * lineHeight / 2);
            }

            lines.forEach((line, i) => {
                ctx.fillText(line, canvas.width / 2, startY + (i * lineHeight));
            });
            ctx.restore();
        }

        // Recording Controls
        document.getElementById('exportBtn').onclick = async () => {
            chunks = [];
            const stream = canvas.captureStream(60);
            recorder = new MediaRecorder(stream, { mimeType: 'video/webm;codecs=vp9' });
            recorder.ondataavailable = e => chunks.push(e.data);
            recorder.onstop = () => {
                const blob = new Blob(chunks, { type: 'video/webm' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = `broll-export-${Date.now()}.webm`;
                a.click();
            };
            recorder.start();
            state.isRecording = true;
            document.getElementById('exportBtn').classList.add('hidden');
            document.getElementById('stopBtn').classList.remove('hidden');
            document.getElementById('statusMsg').innerText = "🔴 RECORDING IN PROGRESS";
        };

        document.getElementById('stopBtn').onclick = () => {
            recorder.stop();
            state.isRecording = false;
            document.getElementById('exportBtn').classList.remove('hidden');
            document.getElementById('stopBtn').classList.add('hidden');
            document.getElementById('statusMsg').innerText = "SYSTEM IDLE";
        };
		let currentRecord = null; // To store the DB row

		async function fetchFromDB() {
			const langKey = document.getElementById('langPicker').value;
			const infoDiv = document.getElementById('dbInfo');
			const inputField = document.getElementById('scriptInput'); // Matches your textarea ID
			
			try {
				const response = await fetch('fetch_record.php?action=fetch');
				const data = await response.json();
				
				if(data.id) {
					currentRecord = data;
					
					// Get content for selected language
					const textToShow = data[langKey] || "Content empty for this language.";
					
					// Update State and UI
					state.text = textToShow;
					state.words = textToShow.split(/\s+/);
					inputField.value = textToShow;
					
					infoDiv.innerHTML = `DATABASE ID: ${data.id}`;
					document.getElementById('updateStatusBtn').classList.remove('hidden');
					
					// Trigger visual update
					update(); 
				} else {
					alert(data.error || "No data returned");
				}
			} catch (e) {
				console.error("DB Error:", e);
			}
		}

		async function markAsRecorded() {
			if(!currentRecord) return;
			
			const formData = new FormData();
			formData.append('action', 'update_status');
			formData.append('id', currentRecord.id);
			
			try {
				const response = await fetch('fetch_record.php', { method: 'POST', body: formData });
				const res = await response.json();
				if(res.success) {
					document.getElementById('dbInfo').innerHTML = `<span class="text-emerald-500">ID ${currentRecord.id} UPDATED</span>`;
					document.getElementById('updateStatusBtn').classList.add('hidden');
					currentRecord = null;
				}
			} catch (e) {
				alert("Update failed.");
			}
		}

		// Ensure the text updates if the user switches language AFTER loading a row
		document.getElementById('langPicker').addEventListener('change', function() {
			if(currentRecord) {
				const text = currentRecord[this.value] || "Content empty.";
				state.text = text;
				state.words = text.split(/\s+/);
				document.getElementById('scriptInput').value = text;
				update();
			}
		});

        window.onload = init;
    </script>
</body>
</html>
