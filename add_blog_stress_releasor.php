<?php
include 'functionlib.php';
include 'dbconnect_hdb.php';

$pagename = "blog_admin";
session_start();
$_SESSION['start'] = time(); 
$updatemsgtype = "";
$_SESSION['expire'] = $_SESSION['start'] + (15 * 60);

$updatemsg = isset($_GET['updatemsg']) ? escape($_GET['updatemsg']) : '';
$updatemsgtype = isset($_GET['updatemsgtype']) ? escape($_GET['updatemsgtype']) : '';

$user_id = isset($_GET["user_id"]) ? escape($_GET["user_id"]) : '2';
$action = isset($_GET["action"]) ? escape($_GET["action"]) : '';
$blog_id = isset($_GET["blog_id"]) ? escape($_GET["blog_id"]) : '';

// --- PAGINATION ---
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20; // Show 20 blogs per page
$offset = ($page - 1) * $per_page;

// --- LANGUAGE FILTER LOGIC ---
$current_lang = isset($_GET['lang']) ? escape($_GET['lang']) : 'en';
$languages = [
    'en' => 'English',
    'ur' => 'Urdu',
    'ar' => 'Arabic',
    'hi' => 'Hindi',
    'es' => 'Spanish',
    'fr' => 'French'
];

$editflag = "off";
$image_dir = 'blog_images/';
if (!file_exists($image_dir)) { mkdir($image_dir, 0755, true); }

// --- ACTION: GENERATE NEW BLANK RECORD ---
if(isset($_POST['generate_record'])) {
    $todays_date = date('Y-m-d');
    $query = "INSERT INTO hdb_blog_pages (`blog_title`, `category`, `blog_date`, `status`, `lang_code`) 
              VALUES ('New Blog Post ($current_lang)', 'Uncategorized', '$todays_date', 'draft', '$current_lang')";
    
    if(mysqli_query($conn, $query)) {
        $new_id = mysqli_insert_id($conn);
        header("Location: add_blog_stress_releasor.php?user_id=$user_id&blog_id=$new_id&action=edit&lang=$current_lang");
        exit;
    }
}

// --- ACTION: LOAD FOR EDIT ---
if ($action == 'edit' && !empty($blog_id)) {
    $query = "SELECT * FROM hdb_blog_pages WHERE id = '".$blog_id."' LIMIT 1";
    $res_data = mysqli_query($conn, $query);
    if($res_data && mysqli_num_rows($res_data) > 0) {
        $data_row = mysqli_fetch_array($res_data);
        extract($data_row); 
        $editflag = "on";
    }
}

// --- ACTION: DELETE ---
if ($action == 'delete' && !empty($blog_id)) {
    mysqli_query($conn, "DELETE FROM hdb_blog_pages WHERE id = '$blog_id'");
    header("Location: add_blog_stress_releasor.php?user_id=$user_id&lang=$current_lang&updatemsg=Blog Deleted&updatemsgtype=0");
    exit;
}

