<?
// -------------------------this is to avoid session expiry -----------------------------------------
// ÿ
include 'functionlib.php';
include 'dbconnect.php';  
     
        
                                                                 
 $textstr = "قولٌ باللّسانل";
 
 function insert_bookmark_auto($book_id,$chapter_id , $subject_id, $topic_id, $profile_language, $user_id)
 {
 
	include 'dbconnect.php';  
	 $query  =  "delete  FROM s_bookmark_auto WHERE user_id= '".$user_id."' and book_id = '".$book_id."' and  language = '".$profile_language."' ";
	 $run_query  = mysqli_query($conn, $query);
        

    $Insertdates = date('d-m-Y');
    $inscheck = "INSERT INTO s_bookmark_auto (`user_id`, `book_id`, `chapter_id`, `subject_id`,`topic_id`,`bookdate`,`bookmark_status`, `language`,  `seq_no`) VALUES ($user_id,$book_id, $chapter_id, $subject_id, $topic_id,'$Insertdates',0,'$profile_language' ,'$seq_no' )";
    //echo "  insert is ".$inscheck;die;
    $ins = mysqli_query($conn, $inscheck);	 
	return $ins;
 
	
 }
  
 function getrecords_quiz($book_id,$chapter_id , $subject_id, $topic_id, $language)
 {
	include 'dbconnect.php';    
	$query = "select * from s_questions_answers  where book_id ='$book_id' and chapter_id='$chapter_id' and subject_id='$subject_id' and topic_id='$topic_id' and language='$language' ";
	//echo "query".$query;die;
	$retval = mysqli_query($conn, $query);
    if (!$retval) die("Error: " . mysqli_error($conn));

    $count = mysqli_num_rows($retval);
	//echo "total rows".$count;
	return $count;
	 
	 
 }
 
 function getrecords_question($book_id,$chapter_id , $subject_id, $topic_id, $language)
 {
	include 'dbconnect.php';    
	$query = "select * from s_questions_general  where book_id ='$book_id' and chapter_id='$chapter_id' and subject_id='$subject_id' and topic_id='$topic_id' and language='$language'";
	$res = mysqli_query($conn, $query);
    if (!$res) die("Error: " . mysqli_error($conn));

    $row = mysqli_fetch_assoc($res);
	return $row;
	 
	 
 }
 
 
// ini_set('session.gc_maxlifetime', 60*60*4);
session_start();
//ini_set('session.gc_maxlifetime', 60*60*4);
$_SESSION['start'] = time();
$updatemsgtype ="";
$_SESSION['expire'] = $_SESSION['start'] + (15 * 60) ;

//echo " I am here ";die;
$bookname  = $_SESSION['bookname'];
$updatemsg        = escape($_GET ['updatemsg']);
$updatemsgtype    = escape($_GET ['updatemsgtype']);
//echo "email id ".$email_id;die;

$linkdata            = escape($_GET ['linkdata']);
//echo "came to display page linkdata ".$linkdata;die;
$user_id            = $_SESSION['user_id'];
function save_as_mp3($bookid, $textdataid,$textdata_subitem_id,$lang , $txt, $book_id, $textdata_id, $filename)
{


	$vowels = array("<h6>", "</h6>", "<h5>", "</h5>" , "<h4>", "</h4>", "<h3>", "</h3>", "<H6>", "</H6>", "<H5>", "</H5>" , "<H4>", "</hH>", "<H3>", "</H3>" );
    $txt = str_replace($vowels, " ", $txt);


   //echo "<br>came here........txt.........".$txt;die;

 if ($lang == "Arabic")
    {
        $lang = "ar";
    }
    else if ($lang == "Urdu")
    {
        $lang = "ur";
    }
    else
    {
        $lang = "en";
    }

// Specify the URL of the MP3 file
$url = 'https://playaudio.sulaimania.academy/hear?lang='.$lang.'&text='.urlencode($txt);

// Initialize a cURL session
$ch = curl_init();
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Return data inplace of echoing on screen
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$rsData = curl_exec($ch);
curl_close($ch);

// if ($mp3Data === FALSE) {
//     die("Error: Could not fetch the MP3 file.");
// }
// print_r($mp3Data);
// exit;


//echo "<br>File".$filename;//die;
$fileSaved = file_put_contents('./audio_books/'.$filename, $rsData);

if ($fileSaved === FALSE) {
    die("Error: Could not save the MP3 file.");
} else {
    //echo "MP3 file saved successfully!";
    ?>
   <audio controls>
    <source src="<?php echo './audio_books/'.$filename; ?>" type="audio/mpeg">
  </audio>
<?
}
    
// die;
}

function checkin_common_words_table($textstr)
{

         include 'dbconnect.php';
         $query         =  "SELECT * FROM s_arabic_common_words WHERE ar_word = '".$textstr."' ";
         $run_query     = mysqli_query($conn, $query   );
         $totrows       = mysqli_num_rows($run_query);
         if ($totrows > 0)
         {
                $row = mysqli_fetch_array($run_query);
      
                return $row['ar_word'];
         }
         else
         {
                return "";
         }


}

function update_subitem_urdu ($id, $textdata_common)
{

     include 'dbconnect.php';
     $source = "ar";
     $target = "ur";
     $responses = translate_text($textdata_common, $source, $target);
     $textdata_common_urdu = $responses[0][0][0];
                      
     $target = "en";
     $responses = translate_text($textdata_common, $source, $target);
     $textdata_common_english = $responses[0][0][0];
     
     $textdata_common_english= addslashes($textdata_common_english);
     $textdata_common_urdu= addslashes($textdata_common_urdu);
     $query =  "update  s_textdata_subitems set textdata_common_urdu =  '".$textdata_common_urdu."', textdata_common_english = '".$textdata_common_english."' where id = '".$id."' ";
     if(!mysqli_query($conn,$query))
     {
         echo("3 description: in : s_chapters " . mysqli_error($conn)."    ");
        $updatemsgtype = 99;
        $updatemsg = "Error in inserting the record";die;

     }


}
function checkin_vocabulary_table($textstr)
{
         include 'dbconnect.php';
         $query         =  "SELECT * FROM s_arabic_vocabulary WHERE ar_word = '".$textstr."' ";
         $run_query     = mysqli_query($conn, $query   );
         $totrows       = mysqli_num_rows($run_query);
         if ($totrows > 0)
         {
                $row = mysqli_fetch_array($run_query);
      
                return $row['meaning_urdu'];
         }
         else
         {
                return "";
         }



}

function multiexplode ($delimiters,$string) 
{

    $ready = str_replace($delimiters, $delimiters[0], $string);
    $launch = explode($delimiters[0], $ready);
    return  $launch;
}
function addto_subitem_table($book_id, $chapter_id, $subject_id, $topic_id, $textdata_id, $textdata, $language)
{
        include 'dbconnect.php';
        //echo "came in function ".$textdata;//die;
        $vowels  = array( "</h5>",  "</h6>");
      //  $textdata = str_replace($vowels, "", $textdata);
        $textdata = str_replace("</h5>", "</h5>~", $textdata);
        $textdata = str_replace("</h6>", "</h6>~", $textdata);
        
        $text = "here is a sample: this text, and this will be exploded. this also | this one too :)";
        $pieces = multiexplode(array(",",".","?",":", "،", "-", ":", "۔", "؟", "،"   ,"-" , "~"    ),$textdata);

        //print_r($exploded);die;
        
       // $pieces = explode("~", $textdata);
        $word_count =  count($pieces);
        //echo "word count".$word_count; //die;
        for ($x = 0; $x < $word_count; $x++)
        {
                //echo "</br>looping";
                // if 
                $k = strval($x);
                $k = str_pad($k, 7, "0", STR_PAD_LEFT);  // produces "-=-=-Alien"
               // echo "$k = ".$k;die;
               
               $book = strval($book_id);
               $book = str_pad($book, 3, "0", STR_PAD_LEFT);  // produces "-=-=-Alien"
               
               if ($language == "Arabic")
				{
					$abv = "ar";
					
				}
				elseif ($language == "Urdu")
				{
					$abv = "ar";
					
				}
				else {
						$abv = "en";
				}
                $textid = strval($textdata_id);
                $textid = str_pad($textid, 4, "0", STR_PAD_LEFT);  // produces "-=-=-Alien"
                $recitation = $book."-".$textid."-".$k."-".$abv;
				
				
                
                //$check = check_text_exists($pieces[$x]);
                $check[0] = "--";
                if($check[0] == "")
                {
                      $subitem_id = $check[1];
                      $source = "ar";
                      $target = "ur";
                    //  $responses = translate_text($pieces[$x], $source, $target);
                    //  $textdata_common_urdu = $responses[0][0][0];
                      
                      $target = "en";
                    //  $responses = translate_text($pieces[$x], $source, $target);
                    //  $textdata_common_english = $responses[0][0][0];
                     // actual_update_subitem($subitem_id $textdata_common_urdu, $textdata_common_english);
           
                
                }
                if (strpos($pieces[$x], '</h5>') !== false)  
                {

                      //$textdata_common_english  = "english text ";
                      //$textdata_common_urdu     = "urdu text";
                      //echo "</br> This is heading srting ".$pieces[$x];
                      $source = "ar";
                      $target = "ur";
                    //  $responses = translate_text($pieces[$x], $source, $target);
                    //  $textdata_common_urdu = $responses[0][0][0];
                      
                      $target = "en";
                    //  $responses = translate_text($pieces[$x], $source, $target);
                    //  $textdata_common_english = $responses[0][0][0];
                      
                      
                      //echo "<br> recitation is  is ".$recitation;//die;

                      
                      actual_addto_subitem($book_id, $chapter_id, $subject_id, $topic_id, $textdata_id, $pieces[$x], $language, $recitation);
                 }
                 else
                 {
                    //echo "</br> This is normal  srting............ ".$pieces[$x];
                    $keywords = explode("~", str_replace(array("،", "۔", ".", ":", "؟", "-"), "~", $pieces[$x]));
                    $key_count =  count($keywords);
                    //echo "keywords count".$key_count; //die;
                    for ($y = 0; $y < $key_count; $y++)
                    {
                                          $keywords[$y] = str_replace("</h6>", "", $keywords[$y]);
                                          $keywords[$y] = str_replace("<h6>", "", $keywords[$y]);
                                          
                                          $orginal_text = $keywords[$y];
                                          
                                          $keywords[$y] = "<h6> ".$keywords[$y]." </h6>";
                                         // echo "<br> ".$keywords[$y]."//////////////////////";
                                          $target = "ur";
                                        //  $responses = translate_text($orginal_text, $source, $target);
                                        //  $textdata_common_urdu = $responses[0][0][0];
                                        //  $textdata_common_urdu = "<h6> ".$textdata_common_urdu." </h6>";
                                          
                                          $target = "en";
                                       //   $responses = translate_text($keywords[$y], $source, $target);
                                       //   $textdata_common_english = $responses[0][0][0];
                                          //$textdata_common_english = "<h6> ".$textdata_common_english." </h6>";
										  
										//    echo "<br> recitation is  is ".$recitation;//die;

                                          actual_addto_subitem($book_id, $chapter_id, $subject_id, $topic_id, $textdata_id, $keywords[$y], $language, $recitation);
                                          
                                          $source = "ar";
                      
        
                    }
                    
                 }
                 
            
        
        
        }




}

