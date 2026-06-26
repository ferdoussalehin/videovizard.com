<?php
$videos = [
    "published_videos/101673-video-720.mp4",
    "published_videos/101460-video-720.mp4",
    "published_videos/101546-video-1080.mp4",
    "published_videos/10048550-uhd_2160_4096_25fps.mp4",
    "published_videos/10059770-uhd_2160_4096_25fps.mp4",
    "published_videos/10131849-uhd_2160_4096_25fps.mp4"
];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Vertical Video Playlist</title>

<style>
html,body{
    margin:0;
    height:100%;
    background:#000;
    display:flex;
    justify-content:center;
    align-items:center;
}

/* 🔥 FIXED 9:16 CONTAINER (prevents box + resize flash) */
#wrap{
    position:relative;
    width:360px;
    height:640px;
    background:#000;
    overflow:hidden;
}

/* 🔥 FORCE FULL COVER ALWAYS */
video{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;

    object-fit:cover;
    background:#000;

    display:block; /* IMPORTANT: removes “box flash” */

    opacity:1;
}
</style>
</head>
<body>

<div id="wrap">
    <video id="player" muted autoplay playsinline></video>
</div>

<script>

const playlist = <?php echo json_encode($videos); ?>;

const player = document.getElementById("player");

let index = 0;

// preload next video (reduces gap)
function preload(i){
    const v = document.createElement("video");
    v.src = playlist[i];
    v.preload = "auto";
}

function playVideo(i){

    if (i >= playlist.length) return;

    console.log("Playing:", playlist[i]);

    // preload next
    preload((i + 1) % playlist.length);

    // IMPORTANT: reset BEFORE changing source
    player.pause();
    player.removeAttribute("src");
    player.load();

    player.src = playlist[i];

    // try immediate play
    const p = player.play();

    if (p !== undefined) {
        p.catch(err => {
            console.log("Autoplay blocked:", err);
        });
    }
}

// switch video
player.addEventListener("ended", () => {
    index++;
    if (index < playlist.length) {
        playVideo(index);
    }
});

// START
playVideo(index);

</script>

</body>
</html>