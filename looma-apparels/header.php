<?php
/**
 * Site header.
 *
 * @package Looma_Apparels
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#0b1426">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'looma' ); ?></a>

<?php if ( looma_opt( 'looma_announce' ) ) : ?>
	<div class="announce"><div class="container"><?php echo esc_html( looma_opt( 'looma_announce' ) ); ?></div></div>
<?php endif; ?>

<header class="site-header">
	<div class="container header-inner">
		<?php looma_logo(); ?>

		<nav class="primary-nav" id="primary-nav" aria-label="<?php esc_attr_e( 'Main', 'looma' ); ?>">
			<?php looma_primary_menu(); ?>
			<div class="nav-mobile-extra">
				<a class="btn btn-wa btn-block" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like a quote.' ) ); ?>" target="_blank" rel="noopener"><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'WhatsApp us', 'looma' ); ?></a>
				<a class="nav-phone" href="<?php echo esc_attr( looma_tel() ); ?>"><?php looma_the_icon( 'phone' ); ?> <?php echo esc_html( looma_opt( 'looma_phone' ) ); ?></a>
			</div>
		</nav>

		<div class="header-actions">
			<a class="header-icon" href="<?php echo esc_url( looma_page_url( 'quote' ) ); ?>" aria-label="<?php esc_attr_e( 'Quote list', 'looma' ); ?>">
				<?php looma_the_icon( 'bag' ); ?><span class="quote-count" data-quote-count hidden>0</span>
			</a>
			<a class="btn btn-dark btn-sm header-cta" href="<?php echo esc_url( looma_page_url( 'contact' ) ); ?>"><?php esc_html_e( 'Get a Quote', 'looma' ); ?></a>
			<button class="nav-toggle" type="button" aria-controls="primary-nav" aria-expanded="false">
				<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'looma' ); ?></span>
				<span class="nav-toggle-open"><?php looma_the_icon( 'menu' ); ?></span>
				<span class="nav-toggle-close"><?php looma_the_icon( 'close' ); ?></span>
			</button>
		</div>
	</div>
</header>