function actual_addto_subitem($book_id, $chapter_id, $subject_id, $topic_id, $textdata_id, $textdata, $language, $recitation)
{


    include 'dbconnect.php';
	//echo "<br> before recitation  ".$recitation;//die;
   // echo " actual function  lang".$textdata_id;die;
    //alok backslash
    //$textdata_common_english= addslashes($textdata_common_english);
   // $textdata_common_urdu= addslashes($textdata_common_urdu);
   $textdata_common_urdu= "";
   $textdata_common_english= "";
   
   $textdata_temp = checkempty_string($textdata);
   
   if ($textdata_temp <> "<h6></h6>")
   {
   $textdata= addslashes($textdata);
   if ($language  == "Arabic")
   {
         $query = "INSERT INTO  s_textdata_subitems (  `book_id`,    `chapter_id`,   `subject_id`,  `topic_id`, `textdata_id`, `textdata`, `language`, `recitation`  )
                                VALUES  ('$book_id',   '$chapter_id',  '$subject_id',  '$topic_id',  '$textdata_id' ,  '$textdata' , '$language' , '$recitation'  )";

   }
   else if ($language  == "Urdu")
   {
         $query = "INSERT INTO  s_textdata_subitems (  `book_id`,    `chapter_id`,   `subject_id`,  `topic_id`, `textdata_id`, `textdata` , `language`, `recitation`  )
                                VALUES  ('$book_id',   '$chapter_id',  '$subject_id',  '$topic_id',  '$textdata_id' ,  '$textdata' , '$language' , '$recitation'  )";

   }
   else
   {
         $query = "INSERT INTO  s_textdata_subitems (  `book_id`,    `chapter_id`,   `subject_id`,  `topic_id`, `textdata_id`, `textdata`, `language`, `recitation`  )
                                VALUES  ('$book_id',   '$chapter_id',  '$subject_id',  '$topic_id',  '$textdata_id' ,  '$textdata' , '$language' , '$recitation'  )";

   }
   
   // echo "<br> query:".$query;die;
    
    
    if(!mysqli_query($conn,$query))
    {
         echo "query ".$query."</br>";
         echo("3 description: in s_textdata_subitems -function add new record " . mysqli_error($conn)."    ");
         $updatemsgtype = 99;
         $updatemsg = "Error in inserting the record";die;

    }
    else
    {
       $result_id = mysqli_insert_id($conn);
		//echo "<br> result id ".$result_id;
       //huda
        $book = strval($book_id);
        $book = str_pad($book, 3, "0", STR_PAD_LEFT);  // produces "-=-=-Alien"
       
       
        $textid = strval($textdata_id);
        $textid = str_pad($textid, 4, "0", STR_PAD_LEFT);  // produces "-=-=-Alien"
        
        $k = strval($result_id);
        $k = str_pad($k, 6, "0", STR_PAD_LEFT); 
		
		if ($language == "Arabic")
		{
			$abv= "-ar";
		}
		else if ($language == "Urdu")
		{
			$abv= "-ur";
		}
		else 
		{
			$abv = "-en";	
		}
		
        $recitation = $book."-".$textid."-".$k.$abv;
       // echo "recitation after ".$recitation;die;
        
        include 'dbconnect.php';
         //$todays_date = date('Y-m-d H:i:s');
          $todays_date = date('Y-m-d');
         $query = "update  s_textdata_subitems set ";
         $query .= "recitation =  '".$recitation."' ";
        

         $query .= " where id =   '".$result_id."'  ";
         //echo "query ".$query."]";//die;
         if(!mysqli_query($conn,$query))
         {
             echo("3 description: in  " . mysqli_error($conn)."    ");die;
             $updatemsgtype = 99;
             $updatemsg = "Error in inserting the record";die;

         }
        
        // die;
                
     }  
       
       return $result_id;
    }
     // echo "<br> query:".$query;die;

}

function get_topic_text($subject_id)
{
         include 'dbconnect.php';
         $sql = "Select * From  s_topics where subject_id =  '".$subject_id."' ";
                     //        echo "sql....".$sql;//die;
         $ret_rec               = mysqli_query( $conn, $sql );
         $rec_count             = mysqli_num_rows($ret_rec);
        // echo "returning ".$rec_count;
         return $rec_count;


}
if ($user_id == '')
{
        if ($linkdata <> '')
        {
            header("Location: index.php?linkdata=$linkdata");
        }
        else
        {

            header("Location: index.php");
            echo "Issue witth user id";die;
        }

}
if ($linkdata <> '')
{
        $pieces = explode("-", $linkdata);

        // linkdata  = "referred_by=".$user_passcode;
        $referred_by        =  $pieces[0];
        $referred_by2        =  $pieces[1];
        //echo "ref 1 ".$referred_by." ref2 ".$referred_by2;die;

        $book_id            =  $pieces[2];
        $chapter_id         =  $pieces[3];
        $subject_id         =  $pieces[4];
        $topic_id           =  $pieces[5];
        $referred_by        =  $pieces[6];
        $profile_language   =  $pieces[7];
        //echo "profile language ".$profile_language ;die;
       // $reply = log_user_view_activity($user_id, "2",  $book_id, $chapter_id, $subject_id, $topic_id, $profile_language);



}
else
{

      $subject_id            =cleanstring($_GET["subject_id"]);
      $chapter_id            =cleanstring($_GET["chapter_id"]);
      $book_id               =cleanstring($_GET["book_id"]);
      $topic_id              =cleanstring($_GET["topic_id"]);
      $action                =cleanstring($_GET["action"]);
      $search_str            =cleanstring($_POST["search_str"]);
      $profile_language      = $_SESSION['profile_language'] ;

}

/*
$common_words   =  $_SESSION['common_words'];
$count = count($common_words);
echo "</br>count is ".$count;
for ($x = 0; $x < $count; $x++)
     {
        echo "[".$common_words[$x]."]";
     
     }
     
*/

if(isset($_POST['update_vocab']))
{

  // echo '<pre>';print_r($_POST);exit;
//filter_has_var(INPUT_POST, 'colors');
//echo "came here ";die;

//echo "<pre>";
//var_dump($_POST);

//var_dump($_POST['c_words']);


$common_words =$_POST['c_words'];
$count = count($common_words);
foreach($_POST['colors'] as $interest) 
{
    $flag = 1;
     //echo "<br>[".$interest."]</br>";die;
     for ($x = 0; $x < $count; $x++)
     {
        echo "[".$common_words[$x]."]";
        if ($common_words[$x]==$interest )
        {
               
               // insert into the table
               
               $source = "ar";
               $target = "ur";
               $responses = translate_text($common_words[$x], $source, $target);
               $meaning_urdu = $responses[0][0][0];
               
               $target = "en";
               $responses = translate_text($common_words[$x], $source, $target);
               $meaning_english = $responses[0][0][0];
               //echo "going to add ";die;
                addto_vocab_table1($common_words[$x], $meaning_urdu, $meaning_english);
                $common_words[$x] = "";
               // echo "</br> word erased from tray ".$interest;
        
        }
     
     
     }
     for ($x = 0; $x < $count; $x++)
     {
     
        //echo "</br> common word  aray ".$common_words[$x] ;
        if ($common_words[$x] <> "")
        {
           addto_common_word_table($common_words[$x]);
        }
     }
    
     
}

if ($flag <>  1)
{
  
    $count = count($common_words);
    for ($x = 0; $x < $count; $x++)
     {
     
        //echo "</br> common word  aray ".$common_words[$x] ;
        if ($common_words[$x] <> "")
        {
           addto_common_word_table($common_words[$x]);
        }
     }
    
     //echo " when non selected ";die;
}


//echo "i am here ";die;



}


function addto_vocab_table1($ar_word, $meaning_urdu, $meaning_english)
{

     include 'dbconnect.php';
      // echo "before check........................ ".$ar_word;die;
       
       
     $reply = checkin_vocabulary_table($ar_word);
     //echo "after  check ".$reply;die;
     if ($reply == "")
     {
     
           $query = "INSERT INTO   s_arabic_vocabulary (  `ar_word` , `meaning_urdu`, `meaning_english`  )
                                                 VALUES  ('$ar_word', '$meaning_urdu', '$meaning_english' )";

          //echo "query:".$query;die;
          if(!mysqli_query($conn,$query))
          {
               echo("3 description: in s_arabic_vocabulary " . mysqli_error($conn)."    ");
               $updatemsgtype = 99;
               $updatemsg = "Error in inserting the record";die;

          }
    }
   
       

}
function addto_common_word_table($common_word)
{

     include 'dbconnect.php';
     $query = "INSERT INTO   s_arabic_common_words (  `ar_word`   )
                                           VALUES  ('$common_word' )";

    //echo "query:".$query;//die;
    if(!mysqli_query($conn,$query))
    {
         echo("3 description: in arabic_common_words " . mysqli_error($conn)."    ");
         $updatemsgtype = 99;
         $updatemsg = "Error in inserting the record";die;

    }
   
       

}

