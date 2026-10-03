<?php
/**
 * Looma Apparels theme functions.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

define( 'LOOMA_VERSION', '2.7.0' );

require get_template_directory() . '/inc/catalog.php';
require get_template_directory() . '/inc/template-tags.php';
require get_template_directory() . '/inc/setup.php';
require get_template_directory() . '/inc/security.php';
require get_template_directory() . '/inc/designs.php';

/**
 * Theme setup.
 */
function looma_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 80,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	register_nav_menus(
		array(
			'primary' => __( 'Main Menu', 'looma' ),
			'footer'  => __( 'Footer Menu', 'looma' ),
		)
	);
}
add_action( 'after_setup_theme', 'looma_setup' );

/**
 * Styles and scripts.
 */
function looma_assets() {
	wp_enqueue_style( 'looma-style', get_stylesheet_uri(), array(), LOOMA_VERSION );
	wp_enqueue_script( 'looma-main', get_template_directory_uri() . '/assets/js/main.js', array(), LOOMA_VERSION, true );

	$products = array();
	foreach ( looma_products() as $p ) {
		$products[] = array(
			'id'     => $p['id'],
			'name'   => $p['name'] . ' — ' . $p['spec'],
			'prices' => $p['prices'],
			'url'    => looma_product_url( $p['id'] ),
		);
	}

	wp_localize_script(
		'looma-main',
		'LOOMA',
		array(
			'products' => $products,
			'dtf'      => looma_dtf_prices(),
			'gst'      => 5,
			'whatsapp' => looma_whatsapp_number(),
			'quoteUrl' => looma_page_url( 'quote' ),
		)
	);

	// Design Studio (Design your Product page only).
	if ( is_page( 'design' ) ) {
		wp_enqueue_script( 'looma-designer', get_template_directory_uri() . '/assets/js/designer.js', array( 'looma-main' ), LOOMA_VERSION, true );
		$zones = looma_mockup_zones();
		$items = array();
		foreach ( looma_products() as $p ) {
			$colours = array();
			foreach ( $p['colours'] as $c ) {
				$colours[] = array(
					'name'  => $c[0],
					'hex'   => $c[1],
					'front' => looma_product_photo( $p, $c[0] ),
					'back'  => looma_product_photo( $p, $c[0], 'back' ),
				);
			}
			$items[] = array(
				'id'      => $p['id'],
				'name'    => $p['name'],
				'spec'    => $p['spec'],
				'gsm'     => $p['gsm'],
				'fit'     => $p['fit'],
				'from'    => looma_from_price( $p ),
				'prices'  => $p['prices'],
				'colours' => $colours,
				'display' => looma_display_colour( $p )[0],
				'zones'   => isset( $zones[ $p['id'] ] ) ? $zones[ $p['id'] ] : reset( $zones ),
			);
		}
		wp_localize_script(
			'looma-designer',
			'LOOMA_DESIGN',
			array(
				'products'   => $items,
				'sizes'      => looma_sizes(),
				'ppi'        => 18.2,
				'embroidery' => looma_embroidery_rates(),
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'maxUpload'  => LOOMA_DESIGN_MAX_BYTES,
			)
		);
	}

	// Order builder (Price Estimator page only).
	if ( is_page( 'price-estimator' ) ) {
		wp_enqueue_script( 'looma-estimator', get_template_directory_uri() . '/assets/js/estimator.js', array( 'looma-main' ), LOOMA_VERSION, true );
		$builder = array();
		foreach ( looma_products() as $p ) {
			$colours = array();
			foreach ( $p['colours'] as $c ) {
				$colours[] = array(
					'name'  => $c[0],
					'hex'   => $c[1],
					'photo' => looma_product_photo( $p, $c[0] ),
				);
			}
			$builder[] = array(
				'id'      => $p['id'],
				'name'    => $p['name'],
				'spec'    => $p['spec'],
				'gsm'     => $p['gsm'],
				'from'    => looma_from_price( $p ),
				'prices'  => $p['prices'],
				'colours' => $colours,
				'display' => looma_display_colour( $p )[0],
			);
		}
		wp_localize_script(
			'looma-estimator',
			'LOOMA_BUILDER',
			array(
				'products'   => $builder,
				'sizes'      => looma_sizes(),
				'embroidery' => looma_embroidery_rates(),
				'staff'      => current_user_can( 'edit_posts' ),
				'business'   => array(
					'phone'   => looma_opt( 'looma_phone' ),
					'email'   => looma_opt( 'looma_email' ),
					'address' => preg_replace( '/,?\s*\n\s*/', ', ', looma_opt( 'looma_address' ) ),
				),
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'looma_assets' );

/**
 * Preload the two most-used font files.
 */
function looma_preload_fonts() {
	foreach ( array( 'archivo-latin-800-normal.woff2', 'inter-latin-400-normal.woff2' ) as $font ) {
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( get_template_directory_uri() . '/assets/fonts/' . $font ) );
	}
}
add_action( 'wp_head', 'looma_preload_fonts', 2 );

/* ------------------------------------------------------------------
 * Product pages: /shop/{product-id}/
 * ------------------------------------------------------------------ */

function looma_rewrite_rules() {
	add_rewrite_rule( '^shop/([^/]+)/?$', 'index.php?pagename=shop&looma_product=$matches[1]', 'top' );
}
add_action( 'init', 'looma_rewrite_rules' );

function looma_query_vars( $vars ) {
	$vars[] = 'looma_product';
	return $vars;
}
add_filter( 'query_vars', 'looma_query_vars' );

/**
 * The product being viewed (or null).
 */
function looma_current_product() {
	$id = get_query_var( 'looma_product' );
	return $id ? looma_get_product( sanitize_title( $id ) ) : null;
}

/**
 * Unknown product address → proper 404.
 */
function looma_product_404() {
	if ( get_query_var( 'looma_product' ) && ! looma_current_product() ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}
}
add_action( 'template_redirect', 'looma_product_404' );

/**
 * Product name in the browser tab.
 */
function looma_document_title( $parts ) {
	$p = looma_current_product();
	if ( $p ) {
		$parts['title'] = $p['name'] . ' ' . $p['spec'];
	}
	return $parts;
}
add_filter( 'document_title_parts', 'looma_document_title' );

/* ------------------------------------------------------------------
 * Business details — Appearance → Customize → Looma Business Details.
 * ------------------------------------------------------------------ */

function looma_defaults() {
	return array(
		'looma_phone'     => '+91 8089963691',
		'looma_whatsapp'  => '918089963691',
		'looma_email'     => 'loomaapparels@gmail.com',
		'looma_address'   => "Watani Complex, Manjeri Rd,\nKizhisseri, Malappuram,\nKerala 673641",
		'looma_hours'     => '',
		'looma_map'       => 'Looma Apparels, Watani Complex, Manjeri Rd, Kizhisseri, Malappuram, Kerala 673641',
		'looma_instagram' => '',
		'looma_facebook'  => '',
		'looma_announce'  => 'No minimum order on print on demand · Bulk orders from 10 pcs · Pan-India delivery',
	);
}

function looma_opt( $key ) {
	$defaults = looma_defaults();
	return get_theme_mod( $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
}

function looma_whatsapp_number() {
	return preg_replace( '/\D+/', '', looma_opt( 'looma_whatsapp' ) );
}

function looma_whatsapp_link( $message = '' ) {
	$url = 'https://wa.me/' . looma_whatsapp_number();
	if ( $message ) {
		$url .= '?text=' . rawurlencode( $message );
	}
	return $url;
}

function looma_tel() {
	return 'tel:' . preg_replace( '/[^\d+]/', '', looma_opt( 'looma_phone' ) );
}

function looma_customize_register( $wp_customize ) {
	$wp_customize->add_section(
		'looma_business',
		array(
			'title'    => __( 'Looma Business Details', 'looma' ),
			'priority' => 30,
		)
	);

	$fields = array(
		'looma_announce'  => array( __( 'Announcement bar text (top of every page)', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_phone'     => array( __( 'Phone number (shown on site)', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_whatsapp'  => array( __( 'WhatsApp number (country code + number, digits only, e.g. 918089963691)', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_email'     => array( __( 'Email (enquiries are sent here)', 'looma' ), 'email', 'sanitize_email' ),
		'looma_address'   => array( __( 'Address', 'looma' ), 'textarea', 'sanitize_textarea_field' ),
		'looma_hours'     => array( __( 'Working hours (optional)', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_map'       => array( __( 'Google Maps search text for the map', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_instagram' => array( __( 'Instagram URL', 'looma' ), 'url', 'esc_url_raw' ),
		'looma_facebook'  => array( __( 'Facebook URL', 'looma' ), 'url', 'esc_url_raw' ),
	);

	$defaults = looma_defaults();
	foreach ( $fields as $id => $field ) {
		$wp_customize->add_setting(
			$id,
			array(
				'default'           => $defaults[ $id ],
				'sanitize_callback' => $field[2],
			)
		);
		$wp_customize->add_control(
			$id,
			array(
				'label'   => $field[0],
				'section' => 'looma_business',
				'type'    => $field[1],
			)
		);
	}
}
add_action( 'customize_register', 'looma_customize_register' );

/* ------------------------------------------------------------------
 * Enquiry + quote-list form → email.
 * ------------------------------------------------------------------ */

function looma_handle_enquiry() {
	$back = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( $_POST['_back'] ) ) : home_url( '/' );
	$back = wp_validate_redirect( $back, home_url( '/' ) );
	$go   = function ( $status ) use ( $back ) {
		wp_safe_redirect( add_query_arg( 'enquiry', $status, $back ) . '#enquiry' );
		exit;
	};

	if ( ! isset( $_POST['looma_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['looma_nonce'] ) ), 'looma_enquiry' ) ) {
		$go( 'error' );
	}
	if ( ! empty( $_POST['website'] ) ) { // Honeypot.
		$go( 'sent' );
	}
	if ( looma_rate_limited( 'enquiry', 6 ) ) { // Stops bots flooding the inbox.
		$go( 'error' );
	}

	$field = function ( $key, $multiline = false ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '';
		}
		$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	};

	$name  = $field( 'name' );
	$phone = $field( 'phone' );
	$email = sanitize_email( $field( 'email' ) );
	$type  = $field( 'order_type' );
	$qty   = $field( 'quantity' );
	$items = $field( 'items', true );
	$msg   = $field( 'message', true );

	if ( '' === $name || '' === $phone ) {
		$go( 'missing' );
	}

	$body  = "Name: {$name}\nPhone: {$phone}\nEmail: {$email}\n";
	$body .= $type ? "Interested in: {$type}\n" : '';
	$body .= $qty ? "Quantity: {$qty}\n" : '';
	$body .= $items ? "\nQuote list:\n{$items}\n" : '';
	$body .= $msg ? "\nMessage:\n{$msg}\n" : '';

	$headers = array();
	if ( is_email( $email ) ) {
		$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
	}

	$subject = $items ? 'New quote request from %s — Looma Apparels website' : 'New enquiry from %s — Looma Apparels website';
	$sent    = wp_mail( looma_opt( 'looma_email' ), sprintf( $subject, $name ), $body, $headers );
	$go( $sent ? 'sent' : 'error' );
}
add_action( 'admin_post_nopriv_looma_enquiry', 'looma_handle_enquiry' );
add_action( 'admin_post_looma_enquiry', 'looma_handle_enquiry' );

/* ------------------------------------------------------------------
 * SEO: meta description, social image, structured data.
 * ------------------------------------------------------------------ */

function looma_page_description() {
	$p = looma_current_product();
	if ( $p ) {
		return $p['name'] . ' ' . $p['spec'] . ' — ' . $p['tagline'] . ' From ' . looma_rupee( looma_from_price( $p ) ) . ' per piece. Custom printing and bulk pricing by Looma Apparels.';
	}
	$map = array(
		'shop'            => 'Shop premium blank t-shirts — oversized, regular fit, full sleeve and acid wash, 190 to 250 GSM. Bulk pricing from 10 pieces.',
		'dropshipping'    => 'Print on demand and dropshipping with no minimum order. We print, pack and ship directly to your customers across India.',
		'bulk-orders'     => 'Custom t-shirts in bulk from just 10 pieces — DTF, screen print, puff, HD and embroidery with free neck-label branding.',
		'printing'        => 'DTF, puff, high density, embroidery and screen printing — techniques, minimums and DTF print charges.',
		'price-estimator' => 'Estimate the price of your custom printed t-shirts instantly — pick a t-shirt, quantity and print sizes.',
		'size-guide'      => 'Oversized and regular fit t-shirt size charts in inches.',
		'contact'         => 'Contact Looma Apparels in Malappuram, Kerala — call, WhatsApp or send an enquiry.',
	);
	foreach ( $map as $slug => $desc ) {
		if ( is_page( $slug ) ) {
			return $desc;
		}
	}
	return 'Looma Apparels — premium blank t-shirts and custom printing from Kerala. Print on demand with no minimum, bulk orders from 10 pieces, pan-India delivery.';
}

function looma_head_meta() {
	if ( is_admin() || is_404() ) {
		return;
	}
	$desc = looma_page_description();
	$img  = get_template_directory_uri() . '/assets/img/hero.jpg';
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:site_name" content="Looma Apparels">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";

	if ( is_front_page() ) {
		$schema = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'ClothingStore',
			'name'      => 'Looma Apparels',
			'slogan'    => 'Ideas into Apparel',
			'url'       => home_url( '/' ),
			'telephone' => looma_opt( 'looma_phone' ),
			'email'     => looma_opt( 'looma_email' ),
			'image'     => $img,
			'address'   => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => 'Watani Complex, Manjeri Rd, Kizhisseri',
				'addressLocality' => 'Malappuram',
				'addressRegion'   => 'Kerala',
				'postalCode'      => '673641',
				'addressCountry'  => 'IN',
			),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	$p = looma_current_product();
	if ( $p ) {
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $p['name'] . ' ' . $p['spec'],
			'description' => $p['desc'],
			'brand'       => array( '@type' => 'Brand', 'name' => 'Looma Apparels' ),
			'offers'      => array(
				'@type'         => 'AggregateOffer',
				'priceCurrency' => 'INR',
				'lowPrice'      => looma_from_price( $p ),
				'highPrice'     => max( wp_list_pluck( $p['prices'], 'price' ) ),
				'availability'  => 'https://schema.org/InStock',
			),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
add_action( 'wp_head', 'looma_head_meta', 1 );

/**
 * Extra body classes.
 */
function looma_body_class( $classes ) {
	if ( looma_current_product() ) {
		$classes[] = 'is-product';
	}
	return $classes;
}
add_filter( 'body_class', 'looma_body_class' );
