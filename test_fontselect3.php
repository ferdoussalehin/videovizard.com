<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Font Controls</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: #111;
  min-height: 100vh;
  font-family: system-ui, sans-serif;
  padding: 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
}

/* ── PREVIEW ── */
.preview {
  width: 100%;
  max-width: 480px;
  background: #1a1a1a;
  border-radius: 16px;
  padding: 32px 24px;
  border: 1px solid rgba(255,255,255,.07);
  text-align: center;
}
.preview-text {
  font-size: 36px;
  color: #fff;
  line-height: 1.3;
  transition: all .2s ease;
  word-break: break-word;
  outline: none;
  cursor: text;
}
.preview-hint {
  font-size: 11px;
  color: rgba(255,255,255,.2);
  margin-top: 10px;
  letter-spacing: .05em;
}

/* ── CARD ── */
.card {
  width: 100%;
  max-width: 480px;
  background: #1a1a1a;
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.07);
  overflow: hidden;
}
.card-header {
  padding: 14px 16px 0;
  font-size: 10px;
  color: rgba(255,255,255,.3);
  letter-spacing: .1em;
  text-transform: uppercase;
  font-weight: 600;
}
.card-body { padding: 14px 16px 16px; }

/* ── FONT SIZE ── */
.size-stepper {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.step-btn {
  width: 40px; height: 40px;
  border-radius: 10px;
  background: rgba(255,255,255,.08);
  border: none; color: #fff;
  font-size: 22px; line-height: 1;
  cursor: pointer;
  transition: background .12s;
  flex-shrink: 0;
}
.step-btn:hover { background: rgba(255,255,255,.15); }
.step-btn:active { background: rgba(255,255,255,.2); }
.size-display {
  flex: 1;
  text-align: center;
}
.size-number {
  font-size: 36px;
  font-weight: 700;
  color: #fff;
  line-height: 1;
}
.size-unit {
  font-size: 13px;
  color: rgba(255,255,255,.3);
  margin-left: 4px;
}
.size-slider {
  -webkit-appearance: none;
  width: 100%;
  height: 4px;
  border-radius: 4px;
  background: rgba(255,255,255,.15);
  outline: none;
  margin-bottom: 14px;
  cursor: pointer;
}
.size-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 20px; height: 20px;
  border-radius: 50%;
  background: #fff;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0,0,0,.4);
  transition: transform .12s;
}
.size-slider::-webkit-slider-thumb:hover { transform: scale(1.15); }
.size-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.size-preset {
  padding: 5px 11px;
  border-radius: 8px;
  border: 1.5px solid transparent;
  background: rgba(255,255,255,.06);
  color: rgba(255,255,255,.4);
  font-size: 12px;
  cursor: pointer;
  transition: all .12s;
}
.size-preset:hover { background: rgba(255,255,255,.1); color: rgba(255,255,255,.7); }
.size-preset.active {
  background: rgba(255,255,255,.15);
  border-color: rgba(255,255,255,.5);
  color: #fff;
  font-weight: 600;
}