// upddate user profile language -------------------------------------------------------------
$language           = escape($_GET ['language']);
if ($language <> '')
{
    update_profie_language ($user_id, $language);
    $profile_language   =  $language;
    $_SESSION['profile_language']  = $language;
}

//   $linkdata = $book_id."-".$chapter_id."-".$subject_id."-".$topic_id."-".$_SESSION['passcode']."-".$profile_language;




//echo "came here";die;
/*

      $sql = "Select * From  s_textdata where book_id =  '1'  order by id";
    //        echo "sql....".$sql;//die;
      $retval           = mysqli_query( $conn, $sql );
      $count            = mysqli_num_rows($retval);
      //echo "count is ".$count;die;
      while($row = mysqli_fetch_array($retval))
      {
                $str  = $str . " " .$row['textdata_common'];

                $i++;

              //  echo "<br>............................ ".$i." - ".$str;
      }

      $pizza  = $str ;
              $pieces = explode(" ", $pizza);
              $word_count =  count($pieces);
              echo "<br>............................ word count is  ".$word_count." - ".$str;
     ??   die;
 */  

//--------------------- testing

$bookinfo      = getbookinfo($book_id);
$chapterinfo   = getchapterinfo($chapter_id);
$subjectinfo   = getsubjectinfo($subject_id);
$topicinfo     = gettopicinfo($topic_id);
$userinfo      = getuserinfo($user_id);
$profile_language = $userinfo['language'];

//echo "language ".$profile_language;die;
if ($profile_language == "English")
{
        $textalignment  = "left";
}
else
{
        $textalignment  = "right";
}

$logtype = "Text View";
$reply = log_user_view_activity($user_id, $logtype,  $book_id, $chapter_id, $subject_id, $topic_id, $profile_language);



if(isset($_POST['audiofile_gen']))
{
	
	//echo "came to audiofile gen  ".$lesson_id;  die;
	$sql  = "update s_audio_gen set book_id = '".$book_id."',  chapter_id = '".$chapter_id."', language = '".$profile_language."'  where id = '1' ";
    echo "sql ".$sql;//die;
     if(!mysqli_query($conn,$sql))
    {
         echo("3 description: in arabic_common_words " . mysqli_error($conn)."    ");
         $updatemsgtype = 99;
         $updatemsg = "Error in inserting the record";die;

    }
	else{
		echo "success";
	}
	//die;
}

if(isset($_POST['upload_documents']))
{
   //echo "came to add  it is yes....image loading........... ".$lesson_id;  die;

    $row_id         = escape($_GET ['row_id']);

    //echo "row id ".$row_id;die;
    $lesson_info  = getlessonname($lesson_id);

    $imagetype     = escape($_POST ['image_type']);
    $recitation    = escape($_POST ['recitation']);

    $todaysdate = date("Y-m-d H:i:s");
    $target_dir = "audio/";

     $sql  = "update s_textdata set recitation = '".$recitation."'   where id = '".$row_id."' ";
     //echo "sql ".$sql;die;
     if(!mysqli_query($conn,$sql))
     {
         echo("3 description: in student_test_results " . mysqli_error($conn)."    ");die;
         $updatemsgtype = 99;
         $updatemsg = "Error in inserting the record";die;

     }

}

if ($action == 'inam_delete')
{
	
	 $textdata_id =     cleanstring($_GET["textdata_id"]);
	// $sql = "delete From  s_textdata where id =  '".$textdata_id."'  ";

  //   $retval = mysqli_query( $conn, $sql );
 //echo "came to delete ".$textdata_id ;die;	
	
	
}


if ($action == 'unlock')
{
 $textdata_id         = escape($_GET ['textdata_id']);
 
 if ($profile_language == "Arabic")
 {
	 $sql  = "update s_textdata set spilit_lock = '0'   where id = '".$textdata_id."' ";
 }
 else if ($profile_language == "Urdu")
 {
	  $sql  = "update s_textdata set spilit_lock_urdu = '0'   where id = '".$textdata_id."' ";
 }
 else{
	 $sql  = "update s_textdata set spilit_lock_english = '0'   where id = '".$textdata_id."' ";
 }
  
     //echo "sql ".$sql;die;
     if(!mysqli_query($conn,$sql))
     {
         echo("3 description: in s_textdata " . mysqli_error($conn)."    ");die;
         $updatemsgtype = 99;
         $updatemsg = "Error in inserting the record";die;

     }

}

$row_setting = check_font_settings($user_id);

$user_quran_translation = $row_setting['quran_translation'];
//echo "quran translation [".$user_quran_translation."]";die;
$h1_fontsize  = $row_setting['h1_fontsize'];
$h1_fontname  = $row_setting['h1_fontname'];
$h1_fontcolor = $row_setting['h1_fontcolor'];

$h2_fontsize  = $row_setting['h2_fontsize'];
$h2_fontname  = $row_setting['h2_fontname'];
$h2_fontcolor = $row_setting['h2_fontcolor'];


$h4_fontsize  = $row_setting['h4_fontsize'];
$h4_fontname  = $row_setting['h4_fontname'];
$h4_fontcolor = $row_setting['h4_fontcolor'];

$h5_fontsize  = $row_setting['h5_fontsize'];
$h5_fontname  = $row_setting['h5_fontname'];
$h5_fontcolor = $row_setting['h5_fontcolor'];

$h6_fontsize  = $row_setting['h6_fontsize'];
$h6_fontname  = $row_setting['h6_fontname'];
$h6_fontcolor = $row_setting['h6_fontcolor'];


$h3_fontsize  = $h6_fontsize + .2;
$h3_fontname  = $row_setting['arial'];
$h3_fontcolor = $row_setting['h3_fontcolor'];

if ($action == 'increase')
{

 //echo "came to edit";die;

 //echo "count ".$count; die;
 $h1_fontsize = $row_setting['h1_fontsize'];
 //$h2_fontsize = $row_setting['h2_fontsize'];
 //$h3_fontsize = $row_setting['h3_fontsize'];
 $h4_fontsize = $row_setting['h4_fontsize'];
 $h5_fontsize = $row_setting['h5_fontsize'];
 $h6_fontsize = $row_setting['h6_fontsize'];


 $h1_fontsize +=.2;
 $h2_fontsize +=.2;
 $h3_fontsize +=.2;
 $h4_fontsize +=.2;
 $h5_fontsize +=.2;
 $h6_fontsize +=.2;
 
 $maxsize = 2.4;

if ($h1_fontsize  > $maxsize)
 {
        $h1_fontsize=$maxsize;
 }
if ($h2_fontsize  > $maxsize)
 {
        $h2_fontsize=$maxsize;
 }
 if ($h3_fontsize  > $maxsize)
 {
        $h3_fontsize=$maxsize;
 }
 if ($h4_fontsize  > $maxsize)
 {
        $h4_fontsize=$maxsize;
 }
 if ($h5_fontsize  > $maxsize)
 {
        $h5_fontsize=$maxsize;
 }
 if ($h6_fontsize  > $maxsize)
 {
        $h6_fontsize=$maxsize;
 }

     $query = "update s_textdata_settings set h1_fontsize = '".$h1_fontsize."', h2_fontsize = '".$h2_fontsize."', h1_fontsize = '".$h1_fontsize."', h3_fontsize = '".$h3_fontsize."', h4_fontsize = '".$h4_fontsize."',  h5_fontsize = '".$h5_fontsize."',  h6_fontsize = '".$h6_fontsize."'   where user_id = '".$user_id."'  ";
    // echo "q ".$query;die;
     mysqli_query($conn,$query);

//
//echo " font is ".$h6_fontsize;die;
}

else if ($action == 'decrease')
{

 //echo "came to edit";die;

 //echo "count ".$count; die;
 $h1_fontsize = $row_setting['h1_fontsize'];
 $h2_fontsize = $row_setting['h2_fontsize'];
 $h3_fontsize = $row_setting['h3_fontsize'];
 $h4_fontsize = $row_setting['h4_fontsize'];
 $h5_fontsize = $row_setting['h5_fontsize'];
 $h6_fontsize = $row_setting['h6_fontsize'];


 $h1_fontsize -=.2;
 $h2_fontsize -=.2;
 $h3_fontsize -=.2;
 $h4_fontsize -=.2;
 $h5_fontsize -=.2;
 $h6_fontsize -=.2;
 
 $minsize = 1;
 if ($h1_fontsize  < $minsize)
 {
        $h1_fontsize=$minsize;
 }
 if ($h2_fontsize  < $minsize)
 {
        $h2_fontsize=$minsize;
 }
 if ($h3_fontsize  < $minsize)
 {
        $h3_fontsize=$minsize;
 }
 if ($h4_fontsize  < $minsize)
 {
        $h4_fontsize=$minsize;
 }
 if ($h5_fontsize  < $minsize)
 {
        $h5_fontsize=$minsize;
 }
 if ($h6_fontsize  < $minsize)
 {
        $h6_fontsize=$minsize;
 }

  $query = "update s_textdata_settings set h1_fontsize = '".$h1_fontsize."', h2_fontsize = '".$h2_fontsize."', h1_fontsize = '".$h1_fontsize."', h3_fontsize = '".$h3_fontsize."', h4_fontsize = '".$h4_fontsize."',  h5_fontsize = '".$h5_fontsize."',  h6_fontsize = '".$h6_fontsize."'   where user_id = '".$user_id."'  ";
    // echo "q ".$query;die;
     mysqli_query($conn,$query);


}
else if ($action == 'addbookmark')
{

   //echo "i am here at bookmarkq ";//die;

  $checkbookmrk =  "SELECT * FROM s_bookmark WHERE user_id= '".$user_id."' and book_id = '".$book_id."'  and  chapter_id = '".$chapter_id."'  and subject_id   = '".$subject_id."'  and topic_id= '".$topic_id."'   and  language = '".$profile_language."' ";
  $run_query  = mysqli_query($conn, $checkbookmrk   );
  $totrows = mysqli_num_rows($run_query);
  //echo "total rows=".$totrows." query ".$checkbookmrk;die;
  if($totrows<1)
  {
            $checkbookmrk  =  "SELECT * FROM s_bookmark WHERE user_id= '".$user_id."' and book_id = '".$book_id."' order by seq_no DESC";
            $run_query     = mysqli_query($conn, $checkbookmrk   );
            $totrows       = mysqli_num_rows($run_query);
            $row           = mysqli_fetch_array($run_query);
            $seq_no        = $row['seq_no'];
            $seq_no++;




    $Insertdates = date('d-m-Y');
    $inscheck = "INSERT INTO s_bookmark (`user_id`,`subject_id`,`chapter_id`,`topic_id`,`book_id`,`bookdate`,`bookmark_status`, `language`,  `seq_no`) VALUES ($user_id,$subject_id,$chapter_id,$topic_id,$book_id,'$Insertdates',0,'$profile_language' ,'$seq_no' )";
   // echo "  insert is ".$inscheck;die;
    $ins = mysqli_query($conn, $inscheck);
  }
  else
  {

  }



}


