<?php
// show errors for debugging (remove or set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// includes
include 'functionlib.php';
include 'dbconnect.php';

// safe user_id fallback (if you set it in session)
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// inputs
$todaysdate = date("Y-m-d H:i:s");
$category = isset($_GET['category']) ? cleanstring($_GET['category']) : '';

// helper to safely output
function e($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Zuzoo - Virtual Hypnotherapist</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700,800,900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
  <link rel="stylesheet" href="css/animate.css">
  <link rel="stylesheet" href="css/owl.carousel.min.css">
  <link rel="stylesheet" href="css/owl.theme.default.min.css">
  <link rel="stylesheet" href="css/magnific-popup.css">
  <link rel="stylesheet" href="css/flaticon.css">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<script>
/* Reduce the vertical spacing between sections */
/* Reduce space between stacked ftco-sections */
.ftco-section {
  padding-top: 4em !important;
  padding-bottom: 2em !important;
}

/* Remove double spacing when two sections are consecutive */
.ftco-section + .ftco-section {
  margin-top: -2em !important;
}

/* Optional: tighten heading spacing */
.ftco-section .heading-section {
  margin-bottom: 1.5em !important;
}
</script>

<?php include 'header.php'; ?>

<section class="hero-wrap hero-wrap-2" style="background-image: url('images/bg_2.jpg');" data-stellar-background-ratio="0.5">
  <div class="overlay"></div>
  <div class="container">
    <div class="row no-gutters slider-text align-items-end">
      <div class="col-md-9 ftco-animate pb-5">
        <p class="breadcrumbs mb-2">
          <span class="mr-2"><a href="index.html">Home <i class="ion-ios-arrow-forward"></i></a></span>
          <span>Blog <i class="ion-ios-arrow-forward"></i></span>
        </p>
        <h1 class="mb-0 bread">Videos</h1>
      </div>
    </div>
  </div>
</section>



<!-- ---------- Mental Health (eh_hypnotherapy) ---------- -->
<section class="ftco-section">
  <div class="row justify-content-center pb-5 mb-3">
    <div class="col-md-7 heading-section text-center ftco-animate">
      <h1>Mental Health</h1>
    </div>
  </div>

  <div class="container">
    <div class="row">
<?php
$query = "SELECT * FROM eh_hypnotherapy WHERE video_en <> '' ORDER BY title_english, title_english ASC";
$res = mysqli_query($conn, $query);
if (!$res) {
  echo "<div class='col-12'><div class='alert alert-danger'>Query error (eh_hypnotherapy): " . mysqli_error($conn) . "</div></div>";
} else {
  while ($row = mysqli_fetch_assoc($res)) {
    $id = (int)$row['id'];
    $topicname = $row['topicname'];
    $pagelink = $row['pagelink'];
    $image_name = $row['image_name'];
    $title_english = $row['title_english'];
    $video_link = $row['video_link'] ?? '';
    $blog_author = $row['blog_author'] ?? 'Admin';
    $blog_seqno = $row['blog_seqno'] ?? '';

    $programname = !empty($topicname) ? $pagelink . '?topic=' . urlencode($topicname) : 'https://sulaimania.org/inaamalvi/login.php';
    $program_url = 'https://inaamalvi.com/' . $programname . '&id=' . $id . '&user_id=' . $user_id;
?>
      <div class="col-md-4 mb-4 d-flex align-items-stretch ftco-animate">
        <div class="blog-entry shadow rounded overflow-hidden w-100">

          <!-- Thumbnail -->
          <a href="<?= htmlspecialchars($program_url) ?>" class="block-20" 
             style="background-image: url('images/<?= htmlspecialchars($image_name) ?>'); height:220px; background-size:cover; background-position:center;">
          </a>

          <!-- Content -->
          <div class="text p-3 text-center">
            <h3 class="heading mb-2">
              <a href="<?= htmlspecialchars($program_url) ?>"><?= htmlspecialchars($title_english) ?></a>
            </h3>

            <div class="meta mb-2 text-muted small">
              <span><i class="fa fa-user"></i> <?= htmlspecialchars($blog_author) ?></span> |
              <span><i class="fa fa-comment"></i> <?= htmlspecialchars($blog_seqno) ?></span>
            </div>

           
            <p class="small text-muted mt-1" style="word-break: break-all;"><?= htmlspecialchars($video_link) ?></p>
          </div>
        </div>
      </div>
<?php
  } // end while
} // end else
?>
    </div>
  </div>
</section>

<script>
function copyLink(link) {
  navigator.clipboard.writeText(link)
    .then(() => alert("✅ Video link copied!"))
    .catch(() => alert("❌ Could not copy link."));
}
</script>


    </div>
  </div>
</section>

<script>
function copyLink(link) {
  navigator.clipboard.writeText(link)
    .then(() => alert("✅ Video link copied to clipboard!"))
    .catch(() => alert("❌ Failed to copy link."));
}
</script>





  <script>
  function copyLink(link) {
    navigator.clipboard.writeText(link)
      .then(() => alert("✅ Video link copied!"))
      .catch(() => alert("❌ Could not copy link."));
  }
  </script>





<!-- Footer and scripts -->
<?php include 'footer.php'; ?>

<!-- single copyLink function (used by buttons above) -->
<script>
function copyLink(link) {
  if (!link) {
    alert("No link available to copy.");
    return;
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(link).then(function() {
      alert("✅ Video link copied to clipboard!");
    }).catch(function(err) {
      console.error("Copy failed:", err);
      alert("❌ Failed to copy link. Try selecting/copying manually.");
    });
  } else {
    // fallback
    var textarea = document.createElement('textarea');
    textarea.value = link;
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      alert("✅ Video link copied to clipboard!");
    } catch (e) {
      alert("❌ Failed to copy link. Try selecting/copying manually.");
    }
    document.body.removeChild(textarea);
  }
}
</script>

<script src="js/jquery.min.js"></script>
<script src="js/jquery-migrate-3.0.1.min.js"></script>
<script src="js/popper.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.easing.1.3.js"></script>
<script src="js/jquery.waypoints.min.js"></script>
<script src="js/jquery.stellar.min.js"></script>
<script src="js/jquery.animateNumber.min.js"></script>
<script src="js/owl.carousel.min.js"></script>
<script src="js/jquery.magnific-popup.min.js"></script>
<script src="js/scrollax.min.js"></script>
<!-- Google Maps key (if used) -->
<!-- <script src="https://maps.googleapis.com/maps/api/js?key=YOUR_KEY&sensor=false"></script> -->
<script src="js/google-map.js"></script>
<script src="js/main.js"></script>

</body>
</html>
