<!DOCTYPE html>
<html>
<head>
<title>Text-to-Speech Generator</title>
<style>
body {
  font-family: Arial;
  padding: 40px;
  background: #f5f5f5;
}
textarea {
  width: 400px;
  height: 120px;
  font-size: 16px;
  padding: 10px;
}
select, button {
  padding: 10px 20px;
  font-size: 16px;
  margin-top: 10px;
  cursor: pointer;
}
audio {
  margin-top: 20px;
  width: 400px;
}
</style>
</head>
<body>

<h2>Text to Speech Generator</h2>

<textarea id="text">Hello my name is Inam</textarea>
<br><br>

<label for="voice">Select Voice:</label>
<select id="voice">
  <option value="alloy">Alloy</option>
  <option value="ash">Ash</option>
  <option value="ballad">Ballad</option>
  <option value="coral">Coral</option>
  <option value="echo">Echo</option>
  <option value="fable">Fable</option>
  <option value="onyx">Onyx</option>
  <option value="nova">Nova</option>
  <option value="sage">Sage</option>
  <option value="shimmer">Shimmer</option>
  <option value="verse">Verse</option>
</select>
<br><br>

<button onclick="generate()">Generate Voice</button>
<br><br>

<audio id="player" controls></audio>
<br><br>

<a id="download" download>Download Audio</a>

<script>
function generate() {
  const text = document.getElementById("text").value;
  const voice = document.getElementById("voice").value;

  const formData = new FormData();
  formData.append("text", text);
  formData.append("voice", voice);

  fetch("generate_audio.php", {
    method: "POST",
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if(data.audio){
      const player = document.getElementById("player");
      player.src = data.audio + "?t=" + new Date().getTime();
      player.load();
      player.play();

      document.getElementById("download").href = data.audio;
    } else {
      console.log(data);
      alert("Error generating audio: " + JSON.stringify(data));
    }
  });
}
</script>

</body>
</html>