else if ($action == "add")
{
   
    $word       = escape($_GET ['word']);
    $vowels  = array(",", "?", "!", "-", ".");
    $word = str_replace($vowels, "", $word);  
    
    $reply      = checkin_vocabulary_table($word);
    if ($reply[0] == "")
    {
            // echo "came to add reply - blank";die;
               $source = "ar";
               $target = "ur";
               $responses = translate_text($word, $source, $target);
               $meaning_urdu = $responses[0][0][0];
               
               $target = "en";
               $responses = translate_text($word, $source, $target);
               $meaning_english = $responses[0][0][0];
               //echo "going to add ";die;
               addto_vocab_table1($word, $meaning_urdu, $meaning_english);
    
    }
    


}


else if ($action == 'save_playlist')
{

  // echo "i am here at playlist ";//die;

   $playlist_group_id = "1";
   $subject_id            =cleanstring($_GET["subject_id"]);
   $topic_id              =cleanstring($_GET["topic_id"]);
   $chapter_id            =cleanstring($_GET["chapter_id"]);
   $book_id               =cleanstring($_GET["book_id"]);
   $textdata_id           =cleanstring($_GET["textdata_id"]);
   //echo "text data id ".$textdata_id;die;
   $topic_info             = gettopicinfo($topic_id);
   $title_english         = $topic_info['topic_english'];
   $title_urdu            = $topic_info['topic_urdu'];
   $title_arabic          = $topic_info['topic_arabic'];


//echo "this is arabic  I will add in playlist ".$title_urdu;die;
  $checkbookmrk =  "SELECT * FROM  s_playlist WHERE user_id= '".$user_id."' and book_id = '".$book_id."'  and  chapter_id = '".$chapter_id."'  and subject_id   = '".$subject_id."'  and topic_id= '".$topic_id."'   and  textdata_id  = '".$textdata_id."' ";
  $run_query  = mysqli_query($conn, $checkbookmrk   );
  $totrows = mysqli_num_rows($run_query);
  //echo "total rows=".$totrows." query ".$checkbookmrk;die;
  if($totrows<1)
  {
    $Insertdates = date('d-m-Y');
    $inscheck  = "INSERT INTO s_playlist (`user_id`,`playlist_group_id`, `book_id`, `chapter_id`, `subject_id`,`topic_id`,`textdata_id` ) ";
    $inscheck .= " VALUES ( '".$user_id."' , ";
    $inscheck .= " '".$playlist_group_id."' , ";
    $inscheck .= " '".$book_id."' , ";
    $inscheck .= " '".$chapter_id."' , ";
    $inscheck .= " '".$subject_id."' , ";

    $inscheck .= " '".$topic_id."' , ";
    $inscheck .= " '".$textdata_id."' ) ";



    //$inscheck .= $title_english." , ";
   // $inscheck .= $title_arabic." , ";
   // $inscheck .= $title_urdu." ) ";


  //  echo "this is arabic  I will add in playlist ".$title_urdu;//die;
  //  echo "this is I will add in playlist ".$inscheck;die;

    if(!mysqli_query($conn,$inscheck))
    {
             echo("3 description: in student_test_results " . mysqli_error($conn)."    ".$inscheck);die;
    }
    else
    {
            echo "success........................ ";
    }


   // $ins = mysqli_query($conn, $inscheck);
  }
  else
  {

  }



}
else if ($action == 'commontopic')
{
   
   
    $checkbookmrk =  "SELECT * FROM  s_topics_common WHERE book_id = '".$book_id."'  and  chapter_id = '".$chapter_id."'  and subject_id   = '".$subject_id."'  and topic_id= '".$topic_id."'  ";
    $run_query    = mysqli_query($conn, $checkbookmrk);
    $rows         = mysqli_num_rows($run_query);
    
    //echo "came here rows s  ";$rows;die;
    if ($rows == 0)
    {
    
        // get seqno
        $checkbookmrk =  "SELECT * FROM  s_topics_common WHERE book_id = '".$book_id."'  order by seqno DESC  ";
        $run_query    = mysqli_query($conn, $checkbookmrk);
        $rows         = mysqli_num_rows($run_query);
        $row_rec      =  mysqli_fetch_assoc($run_query);
        $seqno        = $row_rec['seqno'];
      //  echo "sequ no before ".$seqno;
        $seqno++;
      //  echo "sequ no".$seqno;
   
 //  $chapterinfo   = getchapterinfo($chapter_id);
 //  $subjectinfo   = getsubjectinfo($subject_id);
     $topicinfo     = gettopicinfo($topic_id);
     $name_english  = $topicinfo['topic_english'];
     $name_arabic   = $topicinfo['topic_arabic'];
     $name_urdu     = $topicinfo['topic_urdu'];
     if ($topicinfo['bookicon_english'] == 0 )
     {
            //echo "topic not found ";
            $subjectinfo   = getsubjectinfo($subject_id);
            if ($subjectinfo['bookicon_english'] == "" )
            {
                //echo "subject not found";
                $chapterinfo   = getchapterinfo($chapter_id);
                $bookicon_english = $chapterinfo['bookicon_english'];
                $bookicon_arabic = $chapterinfo['bookicon_arabic'];
                $bookicon_urdu = $chapterinfo['bookicon_urdu'];
            }
            else
            {
                // echo "subject ..................... found";
                $bookicon_english = $subjectinfo['bookicon_english'];
                $bookicon_arabic = $subjectinfo['bookicon_arabic'];
                $bookicon_urdu = $subjectinfo['bookicon_urdu'];
                 //echo "subject not found".$bookicon_english;
            }
    
    }
    else
    {
        $bookicon_english = $topicinfo['bookicon_english'];
        $bookicon_arabic = $topicinfo['bookicon_arabic'];
        $bookicon_urdu = $topicinfo['bookicon_urdu'];
    }
    
    $Insertdates = date('d-m-Y');
    
    
    //echo "came here2";die;
    
    $inscheck = "INSERT INTO s_topics_common (`book_id`, `seqno`,  `subject_id`,`chapter_id`,`topic_id`,`status`,`name_english`,`name_arabic`, `name_urdu`,   `bookicon_english`,`bookicon_arabic`,`bookicon_urdu`) VALUES ('$book_id',  '$seqno',  '$subject_id','$chapter_id',  '$topic_id', 'active',  '$name_english', '$name_arabic','$name_urdu',  '$bookicon_english', '$bookicon_arabic', '$bookicon_urdu' )";
    //echo "  insert is ".$inscheck;die;
    if(!mysqli_query($conn,$inscheck))
    {
             echo("3 description: in student_test_results " . mysqli_error($conn)."    ".$inscheck);die;
    }
    else
    {

        //echo"updated....";die;
    }
    
  }
  else
  {
        //echo "row is not 0 ";die;
  }


}
if ($profile_language  == "Urdu")
{
  //  echo "going for urdu";
    $bookname    = $bookinfo['bookname_urdu'];
    $chaptername = $chapterinfo['chaptername_urdu'];
    $subjectname = $chapterinfo['subject_urdu'];
    $search_query = "Select * From    s_topics  where (topic_status= 'active' and book_id = '".$book_id."'  and topic_urdu like   '%".$search_str."%') or (topic_status= 'active' and book_id = '".$book_id."'  and search_str like   '%".$search_str."%')   order by id";

}
else if ($profile_language  == "Arabic")
{
   //  echo "going for arabic";
    $bookname    = $bookinfo['bookname_arabic'];
    $subjectname = $chapterinfo['subject_arabic'];
    $chaptername = $chapterinfo['chaptername_arabic'];
    $search_query = "Select * From    s_topics  where (topic_status= 'active' and book_id = '".$book_id."'  and topic_arabic like   '%".$search_str."%')  or (topic_status= 'active' and book_id = '".$book_id."'  and search_str like   '%".$search_str."%') order by id";

}
else
{
    //echo "going for else h";

    $bookname    = $bookinfo['bookname_english'];

    $subjectname = $chapterinfo['subject_english'];
    $chaptername = $chapterinfo['chaptername_english'];
    $search_query = "Select * From    s_topics  where (topic_status= 'active' and book_id = '".$book_id."' and topic_english like  '%".$search_str."%') or (topic_status= 'active' and book_id = '".$book_id."'  and search_str like   '%".$search_str."%')   order by id";
   // $search_query = "Select * From    s_textdata  where book_id = '".$book_id."' and textdata_english like  '%".$search_str."%'     order by id";

}

if ($search_str  <> '')
{

             // echo "going book id".$book_id;die;
             $_SESSION["recordset"]            = "";
             $_SESSION["recordset_count"]      = 0 ;
             $_SESSION["recordset_used "]      = 0 ;
             header("Location: page-display_search.php?user_id=$user_id&book_id=$book_id&search_str=$search_str");//echo " total rows".$total_rows;


}
else
{
   // echo "search str is blank";die;
}




