<?php
/**
 * Pages and single posts (e.g. Privacy Policy, Terms, blog posts).
 *
 * @package Looma_Apparels
 */

get_header();
?>

<main id="main" class="section page-main">
	<div class="container narrow">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<h1 class="page-title"><?php the_title(); ?></h1>
				<?php if ( is_single() ) : ?>
					<p class="post-date"><?php echo esc_html( get_the_date() ); ?></p>
				<?php endif; ?>
				<?php if ( has_post_thumbnail() ) : ?>
					<div class="page-thumb"><?php the_post_thumbnail( 'large' ); ?></div>
				<?php endif; ?>
				<div class="entry-content">
					<?php the_content(); ?>
					<?php wp_link_pages(); ?>
				</div>
			</article>
		<?php endwhile; ?>
	</div>
</main>

<?php
get_footer();
