<?php
/**
 * One-time site setup when the theme is activated:
 * creates the pages, sets the home page, builds the menus
 * and switches on pretty links.
 *
 * Safe to run again — existing pages and menus are left alone.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pages the theme provides (slug => title). Each has a matching page-{slug}.php template.
 */
function looma_site_pages() {
	return array(
		'home'            => 'Home',
		'shop'            => 'Shop',
		'dropshipping'    => 'Dropshipping',
		'bulk-orders'     => 'Bulk Orders',
		'printing'        => 'Printing',
		'price-estimator' => 'Price Estimator',
		'size-guide'      => 'Size Guide',
		'about'           => 'About Us',
		'contact'         => 'Contact',
		'quote'           => 'Quote List',
	);
}

/**
 * Create pages, menus and settings.
 */
function looma_run_setup() {
	$ids = array();
	foreach ( looma_site_pages() as $slug => $title ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			$ids[ $slug ] = $page->ID;
			continue;
		}
		$ids[ $slug ] = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => '',
			)
		);
	}

	// Home page = the "home" page.
	if ( ! empty( $ids['home'] ) && ! is_wp_error( $ids['home'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['home'] );
	}

	// Pretty links (needed for /shop/product-name/ addresses).
	if ( ! get_option( 'permalink_structure' ) ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	// Menus.
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	$menus     = array(
		'primary' => array( 'Looma Main Menu', array( 'shop', 'dropshipping', 'bulk-orders', 'printing', 'price-estimator', 'contact' ) ),
		'footer'  => array( 'Looma Footer Menu', array( 'shop', 'dropshipping', 'bulk-orders', 'printing', 'price-estimator', 'size-guide', 'about', 'contact' ) ),
	);
	foreach ( $menus as $location => $menu ) {
		if ( ! empty( $locations[ $location ] ) && wp_get_nav_menu_object( $locations[ $location ] ) ) {
			continue;
		}
		$existing = wp_get_nav_menu_object( $menu[0] );
		$menu_id  = $existing ? $existing->term_id : wp_create_nav_menu( $menu[0] );
		if ( is_wp_error( $menu_id ) ) {
			continue;
		}
		if ( ! $existing ) {
			foreach ( $menu[1] as $slug ) {
				if ( empty( $ids[ $slug ] ) || is_wp_error( $ids[ $slug ] ) ) {
					continue;
				}
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-object-id' => $ids[ $slug ],
						'menu-item-object'    => 'page',
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
					)
				);
			}
		}
		$locations[ $location ] = $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $locations );

	looma_rewrite_rules();
	flush_rewrite_rules();
	update_option( 'looma_setup_done', LOOMA_VERSION );
}
add_action( 'after_switch_theme', 'looma_run_setup' );

/**
 * Admin notice after activation.
 */
function looma_setup_notice() {
	if ( ! current_user_can( 'manage_options' ) || get_option( 'looma_setup_notice_dismissed' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'themes' !== $screen->id ) {
		return;
	}
	printf(
		'<div class="notice notice-success"><p><strong>Looma Apparels is ready.</strong> Your pages and menu have been created. <a href="%1$s" target="_blank">View your website</a> · <a href="%2$s">Edit phone, WhatsApp &amp; email</a></p></div>',
		esc_url( home_url( '/' ) ),
		esc_url( admin_url( 'customize.php?autofocus[section]=looma_business' ) )
	);
}
add_action( 'admin_notices', 'looma_setup_notice' );
