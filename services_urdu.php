
<?// ÿ
include 'functionlib.php';
include 'dbconnect.php';  


$id = $_GET['id'];

if ($id <> '')
{
	$rec = get_therapy_contents($id);
	$contents = $rec['contents_urdu'];
	$displayflag = 1;
	
}
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
  <body>
		  <?
    include 'header_urdu.php';  
   ?>
	
   
    <section class="hero-wrap hero-wrap-2" style="background-image: url('images/bg_2.jpg');" data-stellar-background-ratio="0.5">
      <div class="overlay"></div>
      <div class="container">
        <div class="row no-gutters slider-text align-items-end">
          <div class="col-md-9 ftco-animate pb-5">
          	<p class="breadcrumbs mb-2"><span class="mr-2"><a href="index.html">Home <i class="ion-ios-arrow-forward"></i></a></span> <span>Services <i class="ion-ios-arrow-forward"></i></span></p>
            <h1 class="mb-0 bread">Services</h1>
          </div>
        </div>
      </div>
    </section>
<section class="intro py-5">
			<div class="container">
				<div class="row">
					<div class="col-md-4">
						<div class="intro-box w-100 d-flex">
							<div class="icon d-flex align-items-center justify-content-center">
								<span class="fa fa-phone"></span>
							</div>
							<div class="text pl-3">
								<h4 class="mb-0">Whatsapp: +1 647 9854626</h4>
								<span> Georgetown, Ontario, Canada</span>
							</div>
						</div>
					</div>
					<div class="col-md-4">
						<div class="intro-box w-100 d-flex">
							<div class="icon d-flex align-items-center justify-content-center">
								<span class="fa fa-clock-o"></span>
							</div>
							<div class="text pl-3">
								<h4 class="mb-0">Consulting hours</h4>
								<span>Mon - Friday 11:00 AM - 8:00 PM / Saturday & Sundays closed</span>
							</div>
						</div>
					</div>
					<div class="col-md-4">
						<div class="intro-box w-100">
							<p class="mb-0"><a href="https://sulaimania.org/inaamalvi/login.php" class="btn btn-primary">Make an Appointment</a></p>
						</div>
					</div>
				</div>
			</div>	
		</section>
		
		
			
			<?
			
			if ($displayflag ==1)
			{
				?>
				<div class="container">
				<section class="ftco-section >
				 <div class="col-md-12 heading-section text-center ftco-animate">
				<? echo $contents;
					echo "<hr>";
				?>
				</div>
				</section>
				</div>
				
				
		<?	} ?>
	
	
	

	
			
			
	<div class="container">		
    <section class="ftco-section >
		
    		<div class="row justify-content-center pb-5 mb-3">
          <div class="col-md-12 heading-section text-center ftco-animate">
            <h2>ہائپنوتھراپی ۱۴۵ طریقوں سے  آپ کی مدد کرسکتی ہے
