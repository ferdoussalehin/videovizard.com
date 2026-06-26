<?php
include 'functionlib.php';
include 'dbconnect.php';
session_start();
$_SESSION['start'] = time();
$_SESSION['expire'] = $_SESSION['start'] + (15 * 60);

$topicname = "lowerbloodpressure";
?>
<!DOCTYPE html>
<html lang="en">

<?include 'head_section.php';?>

<body class="bg-gray-50 font-sans">

<!-- Header -->
<header class="bg-white shadow-md">
  <div class="max-w-5xl mx-auto px-6 py-4 flex justify-between items-center">
    <a href="index.php">
      <h1 class="text-xl font-bold text-indigo-600">Feel Better Now <br> with Inam Alvi</h1>
    </a>
    <a href="page_session_request.php?topic=<?=$topicname;?>" class="bg-indigo-600 text-white px-4 py-2 rounded-lg shadow hover:bg-indigo-700">Book Free Session</a>
  </div>
</header>

<!-- HERO -->
<section class="bg-indigo-50 py-16">
  <div class="max-w-6xl mx-auto px-6 grid md:grid-cols-2 gap-8 items-center">
    <div class="flex justify-center"> 
      <img src="images/lowerbloodpressure.jpg" alt="Lower Blood Pressure Naturally" class="rounded-xl shadow-lg w-full max-w-md">
    </div>
    <div class="text-center md:text-left">
      <h2 class="text-3xl font-bold text-gray-800 mb-4">
        Lower Blood Pressure Naturally with Hypnotherapy
      </h2>
      <p class="text-lg text-gray-600 mb-6">
        Hypnotherapy helps reduce stress, promote relaxation, and support healthier blood pressure levels.
      </p>
      <a href="page_session_request.php?topic=<?=$topicname;?>" class="bg-indigo-600 text-white px-6 py-3 rounded-xl shadow hover:bg-indigo-700">Book Your Free Session</a>
    </div>
  </div>
</section>

<?include 'aboutme_section.php';?>

<!-- How Hypnotherapy Helps -->
<section class="py-16 bg-white">
  <div class="max-w-5xl mx-auto px-6 text-left">
    <h3 class="text-2xl font-bold text-gray-800 mb-6">How Hypnotherapy Helps Lower Blood Pressure</h3>
    <p class="text-gray-600 mb-4">
      High blood pressure is often influenced by stress, tension, and lifestyle habits. Hypnotherapy calms the nervous system, reduces stress hormones, and encourages healthy lifestyle behaviors.
    </p>
    <p class="text-gray-600 mb-4">
      Over time, you can naturally support healthier blood pressure levels while improving relaxation, focus, and well-being.
    </p>
  </div>
</section>

<!-- Benefits -->
<section class="py-16 bg-white">
  <div class="max-w-5xl mx-auto px-6 grid md:grid-cols-3 gap-8 text-center">
    <div class="bg-white p-6 rounded-2xl shadow">
      <h3 class="text-xl font-semibold text-indigo-600 mb-4">Reduce Stress</h3>
      <p class="text-gray-600">Calm your mind and body to lower tension-related spikes.</p>
    </div>
    <div class="bg-white p-6 rounded-2xl shadow">
      <h3 class="text-xl font-semibold text-indigo-600 mb-4">Promote Relaxation</h3>
      <p class="text-gray-600">Learn deep relaxation techniques for daily life.</p>
    </div>
    <div class="bg-white p-6 rounded-2xl shadow">
      <h3 class="text-xl font-semibold text-indigo-600 mb-4">Support Healthy Habits</h3>
      <p class="text-gray-600">Reinforce routines that contribute to better blood pressure control.</p>
    </div>
  </div>
</section>

<!-- Testimonial -->
<section class="bg-gray-100 py-16">
  <div class="max-w-4xl mx-auto px-6 text-center">
    <h3 class="text-2xl font-bold text-gray-800 mb-8">What Clients Are Saying</h3>
    <blockquote class="bg-white p-6 rounded-2xl shadow">
      <span class="text-yellow-400 text-xl">★★★★★</span>
      <p class="text-gray-700 italic mt-2">“Hypnotherapy helped me stay calm and relaxed. Over time, my blood pressure improved naturally without added medication.”</p>
      <p class="mt-3 font-semibold text-indigo-600">— James M.</p>
    </blockquote>
  </div>
</section>

<!-- Call to Action -->
<section id="book" class="py-16 bg-white text-center">
  <div style="max-width: 600px; margin: 0 auto;">
    <h3 style="font-size: 2rem; font-weight: 700; color: #111827; margin-bottom: 16px;">Ready to Support Healthy Blood Pressure?</h3>
    <p style="color: #6B7280; margin-bottom: 24px;">Book your free hypnotherapy session today and start your journey toward calm, relaxation, and better health.</p>
    <a href="page_session_request.php?topic=<?=$topicname;?>" class="cta-button" style="display: inline-block; padding: 12px 32px; background-color: #4F46E5; color: #fff; border-radius: 12px;">Book Free Session</a>
  </div>
</section>

<?include 'footer_section.php';?>

</body>
</html>
