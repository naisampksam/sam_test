<?php
/**
 * Page not found.
 *
 * @package Looma_Apparels
 */

get_header();
?>

<main id="main" class="section page-main">
	<div class="container narrow center">
		<p class="eyebrow">404</p>
		<h1 class="page-title"><?php esc_html_e( 'Page not found', 'looma' ); ?></h1>
		<p><?php esc_html_e( 'The page you are looking for does not exist or has moved.', 'looma' ); ?></p>
		<p><a class="btn btn-dark" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'looma' ); ?></a></p>
	</div>
</main>

<?php
get_footer();
