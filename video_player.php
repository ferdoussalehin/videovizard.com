<?php
include 'functionlib.php';
include 'dbconnect.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
       <title>Hypnotherapy and Life Coaching</title>
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

<body class="bg-gray-50 text-gray-800">

  <!-- Header -->
  <header class="bg-white shadow">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
      <h1 class="text-xl font-semibold text-gray-900">Hypnotherapy and Life Coaching</h1>
      <nav>
        <a href="#" class="text-gray-600 hover:text-indigo-600 px-3">Home</a>
        <a href="#" class="text-gray-600 hover:text-indigo-600 px-3">About</a>
        <a href="#" class="text-gray-600 hover:text-indigo-600 px-3">Contact</a>
      </nav>
    </div>
  </header>



  <!-- ✅ Main Content -->
  <main class="container mx-auto px-4 py-12">
   
								   <h2 class="text-3xl font-bold mb-6">Welcome to Your Healing Journey</h2>
      <p class="text-lg leading-relaxed mb-8">
        Discover the power of hypnotherapy and life coaching to transform your mindset,
        heal from within, and unlock your highest potential. Watch, learn, and experience peace.
      </p>

      <!-- ✅ 9:16 Video Below Paragraph -->
    <div style="
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 80vh; /* centers vertically within main area */
">
  <div style="
    position: relative;
    width: 100%;
    max-width: 360px; /* you can make it 420px or 480px if you want larger */
    aspect-ratio: 9 / 16;
    overflow: hidden;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    background-color: black;
  ">
    <video 
      controls 
      playsinline 
      style="
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: 16px;
      ">
      <source src="video_reels/hypnotherapy_experience.mp4" type="video/mp4">
      Your browser does not support the video tag.
    </video>
  </div>
</div>



    </div>
  </main>

  <!-- Footer -->
  <footer class="bg-gray-900 text-gray-300 py-6 text-center">
    <p>&copy; <?= date("Y") ?> Hypnotherapy and Life Coaching. All rights reserved.</p>
  </footer>

</body>
</html>
