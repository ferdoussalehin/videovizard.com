const axios = require("axios");
const fs = require("fs");
const path = require("path");
const ffmpeg = require("fluent-ffmpeg");

// 🔑 YOUR GOOGLE API KEY
const API_KEY = "YOUR_GOOGLE_API_KEY";

// 📁 Folders
const OUTPUT_DIR = path.join(__dirname, "sample_videos");
const IMAGE_DIR = path.join(__dirname, "temp_images");

if (!fs.existsSync(OUTPUT_DIR)) fs.mkdirSync(OUTPUT_DIR);
if (!fs.existsSync(IMAGE_DIR)) fs.mkdirSync(IMAGE_DIR);

// 🎯 Step 1: Generate script from Gemini
async function generateScript(prompt) {
  const res = await axios.post(
    `https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro:generateContent?key=${API_KEY}`,
    {
      contents: [
        {
          parts: [
            {
              text: `Create a short video broken into 5 scenes.
              Each scene should have:
              - short visual description
              - narration text
              
              Topic: ${prompt}`
            }
          ]
        }
      ]
    }
  );

  return res.data.candidates[0].content.parts[0].text;
}

// 🧠 Step 2: Extract scenes (basic split)
function extractScenes(text) {
  return text.split("\n").filter(line => line.trim().length > 20).slice(0, 5);
}

// 🎨 Step 3: Create placeholder images (you can replace with real AI later)
function createImages(scenes) {
  const images = [];

  scenes.forEach((scene, i) => {
    const file = path.join(IMAGE_DIR, `scene_${i}.png`);

    // Simple colored placeholder using SVG → PNG
    const svg = `
      <svg width="1280" height="720">
        <rect width="100%" height="100%" fill="#1e293b"/>
        <text x="50%" y="50%" font-size="30" fill="white" text-anchor="middle">
          Scene ${i + 1}
        </text>
      </svg>
    `;

    fs.writeFileSync(file, svg);
    images.push(file);
  });

  return images;
}

// 🎬 Step 4: Create video with FFmpeg
function createVideo(images) {
  return new Promise((resolve, reject) => {
    const output = path.join(OUTPUT_DIR, `video_${Date.now()}.mp4`);

    const command = ffmpeg();

    images.forEach(img => {
      command.input(img).inputOptions(["-loop 1", "-t 3"]);
    });

    command
      .outputOptions([
        "-r 30",
        "-pix_fmt yuv420p"
      ])
      .on("end", () => {
        console.log("✅ Video created:", output);
        resolve(output);
      })
      .on("error", reject)
      .save(output);
  });
}

// 🚀 Main function
async function run(prompt) {
  try {
    console.log("⏳ Generating script...");
    const script = await generateScript(prompt);

    console.log("🧠 Extracting scenes...");
    const scenes = extractScenes(script);

    console.log("🎨 Creating images...");
    const images = createImages(scenes);

    console.log("🎬 Rendering video...");
    await createVideo(images);

    console.log("🎉 Done!");

  } catch (err) {
    console.error("❌ Error:", err.response?.data || err.message);
  }
}

// 🎯 CLI
const prompt = process.argv.slice(2).join(" ");

if (!prompt) {
  console.log("❗ Usage:");
  console.log('node video_pipeline.js "Your video idea here"');
  process.exit(1);
}

run(prompt);