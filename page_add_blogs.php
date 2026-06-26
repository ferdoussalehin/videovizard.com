<?php


//�
include 'functionlib.php';
include 'dbconnect.php';

//echo "came here";die;
$pagename = "login";
// ini_set('session.gc_maxlifetime', 60*60*4);
session_start();
//ini_set('session.gc_maxlifetime', 60*60*4);
$_SESSION['start'] = time();
$updatemsgtype ="";
$_SESSION['expire'] = $_SESSION['start'] + (15 * 60) ;

//echo " I am here ";die;

$updatemsg        = escape($_GET ['updatemsg']);
$updatemsgtype    = escape($_GET ['updatemsgtype']);
//echo "email id ".$email_id;die;

$user_id                  =cleanstring($_GET["user_id"]);
$action                   =cleanstring($_GET["action"]);
$patient_id               =cleanstring($_GET["patient_id"]);




//echo "user ".$user_id;die;
 $query = "Select * From  eh_patients where id ='".$user_id."' ";
    //echo "query ".$query;//die;
    $retval = mysqli_query( $conn, $query );

    $total = mysqli_num_rows($retval);
	//echo "total ".$total;die;
	if ($total == 0)
	{
			$user_id = '2';
			echo "user is ".$user_id;die;
			header("Location: index.php");

	}
	$applicantinfo = mysqli_fetch_assoc($retval);
	$user_type = $applicantinfo['user_type'];
//echo "user ".$user_type;//die;

if ($user_type == 'admin' || $user_type == "admin2")
{
   // $user_id = '2';
	

}
else
{
	echo "user....... is ".$user_type;die;
    header("Location: index.php");
}


$taskaction       =cleanstring($_GET["taskaction"]);


if(isset($_POST['show_patient']))
{ 
	$action = 'edit';
   //   echo "came to edit".$patient_id;die;
}
if ($action == 'edit')
{
 
		 //echo "came to edit";die;
		 
		 $todays_date = date('Y-m-d');
		
		 $id       =cleanstring($_GET["id"]);
		 $query = "Select * From  eh_hypnotherapy  where  id = '".$id."'  ";
		
		 // echo $query; die;
		 $res_data              = mysqli_query($conn,$query);
		 $count                 = mysqli_num_rows($res_data);
		 $data_row              = mysqli_fetch_array($res_data);
		 
		
		 $title_english              = $data_row['title_english'];
		 $title_urdu                 = $data_row['title_urdu'];
		 $contents_english           = $data_row['contents_english'];
		 $contents_urdu              = $data_row['contents_urdu'];
		 $script_english             = $data_row['script_english'];
		 $script_urdu                = $data_row['script_urdu'];
		 $video_english              = $data_row['video_english'];
		 $video_urdu                 = $data_row['video_urdu'];
		 $audio_english              = $data_row['audio_english'];
		 $audio_urdu                 = $data_row['audio_urdu'];

		//echo "title ".$title_english;die;


		
		 $editflag ="on";
		 
		 
		
		 
		 
 
}
if ($action == 'delete')
{

    $query = "delete   From  s_books  where  id = '".$bood_id."'  ";
    // echo $query; die;
    $res_data              = mysqli_query($conn,$query);
}

if(isset($_POST['generate_record']))
{
  
   $todays_date = date('Y-m-d');
   $query = "INSERT INTO  eh_patients (  `phoneno`,      `patient_name`, `status`,  `create_date` )
                             VALUES  ( '9999999',     'new patient', 'active',   '$todays_date' )";

    //echo "query:".$query;die;
    if(!mysqli_query($conn,$query))
    {
         echo("3 description: in s_subject " . mysqli_error($conn)."    ");
         $updatemsgtype = 99;
         $updatemsg = "Error in inserting the record";die;
 
    }
    else
    {
       $result_id = mysqli_insert_id($conn);
       $editflag ="off";
	   $insertflag= "1";


    }
}

