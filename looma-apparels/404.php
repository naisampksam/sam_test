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
		<p class="section-sub" style="margin:0 auto 28px"><?php esc_html_e( 'The page you are looking for does not exist or has moved. Try one of these:', 'looma' ); ?></p>
		<p class="not-found-links">
			<a class="btn btn-dark" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'looma' ); ?></a>
			<a class="btn btn-outline" href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'Shop', 'looma' ); ?></a>
			<a class="btn btn-outline" href="<?php echo esc_url( looma_page_url( 'price-estimator' ) ); ?>"><?php esc_html_e( 'Price Estimator', 'looma' ); ?></a>
			<a class="btn btn-outline" href="<?php echo esc_url( looma_page_url( 'contact' ) ); ?>"><?php esc_html_e( 'Contact', 'looma' ); ?></a>
		</p>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<p class="fineprint"><?php esc_html_e( 'Admin tip: if website pages are missing, open', 'looma' ); ?> <a href="<?php echo esc_url( admin_url( 'themes.php?page=looma-setup' ) ); ?>"><?php esc_html_e( 'Appearance → Looma Setup', 'looma' ); ?></a>.</p>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