/* ── FONT COLOR ── */
.color-swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  align-items: center;
  margin-bottom: 14px;
}
.swatch {
  width: 30px; height: 30px;
  border-radius: 50%;
  border: 2.5px solid transparent;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform .12s, box-shadow .12s;
  position: relative;
}
.swatch:hover { transform: scale(1.18); }
.swatch.active {
  border-color: #fff;
  box-shadow: 0 0 0 2px rgba(255,255,255,.35);
}
.swatch.transparent-swatch {
  background: repeating-conic-gradient(#555 0% 25%, #222 0% 50%) 0/10px 10px;
}
.swatch-custom {
  background: conic-gradient(red, yellow, lime, aqua, blue, magenta, red);
  cursor: pointer;
  overflow: hidden;
}
.swatch-custom input[type=color] {
  opacity: 0;
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
  border: none;
  padding: 0;
}

/* Hex input row */
.hex-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.hex-preview {
  width: 36px; height: 36px;
  border-radius: 8px;
  border: 2px solid rgba(255,255,255,.15);
  flex-shrink: 0;
  transition: background .15s;
}
.hex-input {
  flex: 1;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 8px;
  padding: 8px 12px;
  color: #fff;
  font-size: 13px;
  font-family: monospace;
  letter-spacing: .05em;
  outline: none;
  transition: border-color .15s;
}
.hex-input:focus { border-color: rgba(255,255,255,.35); }
.hex-input::placeholder { color: rgba(255,255,255,.2); }
.opacity-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
}
.opacity-label {
  font-size: 11px;
  color: rgba(255,255,255,.3);
  width: 52px;
  flex-shrink: 0;
}
.opacity-slider {
  -webkit-appearance: none;
  flex: 1;
  height: 4px;
  border-radius: 4px;
  outline: none;
  cursor: pointer;
}
.opacity-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 18px; height: 18px;
  border-radius: 50%;
  background: #fff;
  cursor: pointer;
  box-shadow: 0 2px 5px rgba(0,0,0,.4);
}
.opacity-value {
  font-size: 12px;
  color: rgba(255,255,255,.4);
  width: 36px;
  text-align: right;
  flex-shrink: 0;
}

/* Divider */
.divider {
  height: 1px;
  background: rgba(255,255,255,.07);
  margin: 14px 0;
}