$todaysdate = date("Y-m-d H:i:s");
$query = "Select * From   s_books  where book_status= 'active'  order by book_seqno";
//echo "query ".$query;
if(!mysqli_query($conn,$query))
{

    echo("3 description: in s_books " . mysqli_error($conn)."    ");die;
    $error = mysqli_error($conn);



}
else
{
      $run_appquery  = mysqli_query($conn, $query);
      $totalbooks    = mysqli_num_rows($run_appquery);
       //echo "rec cound is ".$totalbooks;die;


}


if ($profile_language == "Arabic")
{
        $book_image     = $bookinfo['bookicon_arabic'];
        $chapter_image  = $chapterinfo['bookicon_arabic'];
        $subject_image  = $subjectinfo['bookicon_arabic'];
        $topic_image    = $topicinfo['bookicon_arabic'];
}
else if  ($profile_language == "Urdu")
{
        $book_image     = $bookinfo['bookicon_urdu'];
        $chapter_image  = $chapterinfo['bookicon_urdu'];
        $subject_image  = $subjectinfo['bookicon_urdu'];
        $topic_image    = $topicinfo['bookicon_urdu'];
}
else
{
        $book_image     = $bookinfo['bookicon_english'];
        $chapter_image  = $chapterinfo['bookicon_english'];
        $subject_image  = $subjectinfo['bookicon_english'];
        $topic_image    = $topicinfo['bookicon_english'];
}
$book_image = $book_image.".jpg";
$chapter_image = $chapter_image.".jpg";
$subject_image = $subject_image.".jpg";
$topic_image   = $topic_image.".jpg";

if ($profile_language == "Urdu")
{
    $heading3_font = "NotoNastaliqUrdu-Regular";
    $heading4_font = "NotoNastaliqUrdu-Regular";
    $heading5_font = "Al Qalam Quran Majeed Web";
    $heading6_font = "NotoNastaliqUrdu-Regular";
    $heading6_line_height  = "200%";
    $heading5_line_height  = "170%";
    $heading4_line_height  = "180%";

}
else if ($profile_language == "Arabic")
{

        $heading4_font = "arabicfont";
        $heading5_font = "Al Qalam Quran Majeed Web";
        $heading6_font = "arial";
        $heading3_font = "arial";
        $heading4_font = "arial";


        $heading6_line_height  = "160%";
        $heading5_line_height  = "160%";
        $heading4_line_height  = "160%";
}
else
{

      $heading4_font = "ariel";
      $heading5_font = "Al Qalam Quran Majeed Web";
      $heading6_font = "arial";
      $heading3_font = "arial";
      $heading4_font = "arial";
      $heading6_line_height  = "120%";
      $heading4_line_height  = "120%";
      $heading5_line_height  = "170%";

}


//echo "br>  h4 ".$h3_fontcolor;
//echo "br>  h5 ".$h5_fontcolor;
//echo "<br>  h6 ".$h6_fontcolor;die;


?>

<!doctype html>

<?

if ($profile_language == "English")
{ ?>
          <html lang="en">
<? }
else
{ ?>
        
        <html dir="rtl" lang="ar" >
<?}

$h3_fontsize = $h6_fontsize+.4;
?>
<html lang="en">

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
    <link rel="stylesheet" href="assets/css/style_inam.css">
    <link rel="manifest" href="__manifest.json">
      <link rel="stylesheet"
          href="https://fonts.googleapis.com/css?family=Tangerine">
      <style>
	  
	#tooltip {
    position: absolute;
    display: none;
    z-index: 1000;
    max-width: 400px;
    padding: 12px 18px;
    background: #fff200;   /* bright yellow */
    color: #d00000;        /* red text */
    font-size: 20px;       /* bigger font */
    font-weight: bold;     /* bold */
    line-height: 1.5;
    border: 1px solid #aaa;
    border-radius: 6px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    pointer-events: none;
    word-wrap: break-word;
    direction: rtl;        /* good for Arabic */
    text-align: right;
}
}

.center {
 text-align: center
}
@import url('https://fonts.googleapis.com/css?family=Muli&display=swap');
@import url('https://fonts.googleapis.com/css?family=Quicksand&display=swap');
@font-face {
    font-family: 'arabicfont';
    src: url('./fonts/AlQalamQuranMajeedWeb.eot');
    src: url('./fonts/AlQalamQuranMajeedWeb.eot?#iefix') format('embedded-opentype'),
        url('./fonts/AlQalamQuranMajeedWeb.woff2') format('woff2'),
        url('./fonts/AlQalamQuranMajeedWeb.woff') format('woff'),
        url('./fonts/AlQalamQuranMajeedWeb.svg#AlQalamQuranMajeedWeb') format('svg');

    font-weight: normal;
    font-style: normal;

}

@font-face {
    font-family: 'myurdufontnew';
    src: url('http://alviafoundation.org/mypages/fonts_urdu/01_tlp_urduenglishdotted_font_2-webfont.woff') format('woff2'),
         url('http://alviafoundation.org/mypages/fonts_urdu/01_tlp_urduenglishdotted_font_2-webfont.woff2') format('woff');
    font-weight: normal;
    font-style: normal;

}
@font-face {
    font-family: 'Al Qalam Quran Majeed Web';
    src: url('./fonts/AlQalamQuranMajeedWeb.eot');
    src: url('./fonts/AlQalamQuranMajeedWeb.eot?#iefix') format('embedded-opentype'),
        url('./fonts/AlQalamQuranMajeedWeb.woff2') format('woff2'),
        url('./fonts/AlQalamQuranMajeedWeb.woff') format('woff'),
        url('./fonts/AlQalamQuranMajeedWeb.svg#AlQalamQuranMajeedWeb') format('svg');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
}


body {
  font-family: 'Scheherazade' !important;
}
@font-face {
    font-family: 'Attari_Quraan_Word';
    src: url('./fonts/Attari_Quraan_Word.eot');
    src: url('./fonts/Attari_Quraan_Word.eot?#iefix') format('embedded-opentype'),
        url('./fonts/Attari_Quraan_Word.woff2') format('woff2'),
        url('./fonts/Attari_Quraan_Word.woff') format('woff'),
        url('./fonts/Attari_Quraan_Word.svg#AlQalamQuranMajeedWeb') format('svg');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: NotoNastaliqUrdu-Regular;
    src: url('./fonts/NotoNastaliqUrdu-Regular.eot');
    src: url('./fonts/NotoNastaliqUrdu-Regular.eot?#iefix') format('embedded-opentype'),
         url('./fonts/NotoNastaliqUrdu-Regular.woff2') format('woff2'),
         url('./fonts/NotoNastaliqUrdu-Regular.woff') format('woff'),
         url('./fonts/NotoNastaliqUrdu-Regular.svg#NotoNastaliqUrdu-Regular') format('svg');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
} 

h5
{
 //   font-family:Scheherazade;
    font-family:Al Qalam Quran Majeed Web;
    font-family: <?=$heading5_font;?>;
    font-size:  <?php echo $row_setting['h5_fontsize'];?>em;
    color:  <?=$h5_fontcolor;?>;
    text-align: center;
    //line-height: 165%;
    line-height: <?=$heading5_line_height;?>;
    text-justify: inter-word;
    text-align: justify; /* For Edge */
    text-align-last:center;
    font-weight: normal;
    font-weight: 500;
    font-style: normal;
    font-display: swap;
 
}
h6
{
    text-align: <?=$textalignment;?>;
    font-size: <?php echo $row_setting['h6_fontsize'];?>em;

    line-height: <?=$heading6_line_height;?>;
    color:<?=$h6_fontcolor;?>;
    text-align: justify; /* For Edge */
    -moz-text-align-last: right; /* For Firefox prior 58.0 */
    text-align-last:<?=$textalignment;?>;
    text-align: justify; /* For Edge */
    text-align-last:textalignment;

    font-family: <?=$heading6_font;?>;

    font-weight: 500;
    font-style: normal;
 // font-size: 38px;
 // line-height: 1.50;
 //  line-height: 200%;

   }



h3 {
       font-family:ariel;

      font-size:  <?php echo $row_setting['h5_fontsize'];?>em;
      color:  Red;
      text-align: center;
      //line-height: 165%;
      line-height: <?=$heading5_line_height;?>;
      text-justify: inter-word;
      text-align: justify; /* For Edge */
      text-align-last:center;
      font-weight: normal;
      font-weight: 500;
      font-style: normal;
      font-family: 'Arial';
      font-display: swap;

   }
   
   
 

h4 {
       text-align: center;
       font-size:<?php echo $row_setting['h4_fontsize'];?>em;
       color:<?=$h4_fontcolor;?>;
       font-family: 'Quicksand';
       font-weight: 400;
       font-style: normal;
       font-family: <?=$heading4_font;?>;
        line-height: <?=$heading4_line_height;?>;
 // font-size: 38px;
 // line-height: 1.50;
 // line-height: 180%;
   }


input[type="checkbox"] {
    zoom: 1.5;
}
 
p
{
     font-size:<?php echo $row_setting['text_fontsize'];?>em;
     line-height: 1;
     font-family: 'Ariel';
     color: red;
     font-size: 20px;
   
      margin-top: -.4em;
      margin-bottom: 0em;
      height: -10em;

}
hr {
  height: -1px; /* adjust the height to reduce the line thickness */
  width: 100%; /* adjust the width to reduce the line length */
}
.one{
    font-family: 'Ariel';
    color: red;
    font-size: 20px;
     
}
.two{
    font-family: 'Ariel';
    color: blue;
    font-size: 20px;
    margin-top: -.4em;
    margin-bottom: 0em;
   
}
.three{
    font-family: 'Ariel';
    color: red;
    font-size: 25px;
}
.four{
    font-family: 'Times New Roman';
    color: blue;
    font-size: 50px;
}


#ttsBtn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 99999;  /* very high to ensure it’s on top */
    padding: 10px 15px;
    background: #0d6efd;
    color: white;
    border: none;
    border-radius: 6px;
    font-weight: bold;
    cursor: pointer; /* ensure pointer appears */
}