if(isset($_POST['update_record']))
{
	$patient_id                            =cleanstring($_GET["patient_id"]);
	$user_id                               =cleanstring($_GET["user_id"]);
    update_record_msg();
      				
    header("Location: page_add_topics.php?user_id=$user_id");//student_select_subject.php?student_id=$student_id&grade=$grade&taskaction=new


}


function update_record_msg()
{
	include 'dbconnect.php';
	//echo "came to add  it is yes....image loading........... ".$nic_no;  die;
      $id                              =cleanstring($_GET["id"]);
     
    
	  $script_english                          = escape($_POST ['inam']);
      
      $title_english                        = escape($_POST ['title_english']);
	  $title_urdu                           = escape($_POST ['title_urdu']);
	  
	  $video_english                        = escape($_POST ['video_english']);
	  $video_urdu                           = escape($_POST ['video_urdu']);
	  
	  $audio_english                        = escape($_POST ['audio_english']);
	  $audio_urdu                           = escape($_POST ['audio_urdu']);
	  
	  $contents_english                     = escape($_POST ['contents_english']);
	  $contents_urdu                        = escape($_POST ['contents_urdu']);
	  
	 
	  $script_urdu                          = escape($_POST ['script_urdu']);
     
	  //echo "province ".$_POST ['inam'];die;
	  
	  $title_english = ucwords($title_english);
	  $title_urdu     = ucwords($title_urdu);
	  
	  
	  
      //echo " text is ".$script_english;die;
      
      
      
  
      $query =  "update  eh_hypnotherapy set ";
      $query .= "title_english                 = '".$title_english."',   "; 
	  $query .= "title_urdu                    = '".$title_urdu."',   "; 
	  
	  $query .= "script_english                 = '".$script_english."',   "; 
	  $query .= "script_urdu                    = '".$script_urdu."',   "; 
	  
	  
	  $query .= "contents_english                 = '".$contents_english."',   "; 
	  $query .= "contents_urdu                    = '".$contents_urdu."',   "; 
	  
	  $query .= "video_english                 = '".$video_english."',   "; 
	  $query .= "video_urdu                    = '".$video_urdu."',   "; 
	  
	  $query .= "audio_english                 = '".$audio_english."',   "; 
	  $query .= "audio_urdu                    = '".$audio_urdu."'   "; 
	 
	  
	  
	 
     
      $query .= " where id = '".$id."' "; 
      
      
    
       
   // echo "query ".$query;die;
      if(!mysqli_query($conn,$query))
      {
         echo("3 description: in : eh_patients " . mysqli_error($conn)."    ");
        $updatemsgtype = 99;
        $updatemsg = "Error in inserting the record";die;
  
      }
      else
      {
            $updatemsgtype = 0;
            $updatemsg = " updated"; 
            // echo "<br> --------------------------";die;
      
      
      }
}
if(isset($_POST['update_record']))
{ 

	update_record();
    
    //  header("Location: page-select-subject_list.php?user_id=$user_id&book_id=$book_id&chapter_id=$chapter_id");//student_select_subject.php?student_id=$student_id&grade=$grade&taskaction=new

      //echo "flash total ".$flashcard_total;die;
    
   
  
 
     // echo "I am writing flascount ".$flashcard_count;die;  
     
     
    // echo "slide text ".$slide_pic[0];die;
  
    
} 
 
 //echo "insert fag ".$insertflag;die;

if ($insertflag == "1")
{
	$query = "Select * From   eh_hypnotherapy  order by ID ";
}
else
	{
		$query = "Select * From   eh_hypnotherapy  order by id  ";
	}

