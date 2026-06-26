<?php
// ── Return models as JSON (pose_p1 only, grouped by model base name) ──
if (isset($_GET['action']) && $_GET['action'] === 'models') {
    header('Content-Type: application/json');
    $base   = __DIR__ . '/promo_models/';
    $result = [];
    if (is_dir($base)) {
        foreach (glob($base . '*', GLOB_ONLYDIR) as $dir) {
            $cat = basename($dir);
            // Only grab pose_p1 files
            foreach (glob($dir . '/*_pose_p1.{jpg,jpeg,png,webp}', GLOB_BRACE) as $fp) {
                $fn        = basename($fp);
                // Model base = filename without _pose_p1.ext
                $base_name = preg_replace('/_pose_p\d+\.[^.]+$/', '', $fn);
                // Human readable name
                $label     = ucwords(str_replace('_', ' ', $base_name));
                $result[]  = [
                    'cat'       => $cat,
                    'file'      => $fn,
                    'base_name' => $base_name,
                    'label'     => $label,
                    'url'       => 'promo_models/' . $cat . '/' . $fn,
                ];
            }
        }
    }
    echo json_encode($result);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Select Model</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #f3f4f6; color: #111; }
.page { max-width: 960px; margin: 0 auto; padding: 20px 16px; }
h1  { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.sub { font-size: 13px; color: #6b7280; margin-bottom: 18px; }

/* Category tabs */
.cats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
.cat-btn {
  padding: 6px 14px; border: 2px solid #e5e7eb; border-radius: 999px;
  background: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: inherit; transition: all .15s;
}
.cat-btn:hover  { border-color: #8b5cf6; color: #8b5cf6; }
.cat-btn.active { border-color: #8b5cf6; background: #8b5cf6; color: #fff; }

/* Grid */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}
.card-wrap { position: relative; }
.card {
  border: 2px solid #e5e7eb; border-radius: 10px; overflow: hidden;
  cursor: pointer; background: #fff; transition: all .15s;
}
.card:hover { border-color: #8b5cf6; transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,.1); }
.card.selected { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.card img {
  width: 100%; aspect-ratio: 2/3; object-fit: cover;
  object-position: top; display: block; background: #f9fafb;
}
.check {
  display: none; position: absolute; top: 6px; right: 6px;
  width: 22px; height: 22px; background: #10b981; color: #fff;
  border-radius: 50%; font-size: 12px; font-weight: 700;
  align-items: center; justify-content: center;
}
.card.selected .check { display: flex; }
.card-label {
  padding: 6px 8px; font-size: 11px; font-weight: 600; color: #374151;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  border-top: 1px solid #f3f4f6; text-align: center;
}

/* Selected bar */
.sel-bar {
  display: none; position: sticky; bottom: 12px; margin-top: 20px;
  background: #fff; border: 2px solid #10b981; border-radius: 12px;
  padding: 12px 16px; align-items: center; gap: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,.15);
}
.sel-bar.show { display: flex; }
.sel-thumb { width: 44px; height: 60px; object-fit: cover; object-position: top; border-radius: 6px; border: 1px solid #e5e7eb; flex-shrink: 0; }
.sel-info  { flex: 1; min-width: 0; }
.sel-name  { font-size: 14px; font-weight: 700; }
.sel-cat   { font-size: 11px; color: #6b7280; margin-top: 2px; }
.sel-poses { font-size: 11px; color: #8b5cf6; margin-top: 2px; font-weight: 600; }
.btn-ok {
  padding: 10px 22px; background: #10b981; color: #fff; border: none;
  border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;
  font-family: inherit; white-space: nowrap;
}
.btn-ok:hover { background: #059669; }
.msg { text-align: center; padding: 40px; color: #9ca3af; font-size: 14px; }
</style>
</head>
<body>
<div class="page">
  <h1>👗 Select a Model</h1>
  <p class="sub">Showing one photo per model. All 6 poses will be used for try-on.</p>

  <div class="cats" id="cats"></div>
  <div class="grid" id="grid"><div class="msg">Loading models…</div></div>

  <div class="sel-bar" id="sel-bar">
    <img class="sel-thumb" id="sel-thumb" src="" alt="">
    <div class="sel-info">
      <div class="sel-name" id="sel-name"></div>
      <div class="sel-cat"  id="sel-cat"></div>
      <div class="sel-poses">All 6 poses will be generated ✓</div>
    </div>
    <button class="btn-ok" onclick="confirmSelection()">✓ Use this Model</button>
  </div>
</div>

<script>
var allModels = [];
var activeCat = '';
var selected  = null;

window.addEventListener('DOMContentLoaded', function() {
  fetch('?action=models')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.length) {
        document.getElementById('grid').innerHTML = '<div class="msg">No models found in promo_models/</div>';
        return;
      }
      allModels = data;

      // Unique categories
      var cats = [];
      data.forEach(function(m) { if (cats.indexOf(m.cat) === -1) cats.push(m.cat); });

      var catsEl = document.getElementById('cats');
      cats.forEach(function(cat, i) {
        var b = document.createElement('button');
        b.className   = 'cat-btn' + (i === 0 ? ' active' : '');
        b.textContent = cat.replace(/_/g, ' ');
        b.onclick = function() {
          document.querySelectorAll('.cat-btn').forEach(function(x) { x.classList.remove('active'); });
          b.classList.add('active');
          activeCat = cat;
          renderGrid();
        };
        catsEl.appendChild(b);
      });

      activeCat = cats[0];
      renderGrid();
    })
    .catch(function(e) {
      document.getElementById('grid').innerHTML = '<div class="msg">Error: ' + e.message + '</div>';
    });
});

function renderGrid() {
  var grid   = document.getElementById('grid');
  var models = allModels.filter(function(m) { return m.cat === activeCat; });
  grid.innerHTML = '';

  models.forEach(function(m) {
    var wrap  = document.createElement('div');
    wrap.className = 'card-wrap';

    var card  = document.createElement('div');
    card.className = 'card' + (selected && selected.base_name === m.base_name ? ' selected' : '');

    var chk   = document.createElement('div');
    chk.className   = 'check';
    chk.textContent = '✓';

    var img   = document.createElement('img');
    img.src   = m.url;
    img.alt   = m.label;
    img.onerror = function() { this.style.background = '#e5e7eb'; };

    var lbl   = document.createElement('div');
    lbl.className   = 'card-label';
    lbl.textContent = m.label;

    card.appendChild(chk);
    card.appendChild(img);
    card.appendChild(lbl);
    wrap.appendChild(card);
    grid.appendChild(wrap);

    card.onclick = function() {
      selected = m;
      document.querySelectorAll('.card').forEach(function(c) { c.classList.remove('selected'); });
      card.classList.add('selected');
      document.getElementById('sel-thumb').src         = m.url;
      document.getElementById('sel-name').textContent  = m.label;
      document.getElementById('sel-cat').textContent   = m.cat.replace(/_/g, ' ');
      document.getElementById('sel-bar').classList.add('show');
    };
  });

  if (!models.length) {
    grid.innerHTML = '<div class="msg">No models in this category.</div>';
  }
}

function confirmSelection() {
  if (!selected) return;
  // Pass base_name so parent can build all 6 pose filenames
  var payload = {
    type:      'model_selected',
    model: {
      cat:       selected.cat,
      file:      selected.file,       // pose_p1 file
      base_name: selected.base_name,  // e.g. female_casual_af_c1
      label:     selected.label,
      url:       selected.url,
    }
  };
  if (window.opener) {
    window.opener.postMessage(payload, '*');
    window.close();
  } else if (window.parent !== window) {
    window.parent.postMessage(payload, '*');
  }
}
</script>
</body>
</html>
