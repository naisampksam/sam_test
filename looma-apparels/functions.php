<?php
/**
 * Looma Apparels theme functions.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

define( 'LOOMA_VERSION', '1.0.0' );

require get_template_directory() . '/inc/catalog.php';
require get_template_directory() . '/inc/template-tags.php';

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
			'primary' => __( 'Primary Menu', 'looma' ),
			'footer'  => __( 'Footer Menu', 'looma' ),
		)
	);
}
add_action( 'after_setup_theme', 'looma_setup' );

/**
 * Styles and scripts.
 */
function looma_assets() {
	wp_enqueue_style( 'looma-fonts', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800;900&family=Inter:wght@400;500;600&family=Allura&display=swap', array(), null );
	wp_enqueue_style( 'looma-style', get_stylesheet_uri(), array( 'looma-fonts' ), LOOMA_VERSION );
	wp_enqueue_script( 'looma-main', get_template_directory_uri() . '/assets/js/main.js', array(), LOOMA_VERSION, true );

	$products = array();
	foreach ( looma_products() as $p ) {
		$products[] = array(
			'id'     => $p['id'],
			'name'   => $p['name'] . ' — ' . $p['gsm'] . ' ' . $p['fabric'],
			'prices' => $p['prices'],
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
		)
	);
}
add_action( 'wp_enqueue_scripts', 'looma_assets' );

/**
 * Preconnect to Google Fonts.
 */
function looma_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = 'https://fonts.googleapis.com';
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'looma_resource_hints', 10, 2 );

/**
 * Business details — editable in Appearance → Customize → Looma Business Details.
 */
function looma_defaults() {
	return array(
		'looma_phone'     => '+91 8089963691',
		'looma_whatsapp'  => '918089963691',
		'looma_email'     => 'loomaapparels@gmail.com',
		'looma_address'   => "Watani Complex, Manjeri Rd,\nKizhisseri, Malappuram,\nKerala 673641",
		'looma_instagram' => '',
		'looma_facebook'  => '',
		'looma_hours'     => '',
		'looma_map'       => 'Looma Apparels, Watani Complex, Manjeri Rd, Kizhisseri, Malappuram, Kerala 673641',
	);
}

/**
 * Get a business detail from the Customizer.
 */
function looma_opt( $key ) {
	$defaults = looma_defaults();
	return get_theme_mod( $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
}

/**
 * WhatsApp number, digits only (country code + number).
 */
function looma_whatsapp_number() {
	return preg_replace( '/\D+/', '', looma_opt( 'looma_whatsapp' ) );
}

/**
 * wa.me link with an optional prefilled message.
 */
function looma_whatsapp_link( $message = '' ) {
	$url = 'https://wa.me/' . looma_whatsapp_number();
	if ( $message ) {
		$url .= '?text=' . rawurlencode( $message );
	}
	return $url;
}

/**
 * Customizer settings.
 */
function looma_customize_register( $wp_customize ) {
	$wp_customize->add_section(
		'looma_business',
		array(
			'title'    => __( 'Looma Business Details', 'looma' ),
			'priority' => 30,
		)
	);

	$fields = array(
		'looma_phone'     => array( __( 'Phone number (shown on site)', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_whatsapp'  => array( __( 'WhatsApp number (with country code, digits only, e.g. 918089963691)', 'looma' ), 'text', 'sanitize_text_field' ),
		'looma_email'     => array( __( 'Email (enquiries are sent here)', 'looma' ), 'email', 'sanitize_email' ),
		'looma_address'   => array( __( 'Address', 'looma' ), 'textarea', 'sanitize_textarea_field' ),
		'looma_hours'     => array( __( 'Working hours', 'looma' ), 'text', 'sanitize_text_field' ),
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

/**
 * Handle the enquiry form (sent to the email set in the Customizer).
 */
function looma_handle_enquiry() {
	$redirect = home_url( '/' );

	if ( ! isset( $_POST['looma_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['looma_nonce'] ) ), 'looma_enquiry' ) ) {
		wp_safe_redirect( add_query_arg( 'enquiry', 'error', $redirect ) . '#contact' );
		exit;
	}

	// Honeypot: bots fill hidden fields.
	if ( ! empty( $_POST['website'] ) ) {
		wp_safe_redirect( add_query_arg( 'enquiry', 'sent', $redirect ) . '#contact' );
		exit;
	}

	$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$type     = isset( $_POST['order_type'] ) ? sanitize_text_field( wp_unslash( $_POST['order_type'] ) ) : '';
	$quantity = isset( $_POST['quantity'] ) ? sanitize_text_field( wp_unslash( $_POST['quantity'] ) ) : '';
	$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

	if ( '' === $name || '' === $phone ) {
		wp_safe_redirect( add_query_arg( 'enquiry', 'missing', $redirect ) . '#contact' );
		exit;
	}

	$to      = looma_opt( 'looma_email' );
	$subject = sprintf( 'New enquiry from %s — Looma Apparels website', $name );
	$body    = "Name: {$name}\nPhone: {$phone}\nEmail: {$email}\nOrder type: {$type}\nQuantity: {$quantity}\n\nMessage:\n{$message}\n";
	$headers = array();
	if ( is_email( $email ) ) {
		$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
	}

	$sent = wp_mail( $to, $subject, $body, $headers );

	wp_safe_redirect( add_query_arg( 'enquiry', $sent ? 'sent' : 'error', $redirect ) . '#contact' );
	exit;
}
add_action( 'admin_post_nopriv_looma_enquiry', 'looma_handle_enquiry' );
add_action( 'admin_post_looma_enquiry', 'looma_handle_enquiry' );

/**
 * Meta description + LocalBusiness structured data for Google.
 */
function looma_head_meta() {
	if ( ! is_front_page() ) {
		return;
	}
	$desc = 'Looma Apparels — premium blank t-shirts and custom printing in Kerala. Dropshipping / Print on Demand with no minimum order, bulk orders from 10 pieces, DTF, embroidery, screen, puff and HD printing. Pan-India delivery.';
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";

	$schema = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'ClothingStore',
		'name'        => 'Looma Apparels',
		'slogan'      => 'Ideas into Apparel',
		'description' => $desc,
		'url'         => home_url( '/' ),
		'telephone'   => looma_opt( 'looma_phone' ),
		'email'       => looma_opt( 'looma_email' ),
		'image'       => get_template_directory_uri() . '/assets/img/storefront.jpg',
		'address'     => array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => 'Watani Complex, Manjeri Rd, Kizhisseri',
			'addressLocality' => 'Malappuram',
			'addressRegion'   => 'Kerala',
			'postalCode'      => '673641',
			'addressCountry'  => 'IN',
		),
		'priceRange'  => '₹₹',
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', 'looma_head_meta', 1 );