//echo  "query ".$query;die;
if(!mysqli_query($conn,$query))
{

    echo("3 description: in s_books " . mysqli_error($conn)."    ");die;
    $error = mysqli_error($conn);



}
else
{
      $run_appquery  = mysqli_query($conn, $query);
      $totalsubjects  =mysqli_num_rows($run_appquery);
     // echo "rec cound is ".$totalsubjects;die;
      
    //  $row_query =  mysqli_fetch_assoc($run_appquery);
  //    $page_type =  $row_query['page_type'];
      //echo "pdf file urdu ".$page_type;die;
      
     // echo "pdf file name ".$pdfname;die;


}

// echo "
//echo "id ".$id;//die;
 
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport"
        content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, viewport-fit=cover" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#000000">
    <title>Sulaimania Sohof</title>
    <meta name="description" content="Mobilekit HTML Mobile UI Kit">
    <meta name="keywords" content="bootstrap 5, mobile template, cordova, phonegap, mobile, html" />
    <link rel="icon" type="image/png" href="assets/img/favicon.png" sizes="32x32">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/icon/192x192.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="__manifest.json">
     <style>
.center {
 text-align: center
}

h5 {text-align: center;}
h4 {text-align: center;}
h3 {text-align: center;}

button2 {width: 50%;
 border-color: #2196F3;
  color: red;}