.text-block {
  font-size: 20px;
  line-height: 1.7;
  margin-bottom: 10px;
  padding: 10px;
  border-radius: 8px;
  background: #fff;
}

.play-btn {
  background: #28a745;
  color: white;
  border: none;
  padding: 6px 14px;
  font-size: 16px;
  border-radius: 6px;
  cursor: pointer;
  margin-bottom: 15px;
}

.play-btn:hover {
  opacity: 0.85;
}

.highlight-word {
  background: yellow;
  color: red;
  font-weight: bold;
}


</style>
<style>
table, th, td {
  border: 1px solid black;
  border-collapse: collapse;
   
}
</style>
<style>
TD{font-family: Arial; font-size: 16pt; line-height:120%} 
</style>
</head>
<script>
function myFunction_translation(divname1)
{
    // Get the element by its ID
    var x = document.getElementById(divname1);

    // Check the current display style and toggle it
    if (x.style.display === "none")
    {
        x.style.display = "block"; // Show the div
    } else
    {
        x.style.display = "none"; // Hide the div
    }
} // <--- NO COLON HERE

function myFunction_additional_info()
{
  var x = document.getElementById("myDIV2");
  if (x.style.display === "none")
  {
    x.style.display = "block";
  } else
  {
    x.style.display = "none";
  }
}


function myFunction_translitration(divname3)
{
  var x = document.getElementById(divname3);
  if (x.style.display === "none")
  {
    x.style.display = "block";
  } else
  {
    x.style.display = "none";
  }
}
</script>
<style>
  .modal-backdrop{
    z-index: 12 !important;
  }
</style>
<body class="bg-white">

    <!-- loader
    <div id="loader">
        <div class="spinner-border text-primary" role="status"></div>
    </div>-->
    <!-- * loader -->

    <!-- App Header -->




     <!-- App Header -->





    <div class="appHeader bg-success text-light">
        <div class="left">
             <a href="#" class="headerButton toggle-searchbox">
                <ion-icon name="search-outline"></ion-icon>
            </a>
        </div>
        <div class="pageTitle"></div>
        <div class="right">
         <button class="btn btn-warning dropdown-toggle" type="button" data-bs-toggle="dropdown">
                      <?
                        if ($profile_language == "Arabic")
                         {
                                $p_language = "اللغة العربية";
                         }
                         else if ($profile_language == "Urdu")
                         {
                                $p_language = "اردو زبان";
                                
                         }
                         else
                         {
                                $p_language = $profile_language;
                         }
                         
                         
                         //echo $p_language;
                         
                         
                         ?>
           
                        <?=$p_language;?>
                    </button>
                    <div class="dropdown-menu">
                        <h6 class="dropdown-header">Select Language</h6>

                         <div class="dropdown-divider"></div>
                         <?
                            $recno = getbooklanguage("Arabic", $book_id);
                            if ($recno > 0)
                            { ?>
                                    <a class="dropdown-item" href="?book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>>&language=Arabic">اللغة العربية</a>

                       <?   }
                            $recno = getbooklanguage("Urdu", $book_id);
                            if ($recno > 0)
                            { ?>
                                    <a class="dropdown-item" href="?book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&language=Urdu">اردو زبان</a>

                       <?   }
                            $recno = getbooklanguage("English", $book_id);
                            if ($recno > 0)
                            { ?>
                                    <a class="dropdown-item" href="?book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&language=English">English</a>

                       <?   } ?>  </div>
        </div>
    </div>
    <!-- * Search Component -->
    <div id="search" class="appHeader">


         <form   method="post" action="?user_id=<? echo $user_id; ?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>" class="form-horizontal" enctype="multipart/form-data"  >

            <input type="hidden" name = "user_id" value = <?=$user_id;?>
            <input type="hidden" name = "book_id" value = <?=$book_id;?>
            <div class="form-group searchbox">
                <input type="text" name = "search_str" class="form-control" placeholder="Search...">
                  <button class="btn btn-success " name = "search_option" type="submit">Search</button>

                <a href="#" class="ms-1 close toggle-searchbox">
                    <ion-icon name="close-circle"></ion-icon>
                </a>
            </div>
        </form>
    </div>
     
    <!-- App Capsule -->
    <div id="appCapsule">


<div class="form-check form-switch">
                                <input class="form-check-input dark-mode-switch" type="checkbox" id="darkmodesidebar">
                                <label class="form-check-label" for="darkmodesidebar"></label>
                            </div>
         <div class="section mt-2 mb-2">
         
         
         <?
                $sql = "Select * From  s_textdata where topic_id =  '".$topic_id."' order by seq_no ASC, id ASC";
                //        echo "sql....".$sql;//die;
                $retval           = mysqli_query( $conn, $sql );
                $count            = mysqli_num_rows($retval);
				$row_query        = mysqli_fetch_assoc($retval);
				$textdata_id      = $row_query['id'];
				
			
           
                 if ($bookinfo['booktype'] == "menulist")
                 {  //f 
                 
                 $subjectname = $subjectinfo['subject_arabic'];
                 
                 ?>
                        <div class="header-large-title">
                        <h4 style="color: DarkSlateGray; font-size:20px;">
                          <a href="home.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>" class="item"> <?=$bookname;?>

                           </a>
                           </h4>
                          </br>
                          <h4 style="color: DarkSlateGray; font-size:20px;">
                           <a href="page-select-chapter.php.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>" class="item"> <?=$chaptername."/".$subjectname;?>

                           </a>
 

                          </h4>
                          </div>

              <?   }
                 else
                 { ?>


                      <div class="section-title">
                       <a href="page-select-chapter.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>" class="item">
                       <img src="bookicons/<?=$book_image;?>" alt="<?=$book_image;?>" class="imaged w64">
                       </a>
                       <a href="page-select-subject.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>" class="item">
                       <img src="bookicons/<?=$chapter_image;?>" alt="<?=$chapter_image;?>" class="imaged w64">
                       </a>

                       <?
                       if ($topic_image != ".jpg")
                       { ?>

                            <a href="page-select-topic.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>" class="item">
                            <img src="bookicons/<?=$subject_image;?>" alt="<?=$subject_image;?>" class="imaged w64">
                            </a>
                            <a href="page-display_pdf.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>" class="item">
                            <img src="bookicons/<?=$topic_image;?>" alt="<?=$topic_image;?>" class="imaged w64">
                            </a>

                    <?   }
                         else
                         { ?>
                              <img src="bookicons/<?=$subject_image;?>" alt="<?=$subject_image;?>" class="imaged w64">

                        <? }
                    ?>


                      </div>

               <?  }


         ?> 
 
                        
                          <div class="row col-lg-12">
                            <a class="btn btn-primary" href="download-pdf.php?user_id=<?=$user_id?>&book_id=<?=$bppok_id?>&chapter_id=<?=$chapter_id?>&subject_id=<?=$subject_id?>&topic_id=<?=$subject_id?>" target="_blank" style="width:200px; float: right;">Download As PDF</a>
                          </div>


            <div class="card comment-box" id="contentArea" style="clear:float;">
                <?
  

                $sql = "Select * From  s_textdata where topic_id =  '".$topic_id."' order by seq_no ASC, id ASC";
                //        echo "sql....".$sql;//die;
                $retval           = mysqli_query( $conn, $sql );
                $count            = mysqli_num_rows($retval);
                //echo "count is  ".$count;die;
				
				$bookmark_id= insert_bookmark_auto($book_id,$chapter_id , $subject_id, $topic_id, $profile_language, $user_id);
				
				
				$count = getrecords_quiz($book_id,$chapter_id , $subject_id, $topic_id, $profile_language);
				//echo "count is ".$count;die;
				if ($count >0 )
				{ ?>
						<a href="quiz_index.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&textdata_id=<?=$textdata_id;?>&action=unlock" class="btn btn-sm btn-success btn-block">Quiz</a>
			
				<?} 
				$count = getrecords_question($book_id,$chapter_id , $subject_id, $topic_id, $profile_language);
				if ($count >0 )
				{?>
				
				<a href="page_questions_accordion.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>" class="btn btn-sm btn-success btn-block">Questions</a>
									
				<? }
				
				
                if ($userinfo['user_type'] =='admin' || $userinfo['user_type'] =='admin2')
                {

                        if ($book_id == 14  || $book_id == 66)
                        {
                            $program_name = "page_textdata2_dua.php";
                        }
						
                        else
                        {
                            $program_name = "page_textdata2.php";
                        }
						//$program_name = "page_textdata2.php";
                ?>
                 <a href="<?=$program_name;?>?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>" class="btn btn-sm btn-primary btn-block">List Pages /New Pages</a>
                 <a href="page_textdata3.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>" class="btn btn-sm btn-warning btn-block">Save Dictionary</a>

               
                 <a href="?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&action=commontopic" class="btn btn-sm btn-warning btn-block">Add as common topic</a>
                .
                 <a href="page_add_questions_mcq.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&action=commontopic" class="btn btn-sm btn-warning btn-block">MCQ Questions</a>
               .
                <a href="page_add_questions.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&action=commontopic" class="btn btn-sm btn-warning btn-block">Questions & Answers</a>
                 <a href="page-show-questions.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>" class="btn btn-sm btn-primary btn-block">Show Questions</a>
                . <a href="<?="page_textdata2_dua.php";?>?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>" class="btn btn-sm btn-primary btn-block">Add/Edit  dua</a>
                  
                   <a href="<?="page_add_topic.php";?>?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>" class="btn btn-sm btn-primary btn-block">Add/Edit Topic</a>
                     			


                <?
                }
                 if ($profile_language == "Arabic")
				  {
						 $button_title    = "الموضوع التالي";
				  }
				  else
				  {
						 $button_title    = "Next";
				  }
				
   while ($row_query = mysqli_fetch_assoc($retval)):

    // Determine which text to show
	$textdata_id = $row_query['id'];
	if ($userinfo['user_type'] =='admin' || $userinfo['user_type'] =='admin2')
    {?>
		   <a href="page_textdata2.php?user_id=<?=$user_id;?>&book_id=<?=$book_id;?>&chapter_id=<?=$chapter_id;?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&textdata_id=<?=$textdata_id;?>&action=edit" class="btn btn-sm btn-warning btn-block">Simple Edit</a>
					
	<?}

	
	
	
    if ($profile_language == "English") {
        $textdata = str_replace("~", "", $row_query['textdata_english']);
		
        $translation = $row_query['textdata_common_english'];
        $additional_info = $row_query['additional_info_english'];
    } elseif ($profile_language == "Urdu") {
        $textdata = str_replace("~", "", $row_query['textdata_urdu']);
        $textdata = str_replace("على (صلی اللہ علیہ والہ وسلم)", " على علیہ السلام", $textdata);
        $translation = $row_query['textdata_common_urdu'];
        $additional_info = $row_query['additional_info_urdu'];
    } else {
        $textdata = str_replace("~", "", $row_query['textdata_arabic']);
        $textdata = str_replace("صلی اللہ علیہ والہ وسلم", "علیہ السلام", $textdata);
        $translation = "";
        $additional_info = "";
    }
	$textdata_common = $row_query['textdata_common'];
	

