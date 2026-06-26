<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Video Wizard</title>

<style>
body { font-family: Arial; background:#f5f5f5; padding:20px; }
.container { max-width:700px; margin:auto; background:#fff; padding:25px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.1); }
.progress-bar { background:#eee; height:12px; border-radius:6px; overflow:hidden; margin-bottom:20px; }
.progress { background:#4CAF50; height:12px; width:0%; transition:.3s; }
.options { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
.option-btn { padding:10px 14px; border:1px solid #4CAF50; border-radius:6px; background:#fff; cursor:pointer; }
.option-btn.selected { background:#4CAF50; color:#fff; }
input { width:100%; padding:10px; margin-top:10px; border-radius:6px; border:1px solid #ccc; }
button { margin-top:15px; padding:12px; border:none; background:#4CAF50; color:#fff; border-radius:6px; cursor:pointer; }
button:disabled { background:#ccc; }
.preview { background:#f0f0f0; padding:10px; margin-top:20px; border-radius:6px; font-family:monospace; }
.loader { margin-top:10px; }
</style>
</head>

<body>

<div class="container">
<h2>🎬 AI Video Wizard</h2>

<div class="progress-bar">
<div class="progress" id="progress"></div>
</div>

<div id="step"></div>
<button id="nextBtn" disabled>Next</button>

<div class="preview" id="preview"></div>

</div>

<script>
let currentStep = 0;
const answers = {};

const steps = [
  "niche",
  "topic",
  "title",
  "objective",
  "audience",
  "angle",
  "duration",
  "cta"
];

const stepContainer = document.getElementById("step");
const nextBtn = document.getElementById("nextBtn");
const preview = document.getElementById("preview");

function updateProgress() {
  document.getElementById("progress").style.width = (currentStep / steps.length * 100) + "%";
}

function updatePreview() {
  preview.textContent = JSON.stringify(answers, null, 2);
}

function setNext(enabled=true) {
  nextBtn.disabled = !enabled;
}

function renderOptions(options, key) {
  return `<div class="options">
    ${options.map(o => `<div class="option-btn" data-val="${o}">${o}</div>`).join("")}
  </div>
  <input type="text" id="customInput" placeholder="Or type your own...">`;
}

function attachOptionEvents(key) {
  document.querySelectorAll(".option-btn").forEach(btn => {
    btn.onclick = () => {
      document.querySelectorAll(".option-btn").forEach(b=>b.classList.remove("selected"));
      btn.classList.add("selected");
      answers[key] = btn.dataset.val;
      setNext(true);
      updatePreview();
    };
  });

  const input = document.getElementById("customInput");
  if(input){
    input.oninput = () => {
      answers[key] = input.value;
      setNext(input.value.trim().length > 2);
      updatePreview();
    };
  }
}

/* ---------------- AI CALLS ---------------- */

async function fetchAI(url, data) {
  const res = await fetch(url, {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify(data)
  });
  return await res.json();
}

/* ---------------- STEP RENDER ---------------- */

async function renderStep() {

  updateProgress();
  setNext(false);

  if(steps[currentStep] === "niche") {
    stepContainer.innerHTML = `
      <h3>Enter your niche / profession</h3>
      <input id="nicheInput" placeholder="e.g. Hypnotherapy, Real Estate">
    `;
    document.getElementById("nicheInput").oninput = e=>{
      answers.niche = e.target.value;
      setNext(e.target.value.length > 2);
      updatePreview();
    };
  }

  else if(steps[currentStep] === "topic") {
    stepContainer.innerHTML = `<h3>Generating topics...</h3><div class="loader">⏳ Please wait</div>`;
    
    const res = await fetchAI("generate_topics.php", { niche: answers.niche });

    const topics = res.topics || ["General","Tips","Guide"];

    stepContainer.innerHTML = `<h3>Select topic</h3>` + renderOptions(topics, "topic");

    attachOptionEvents("topic");
  }

  else if(steps[currentStep] === "title") {
    stepContainer.innerHTML = `<h3>Generating titles...</h3><div class="loader">⏳ Please wait</div>`;
    
    const res = await fetchAI("generate_titles.php", { niche: answers.niche, topic: answers.topic });

    const titles = res.titles || ["Simple Guide","Top Tips","Quick Fix"];

    stepContainer.innerHTML = `<h3>Select video idea</h3>` + renderOptions(titles, "title");

    attachOptionEvents("title");
  }

  else if(steps[currentStep] === "objective") {
    const opts = ["Educate","Inspire","Entertain","Inform"];
    stepContainer.innerHTML = `<h3>Select objective</h3>` + renderOptions(opts,"objective");
    attachOptionEvents("objective");
  }

  else if(steps[currentStep] === "audience") {
    const opts = ["Beginners","Professionals","General Public"];
    stepContainer.innerHTML = `<h3>Select audience</h3>` + renderOptions(opts,"audience");
    attachOptionEvents("audience");
  }

  else if(steps[currentStep] === "angle") {
    const opts = ["Quick Hacks","Step-by-Step","Common Mistakes","Secrets"];
    stepContainer.innerHTML = `<h3>Select hook/angle</h3>` + renderOptions(opts,"angle");
    attachOptionEvents("angle");
  }

  else if(steps[currentStep] === "duration") {
    const opts = ["15 sec","30 sec","60 sec"];
    stepContainer.innerHTML = `<h3>Select duration</h3>` + renderOptions(opts,"duration");
    attachOptionEvents("duration");
  }

  else if(steps[currentStep] === "cta") {
    const opts = ["Follow","Subscribe","Book Call","Visit Website"];
    stepContainer.innerHTML = `<h3>Select CTA</h3>` + renderOptions(opts,"cta");
    attachOptionEvents("cta");
  }

}

/* ---------------- NEXT BUTTON ---------------- */

nextBtn.onclick = ()=>{
  currentStep++;
  if(currentStep < steps.length){
    renderStep();
  } else {
    stepContainer.innerHTML = "<h3>✅ Done! Ready to generate video</h3>";
    nextBtn.style.display = "none";
  }
};

/* INIT */
renderStep();

</script>

</body>
</html>