</h2>
			
			</div>
			
			
			
			<table class="table mb-0 table-striped">
              
               <tbody>

			   
			   
			   <?
			   //where patient_status= '' create_date ASC
				$query = "Select * From    eh_hypnotherapy    order by title_english ";
			//	echo "query ".$query;
				if(!mysqli_query($conn,$query))
				{

					//echo("3 description: in eh_blogs " . mysqli_error($conn)."    ");die;
					$error = mysqli_error($conn);
					echo "error".$error;



				}
				else
				{
					  $run_appquery  = mysqli_query($conn, $query);
					  $totalbooks    = mysqli_num_rows($run_appquery);
					   //echo "rec cound is ".$totalbooks;die;


				}
				while($row_query =  mysqli_fetch_assoc($run_appquery))
               { 
		   
		   
					
					if ($row_query['title_urdu'] ==  "")
					{
						
						$title = $row_query['title_english'];
					}
					else{
						$title = $row_query['title_urdu'];
					}
					
					$contents = $row_query['contents_urdu'];
					//echo "contents".$contents;
					$pieces[1]= substr($pieces[1],0,1);
					$name = $pieces[0]." ".$pieces[1]."..."; 
		   //echo "</br>name".$name;
					$id = $row_query['id'];
					$client_id = $row_query['client_id'];
					$client_image = $row_query['client_image'];
					//echo "</br>".$row_query['id'];
					$count++;
					
					echo '<tr>';
					echo '<td align ="left" ><b>';
					echo $count;
					
					echo '</b></td>';
					echo '<td align ="left" >';
					if ($contents == "")
					{
						echo $title;
					}
					else{
						?>
						<a href="?id=<?=$id;?>"><?=$title;?></a>
					<?
						
					}
					
					echo '</td>';
					
					echo '</tr>';
					
			   }
       ?>
		
			   
			   
			   
			   </tbody>
			   </table>
			
			
           
          </div>
       
    	</section>
		<div class="container">
    <<section class="ftco-section bg-light">
    	
    		<div class="row justify-content-center pb-5 mb-3">
          <div class="col-md-7 heading-section text-center ftco-animate">
          
			<h2>میں آپ کے لئے کیا کرسکتا ہوں</h2>
           
          </div>
        </div>
    		<div class="row">
          <div class="col-md-3 d-flex services align-self-stretch px-4 ftco-animate">
            <div class="d-block text-center">
              <div class="icon d-flex justify-content-center align-items-center">
            		<span class="flaticon-goal"></span>
              </div>
              <div class="media-body p-2 mt-3">
                <h3 class="heading">انزائیٹی اور ڈیپریشن</h3>
                
				<p>
				ہائپنو تھراپی سے منفی خیالات کو تبدیل کریں اور گہرا سکون حاصل کریں۔ مستقل اطمینان، کنٹرول، اور ذہنی توازن کا تجربہ کریں۔ آج ہی اپنے سکون کے سفر کا آغاز کریں!
				</p>
			  </div>
            </div>      
          </div>
          <div class="col-md-3 d-flex services align-self-stretch px-4 ftco-animate">
            <div class="d-block text-center">
              <div class="icon d-flex justify-content-center align-items-center">
            		<span class="flaticon-stress"></span>
              </div>
              <div class="media-body p-2 mt-3">
                <h3 class="heading">خوف ، ڈر اور وہم </h3>
               
			  <p>
			  ہائپنو تھراپی سے گہرے خوف اور فوبیاز کو ختم کریں، بے چینی کو سکون اور اعتماد میں بدلیں۔ خوف سے نجات پا کر اپنی زندگی پر دوبارہ قابو پائیں!
			  </p>
			  
			  </div>
            </div>    
          </div>
          <div class="col-md-3 d-flex services align-self-stretch px-4 ftco-animate">
            <div class="d-block text-center">
              <div class="icon d-flex justify-content-center align-items-center">
            		<span class="flaticon-crm"></span>
              </div>
              <div class="media-body p-2 mt-3">
                <h3 class="heading">خود اعتمادی اور کامیابی</h3>
                <
<p>
ہائپنو تھراپی سے خود اعتمادی بڑھائیں اور شک و شبہات کو دور کریں۔ اپنے لاشعور کو مثبت سوچ، کامیابی اور یقین سے ہم آہنگ کریں۔ آج ہی خود کو بہترین بنائیں!
</p>             


			 </div>
            </div>      
          </div>
          <div class="col-md-3 d-flex services align-self-stretch px-4 ftco-animate">
            <div class="d-block text-center">
              <div class="icon d-flex justify-content-center align-items-center">
            		<span class="flaticon-marriage"></span>
              </div>
              <div class="media-body p-2 mt-3">
                <h3 class="heading">بہتر کمیونیکیشن</h3>
               
				<p>
				این ایل پی تکنیکس سے اپنی بات چیت کی مہارت بہتر بنائیں۔ اعتماد، وضاحت اور اثر و رسوخ حاصل کریں اور لاشعوری پیٹرنز کو سمجھ کر مؤثر انداز میں گفتگو کریں۔ مضبوط تعلقات قائم کریں اور مؤثر انداز میں اظہار کریں!
				</p>
              </div>
            </div>      
          </div>
        </div>
    	</div>
    </section>
</div>
   	
  
		
		
<?
   include 'footer.php';  
   ?>
   
    
  

  <!-- loader -->
  <div id="ftco-loader" class="show fullscreen"><svg class="circular" width="48px" height="48px"><circle class="path-bg" cx="24" cy="24" r="22" fill="none" stroke-width="4" stroke="#eeeeee"/><circle class="path" cx="24" cy="24" r="22" fill="none" stroke-width="4" stroke-miterlimit="10" stroke="#F96D00"/></svg></div>


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
  <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBVWaKrjvy3MaE7SQ74_uJiULgl1JY0H2s&sensor=false"></script>
  <script src="js/google-map.js"></script>
  <script src="js/main.js"></script>

    
  </body>
</html>