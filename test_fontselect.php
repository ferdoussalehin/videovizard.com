<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Font Family Picker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Pacifico&family=Oswald:wght@400;700&family=Dancing+Script:wght@400;700&family=Abril+Fatface&family=Righteous&family=Lobster&family=Raleway:wght@400;700&family=Montserrat:wght@400;700&family=Permanent+Marker&family=Satisfy&family=Fredoka+One&family=Bungee&family=Caveat:wght@400;700&family=Cinzel:wght@400;700&family=Squada+One&family=Russo+One&family=Comfortaa:wght@400;700&family=Black+Han+Sans&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: #111;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: system-ui, sans-serif;
    padding: 24px;
  }

  .picker-wrap {
    width: 100%;
    max-width: 420px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  /* Preview */
  .preview-card {
    background: #1a1a1a;
    border-radius: 16px;
    padding: 28px 24px;
    border: 1px solid rgba(255,255,255,.07);
    text-align: center;
  }
  .preview-label {
    font-size: 10px;
    color: rgba(255,255,255,.25);
    letter-spacing: .1em;
    text-transform: uppercase;
    margin-bottom: 12px;
  }
  .preview-text {
    font-size: 36px;
    color: #fff;
    line-height: 1.2;
    transition: font-family .2s ease;
  }
  .preview-chars {
    font-size: 13px;
    color: rgba(255,255,255,.35);
    margin-top: 8px;
    transition: font-family .2s ease;
  }
  .preview-badge {
    display: inline-block;
    margin-top: 10px;
    background: rgba(255,255,255,.07);
    border-radius: 8px;
    padding: 4px 12px;
    font-size: 12px;
    color: rgba(255,255,255,.45);
    transition: font-family .2s ease;
  }

  /* Picker card */
  .picker-card {
    background: #1a1a1a;
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,.07);
    overflow: hidden;
  }

  /* Search */
  .search-wrap {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(255,255,255,.07);
  }
  .search-inner {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,.06);
    border-radius: 10px;
    padding: 8px 12px;
    border: 1px solid rgba(255,255,255,.08);
    transition: border-color .15s;
  }
  .search-inner:focus-within {
    border-color: rgba(255,255,255,.3);
  }
  .search-inner svg { flex-shrink: 0; }
  .search-input {
    background: none;
    border: none;
    outline: none;
    color: #fff;
    font-size: 13px;
    width: 100%;
  }
  .search-input::placeholder { color: rgba(255,255,255,.2); }
  .search-clear {
    background: none;
    border: none;
    color: rgba(255,255,255,.3);
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
    padding: 0;
    display: none;
  }

  /* Font list */
  .font-list {
    max-height: 340px;
    overflow-y: auto;
  }
  .font-list::-webkit-scrollbar { width: 4px; }
  .font-list::-webkit-scrollbar-track { background: transparent; }
  .font-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 4px; }

  .font-row {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 16px;
    border: none;
    border-left: 3px solid transparent;
    border-bottom: 1px solid rgba(255,255,255,.04);
    background: transparent;
    cursor: pointer;
    text-align: left;
    transition: background .12s, border-color .12s;
  }
  .font-row:last-child { border-bottom: none; }
  .font-row:hover { background: rgba(255,255,255,.06); }
  .font-row.active {
    background: rgba(255,255,255,.09);
    border-left-color: #fff;
  }
  .font-name {
    flex: 1;
    font-size: 22px;
    color: rgba(255,255,255,.6);
    line-height: 1;
    transition: color .12s;
  }
  .font-row.active .font-name { color: #fff; }
  .font-aa {
    font-size: 12px;
    color: rgba(255,255,255,.2);
    flex-shrink: 0;
  }
  .font-check {
    flex-shrink: 0;
    opacity: 0;
    transition: opacity .12s;
  }
  .font-row.active .font-check { opacity: 1; }

  .no-results {
    padding: 32px;
    text-align: center;
    color: rgba(255,255,255,.2);
    font-size: 13px;
    display: none;
  }

  /* Footer */
  .picker-footer {
    padding: 10px 16px;
    border-top: 1px solid rgba(255,255,255,.07);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .footer-count { font-size: 11px; color: rgba(255,255,255,.2); }
  .footer-selected { font-size: 11px; color: rgba(255,255,255,.4); transition: font-family .2s; }
</style>
</head>
<body>

<div class="picker-wrap">

  <!-- Preview -->
  <div class="preview-card">
    <div class="preview-label">Preview</div>
    <div class="preview-text" id="previewText">The quick brown fox</div>
    <div class="preview-chars" id="previewChars">ABCDEFGHIJKLM · 0123456789</div>
    <div class="preview-badge" id="previewBadge">Bebas Neue</div>
  </div>

  <!-- Picker -->
  <div class="picker-card">

    <!-- Search -->
    <div class="search-wrap">
      <div class="search-inner">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.3)" stroke-width="2.5" stroke-linecap="round">
          <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input class="search-input" id="searchInput" placeholder="Search fonts…" autocomplete="off">
        <button class="search-clear" id="searchClear">×</button>
      </div>
    </div>

    <!-- List -->
    <div class="font-list" id="fontList"></div>
    <div class="no-results" id="noResults">No fonts found</div>

    <!-- Footer -->
    <div class="picker-footer">
      <span class="footer-count" id="footerCount">20 fonts</span>
      <span class="footer-selected" id="footerSelected">Bebas Neue</span>
    </div>

  </div>
</div>

<script>
const FONTS = [
  { id: "bebas",      name: "Bebas Neue",       family: "'Bebas Neue', cursive" },
  { id: "playfair",   name: "Playfair Display",  family: "'Playfair Display', serif" },
  { id: "pacifico",   name: "Pacifico",          family: "'Pacifico', cursive" },
  { id: "oswald",     name: "Oswald",            family: "'Oswald', sans-serif" },
  { id: "dancing",    name: "Dancing Script",    family: "'Dancing Script', cursive" },
  { id: "abril",      name: "Abril Fatface",     family: "'Abril Fatface', cursive" },
  { id: "righteous",  name: "Righteous",         family: "'Righteous', cursive" },
  { id: "lobster",    name: "Lobster",           family: "'Lobster', cursive" },
  { id: "raleway",    name: "Raleway",           family: "'Raleway', sans-serif" },
  { id: "montserrat", name: "Montserrat",        family: "'Montserrat', sans-serif" },
  { id: "marker",     name: "Permanent Marker",  family: "'Permanent Marker', cursive" },
  { id: "satisfy",    name: "Satisfy",           family: "'Satisfy', cursive" },
  { id: "fredoka",    name: "Fredoka One",       family: "'Fredoka One', cursive" },
  { id: "bungee",     name: "Bungee",            family: "'Bungee', cursive" },
  { id: "caveat",     name: "Caveat",            family: "'Caveat', cursive" },
  { id: "cinzel",     name: "Cinzel",            family: "'Cinzel', serif" },
  { id: "squada",     name: "Squada One",        family: "'Squada One', cursive" },
  { id: "russo",      name: "Russo One",         family: "'Russo One', sans-serif" },
  { id: "comfortaa",  name: "Comfortaa",         family: "'Comfortaa', cursive" },
  { id: "blackhan",   name: "Black Han Sans",    family: "'Black Han Sans', sans-serif" },
];

let selectedFont = FONTS[0];
let query = "";

const fontList    = document.getElementById("fontList");
const noResults   = document.getElementById("noResults");
const searchInput = document.getElementById("searchInput");
const searchClear = document.getElementById("searchClear");
const footerCount = document.getElementById("footerCount");
const footerSelected = document.getElementById("footerSelected");
const previewText  = document.getElementById("previewText");
const previewChars = document.getElementById("previewChars");
const previewBadge = document.getElementById("previewBadge");

function applyPreview(font) {
  previewText.style.fontFamily  = font.family;
  previewChars.style.fontFamily = font.family;
  previewBadge.style.fontFamily = font.family;
  previewBadge.textContent      = font.name;
  footerSelected.style.fontFamily = font.family;
  footerSelected.textContent      = font.name;
}

function selectFont(font) {
  selectedFont = font;
  applyPreview(font);
  // Update active row styles
  document.querySelectorAll(".font-row").forEach(row => {
    const isActive = row.dataset.id === font.id;
    row.classList.toggle("active", isActive);
    if (isActive) row.scrollIntoView({ block: "nearest", behavior: "smooth" });
  });
  // Dispatch custom event — easy to hook into from PHP-rendered pages
  document.dispatchEvent(new CustomEvent("fontSelected", { detail: font }));
}

function renderList() {
  const filtered = FONTS.filter(f =>
    f.name.toLowerCase().includes(query.toLowerCase())
  );

  fontList.innerHTML = "";
  footerCount.textContent = `${filtered.length} font${filtered.length !== 1 ? "s" : ""}`;

  if (filtered.length === 0) {
    noResults.style.display = "block";
    fontList.style.display  = "none";
    return;
  }

  noResults.style.display = "none";
  fontList.style.display  = "block";

  filtered.forEach(font => {
    const btn = document.createElement("button");
    btn.className = "font-row" + (font.id === selectedFont.id ? " active" : "");
    btn.dataset.id = font.id;
    btn.innerHTML = `
      <span class="font-name" style="font-family:${font.family}">${font.name}</span>
      <span class="font-aa"   style="font-family:${font.family}">Aa</span>
      <svg class="font-check" width="15" height="15" viewBox="0 0 24 24" fill="none"
           stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    `;
    btn.addEventListener("click", () => selectFont(font));
    fontList.appendChild(btn);
  });
}

// Search
searchInput.addEventListener("input", () => {
  query = searchInput.value;
  searchClear.style.display = query ? "block" : "none";
  renderList();
});

searchClear.addEventListener("click", () => {
  searchInput.value = "";
  query = "";
  searchClear.style.display = "none";
  searchInput.focus();
  renderList();
});

// Init
renderList();
applyPreview(selectedFont);

// ── How to use from PHP / your app ──────────────────────────────
// Listen for selection:
// document.addEventListener("fontSelected", e => {
//   console.log(e.detail.family); // "'Bebas Neue', cursive"
//   myTextElement.style.fontFamily = e.detail.family;
// });
//
// Or read the current value any time:
// const current = window.getSelectedFont(); → { id, name, family }
window.getSelectedFont = () => selectedFont;
</script>
</body>
</html>