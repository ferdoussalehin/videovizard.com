<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CalmBot Effect Demo</title>
    <script src="https://unpkg.com/typed.js@2.1.0/dist/typed.umd.js"></script>
    <style>
        body { background: #1a1a2e; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; display: flex; flex-direction: column; align-items: center; padding: 50px; }
        .box { width: 80%; max-width: 600px; padding: 20px; border-radius: 12px; background: #16213e; box-shadow: 0 4px 15px rgba(0,0,0,0.3); margin-bottom: 20px; min-height: 100px; }
        
        /* 1. Typewriter Style */
        #typed-output { font-size: 1.2rem; line-height: 1.5; color: #4db8ff; }

        /* 2. Fade/Pulse Style (For Stress Impact) */
        .fade-text { opacity: 0; transition: opacity 2s ease-in-out; text-align: center; font-style: italic; color: #a29bfe; }
        .fade-in { opacity: 1; }

        /* 3. Scrolling Marquee Style (For Background/Status) */
        .scroll-container { width: 100%; overflow: hidden; background: #0f3460; padding: 5px 0; border-radius: 5px; }
        .scroll-text { display: inline-block; white-space: nowrap; animation: scroll 15s linear infinite; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; }
        @keyframes scroll { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }

        button { padding: 10px 20px; background: #4db8ff; border: none; border-radius: 5px; cursor: pointer; color: white; margin-top: 10px; }
    </style>
</head>
<body>

    <h3>Phase 1: Registration (Typed.js)</h3>
    <div class="box">
        <span id="typed-output"></span>
    </div>

    <h3>Phase 2: Stress Discovery (Fade Effect)</h3>
    <div class="box">
        <p id="impact-text" class="fade-text">Analyzing your financial stress impact...</p>
    </div>

    <h3>Phase 3: Background Status (Scrolling)</h3>
    <div class="scroll-container">
        <div class="scroll-text">
            CATEGORY: FINANCE & MONEY • ISSUE: CREDIT CARD BILLS • INTENSITY: HIGH • FREQUENCY: DAILY • STATUS: CALCULATING IMPACT...
        </div>
    </div>

    <button onclick="startDemo()">Restart Demo</button>

    <script>
        function startDemo() {
            // 1. Initialize Typed.js
            new Typed('#typed-output', {
                strings: [
                    'Welcome to your session.', 
                    'Let\'s talk about your **Credit Card Bills**.', 
                    'How long has this been causing you stress?',
                    'I am here to help you find clarity.'
                ],
                typeSpeed: 40,
                backSpeed: 20,
                loop: false,
                contentType: 'html' // Allows bolding/HTML tags
            });

            // 2. Trigger Fade Effect (Simulating the Impact Report)
            setTimeout(() => {
                const fadeEl = document.getElementById('impact-text');
                fadeEl.classList.remove('fade-in');
                
                // Small delay to reset the transition
                setTimeout(() => {
                    fadeEl.innerText = "Chronic stress from expenses can impact sleep by up to 40%. Let's start your free audio.";
                    fadeEl.classList.add('fade-in');
                }, 100);
            }, 6000);
        }

        // Auto-start on load
        window.onload = startDemo;
    </script>
</body>
</html>