/* ── OUTPUT BAR ── */
.output-bar {
  width: 100%;
  max-width: 480px;
  background: #1a1a1a;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.07);
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.output-item { display: flex; flex-direction: column; gap: 3px; }
.output-label { font-size: 10px; color: rgba(255,255,255,.25); letter-spacing: .08em; text-transform: uppercase; }
.output-value { font-size: 13px; color: #fff; font-family: monospace; }
</style>
</head>
<body>

<!-- Preview -->
<div class="preview">
  <div class="preview-text" id="previewText" contenteditable="true" spellcheck="false">
    Click to edit this text
  </div>
  <div class="preview-hint">click text to edit · changes apply live</div>
</div>

<!-- Font Size Picker -->
<div class="card">
  <div class="card-header">Font Size</div>
  <div class="card-body">
    <div class="size-stepper">
      <button class="step-btn" id="sizeDown">−</button>
      <div class="size-display">
        <span class="size-number" id="sizeNumber">36</span>
        <span class="size-unit">px</span>
      </div>
      <button class="step-btn" id="sizeUp">+</button>
    </div>
    <input class="size-slider" type="range" id="sizeSlider" min="8" max="120" value="36">
    <div class="size-presets" id="sizePresets"></div>
  </div>
</div>

<!-- Font Color Picker -->
<div class="card">
  <div class="card-header">Font Color</div>
  <div class="card-body">

    <!-- Swatches -->
    <div class="color-swatches" id="colorSwatches"></div>

    <div class="divider"></div>

    <!-- Hex + preview -->
    <div class="hex-row">
      <div class="hex-preview" id="hexPreview"></div>
      <input class="hex-input" id="hexInput" type="text" placeholder="#000000" maxlength="7">
    </div>

    <!-- Opacity -->
    <div class="opacity-row">
      <span class="opacity-label">Opacity</span>
      <input class="opacity-slider" type="range" id="opacitySlider" min="0" max="100" value="100" id="opacitySlider">
      <span class="opacity-value" id="opacityValue">100%</span>
    </div>

  </div>
</div>

<!-- Output -->
<div class="output-bar">
  <div class="output-item">
    <span class="output-label">Size</span>
    <span class="output-value" id="outSize">36px</span>
  </div>
  <div class="output-item">
    <span class="output-label">Color</span>
    <span class="output-value" id="outColor">#ffffff</span>
  </div>
  <div class="output-item">
    <span class="output-label">Opacity</span>
    <span class="output-value" id="outOpacity">100%</span>
  </div>
  <div class="output-item">
    <span class="output-label">CSS</span>
    <span class="output-value" id="outCSS">color: #ffffff</span>
  </div>
</div>

<script>
// ── DATA ────────────────────────────────────────────────────────

const SIZE_PRESETS = [8, 10, 12, 14, 16, 18, 20, 24, 28, 32, 36, 42, 48, 56, 64, 72, 96, 120];

const COLOR_SWATCHES = [
  "#ffffff", "#000000", "#1a1a1a", "#FF3B30", "#FF9500",
  "#FFCC00", "#34C759", "#00C7BE", "#007AFF", "#5856D6",
  "#AF52DE", "#FF2D55", "#FF6B6B", "#FFE66D", "#A8E6CF",
  "#3D5A80", "#E07A5F", "#F2CC8F", "#c9a84c", "#636366",
];

// ── STATE ───────────────────────────────────────────────────────

let fontSize   = 36;
let fontColor  = "#ffffff";
let opacity    = 100;

// ── ELEMENTS ────────────────────────────────────────────────────

const previewText   = document.getElementById("previewText");
const sizeNumber    = document.getElementById("sizeNumber");
const sizeSlider    = document.getElementById("sizeSlider");
const sizePresetsEl = document.getElementById("sizePresets");
const sizeDown      = document.getElementById("sizeDown");
const sizeUp        = document.getElementById("sizeUp");
const swatchesEl    = document.getElementById("colorSwatches");
const hexInput      = document.getElementById("hexInput");
const hexPreview    = document.getElementById("hexPreview");
const opacitySlider = document.getElementById("opacitySlider");
const opacityValue  = document.getElementById("opacityValue");
const outSize       = document.getElementById("outSize");
const outColor      = document.getElementById("outColor");
const outOpacity    = document.getElementById("outOpacity");
const outCSS        = document.getElementById("outCSS");

// ── HELPERS ─────────────────────────────────────────────────────

function hexToRgb(hex) {
  const r = parseInt(hex.slice(1,3),16);
  const g = parseInt(hex.slice(3,5),16);
  const b = parseInt(hex.slice(5,7),16);
  return { r, g, b };
}

function isValidHex(h) { return /^#[0-9a-fA-F]{6}$/.test(h); }

function applyAll() {
  const { r, g, b } = hexToRgb(fontColor);
  const alpha = opacity / 100;
  const cssColor = alpha < 1
    ? `rgba(${r},${g},${b},${alpha.toFixed(2)})`
    : fontColor;

  // Preview
  previewText.style.fontSize = fontSize + "px";
  previewText.style.color    = cssColor;

  // Slider fill
  const pct = ((fontSize - 8) / (120 - 8)) * 100;
  sizeSlider.style.background =
    `linear-gradient(to right, #fff ${pct}%, rgba(255,255,255,.15) ${pct}%)`;

  // Opacity slider fill
  const oPct = opacity;
  opacitySlider.style.background =
    `linear-gradient(to right, rgba(${r},${g},${b},1) ${oPct}%, rgba(255,255,255,.1) ${oPct}%)`;

  // Hex preview swatch
  hexPreview.style.background = fontColor;

  // Size UI
  sizeNumber.textContent = fontSize;
  sizeSlider.value       = fontSize;
  document.querySelectorAll(".size-preset").forEach(btn => {
    btn.classList.toggle("active", +btn.dataset.size === fontSize);
  });

  // Hex input (only update if not focused to avoid fighting with user typing)
  if (document.activeElement !== hexInput) {
    hexInput.value = fontColor;
  }

  // Opacity
  opacityValue.textContent = opacity + "%";
  opacitySlider.value      = opacity;

  // Swatch active state
  document.querySelectorAll(".swatch:not(.swatch-custom)").forEach(sw => {
    sw.classList.toggle("active", sw.dataset.color === fontColor);
  });

  // Output bar
  outSize.textContent    = fontSize + "px";
  outColor.textContent   = fontColor;
  outOpacity.textContent = opacity + "%";
  outCSS.textContent     = `color: ${cssColor}`;

  // Fire event for your app
  document.dispatchEvent(new CustomEvent("styleChanged", {
    detail: { fontSize, fontColor, opacity, cssColor }
  }));
}

// ── SIZE CONTROLS ───────────────────────────────────────────────

function setSize(val) {
  fontSize = Math.min(120, Math.max(8, val));
  applyAll();
}

sizeDown.addEventListener("click", () => setSize(fontSize - 1));
sizeUp.addEventListener("click",   () => setSize(fontSize + 1));
sizeSlider.addEventListener("input", e => setSize(+e.target.value));

// Mouse wheel on size display
sizeNumber.parentElement.addEventListener("wheel", e => {
  e.preventDefault();
  setSize(fontSize + (e.deltaY < 0 ? 1 : -1));
}, { passive: false });

// Build preset chips
SIZE_PRESETS.forEach(s => {
  const btn = document.createElement("button");
  btn.className = "size-preset" + (s === fontSize ? " active" : "");
  btn.dataset.size = s;
  btn.textContent  = s;
  btn.addEventListener("click", () => setSize(s));
  sizePresetsEl.appendChild(btn);
});

// ── COLOR CONTROLS ──────────────────────────────────────────────

function setColor(hex) {
  if (!isValidHex(hex)) return;
  fontColor = hex.toLowerCase();
  applyAll();
}

// Build swatches
COLOR_SWATCHES.forEach(c => {
  const sw = document.createElement("button");
  sw.className  = "swatch" + (c === fontColor ? " active" : "");
  sw.dataset.color = c;
  sw.style.background = c;
  sw.title = c;
  sw.addEventListener("click", () => setColor(c));
  swatchesEl.appendChild(sw);
});

// Custom color swatch (rainbow wheel → native picker)
const customSwatch = document.createElement("label");
customSwatch.className = "swatch swatch-custom";
customSwatch.title = "Custom color";
const nativePicker = document.createElement("input");
nativePicker.type  = "color";
nativePicker.value = fontColor;
nativePicker.addEventListener("input", e => setColor(e.target.value));
customSwatch.appendChild(nativePicker);
swatchesEl.appendChild(customSwatch);

// Hex input
hexInput.value = fontColor;
hexInput.addEventListener("input", e => {
  let val = e.target.value.trim();
  if (!val.startsWith("#")) val = "#" + val;
  if (isValidHex(val)) setColor(val);
  hexPreview.style.background = isValidHex(val) ? val : "";
});
hexInput.addEventListener("blur", () => {
  hexInput.value = fontColor; // reset if invalid
});
hexInput.addEventListener("keydown", e => {
  if (e.key === "Enter") hexInput.blur();
});

// Opacity
opacitySlider.addEventListener("input", e => {
  opacity = +e.target.value;
  applyAll();
});

// ── INIT ────────────────────────────────────────────────────────

applyAll();

// ── PUBLIC API (use from PHP pages) ─────────────────────────────
// Read values:   window.getFontSize()  → 36
//                window.getFontColor() → "#ffffff"
//                window.getOpacity()   → 100
//
// Set values (e.g. from PHP-injected saved data):
//   window.setFontSize(24)
//   window.setFontColor("#FF3B30")
//   window.setOpacity(80)
//
// Listen for changes:
//   document.addEventListener("styleChanged", e => {
//     console.log(e.detail); // { fontSize, fontColor, opacity, cssColor }
//   });

window.getFontSize  = () => fontSize;
window.getFontColor = () => fontColor;
window.getOpacity   = () => opacity;
window.setFontSize  = (v) => setSize(v);
window.setFontColor = (v) => setColor(v);
window.setOpacity   = (v) => { opacity = Math.min(100, Math.max(0, v)); applyAll(); };
</script>
</body>
</html>