$textdata_saved = $textdata;
$closing_tags = array(
    "</h3>",
    "</h4>",
    "</h5>",
    "</h6>"
);

// Replace all occurrences of these closing tags with a period and a space ". ".
$plaintext  = str_replace($closing_tags, ". ", $textdata);




// Define an array of all opening heading tags to be completely removed.
$opening_tags = array(
    "<h3>",
    "<h4>",
    "<h5>",
    "<h6>"
);

// Replace all occurrences of these opening tags with an empty string.
$plaintext = str_replace($opening_tags, "", $plaintext);




// Use a regular expression to replace one or more consecutive whitespace characters (\s+)
// with a single space (' '). This handles spaces, tabs, and newlines safely for Arabic text.
$plaintext = preg_replace('/\s+/', ' ', $plaintext);

// Remove any remaining whitespace from the very beginning or end of the string.
$plaintext = trim($plaintext);

// echo $textdata;
// Output for the example: Chapter Title. This is content. Section Heading. More text. A Subheading. Now the text starts. A small note. And some extra spaces.
?>
	
	
	
 

<!-- Actual block for highlighting & selection -->
<div class="text-block" id="textblock-<?= $row_query['id']; ?>">
    <?= $textdata; ?>
</div>




<!-- Virtual block for speech synthesis (hidden) -->
<div class="text-block-virtual" style="display:none" id="textblock-virtual-<?= $row_query['id']; ?>">
    <?= $plaintext; ?>
</div>

<?php if ($textdata_common  <> ""): ?>
    <div><?= $textdata_common; ?></div>
<?php endif; ?>


<?php if ($translation <> ""): 
    $js_variable = "myDIV1-".$row_query['id']; ?>
    <button onclick="myFunction_translation('<?= $js_variable; ?>')">Translation</button>
    <div id="<?= $js_variable; ?>" style="display:none">
        <?= $translation; ?>
    </div>
<?php endif; ?>

<?php if ($additional_info <> ""): ?>
    <div><?= $additional_info; ?></div>
<?php endif; ?>



<hr>
<?php endwhile; ?>

<!-- ✅ Single Play/Pause Button After Loop -->
<!-- ✅ Single Play/Pause Button After Loop -->











<?

/*  this is for next button alok ji */ 
//echo "i am here stopping";
$topic_id_new = get_nexttopic($chapter_id, $subject_id, $topic_id);

if ($topic_id_new > 0) {
    $chapter_id_new = $chapter_id;
    $subject_id_new = $subject_id;
    $nextbutton = 1;
} else {
    $inforec = get_nextsubject_new($chapter_id, $subject_id);
    $subject_id_new = $inforec['id'] ?? '';

    if (!empty($inforec)) {
        $subject_id_new = $inforec['id'];
        $topic_id_new = get_nexttopic($chapter_id, $subject_id_new, 0);
        $chapter_id_new = $chapter_id;
        $nextbutton = 1;
    } else {
        $chapter_id_new = get_nextchapter($book_id, $chapter_id);
        if ($chapter_id_new > 0) {
            $subject_id_new = get_first_subject($chapter_id_new);
            $topic_id_new = get_first_topic($subject_id_new);
            $nextbutton = 1;
        }
    }
}

	if (isset($nextbutton) && $nextbutton == 1)
	{ ?>
    <div class="mt-2">
        <a href="?book_id=<?= $book_id; ?>&chapter_id=<?= $chapter_id_new; ?>&subject_id=<?= $subject_id_new; ?>&topic_id=<?= $topic_id_new; ?>" class="item">
            <button class="btn btn-warning btn-block" name="upload_documents"><?= $button_title; ?></button>
        </a>
    </div>
<?php }  ?>








                
                <div class="text">
                  <!--Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed laoreet leo eget maximus ultricies.-->
                </div>
            </div>
        </div>

    <!-- * App Capsule -->


     <!-- App Bottom Menu -->
     
     
        <?
                      if ($profile_language == "Arabic")
                      {
                        $b_books = "كتب";
                        $b_menu = "قائمة";
                        $b_chapters = "فصول";
                        $b_gotobookmark = "انتقل إلى الإشارة المرجعية";
                        $b_subjects="المواضيع";
                        $b_addbookmark =  "اضافة للمفضلة";
                        $b_increasefont = "تكبير الخط";
                        $b_decreasefont = "تقليل الخط";
                      }
                      else
                      {
                        $b_books = "Books";
                        $b_menu  = "Menu";
                        $b_chapters = "Chapters";
                        $b_gotobookmark = "Goto Bookmark";
                        $b_subjects="Subjects";
                        $b_addbookmark = "Add Bookmark";
                        $b_increasefont = "Increase font";
                        $b_decreasefont = "Decrease Font";
                      }
                ?>
     
         <div class="appBottomMenu">

        <a href="home.php" class="item">
            <div class="col">
                <ion-icon name="book-outline"></ion-icon>
                  <strong><?=$b_books;?></strong>
            </div>
        </a>



        <a href="page-select-chapter.php?book_id=<?= $book_id; ?>" class="item">

            <div class="col">
              <ion-icon name="reader-outline"></ion-icon>
              <strong><?=$b_chapters;?></strong>
            </div>
        </a>

        <?$program_name =  "page-select-subject_list.php";




        ?>
       
       <?
            if ($book_id == 45 || $book_id == 41 )
            { ?>
            
                  <a href="page-select-subject_list.php?book_id=<?= $book_id; ?>&chapter_id=<?=$chapter_id; ?>" class="item">

                      <div class="col">
                        <ion-icon name="reader-outline"></ion-icon>
                        <strong>Subject </strong>
                      </div>
                  </a>
            
            
            
            
            
            
            
            
            
            
        <?    }?>
       
       
       
       
       
          

          <a href="page-display_pdf.php?book_id=<?= $book_id; ?>&chapter_id=<?=$chapter_id; ?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&textdata_id=<?=$textdata_id;?>&action=addbookmark" class="item">

            <div class="col">
               <ion-icon name="star-half-outline"></ion-icon>
               <strong><?=$b_addbookmark;?></strong>
            </div>
        </a>







         <a href="page-show_bookmark.php?book_id=<?= $book_id; ?>&chapter_id=<?=$chapter_id; ?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&textdata_id=<?=$textdata_id;?>&action=addbookmark" class="item">

            <div class="col">
              <ion-icon name="star-outline"></ion-icon>
               <strong><?=$b_gotobookmark;?></strong>
            </div>
        </a>
       
       <?
       
        if ($profile_language == "Urdu" || $profile_language == "English")
        {?>
       
       
       


          
        
        
        <?}?>
        <?
        $linkdata = "referred_by-".$_SESSION['passcode']."-".$book_id."-".$chapter_id."-".$subject_id."-".$topic_id."-".$_SESSION['passcode']."-".$profile_language;
        $url_str = "www.sulaimania.org/page-display_pdf.php?linkdata=".$linkdata;
       // echo "url: ".$url_str;
        ?>
         <a href="whatsapp://send?text=<d?=$url_str;?>" data-action="share/whatsapp/share"class="item">


           <a href="page-display_pdf.php?book_id=<?= $book_id; ?>&chapter_id=<?=$chapter_id; ?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&textdata_id=<?=$textdata_id;?>&action=decrease" class="item">
            <div class="col">
                <ion-icon name="caret-down-outline"></ion-icon>
               <strong><?=$b_decreasefont;?></strong>
            </div>
        </a>



          <a href="page-display_pdf.php?book_id=<?= $book_id; ?>&chapter_id=<?=$chapter_id; ?>&subject_id=<?=$subject_id;?>&topic_id=<?=$topic_id;?>&textdata_id=<?=$textdata_id;?>&action=increase" class="item">
            <div class="col">
                <ion-icon name="caret-up-outline"></ion-icon>
                 <strong><?=$b_increasefont;?></strong>
            </div>
            </a>
    
