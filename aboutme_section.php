
  <!-- About Me Section -->
  <section id="about-me" class="py-16 bg-gray-50 text-center"> 
    <div style="max-width: 700px; margin: 0 auto;">
      <img src="images/my_image_original.jpeg" alt="Inam Alvi" width="150" height="100" style="display: block; margin: 0 auto 16px auto; border-radius: 50%;">
      <h3 style="font-size: 2rem; font-weight: 700; color: #111827; margin-bottom: 16px;">Meet Inam Alvi</h3>
     <!-- Meet Inam Alvi Section -->
<section class="py-16 bg-white" x-data="{ tab: '<?= $language; ?>' }">
  <div class="max-w-5xl mx-auto px-6 text-center md:text-left space-y-6">

    <!-- Language Tabs -->
    <div class="flex justify-center space-x-2 mb-6 flex-wrap">
      <?php foreach($meet_inam_title as $lang_code => $title): ?>
        <button @click="tab='<?= $lang_code ?>'" 
                :class="tab==='<?= $lang_code ?>' ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700'" 
                class="px-4 py-2 rounded-xl">
          <?= $title ?>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- Tab Content -->
    <div class="space-y-6">
      <?php foreach($meet_inam_bio as $lang_code => $bio): ?>
        <div x-show="tab==='<?= $lang_code ?>'" 
             :dir="'<?= ($lang_code === 'ur' || $lang_code === 'ar') ? 'rtl' : 'ltr' ?>'" 
             class="space-y-4">
          <h2 class="text-3xl font-bold text-gray-800"><?= $meet_inam_title[$lang_code] ?></h2>
          <div class="text-gray-600 text-lg">
            <?= $bio ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>


	<div style="margin-top: 24px;">
        <a href="page_session_request.php?topic=<?=$topicname;?>" class="cta-button" style="display: inline-block; padding: 12px 32px; background-color: #4F46E5; color: #fff; border-radius: 12px; font-weight: 600; text-decoration: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Book Your Free Session</a>
      </div>
<div class="container mt-4">
  <div class="d-flex justify-content-center">
    <table class="table mb-0 table-striped" style="width:auto; margin:0 auto;">
      <?php
      $res = mysqli_query($conn,"SELECT * FROM eh_certifications WHERE status='active'");
      $cnt = 0;
      echo '<tr>';
      while ($row = mysqli_fetch_assoc($res)):
          $title   = htmlspecialchars($row['title']);
          $thumb   = 'https://www.inaamalvi.com/certificates/'.urlencode($row['image_id']);
          $modalId = 'certModal'.$row['id'];
      ?>
          <td>
            <img src="<?=$thumb?>" alt="<?=$title?>"
                 width="120" height="100" style="cursor:pointer"
                 data-toggle="modal" data-target="#<?=$modalId?>">
          </td>
      <?php
          $cnt++;
          if ($cnt == 3) { echo '</tr><tr>'; $cnt = 0; }
      endwhile;
      echo '</tr>';
      ?>
    </table>
  </div>
</div>

<!-- ====== MODALS – outside, hidden until clicked ====== -->
<?php
mysqli_data_seek($res,0);
while ($row = mysqli_fetch_assoc($res)):
    $title   = htmlspecialchars($row['title']);
    $full    = 'https://www.inaamalvi.com/certificates/'.urlencode($row['image_id']);
    $modalId = 'certModal'.$row['id'];
?>
    <div class="modal fade" id="<?=$modalId?>" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><?=$title?></h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body text-center p-0">
            <img src="<?=$full?>" class="img-fluid" alt="<?=$title?>">
          </div>
        </div>
      </div>
    </div>
<?php endwhile; ?>
</section>

