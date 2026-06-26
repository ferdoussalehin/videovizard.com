   <section class="ftco-section testimony-section bg-secondary">
      <div class="container">
        <div class="row justify-content-center pb-5 mb-3">
          <div class="col-md-7 heading-section heading-section-white text-center ftco-animate">
           
			 <h2>کلائنٹ فیڈبیک </h2>
            
			 <span class="subheading">پیشہ ورانہ رازداری کی وجہ سے نام  تبدیل کردئے گئے ہیں </span>
			
          </div>
        </div>
		  <div class="row ftco-animate">
          <div class="col-md-12">
            <div class="carousel-testimony owl-carousel ftco-owl">
		
		<?
		$sql = "SELECT * FROM eh_feedback where feedback <> ''   ORDER BY feedback_date DESC,  id DESC";

        $result = $conn->query($sql);
        $total_rows      = mysqli_num_rows($result);
        //echo "rows ".$total_rows;
		 while ($row = $result->fetch_assoc()) 
		 {
			 ?>
			  <div class="item">
                <div class="testimony-wrap py-4">
                	<div class="icon d-flex align-items-center justify-content-center"><span class="fa fa-quote-left"></span></div>
                  <div class="text">
                    <p class="mb-4"><?=$row['feedback'];?>  </p>
                    <div class="d-flex align-items-center">
                    	<div class="user-img" style="background-image: url(images/person_1.jpg)"></div>
                    	<div class="pl-3">
		                    <p class="name"><?=$row['clientname'];?>
</p>
                            <span class="position"><?=$row['age'];?>
		                    <span class="position"><?=$row['location'];?>
							<span class="position"><?=$row['feedback_date'];?>
</span>
		                  </div>
	                  </div>
                  </div>
                </div>
              </div>
			 
		<? } ?>
  
 </div>
 </div>
 </div>
       
        
    </section>