// --- ACTION: UPDATE RECORD ---
if(isset($_POST['update_record'])) {
    $blog_id = cleanstring($_GET["blog_id"]);
    
    $blog_title = escape($_POST['blog_title']);
    $blog_excerpt = escape($_POST['blog_excerpt']);
    $blog_summary = escape($_POST['blog_summary']);
    $category = escape($_POST['category']);
    $blog_date = escape($_POST['blog_date']);
    $blog_content = escape($_POST['blog_content']);
    $meta_title = escape($_POST['meta_title']);
    $meta_description = escape($_POST['meta_description']);
    $slug = escape($_POST['slug']);
    $keywords = escape($_POST['keywords']);
    $lang_to_save = escape($_POST['lang_code']); 
    
    $blog_audio = escape($_POST['blog_audio']);
    $main_video = escape($_POST['main_video']);
    $short_video_1 = escape($_POST['short_video_1']);
    $short_video_2 = escape($_POST['short_video_2']);
    $short_video_3 = escape($_POST['short_video_3']);
    
    $new_img = escape($_POST['existing_blog_image']);
    if(isset($_FILES['blog_image_file']) && $_FILES['blog_image_file']['error'] == 0) {
        $img_ext = strtolower(pathinfo($_FILES['blog_image_file']['name'], PATHINFO_EXTENSION));
        $new_img = 'img_' . time() . '.' . $img_ext;
        move_uploaded_file($_FILES['blog_image_file']['tmp_name'], $image_dir . $new_img);
    }

    $sql = "UPDATE hdb_blog_pages SET 
                blog_title='$blog_title', blog_excerpt='$blog_excerpt', blog_summary='$blog_summary', 
                category='$category', blog_date='$blog_date', lang_code='$lang_to_save',
                blog_audio='$blog_audio', main_video='$main_video', short_video_1='$short_video_1',
                short_video_2='$short_video_2', short_video_3='$short_video_3', page_details='$blog_content', 
                blog_image='$new_img', meta_title='$meta_title', meta_description='$meta_description', 
                slug='$slug', keywords='$keywords' WHERE id = '$blog_id'";
    
    if(!mysqli_query($conn,$sql)) {
        echo("Error in update: " . mysqli_error($conn)." query is: ".$sql);
        die;
    }
    
    header("Location: add_blog_stress_releasor.php?user_id=$user_id&lang=$lang_to_save&updatemsg=Blog Saved&updatemsgtype=0");
    exit;
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM hdb_blog_pages WHERE lang_code = '$current_lang'";
$count_result = mysqli_query($conn, $count_query);
$count_row = mysqli_fetch_assoc($count_result);
$total_blogs = $count_row['total'];
$total_pages = ceil($total_blogs / $per_page);

// Fetch blogs with pagination
$all_blogs = mysqli_query($conn, "SELECT * FROM hdb_blog_pages WHERE lang_code = '$current_lang' ORDER BY id DESC LIMIT $per_page OFFSET $offset");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Blog & Script Manager | Stress Releasor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .card { border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .lang-bar { background: #fff; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; border: 1px solid #e0e0e0; }
        .section-label { background: #495057; color: #fff; padding: 10px 15px; border-radius: 6px; margin: 25px 0 10px; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        .script-area { font-family: 'Courier New', monospace; font-size: 13px; background: #fdfdfd !important; border: 1px solid #ced4da; }
        .seo-section { background: #f8f9ff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0ff; }
        .btn-create { background: #28a745; color: white; padding: 10px 25px; font-weight: bold; }
        .pagination { margin: 20px 0; }
        .btn-view-page { background: #17a2b8; color: white; }
        .btn-view-page:hover { background: #138496; color: white; }
    </style>
</head>
<body>

<div class="container">
    <div class="lang-bar">
        <strong>Filter Language:</strong>
        <div class="btn-group" role="group">
            <?php foreach($languages as $code => $name): ?>
                <a href="?user_id=<?php echo $user_id; ?>&lang=<?php echo $code; ?>" 
                   class="btn <?php echo ($current_lang == $code) ? 'btn-primary' : 'btn-outline-primary'; ?>">
                   <?php echo $name; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="post" class="ms-auto">
            <button type="submit" name="generate_record" class="btn btn-create">➕ CREATE <?php echo strtoupper($languages[$current_lang]); ?> BLOG</button>
        </form>
    </div>

    <?php if(!empty($updatemsg)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $updatemsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if($editflag == "on"): ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="text-primary m-0">Editing: <?php echo htmlspecialchars($blog_title); ?></h4>
                <span class="badge bg-dark">Language: <?php echo $languages[$lang_code]; ?></span>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="existing_blog_image" value="<?php echo $blog_image; ?>">
                <input type="hidden" name="lang_code" value="<?php echo $lang_code; ?>">

                <div class="row">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Blog Title</label>
                        <input type="text" name="blog_title" class="form-control" value="<?php echo $blog_title; ?>" id="blog_title">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Date</label>
                        <input type="date" name="blog_date" class="form-control" value="<?php echo $blog_date; ?>">
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Blog Excerpt</label>
                        <textarea name="blog_excerpt" class="form-control" rows="3"><?php echo isset($blog_excerpt) ? $blog_excerpt : ''; ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Blog Summary</label>
                        <textarea name="blog_summary" class="form-control" rows="3"><?php echo isset($blog_summary) ? $blog_summary : ''; ?></textarea>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Category</label>
                        <?php
                        $cat_sql = "SELECT category_key FROM hdb_issue_categories ORDER BY id"; 
                        $cat_res = mysqli_query($conn, $cat_sql);
                        ?>
                        <select name="category" class="form-control" required>
                            <option value="">-- Select Category --</option>
                            <?php
                            if ($cat_res && mysqli_num_rows($cat_res) > 0) {
                                while ($row = mysqli_fetch_assoc($cat_res)) {
                                    $key = $row['category_key'];
                                    $name = $row['category_key'];
                                    $selected = ($category == $key) ? 'selected' : '';
                                    echo "<option value='" . htmlspecialchars($key) . "' $selected>" . htmlspecialchars($name) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">URL Slug</label>
                        <input type="text" name="slug" class="form-control" value="<?php echo isset($slug) ? $slug : ''; ?>" id="slug">
                    </div>
                </div>

                <div class="section-label">SEO & Meta Information</div>
                <div class="seo-section">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">Meta Title (60 chars)</label>
                            <input type="text" name="meta_title" class="form-control" value="<?php echo $meta_title; ?>" maxlength="60" id="meta_title">
                            <small class="text-muted">Count: <span id="meta-title-count">0</span>/60</small>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">Meta Description (160 chars)</label>
                            <textarea name="meta_description" class="form-control" rows="2" maxlength="160" id="meta_description"><?php echo $meta_description; ?></textarea>
                            <small class="text-muted">Count: <span id="meta-desc-count">0</span>/160</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Keywords</label>
                            <input type="text" name="keywords" class="form-control" value="<?php echo $keywords; ?>">
                        </div>
                    </div>
                </div>

                <div class="section-label">Audio & Video Scripts</div>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label">Audio Script</label>
                        <textarea name="blog_audio" class="form-control script-area" rows="3"><?php echo $blog_audio; ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Main Video Script</label>
                        <textarea name="main_video" class="form-control script-area" rows="3"><?php echo $main_video; ?></textarea>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-4">
                        <label class="form-label">Short Video 1</label>
                        <textarea name="short_video_1" class="form-control script-area" rows="3"><?php echo $short_video_1; ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Short Video 2</label>
                        <textarea name="short_video_2" class="form-control script-area" rows="3"><?php echo $short_video_2; ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Short Video 3</label>
                        <textarea name="short_video_3" class="form-control script-area" rows="3"><?php echo $short_video_3; ?></textarea>
                    </div>
                </div>

                <div class="section-label">Article Content & Image</div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Featured Image</label>
                    <input type="file" name="blog_image_file" class="form-control">
                    <?php if(!empty($blog_image)): ?><small>Current: <?php echo $blog_image; ?></small><?php endif; ?>
                </div>

                <div class="mt-4">
                    <label class="form-label fw-bold">Full Blog Content</label>
                    <textarea name="blog_content" class="form-control" rows="10"><?php echo $page_details; ?></textarea>
                </div>

                <button type="submit" name="update_record" class="btn btn-primary btn-lg mt-4 w-100">💾 SAVE ALL CHANGES</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card p-4">
        <h5 class="mb-3">Existing <?php echo $languages[$current_lang]; ?> Entries (<?php echo $total_blogs; ?> total)</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Title</th>
                        <th>Slug</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($all_blogs)): ?>
                    <tr>
                        <td><?php echo $row['blog_date']; ?></td>
                        <td><strong><?php echo $row['blog_title']; ?></strong></td>
                        <td><code><?php echo $row['slug']; ?></code></td>
                        <td class="text-end">
                            <a href="blog_page.php?slug=<?php echo $row['slug']; ?>&lang=<?php echo $row['lang_code']; ?>" 
                               target="_blank" 
                               class="btn btn-sm btn-view-page" 
                               title="View Published Page">
                               👁️ View
                            </a>
                            <a href="?user_id=<?php echo $user_id; ?>&blog_id=<?php echo $row['id']; ?>&action=edit&lang=<?php echo $current_lang; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <a href="?user_id=<?php echo $user_id; ?>&blog_id=<?php echo $row['id']; ?>&action=delete&lang=<?php echo $current_lang; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_pages > 1): ?>
        <nav aria-label="Blog pagination">
            <ul class="pagination justify-content-center">
                <!-- Previous Button -->
                <?php if($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?user_id=<?php echo $user_id; ?>&lang=<?php echo $current_lang; ?>&page=<?php echo ($page - 1); ?>">Previous</a>
                </li>
                <?php endif; ?>

                <!-- Page Numbers -->
                <?php
                // Show max 5 page numbers at a time
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if($start_page > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?user_id='.$user_id.'&lang='.$current_lang.'&page=1">1</a></li>';
                    if($start_page > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }
                
                for($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                    <a class="page-link" href="?user_id=<?php echo $user_id; ?>&lang=<?php echo $current_lang; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php 
                endfor;
                
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?user_id='.$user_id.'&lang='.$current_lang.'&page='.$total_pages.'">'.$total_pages.'</a></li>';
                }
                ?>

                <!-- Next Button -->
                <?php if($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?user_id=<?php echo $user_id; ?>&lang=<?php echo $current_lang; ?>&page=<?php echo ($page + 1); ?>">Next</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Logic to handle character counts and slug generation
document.getElementById('blog_title').addEventListener('input', function() {
    const slug = this.value.toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').trim();
    document.getElementById('slug').value = slug;
});

document.getElementById('meta_title').addEventListener('input', function() {
    document.getElementById('meta-title-count').textContent = this.value.length;
});

document.getElementById('meta_description').addEventListener('input', function() {
    document.getElementById('meta-desc-count').textContent = this.value.length;
});

// Initialize counts on page load
window.addEventListener('load', function() {
    const metaTitle = document.getElementById('meta_title');
    const metaDesc = document.getElementById('meta_description');
    if(metaTitle) document.getElementById('meta-title-count').textContent = metaTitle.value.length;
    if(metaDesc) document.getElementById('meta-desc-count').textContent = metaDesc.value.length;
});
</script>
</body>
</html>
