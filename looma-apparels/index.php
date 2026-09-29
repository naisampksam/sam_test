<?php
/**
 * Default template (blog, archives, search).
 *
 * @package Looma_Apparels
 */

get_header();
?>

<main id="main" class="section page-main">
	<div class="container narrow">
		<?php if ( is_home() && ! is_front_page() ) : ?>
			<h1 class="page-title"><?php single_post_title(); ?></h1>
		<?php elseif ( is_archive() ) : ?>
			<?php the_archive_title( '<h1 class="page-title">', '</h1>' ); ?>
		<?php elseif ( is_search() ) : ?>
			<h1 class="page-title"><?php printf( esc_html__( 'Search results for “%s”', 'looma' ), esc_html( get_search_query() ) ); ?></h1>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="post-list">
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-item' ); ?>>
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="post-thumb"><?php the_post_thumbnail( 'medium_large' ); ?></a>
						<?php endif; ?>
						<h2 class="post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<p class="post-date"><?php echo esc_html( get_the_date() ); ?></p>
						<div class="entry-summary"><?php the_excerpt(); ?></div>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found.', 'looma' ); ?></p>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