</div>
           
 
    <!-- * App Bottom Menu -->

    <!-- App Sidebar -->

    <!-- * App Sidebar -->


 <div class="modal fade modalbox" id="ModalForm111" data-bs-backdrop="static" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
                <a href="#" data-bs-dismiss="modal">Close</a>
            </div>
            <div class="modal-body">
                <div class="login-form">
                    <div class="section mt-2">
                        <h2>Upload Audio </h2>
                        <h4>Upload the audio for this section...</h4>
                    </div>
                    <div class="section mt-4 mb-5">
                          <form   method="post" action="?student_rec_id=<?=$student_rec_id;?>" class="form-horizontal" enctype="multipart/form-data"  >


                              <div class="form-group boxed">

                              <div class="input-group image-preview form-group">
                                  <label class="form-label" for="exampleTextarea1">Recitation Name</label>
                            <input type="text" name = "recitation" class="form-control" value  = "<?=$recitation;?>" placeholder="recitation...">


                          </div>

                        </div>
                          <div class="mt-2">
                                <button class="btn btn-primary btn-block" name = "upload_documents" type="submit">Upload</button>
                          </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade dialogbox" id="translate_data_popup" data-bs-backdrop="static" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="max-width: 100%;">
                <div class="modal-header">
                    <h5 class="modal-title">Select Decision</h5>
                </div>
                <div class="modal-body">
                  <form>
                      <div class="row">
                        <div class="form-group boxed col-md-12">
                            
                            <span id="select_txt"></span>
                        </div>
                        <hr>
                        <div class="form-group boxed col-md-12">
                            <div class="input-wrapper"> 
                                  <?php 
                                  $get_info_for_user = get_user_data($user_id);
                                    if($profile_language=='Urdu'){
                                      $trans_text = $get_info_for_user['lang_urdu'];
                                    }
                                    if($profile_language=='English'){
                                      $trans_text = $get_info_for_user['lang_english'];
                                    }
                                    if($profile_language=='Arabic'){
                                      $trans_text = $get_info_for_user['lang_arabic'];
                                    }
                                  ?>
                                  <input style="display: none;" type="radio" id="target_lang" checked value="<?php echo $trans_text;?>" > <label><strong> <?php //echo $trans_text; ?></strong></label>
                            </div>
                            <div class="trst_dt" style="display:none;">
                                  <h3>Translated Text</h3>
                                  <div id="translated_text_got"></div>
                            </div>
                            <div class="play_dt" style="display:none;">
                                  <h3>Play Audio</h3>
                                  <div id="translated_video_got"></div>
                            </div>
                        </div>
                      </div>
                      <!-- <button type="button" class="btn btn-sm btn-primary mt-5 play_translate">Play  Translate</button>
                      <button type="button" class="btn btn-sm btn-primary mt-5 change_text">Translate Text</button> -->
                  </form>
                </div>
                <div class="modal-footer">
                    <div class="btn-list">
                        <a href="javascript:void(0)" id="close_popup_frt" class="btn btn-text-secondary btn-block close" data-bs-dismiss="modal">CLOSE</a>
                    </div>
            </div>
          </div>
</div>
</div>
 <audio id="playaudio" controls="controls" style="display: none;" autoplay>
        <source id="audioSource" src="">
        </source>
    </audio>
    <div class="modal fade dialogbox" id="trans_lang"  tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="max-width: 100%;">
                <div class="modal-header">
                    <h5 class="modal-title">Select Decision</h5>
                </div>
                <div class="modal-body">
                    <div class="row">
                      
                      <div class=" boxed col-md-12">
                        <button class="btn btn-primary translate_section_form" id="arbut"  data-value="ar" >Arabic</button>
                        <button class="btn btn-primary translate_section_form" id="urbut" data-value="ur">Urdu</button>
                        <button class="btn btn-primary translate_section_form" id="enbut" data-value="en">English</button>
                        <input type="hidden" name="selectedtext1" id="selectedtext1" value="">
                        <input type="hidden" name="textid" id="textid" value="">
                        <input type="hidden" name="sourcelang" id="sourcelang" value="">
                        <input type="hidden" name="objectinfo" id="objectinfo" value="">
                      </div>
                    </div>
                </div>
            </div>
        </div>
      </div>
                               
        <!-- * Modal Form -->
    <!-- ============== Js Files ==============  -->
    <!-- Bootstrap -->
    <script src="assets/js/lib/bootstrap.min.js"></script>
    <!-- Ionicons -->
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <!-- Splide -->
    <script src="assets/js/plugins/splide/splide.min.js"></script>
    <!-- ProgressBar js -->
    <script src="assets/js/plugins/progressbar-js/progressbar.min.js"></script>
    <!-- Base Js File -->
    <script src="assets/js/base.js"></script>
    <script src="https://code.jquery.com/jquery-latest.min.js?<?php time();?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/langdetect-js@0.0.3/index.min.js"></script>


<!-- Floating Small Play Button CSS -->
<style>
.floating-play-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background-color: #28a745;
  color: white;
  border: none;
  font-size: 24px;
  cursor: pointer;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 10px rgba(0,0,0,0.2);
  transition: transform 0.2s;
}
.floating-play-btn:hover {
  transform: scale(1.1);
}
.highlighted {
  background: yellow;
  color: red;
  font-weight: bold;
  border-radius: 3px;
  padding: 1px 3px;
}
.translation {
  background: #f0f0f0;
  padding: 5px 10px;
  margin-top: 5px;
  font-style: italic;
  border-left: 3px solid #007bff;
}
</style>

<!-- Floating Play Button -->


<button id="playBtn" class="floating-play-btn">▶️</button>

<style>
.floating-play-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px; height: 50px;
  border-radius: 50%;
  background-color: #28a745;
  color: white;
  border: none;
  font-size: 24px;
  cursor: pointer;
  z-index: 1000;
}
.highlighted {
  background: yellow;
  color: red;
  font-weight: bold;
  border-radius: 3px;
  padding: 1px 3px;
}
</style>

<div class="text-block" style="margin:20px; font-size:20px; line-height:1.6;"></div>

<script>
let synth = window.speechSynthesis;
let voices = [];
let isPlaying = false;
let isPaused = false;
let utterance = null;

// Load voices
function loadVoices() { voices = synth.getVoices(); }
loadVoices();
if (speechSynthesis.onvoiceschanged !== undefined) speechSynthesis.onvoiceschanged = loadVoices;

// Detect language and pick voice
function getVoice(text) {
  return voices.find(v => /[\u0600-\u06FF]/.test(text) && v.lang.startsWith("ur")) || // Urdu
         voices.find(v => /[ء-ي]/.test(text) && v.lang.startsWith("ar")) ||          // Arabic
         voices.find(v => v.lang.startsWith("en")) || voices[0];
}

// Highlight word by index
function highlightWord(index) {
  document.querySelectorAll(".text-block span").forEach(span => span.classList.remove("highlighted"));
  const span = document.querySelector(`.text-block span[data-word-index='${index}']`);
  if (span) {
    span.classList.add("highlighted");
    span.scrollIntoView({ behavior: "smooth", block: "center" });
  }
}

// Prepare text: wrap words in spans
function wrapWords(text) {
  const container = document.querySelector(".text-block");
  container.innerHTML = "";
  const words = text.split(/\s+/);
  words.forEach((word, i) => {
    const span = document.createElement("span");
    span.textContent = word + " ";
    span.setAttribute("data-word-index", i);
    container.appendChild(span);
  });
  return words;
}

// 🧹 Clean text: remove tags, extra spaces, add pauses where tags exist
function cleanText(raw) {
  let cleaned = raw
    .replace(/<\/?(tag1|tag2|tag3|tag4|tag5|tag6)[^>]*>/gi, ". ") // replace tag groups with pause
    .replace(/<[^>]+>/g, " ")  // remove all remaining HTML tags
    .replace(/\s+/g, " ")      // collapse spaces
    .replace(/\.+/g, ".")      // normalize dots
    .trim();
  return cleaned;
}

// 🎵 Play or pause text
function toggleSpeech(text, originalHTML) {
  const btn = document.getElementById("playBtn");
  const textBlock = document.querySelector(".text-block");

  // Resume paused speech
  if (isPaused) {
    // 👇 Rebuild textdata from saved formatted HTML, clean again before resume
    const freshCleaned = cleanText(originalHTML);
    synth.resume();
    isPaused = false;
    isPlaying = true;
    btn.textContent = "⏸️";
    // Re-render the cleaned text and reapply spans (so highlighting resumes properly)
    wrapWords(freshCleaned);
    return;
  }

  // Pause currently playing speech
  if (isPlaying) {
    synth.pause();
    isPaused = true;
    isPlaying = false;
    btn.textContent = "▶️";
    textBlock.innerHTML = originalHTML; // restore formatted text when paused
    return;
  }

  // Start new speech
  const words = wrapWords(text); // split and wrap words
  utterance = new SpeechSynthesisUtterance(text);
  utterance.voice = getVoice(text);
  utterance.rate = 1;
  utterance.pitch = 1;

  utterance.onstart = () => {
    isPlaying = true;
    isPaused = false;
    btn.textContent = "⏸️";
  };

  utterance.onboundary = (event) => {
    if (event.charIndex !== undefined) {
      const spokenText = text.slice(0, event.charIndex);
      const wordIndex = spokenText.split(/\s+/).length - 1;
      highlightWord(wordIndex);
    }
  };

  utterance.onend = () => {
    isPlaying = false;
    isPaused = false;
    btn.textContent = "▶️";
    textBlock.innerHTML = originalHTML;
  };

  utterance.onerror = () => {
    isPlaying = false;
    isPaused = false;
    btn.textContent = "▶️";
    textBlock.innerHTML = originalHTML;
  };

  synth.cancel(); // stop any ongoing speech
  synth.speak(utterance);
}

// 🖱️ Button click
document.getElementById("playBtn").addEventListener("click", () => {
  let textdata = `<?= str_replace("`", "'", $textdata); ?>`; 
  let textdata_saved = `<?= str_replace("`", "'", $textdata_saved); ?>`; // formatted version

  const cleaned = cleanText(textdata);
  toggleSpeech(cleaned, textdata_saved);
});
</script>
<?
include 'texttranslate.php'
?>









<script>
/* ---------- Text Selection & Translation ---------- */
async function handleTextSelection() {
  const selection = window.getSelection();
  const selectedText = selection.toString().trim();
  if (!selectedText) return;

  const range = selection.getRangeAt(0);
  const parentBlock = range.startContainer.parentNode.closest('.text-block');
  if (!parentBlock) return;

  try {
    const response = await fetch('translate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text: selectedText })
    });

    const data = await response.json();

    if (data.translation) {
      let existing = parentBlock.querySelector('.translation');
      if (existing) existing.remove();

      const div = document.createElement('div');
      div.className = 'translation';
      div.textContent = data.translation; 
      parentBlock.appendChild(div);
    }
  } catch (err) {
    console.error('Translation API error:', err);
  }

  setTimeout(() => selection.removeAllRanges(), 1000);
}

// Desktop & Touch support
document.addEventListener('mouseup', handleTextSelection);
document.addEventListener('touchend', handleTextSelection);
</script>




<div id="tooltip"></div>
</body>

</html>
