<?php
/**
 * Dashboard Sidebar Include
 *
 * Reusable slide-out sidebar for all dashboard/tool pages.
 * The tools menu (Dashboard through CTR Efficiency) is configurable via WordPress.
 * Admin link, Account, Help, and Logout are hardcoded below.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Only show for logged-in users
if ( ! is_user_logged_in() ) {
    return;
}

$current_user    = wp_get_current_user();
$is_admin        = current_user_can( 'manage_options' );

// Get current page slug for active state highlighting
$current_slug = '';
if ( is_page() ) {
    $current_slug = get_post_field( 'post_name', get_post() );
}

// Try to determine current page from template if slug is empty
if ( empty( $current_slug ) ) {
    $template = get_page_template_slug();
    if ( $template ) {
        $template_name = basename( $template, '.php' );
        $template_name = str_replace( 'template-', '', $template_name );
        $current_slug = $template_name;
    }
}

// Check if a sidebar menu is configured
$has_sidebar_menu = has_nav_menu( 'sidebar-menu' );
?>

<!-- Floating Toggle Button (visible when sidebar is collapsed) -->
<button id="bite-sidebar-toggle-float" class="bite-sidebar-toggle-float" aria-label="Open Menu" style="display: none;">
    <span class="material-icons">menu</span>
</button>

<!-- Slide-out Sidebar -->
<aside id="bite-sidebar" class="bite-sidebar">
    <div class="bite-sidebar-header">
        <button id="bite-sidebar-toggle" class="bite-sidebar-toggle" aria-label="Toggle Menu">
            <span class="bite-toggle-icon">☰</span>
        </button>
        <span class="bite-sidebar-title">Menu</span>
    </div>
    
    <nav class="bite-sidebar-nav">
        <?php if ( $has_sidebar_menu ) : ?>
            <!-- Configurable WordPress Menu -->
            <?php
            wp_nav_menu( array(
                'theme_location'  => 'sidebar-menu',
                'container'       => false,
                'menu_class'      => 'bite-sidebar-menu bite-sidebar-wp-menu',
                'fallback_cb'     => false,
                'items_wrap'      => '<ul class="%2$s">%3$s</ul>',
                'walker'          => new BITE_Sidebar_Menu_Walker(),
            ) );
            ?>
        <?php else : ?>
            <!-- Fallback: Default hardcoded menu -->
            <?php
            $dashboard_tools = array(
                array(
                    'slug'  => 'dashboard',
                    'title' => 'Dashboard',
                    'icon'  => 'dashboard',
                ),
                array(
                    'slug'  => 'data-view',
                    'title' => 'View Data',
                    'icon'  => 'insert_chart',
                ),
                array(
                    'slug'  => 'opportunity-finder',
                    'title' => 'Opportunity Finder',
                    'icon'  => 'search',
                ),
                array(
                    'slug'  => 'global-champions',
                    'title' => 'Global Champions',
                    'icon'  => 'emoji_events',
                ),
                array(
                    'slug'  => 'emerging-trends',
                    'title' => 'Emerging Trends',
                    'icon'  => 'trending_up',
                ),
                array(
                    'slug'  => 'keyword-explorer',
                    'title' => 'Keyword Explorer',
                    'icon'  => 'travel_explore',
                ),
                array(
                    'slug'  => 'ctr-efficiency',
                    'title' => 'CTR Efficiency',
                    'icon'  => 'speed',
                ),
            );
            ?>
            <ul class="bite-sidebar-menu">
                <?php foreach ( $dashboard_tools as $tool ) : 
                    // Get URL for the tool
                    if ( $tool['slug'] === 'dashboard' ) {
                        $dashboard_page = get_page_by_path( 'dashboard' );
                        $tool_url = $dashboard_page ? get_permalink( $dashboard_page->ID ) : home_url( '/' );
                    } else {
                        $page = get_page_by_path( $tool['slug'] );
                        $tool_url = $page ? get_permalink( $page->ID ) : home_url( '/' );
                    }
                    $is_active = ( $current_slug === $tool['slug'] ) ? 'active' : '';
                ?>
                    <li class="bite-menu-item <?php echo esc_attr( $is_active ); ?>">
                        <a href="<?php echo esc_url( $tool_url ); ?>">
                            <span class="bite-menu-icon material-icons"><?php echo esc_html( $tool['icon'] ); ?></span>
                            <span class="bite-menu-text"><?php echo esc_html( $tool['title'] ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        
        <?php if ( $is_admin ) : ?>
            <div class="bite-sidebar-divider"></div>
            <ul class="bite-sidebar-menu bite-sidebar-hardcoded">
                <li class="bite-menu-item">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>">
                        <span class="bite-menu-icon material-icons">settings</span>
                        <span class="bite-menu-text">Manage System</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>
    </nav>
    
    <div class="bite-sidebar-footer">
        
        <?php
        // Show review section after user has been registered for 1 day (for testing - change to 7 days in production)
        $user_registered = strtotime( $current_user->user_registered );
        $days_registered = ( time() - $user_registered ) / DAY_IN_SECONDS;
        $has_reviewed = get_user_meta( $current_user->ID, 'bite_review_submitted', true );
        $google_review_url = get_option( 'bite_google_review_url', '' );
        
        if ( $days_registered >= 1 && ! $has_reviewed ) : 
        ?>
        <!-- Review Section -->
        <div class="bite-sidebar-review" id="bite-review-section">
            <div class="bite-review-question">
                <p>How are you enjoying B.I.T.E.?</p>
                <div class="bite-review-stars">
                    <button class="bite-star" data-rating="1" title="1 star">★</button>
                    <button class="bite-star" data-rating="2" title="2 stars">★</button>
                    <button class="bite-star" data-rating="3" title="3 stars">★</button>
                    <button class="bite-star" data-rating="4" title="4 stars">★</button>
                    <button class="bite-star" data-rating="5" title="5 stars">★</button>
                </div>
            </div>
            
            <!-- Review Form (all ratings) -->
            <div class="bite-review-form-container" id="bite-review-form-container" style="display: none;">
                <p class="bite-review-message" id="bite-review-message">Tell us about your experience</p>
                <form id="bite-review-form" class="bite-review-form">
                    <input type="text" id="bite-reviewer-name" placeholder="Your name" value="<?php echo esc_attr( $current_user->display_name ); ?>">
                    <textarea id="bite-review-text" placeholder="Share your thoughts about B.I.T.E. (optional)" rows="3"></textarea>
                    <button type="submit" class="bite-review-button">
                        <span class="material-icons">send</span>
                        Submit Review
                    </button>
                </form>
                <button class="bite-review-skip" id="bite-skip-review">Skip</button>
            </div>
            
            <!-- Thank You Message -->
            <div class="bite-review-thanks" id="bite-review-thanks" style="display: none;">
                <p class="bite-review-message">Thank you for your review!</p>
                <a href="<?php echo esc_url( home_url( '/reviews/' ) ); ?>" class="bite-review-link">
                    <span class="material-icons">reviews</span>
                    View All Reviews
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Account Section -->
        <div class="bite-sidebar-account">
            <div class="bite-account-header">
                <span class="bite-account-avatar"><?php echo esc_html( strtoupper( substr( $current_user->display_name, 0, 1 ) ) ); ?></span>
                <div class="bite-account-details">
                    <span class="bite-account-name"><?php echo esc_html( $current_user->display_name ); ?></span>
                    <span class="bite-account-role"><?php echo $is_admin ? 'Administrator' : 'Client'; ?></span>
                </div>
            </div>
        </div>
        
        <!-- Help Section -->
        <div class="bite-sidebar-help">
            <a href="https://orangewidow.com" target="_blank" class="bite-help-link">
                <span class="bite-menu-icon material-icons">language</span>
                <span class="bite-menu-text">Visit OrangeWidow</span>
            </a>
        </div>
        
        <!-- Logout -->
        <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="bite-sidebar-logout">
            <span class="bite-menu-icon material-icons">logout</span>
            <span class="bite-menu-text">Log Out</span>
        </a>
    </div>
</aside>

<script>
(function() {
    const sidebar = document.getElementById('bite-sidebar');
    const toggleBtn = document.getElementById('bite-sidebar-toggle');
    const floatToggleBtn = document.getElementById('bite-sidebar-toggle-float');
    
    // Check for saved state
    const sidebarCollapsed = localStorage.getItem('bite-sidebar-collapsed');
    if (sidebarCollapsed === 'true') {
        sidebar.classList.add('collapsed');
        if (floatToggleBtn) floatToggleBtn.style.display = 'flex';
    }
    
    // Toggle function
    function toggleSidebar() {
        const isCollapsed = sidebar.classList.toggle('collapsed');
        if (floatToggleBtn) floatToggleBtn.style.display = isCollapsed ? 'flex' : 'none';
        localStorage.setItem('bite-sidebar-collapsed', isCollapsed);
        
        // Recalculate DataTables after sidebar transition completes
        setTimeout(function() {
            if (window.jQuery && jQuery.fn.DataTable) {
                jQuery('.bite-data-table').DataTable().columns.adjust();
            }
            // Trigger window resize for charts
            window.dispatchEvent(new Event('resize'));
        }, 350);
    }
    
    // Event listeners
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    if (floatToggleBtn) {
        floatToggleBtn.addEventListener('click', toggleSidebar);
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && floatToggleBtn && !floatToggleBtn.contains(e.target)) {
                if (!sidebar.classList.contains('collapsed')) {
                    toggleSidebar();
                }
            }
        }
    });
    
    // Review System
    const reviewSection = document.getElementById('bite-review-section');
    if (reviewSection) {
        const stars = reviewSection.querySelectorAll('.bite-star');
        const questionSection = reviewSection.querySelector('.bite-review-question');
        const formContainer = document.getElementById('bite-review-form-container');
        const thanksSection = document.getElementById('bite-review-thanks');
        const reviewForm = document.getElementById('bite-review-form');
        const skipReview = document.getElementById('bite-skip-review');
        const reviewMessage = document.getElementById('bite-review-message');
        
        let selectedRating = 0;
        
        // Star rating hover and click
        stars.forEach(function(star) {
            star.addEventListener('mouseenter', function() {
                const rating = parseInt(this.dataset.rating);
                highlightStars(rating);
            });
            
            star.addEventListener('mouseleave', function() {
                highlightStars(selectedRating);
            });
            
            star.addEventListener('click', function() {
                selectedRating = parseInt(this.dataset.rating);
                highlightStars(selectedRating);
                showReviewForm(selectedRating);
            });
        });
        
        function highlightStars(rating) {
            stars.forEach(function(star, index) {
                if (index < rating) {
                    star.classList.add('active');
                } else {
                    star.classList.remove('active');
                }
            });
        }
        
        function showReviewForm(rating) {
            // Hide question, show form
            questionSection.style.display = 'none';
            formContainer.style.display = 'block';
            
            // Update message based on rating
            if (rating >= 4) {
                reviewMessage.textContent = "We're so glad you're enjoying it! Would you like to share more?";
            } else if (rating >= 3) {
                reviewMessage.textContent = "Thank you for your feedback. How can we improve?";
            } else {
                reviewMessage.textContent = "We're sorry to hear that. Please let us know how we can improve.";
            }
        }
        
        // Skip button
        if (skipReview) {
            skipReview.addEventListener('click', function() {
                hideReviewSection();
            });
        }
        
        // Review form submission
        if (reviewForm) {
            reviewForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const name = document.getElementById('bite-reviewer-name').value;
                const reviewText = document.getElementById('bite-review-text').value;
                
                const formData = new FormData();
                formData.append('action', 'bite_submit_internal_review');
                formData.append('rating', selectedRating);
                formData.append('name', name);
                formData.append('review_text', reviewText);
                formData.append('nonce', '<?php echo wp_create_nonce( 'bite_review_nonce' ); ?>');
                
                fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                    method: 'POST',
                    body: formData
                }).then(function() {
                    formContainer.style.display = 'none';
                    thanksSection.style.display = 'block';
                });
            });
        }
        
        function hideReviewSection() {
            reviewSection.style.display = 'none';
        }
    }
})();
</script>
