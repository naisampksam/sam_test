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

<div class="topbar">
	<div class="container topbar-inner">
		<span><?php esc_html_e( 'Custom manufacture for bigger ideas', 'looma' ); ?></span>
		<span class="topbar-right">
			<a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', looma_opt( 'looma_phone' ) ) ); ?>"><?php echo esc_html( looma_opt( 'looma_phone' ) ); ?></a>
			<span class="sep" aria-hidden="true">|</span>
			<?php esc_html_e( 'Pan-India delivery', 'looma' ); ?>
		</span>
	</div>
</div>

<header class="site-header" id="top">
	<div class="container header-inner">
		<?php looma_logo(); ?>

		<nav class="primary-nav" id="primary-nav" aria-label="<?php esc_attr_e( 'Main', 'looma' ); ?>">
			<?php looma_primary_menu(); ?>
			<a class="btn btn-dark nav-cta" href="<?php echo esc_url( looma_section_url( 'contact' ) ); ?>"><?php esc_html_e( 'Get a Quote', 'looma' ); ?></a>
		</nav>

		<button class="nav-toggle" type="button" aria-controls="primary-nav" aria-expanded="false">
			<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'looma' ); ?></span>
			<span class="nav-toggle-open"><?php echo looma_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<span class="nav-toggle-close"><?php echo looma_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		</button>
	</div>
</header>
