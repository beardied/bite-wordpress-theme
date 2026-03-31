<?php
/**
 * Template Name: Default Page (Gutenberg Compatible)
 *
 * A clean, centered template for standard pages like Privacy Policy, Terms of Service,
 * and other content-heavy pages. Fully supports Gutenberg blocks.
 *
 * @package BITE-theme
 */

get_header();
?>

<main id="main" class="bite-default-page" role="main">
    <div class="bite-default-container">
        
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                
                <article id="post-<?php the_ID(); ?>" <?php post_class( 'bite-default-article' ); ?>>
                    
                    <?php if ( ! is_front_page() ) : ?>
                        <header class="bite-default-header">
                            <?php the_title( '<h1 class="bite-default-title">', '</h1>' ); ?>
                        </header>
                    <?php endif; ?>
                    
                    <div class="bite-default-content entry-content">
                        <?php the_content(); ?>
                    </div>
                    
                </article>
                
            <?php endwhile; ?>
        <?php endif; ?>
        
    </div>
</main>

<?php
get_footer();
