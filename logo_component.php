<?php
function logo($context = 'nav', $link = 'index.php') {
    $html = '<a href="' . htmlspecialchars($link) . '" class="logo">';
    $html .= '<div class="logo-icon">🛟</div>';
    $html .= '<div class="logo-text">';
    $html .= '<div class="logo-title">StressReleasor</div>';
    $html .= '<div class="logo-tagline">Always here to help & support</div>';
    $html .= '</div>';
    $html .= '</a>';
    
    return $html;
}
?>