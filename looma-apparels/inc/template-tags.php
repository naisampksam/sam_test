<?php
/**
 * Small template helpers: images, icons, prices, navigation.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of a theme image in assets/img.
 */
function looma_img_url( $file ) {
	return get_template_directory_uri() . '/assets/img/' . $file;
}

/**
 * Output an <img> for a theme image.
 */
function looma_img( $file, $alt = '', $class = '', $eager = false ) {
	printf(
		'<img src="%1$s" alt="%2$s"%3$s loading="%4$s" decoding="async">',
		esc_url( looma_img_url( $file ) ),
		esc_attr( $alt ),
		$class ? ' class="' . esc_attr( $class ) . '"' : '',
		$eager ? 'eager' : 'lazy'
	);
}

/**
 * Format a rupee amount.
 */
function looma_rupee( $amount ) {
	return '₹' . number_format_i18n( $amount );
}

/**
 * Keep "T-Shirt" on one line (non-breaking hyphen).
 */
function looma_nobreak( $text ) {
	return str_replace( '-', "\u{2011}", $text );
}

/**
 * Lowest price of a product (for "from ₹X").
 */
function looma_from_price( $product ) {
	return min( wp_list_pluck( $product['prices'], 'price' ) );
}

/**
 * Inline SVG icons (stroke style, inherit currentColor).
 */
function looma_icon( $name ) {
	$paths = array(
		'box'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8M7.5 5.5l9 5"/>',
		'shirt'     => '<path d="M8 3 3 6l2 5 3-1v11h8V10l3 1 2-5-5-3a4 4 0 0 1-8 0Z"/>',
		'tag'       => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
		'truck'     => '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>',
		'printer'   => '<path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><path d="M6 14h12v7H6z"/>',
		'hoodie'    => '<path d="M8 4a4 4 0 0 1 8 0l4 3 1 13h-4v-8M8 4 4 7 3 20h4v-8M8 4c0 3 1.5 5 4 5s4-2 4-5M7 12v9h10v-9"/>',
		'layers'    => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
		'leaf'      => '<path d="M12 21v-8M12 13c-4 0-7-3-7-7 3 0 5 1 6 3 1-3 4-6 8-6 0 6-3 10-7 10Z"/>',
		'wave'      => '<path d="M3 8c3-2 6 2 9 0s6 2 9 0M3 13c3-2 6 2 9 0s6 2 9 0M3 18c3-2 6 2 9 0s6 2 9 0"/>',
		'needle'    => '<path d="M4 20 18 6M15 3l6 6M9 15l-2 2M3 21l2-2"/>',
		'diamond'   => '<path d="m12 3 9 9-9 9-9-9 9-9Z"/><path d="M3 12h18"/>',
		'pin'       => '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12Z"/><circle cx="12" cy="9" r="2.5"/>',
		'phone'     => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2Z"/>',
		'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'sheet'     => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/>',
		'card'      => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h3"/>',
		'check'     => '<path d="m5 12 4.5 4.5L19 7"/>',
		'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'bag'       => '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
		'users'     => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0M15 14.5a5 5 0 0 1 6 5.5"/>',
		'bank'      => '<path d="m3 9 9-5 9 5M5 9v9M9.5 9v9M14.5 9v9M19 9v9M3 21h18"/>',
		'store'     => '<path d="M4 9h16l-1-5H5L4 9Z"/><path d="M4 9a3 3 0 0 0 5 0 3 3 0 0 0 6 0 3 3 0 0 0 5 0M5 11v9h14v-9"/>',
		'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
		'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
		'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".6"/>',
		'facebook'  => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v7h4v-7h3l1-4h-4V8Z"/>',
		'whatsapp'  => '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7L4 20Z"/><path d="M9 8.5c0 3 2.5 6.5 6 6.5l1-1.5-2-1-1 .8a4 4 0 0 1-2-2l.8-1-1-2L9 8.5Z"/>',
		'calc'      => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg class="icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
}

/**
 * Brand wordmark (used when no custom logo is uploaded).
 */
function looma_logo() {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	printf(
		'<a class="wordmark" href="%s" rel="home"><span class="wordmark-main">LOOMA</span><span class="wordmark-sub">APPARELS</span></a>',
		esc_url( home_url( '/' ) )
	);
}

/**
 * Default one-page navigation links.
 */
function looma_nav_items() {
	return array(
		'products'  => __( 'Products', 'looma' ),
		'services'  => __( 'Dropshipping & Bulk', 'looma' ),
		'printing'  => __( 'Printing', 'looma' ),
		'pricing'   => __( 'Price Guide', 'looma' ),
		'size'      => __( 'Size Chart', 'looma' ),
		'contact'   => __( 'Contact', 'looma' ),
	);
}

/**
 * Link to a front-page section, working from any page.
 */
function looma_section_url( $id ) {
	return is_front_page() ? '#' . $id : home_url( '/#' . $id );
}

/**
 * Primary menu: a WordPress menu if one is assigned, otherwise the section links.
 */
function looma_primary_menu() {
	if ( has_nav_menu( 'primary' ) ) {
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'nav-list',
				'depth'          => 1,
			)
		);
		return;
	}
	echo '<ul class="nav-list">';
	foreach ( looma_nav_items() as $id => $label ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( looma_section_url( $id ) ), esc_html( $label ) );
	}
	echo '</ul>';
}