</style>
<style>
textarea {
  resize: none;
}
</style>
  </head>
  <body>
    <!-- Preloader 
    <div id="preloader">
      <div class="spinner-grow text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
    </div>
    -->
    <!-- Internet Connection Status -->
    <!-- # This code for showing internet connection status -->
    <div class="internet-connection-status" id="internetStatus"></div>
    <!-- Header Area -->
    <div class="header-area" id="headerArea">
      <div class="container">
        <!-- Header Content -->
        <div class="header-content header-style-five position-relative d-flex align-items-center justify-content-between">
          <!-- Back Button -->
          <div class="back-button"><a href="home.php"><i class="bi bi-arrow-left-short"></i></a></div>
          <!-- Page Title -->
          <div class="page-heading">
            <h6 class="mb-0"><?=$chapterinfo['chaptername_english'];?></h6>
          </div>
          <!-- Navbar Toggler -->
          <div class="navbar--toggler" id="affanNavbarToggler" data-bs-toggle="offcanvas" data-bs-target="#affanOffcanvas" aria-controls="affanOffcanvas"><span class="d-block"></span><span class="d-block"></span><span class="d-block"></span></div>
        </div>
      </div>
    </div>
    <!-- # Sidenav Left -->
    <!-- Offcanvas -->
  
    <div class="page-content-wrapper py-3">
      <!-- Pagination -->
   
      <!-- Top Products -->
      <div class="top-products-area">
        <div class="container">
          <div class="row g-10">
       <!----    <embed src="pdf_files/pdf-test.pdf" type="application/pdf"   height="700px" width="500">

       PDFTRON_about.pdf   - mohararampage
       saifulla.pdf
         --> 
            <form   method="post" action="?user_id=<? echo $user_id; ?>&id=<?=$id; ?>" class="form-horizontal" enctype="multipart/form-data"  >   
            <?
            if ($editflag =="on")
            {
                  //  echo "...........". $textdata_common;
                    if ($textdata_urdu== '' and $textdata_english== '' and $textdata_arabic == '')
                        {
                            // echo $textdata_common;
                        }
                        else
                        {
                           // echo " text .........:".$textdata_english;
                        }
               // echo "urdu is".$textdata_urdu;die;
              
             //echo "title_english ".$title_english;//die;
            ?>                      
                                             
          <div id="example1"></div>
              <button class="btn btn-primary w-100" name = "update_record" type="submit">Sumbit</button>
         <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Title English</label>
                 <input type="text" name = "title_english" class="form-control" value  = "<?=$title_english;?>" placeholder="Title English...">
               
         </div>
		 
		  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Title Urdu</label>
                 <input type="text" name = "title_urdu" class="form-control" value  = "<?=$title_urdu;?>" placeholder="Title Urdu...">
               
         </div>
		 
		 <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Contents English</label>
                 <textarea id="w3review" name="contents_english" rows="4" cols="80"><?=$contents_english;?></textarea>   
         </div>
		 
		  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Contents Urdu</label>
                <textarea id="w3review" name="contents_urdu" rows="4" cols="80"><?=$contents_urdu;?></textarea>   
               
         </div>
		 
		 
		 <div class="form-group">
                <label class="form-label" for="exampleTextarea1">video English</label>
                <input type="text" name = "video_english" class="form-control" value  = "<?=$video_english;?>" placeholder="Video english...">
         </div>
		 
		  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Video Urdu</label>
                <input type="text" name = "video_urdu" class="form-control" value  = "<?=$video_urdu;?>" placeholder="Video Urdu..."> 
               
         </div>
		 
		  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Audio English</label>
                <input type="text" name = "audio_english" class="form-control" value  = "<?=$audio_english;?>" placeholder="Audio English ...">
         </div>
		 
		  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Audio Urdu</label>
                <input type="text" name = "audio_urdu" class="form-control" value  = "<?=$audio_urdu;?>" placeholder="Audio Urdu...">  
               
         </div>
		 
		 
       
		 
		  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Script Urdu</label>
                <textarea id="w3review" name="script_urdu" rows="4" cols="80"><?=$script_urdu;?></textarea>   
               
         </div> 
			  <div class="form-group">
                <label class="form-label" for="exampleTextarea1">Script English</label>
                 <textarea  name="inam" rows="4" cols="80"><?=$script_english;?></textarea>    
         </div>
                                
        
				
				 
			
          <button class="btn btn-primary w-100" name = "update_record" type="submit">Sumbit</button>
		  .
		  <?
		  if ($user_type == 'admin' || $user_type == "admin2")
			{
			   // $user_id = '2';
				?>
				 
	  <?

			}
		  
		  ?>
		 
          <?}?>
          
          
          <button class="btn btn-primary w-100" name = "generate_record" type="submit">Generate New Record</button>
          
          </form>
          <div class="element-heading mt-3">
          <h6>Text Data for Books :<?=$bookinfo['bookname_english'];?></h6>
        </div>
      </div>
	  

	  
	  
      <div class="container">
        <div class="card">
          <div class="card-body">
            <table class="table mb-0 table-striped">
              <thead>
                <tr>
                  <th >ID#</th>

					<th >Topic</th>
					<th >Urdu</th>
					<th >English</th>                 
                 
                  
                </tr>
              </thead>
               <tbody>
         <?
               
                          
                            
                                   
            
                //echo "count ".$count;
                                    
                while($textdata_query =  mysqli_fetch_assoc($run_appquery)) 
                {    // echo "1looping".$textdata_query['id']; 
                
                   $id = $textdata_query['id'];
                   
                    
                  
                        
                       
                       
                       //echo "text arabic is ".$textstr3;
                   
                    ?>
                     <tr>
                     
                       
                        <td>
                        <a href="?user_id=<? echo $user_id; ?>&patient_id=<?= $id; ?>&action=edit">
                        <?=$textdata_query['id'];?>
                        </a>
                          </td>
              </td>
					  
				                 
                      <td>
                          <a href="?user_id=<? echo $user_id; ?>&id=<?= $id; ?>&action=edit">
                   
                      <?=$textdata_query['title_english'];?>
                     <span class="iconedbox bg-warning">
                     <ion-icon name="add"></ion-icon>
                     </span>
                     
                      </a>
					  </td>
              	  <td>  
					  <?
					  $first30 = substr($textdata_query['contents_urdu'], 0, 30);
                      
					  $first30= strip_tags($first30);
					  echo $first30;
					  ?>
					  </td>
					  
					  <td>
					  <?
					  $first31 = substr($textdata_query['contents_english'], 0, 30);
					  $first31= strip_tags($first31);
					  
					 echo $first31;
					  ?>
					  </td>
					
                    
                      
                
                     
                      
                    </tr>
                    
                <?
               
                }   
                 
                      
              
 
         
         ?>
         
         </tbody>
         </table>
         
         
         
         
       
   

          </div>
        </div>
      </div>
      <!-- Pagination -->
     
    </div>
    <!-- Footer Nav -->
    <div class="footer-nav-area" id="footerNav">
      <!-- App Bottom Menu -->
    <div class="appBottomMenu">
         <a href="home.php?user_id=<?=$user_id;?>" class="item">
            <div class="col">
                <ion-icon name="home-outline"></ion-icon>
                 <strong>Home</strong>
            </div>
        </a>
       
       
        
        
        <a href="logout.php?user_id=<?=$user_id;?>&book_id=<?= $book_id; ?>&chapter_id=<?=$chapter_id; ?>" class="item">
      
            <div class="col">
              <ion-icon name="reader-outline"></ion-icon>
               <strong>Logout</strong>
            </div>
        </a>
         
        
        
         
        
       
        
         
    </div>
    <!-- * App Bottom Menu -->
    <div class="modal fade" id="successmodal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h6 class="modal-title" id="exampleModalLabel">Saved</h6>
            <button class="btn btn-close p-1 ms-auto" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="mb-0">Bookmark has been successfully saved!</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-sm btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <!-- <button class="btn btn-sm btn-success" type="button">Ok</button> -->
          </div>
        </div>
      </div>
    </div>
    <!--Already addes  modal -------------->
    <div class="modal fade" id="alreadymodal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <!-- <h6 class="modal-title" id="exampleModalLabel">Saved</h6> -->
            <button class="btn btn-close p-1 ms-auto" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="mb-0">Bookmark Already Added!</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-sm btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
            <!-- <button class="btn btn-sm btn-success" type="button">Ok</button> -->
          </div>
        </div>
      </div>
    </div>
    <!-- All JavaScript Files -->
    <script src="https://code.jquery.com/jquery-latest.min.js?<?php time();?>"></script>
    <script src="js/bootstrap.bundle.min.js?<?php time();?>"></script>
    <script src="js/slideToggle.min.js?<?php time();?>"></script>
    <script src="js/internet-status.js?<?php time();?>"></script>
    <script src="js/tiny-slider.js?<?php time();?>"></script>
    <script src="js/baguetteBox.min.js?<?php time();?>"></script>
    <script src="js/countdown.js?<?php time();?>"></script>
    <script src="js/rangeslider.min.js?<?php time();?>"></script>
    <script src="js/vanilla-dataTables.min.js?<?php time();?>"></script>
    <script src="js/index.js?<?php time();?>"></script>
    <script src="js/imagesloaded.pkgd.min.js?<?php time();?>"></script>
    <script src="js/isotope.pkgd.min.js?<?php time();?>"></script>
    <script src="js/dark-rtl.js?<?php time();?>"></script>
    <script src="js/active.js?<?php time();?>"></script>
    <!-- PWA -->
    <!--<script src="js/pwa.js"></script>-->
    <script>
     jQuery(document).ready(function(e){
       jQuery('#savebookmark').click(function(){
         var urlParams = new URLSearchParams(window.location.search);
         var subject_id = urlParams.get('subject_id'); // true
         var book_id = urlParams.get('book_id'); // true
         var chapter_id = urlParams.get('chapter_id'); // true
         var topic_id = urlParams.get('topic_ic'); // true
         var user_id = urlParams.get('user_id');

         jQuery.ajax({
            type: "POST",
            url: '<?php echo BASEPATH; ?>savebookmark.php',
            data: {'user_id':user_id,'subject_id':subject_id,'book_id':book_id,'chapter_id':chapter_id,'topic_id':topic_id},
            success: function(data){
              if(data==1){
                jQuery('#successmodal').modal('show');
              }
              else{
                jQuery('#alreadymodal').modal('show');
              }
            },
            dataType: 'json'
          });
       })
     })
    </script>
  </body>
</html>
