<?php
/**
 * Template Name: BITE Reviews
 *
 * Displays customer reviews and ratings.
 *
 * @package BITE-theme
 */

get_header();

global $wpdb;
$reviews_table = $wpdb->prefix . 'bite_reviews';

// Get approved reviews
$reviews = $wpdb->get_results( 
    "SELECT * FROM $reviews_table WHERE is_approved = 1 ORDER BY created_at DESC",
    ARRAY_A
);

// Calculate average rating
$avg_rating = 0;
$total_reviews = count( $reviews );
if ( $total_reviews > 0 ) {
    $sum = array_sum( array_column( $reviews, 'rating' ) );
    $avg_rating = round( $sum / $total_reviews, 1 );
}

?>

<main id="main" class="bite-reviews-page" role="main">
    
    <!-- Reviews Header -->
    <section class="bite-reviews-header">
        <div class="bite-reviews-header-content">
            <h1 class="bite-reviews-title">What Our Users Say</h1>
            
            <?php if ( $total_reviews > 0 ) : ?>
                <div class="bite-reviews-summary">
                    <div class="bite-average-rating">
                        <span class="bite-rating-number"><?php echo number_format( $avg_rating, 1 ); ?></span>
                        <div class="bite-rating-stars">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                <span class="bite-star <?php echo $i <= round( $avg_rating ) ? 'filled' : ''; ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <span class="bite-total-reviews">Based on <?php echo $total_reviews; ?> review<?php echo $total_reviews !== 1 ? 's' : ''; ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Reviews Grid -->
    <section class="bite-reviews-section">
        <div class="bite-reviews-container">
            
            <?php if ( ! empty( $reviews ) ) : ?>
                <div class="bite-reviews-grid">
                    <?php foreach ( $reviews as $review ) : ?>
                        <div class="bite-review-card">
                            <div class="bite-review-header">
                                <div class="bite-review-stars">
                                    <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                        <span class="bite-star <?php echo $i <= $review['rating'] ? 'filled' : ''; ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                                <span class="bite-review-date"><?php echo esc_html( date( 'F Y', strtotime( $review['created_at'] ) ) ); ?></span>
                            </div>
                            
                            <?php if ( ! empty( $review['review_text'] ) ) : ?>
                                <div class="bite-review-text">
                                    <p><?php echo esc_html( $review['review_text'] ); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="bite-review-author">
                                <span class="bite-author-name"><?php echo esc_html( $review['user_name'] ); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="bite-reviews-empty">
                    <span class="material-icons">reviews</span>
                    <p>No reviews yet. Be the first to share your experience!</p>
                </div>
            <?php endif; ?>
            
        </div>
    </section>

    <!-- CTA Section -->
    <section class="bite-reviews-cta">
        <div class="bite-reviews-cta-content">
            <h2>Ready to Experience B.I.T.E.?</h2>
            <p>Join our satisfied users and unlock the power of your search data.</p>
            <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" class="bite-button bite-button-large">Request Access</a>
        </div>
    </section>

</main>

<?php
get_footer();
