<?php
/**
 * Template helpers and reusable page sections.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * Basics
 * ------------------------------------------------------------------ */

function looma_img_url( $file ) {
	// Version tag so browsers and host caches fetch new photos after a theme update.
	return get_template_directory_uri() . '/assets/img/' . $file . '?v=' . LOOMA_VERSION;
}

function looma_img( $file, $alt = '', $class = '', $eager = false, $w = 0, $h = 0 ) {
	printf(
		'<img src="%1$s" alt="%2$s"%3$s%4$s loading="%5$s" decoding="async">',
		esc_url( looma_img_url( $file ) ),
		esc_attr( $alt ),
		$class ? ' class="' . esc_attr( $class ) . '"' : '',
		$w && $h ? ' width="' . (int) $w . '" height="' . (int) $h . '"' : '',
		$eager ? 'eager' : 'lazy'
	);
}

function looma_rupee( $amount ) {
	return '₹' . number_format_i18n( $amount );
}

function looma_from_price( $product ) {
	return min( wp_list_pluck( $product['prices'], 'price' ) );
}

/**
 * Photo of a product in one colour (assets/img/products/{id}-{colour}.jpg).
 */
function looma_product_photo( $p, $colour_name, $side = '' ) {
	return looma_img_url( 'products/' . $p['id'] . '-' . sanitize_title( $colour_name ) . ( 'back' === $side ? '-back' : '' ) . '.jpg' );
}

/**
 * Keep "T-Shirt" on one line (non-breaking hyphen).
 */
function looma_nobreak( $text ) {
	return str_replace( '-', "\u{2011}", $text );
}

/**
 * Colour shown first for a product (its 'display' colour, else the first one).
 */
function looma_display_colour( $p ) {
	foreach ( $p['colours'] as $c ) {
		if ( ! empty( $p['display'] ) && $c[0] === $p['display'] ) {
			return $c;
		}
	}
	return $p['colours'][0];
}

/**
 * URL of a theme page by slug (works with or without pretty links).
 */
function looma_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

/**
 * Product page URL.
 */
function looma_product_url( $id ) {
	if ( get_option( 'permalink_structure' ) ) {
		return trailingslashit( looma_page_url( 'shop' ) ) . $id . '/';
	}
	return add_query_arg( 'looma_product', $id, looma_page_url( 'shop' ) );
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
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'minus'     => '<path d="M5 12h14"/>',
		'trash'     => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
		'ruler'     => '<path d="m3 17 14-14 4 4L7 21z"/><path d="m7 13 2 2M10 10l2 2M13 7l2 2"/>',
		'sparkle'   => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
		'shield'    => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'calc'      => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01"/>',
		'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".6"/>',
		'facebook'  => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v7h4v-7h3l1-4h-4V8Z"/>',
		'whatsapp'  => '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7L4 20Z"/><path d="M9 8.5c0 3 2.5 6.5 6 6.5l1-1.5-2-1-1 .8a4 4 0 0 1-2-2l.8-1-1-2L9 8.5Z"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg class="icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
}

/**
 * Echo an icon (icons are trusted, fixed markup).
 */
function looma_the_icon( $name ) {
	echo looma_icon( $name ); // phpcs:ignore WordPress.Security.EscapeOutput
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
		'<a class="wordmark" href="%s" rel="home" aria-label="Looma Apparels home"><span class="wordmark-main">LOOMA</span><span class="wordmark-sub">APPARELS</span></a>',
		esc_url( home_url( '/' ) )
	);
}

/* ------------------------------------------------------------------
 * Navigation
 * ------------------------------------------------------------------ */

function looma_nav_items() {
	return array(
		'shop'            => __( 'Shop', 'looma' ),
		'design'          => __( 'Design your Product', 'looma' ),
		'dropshipping'    => __( 'Dropshipping', 'looma' ),
		'bulk-orders'     => __( 'Bulk Orders', 'looma' ),
		'printing'        => __( 'Printing', 'looma' ),
		'price-estimator' => __( 'Price Estimator', 'looma' ),
		'contact'         => __( 'Contact', 'looma' ),
	);
}

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
	foreach ( looma_nav_items() as $slug => $label ) {
		$current = is_page( $slug ) ? ' class="current-menu-item"' : '';
		printf( '<li%s><a href="%s">%s</a></li>', $current, esc_url( looma_page_url( $slug ) ), esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}

/* ------------------------------------------------------------------
 * Reusable sections
 * ------------------------------------------------------------------ */

/**
 * Page header band.
 */
function looma_page_hero( $eyebrow, $title, $sub = '', $crumb = '' ) {
	?>
	<header class="page-hero">
		<div class="container">
			<nav class="crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'looma' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'looma' ); ?></a>
				<?php if ( $crumb ) : ?>
					<span aria-hidden="true">/</span> <?php echo $crumb; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				<?php endif; ?>
				<span aria-hidden="true">/</span> <span aria-current="page"><?php echo esc_html( is_page() ? get_the_title() : html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) ); ?></span>
			</nav>
			<?php if ( $eyebrow ) : ?><p class="eyebrow"><?php echo esc_html( $eyebrow ); ?></p><?php endif; ?>
			<h1 class="page-hero-title"><?php echo wp_kses( $title, array( 'span' => array(), 'br' => array() ) ); ?></h1>
			<?php if ( $sub ) : ?><p class="page-hero-sub"><?php echo esc_html( $sub ); ?></p><?php endif; ?>
		</div>
	</header>
	<?php
}

/**
 * Product card for grids.
 */
function looma_product_card( $p ) {
	$url = looma_product_url( $p['id'] );
	$c0  = looma_display_colour( $p );
	?>
	<article class="p-card" data-fit="<?php echo esc_attr( $p['fit'] ); ?>" data-price="<?php echo (int) looma_from_price( $p ); ?>">
		<a class="p-card-media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
			<img class="p-card-photo" src="<?php echo esc_url( looma_product_photo( $p, $c0[0] ) ); ?>" alt="<?php echo esc_attr( $p['name'] . ' ' . $p['gsm'] . ' in ' . $c0[0] ); ?>" loading="lazy" decoding="async" width="600" height="680">
			<?php if ( $p['badge'] ) : ?><span class="badge"><?php echo esc_html( $p['badge'] ); ?></span><?php endif; ?>
		</a>
		<div class="p-card-body">
			<ul class="swatches" aria-label="<?php esc_attr_e( 'Colours', 'looma' ); ?>">
				<?php foreach ( array_slice( $p['colours'], 0, 6 ) as $c ) : ?>
					<li><button type="button" class="swatch-dot<?php echo $c === $c0 ? ' is-active' : ''; ?>" style="--sw: <?php echo esc_attr( $c[1] ); ?>" data-colour="<?php echo esc_attr( $c[1] ); ?>" data-photo="<?php echo esc_url( looma_product_photo( $p, $c[0] ) ); ?>" title="<?php echo esc_attr( $c[0] ); ?>"><span class="screen-reader-text"><?php echo esc_html( $c[0] ); ?></span></button></li>
				<?php endforeach; ?>
				<?php if ( count( $p['colours'] ) > 6 ) : ?>
					<li class="more">+<?php echo (int) count( $p['colours'] ) - 6; ?></li>
				<?php endif; ?>
			</ul>
			<h3 class="p-card-title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( looma_nobreak( $p['name'] ) ); ?></a></h3>
			<p class="p-card-spec"><?php echo esc_html( $p['spec'] ); ?></p>
			<p class="p-card-price"><span><?php esc_html_e( 'From', 'looma' ); ?></span> <?php echo esc_html( looma_rupee( looma_from_price( $p ) ) ); ?> <small><?php esc_html_e( '/ piece', 'looma' ); ?></small></p>
		</div>
	</article>
	<?php
}

/**
 * Dark call-to-action band.
 */
function looma_cta_band( $title = '', $text = '' ) {
	$title = $title ? $title : __( 'Got an idea? Let’s make it wearable.', 'looma' );
	$text  = $text ? $text : __( 'Tell us what you need — style, colours, quantity and print — and get a quote on WhatsApp.', 'looma' );
	?>
	<section class="cta-band">
		<div class="container cta-inner">
			<div>
				<h2 class="cta-title"><?php echo esc_html( $title ); ?></h2>
				<p class="cta-text"><?php echo esc_html( $text ); ?></p>
			</div>
			<div class="cta-actions">
				<a class="btn btn-wa btn-lg" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like a quote.' ) ); ?>" target="_blank" rel="noopener"><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'Chat on WhatsApp', 'looma' ); ?></a>
				<a class="btn btn-ghost-light btn-lg" href="<?php echo esc_url( looma_page_url( 'price-estimator' ) ); ?>"><?php looma_the_icon( 'calc' ); ?> <?php esc_html_e( 'Estimate price', 'looma' ); ?></a>
			</div>
		</div>
	</section>
	<?php
}

/**
 * 5-step ordering process.
 */
function looma_steps() {
	$steps = array(
		array( 'shirt', __( 'Choose products', 'looma' ), __( 'Pick t-shirts, colours and sizes from our range.', 'looma' ) ),
		array( 'sheet', __( 'Place your order', 'looma' ), __( 'Share order details in the Google Sheet or WhatsApp group, with your designs and mockups.', 'looma' ) ),
		array( 'card', __( 'Make payment', 'looma' ), __( 'Pay and share the payment screenshot.', 'looma' ) ),
		array( 'box', __( 'We produce & pack', 'looma' ), __( 'We print, quality-check and pack every order with care.', 'looma' ) ),
		array( 'truck', __( 'Dispatch & update', 'looma' ), __( 'We ship to your customer and send you the tracking details.', 'looma' ) ),
	);
	echo '<ol class="steps">';
	foreach ( $steps as $s ) {
		printf( '<li><span class="step-icon">%s</span><h3>%s</h3><p>%s</p></li>', looma_icon( $s[0] ), esc_html( $s[1] ), esc_html( $s[2] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ol>';
}

/**
 * Who we work with.
 */
function looma_ideal_for() {
	$items = array(
		array( 'hoodie', __( 'Clothing Brands', 'looma' ) ),
		array( 'bag', __( 'Startups & Small Businesses', 'looma' ) ),
		array( 'users', __( 'Events & Merchandise', 'looma' ) ),
		array( 'bank', __( 'Colleges & Organisations', 'looma' ) ),
		array( 'store', __( 'Retail Chains & Distributors', 'looma' ) ),
	);
	echo '<ul class="ideal-list">';
	foreach ( $items as $i ) {
		printf( '<li>%s<span>%s</span></li>', looma_icon( $i[0] ), esc_html( $i[1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}

/**
 * Delivery partners strip.
 */
function looma_couriers() {
	echo '<ul class="couriers">';
	foreach ( array( 'Delhivery', 'Ecom Express', 'Blue Dart', 'India Post', 'DTDC', 'Speed & Safe' ) as $c ) {
		echo '<li>' . esc_html( $c ) . '</li>';
	}
	echo '</ul>';
}

/**
 * FAQ accordion.
 */
function looma_faq( $faqs = null ) {
	$faqs = $faqs ? $faqs : looma_faqs();
	echo '<div class="faq">';
	foreach ( $faqs as $i => $f ) {
		printf( '<details%s><summary>%s<span class="faq-icon">%s</span></summary><p>%s</p></details>', 0 === $i ? ' open' : '', esc_html( $f[0] ), looma_icon( 'plus' ), esc_html( $f[1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
}

/**
 * Result notice after an enquiry form submit.
 */
function looma_enquiry_notice() {
	$status = isset( $_GET['enquiry'] ) ? sanitize_key( wp_unslash( $_GET['enquiry'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$map    = array(
		'sent'    => array( 'ok', __( 'Thank you! Your enquiry has been sent. We will get back to you soon.', 'looma' ) ),
		'missing' => array( 'err', __( 'Please enter your name and phone number.', 'looma' ) ),
		'error'   => array( 'err', __( 'Sorry, the message could not be sent. Please use the WhatsApp button instead.', 'looma' ) ),
	);
	if ( isset( $map[ $status ] ) ) {
		printf( '<p class="notice notice-%1$s" role="%2$s">%3$s</p>', esc_attr( $map[ $status ][0] ), 'ok' === $map[ $status ][0] ? 'status' : 'alert', esc_html( $map[ $status ][1] ) );
	}
}

/**
 * Enquiry form.
 *
 * @param string $default_type Pre-selected "interested in" option.
 * @param bool   $with_items   Include the hidden quote-list field.
 */
function looma_enquiry_form( $default_type = '', $with_items = false ) {
	$types = array(
		__( 'Bulk order', 'looma' ),
		__( 'Dropshipping / Print on demand', 'looma' ),
		__( 'Plain t-shirts (no print)', 'looma' ),
		__( 'Custom garment manufacturing', 'looma' ),
		__( 'DTF roll', 'looma' ),
		__( 'Other', 'looma' ),
	);
	$back  = remove_query_arg( 'enquiry', ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) );
	?>
	<form class="enquiry-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-enquiry>
		<input type="hidden" name="action" value="looma_enquiry">
		<input type="hidden" name="_back" value="<?php echo esc_url( $back ); ?>">
		<?php wp_nonce_field( 'looma_enquiry', 'looma_nonce' ); ?>
		<?php if ( $with_items ) : ?><input type="hidden" name="items" value="" data-quote-items><?php endif; ?>
		<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

		<div class="field-row">
			<label class="field"><span><?php esc_html_e( 'Your name *', 'looma' ); ?></span><input type="text" name="name" required autocomplete="name"></label>
			<label class="field"><span><?php esc_html_e( 'Phone / WhatsApp *', 'looma' ); ?></span><input type="tel" name="phone" required autocomplete="tel"></label>
		</div>
		<label class="field"><span><?php esc_html_e( 'Email', 'looma' ); ?></span><input type="email" name="email" autocomplete="email"></label>
		<?php if ( ! $with_items ) : ?>
			<div class="field-row">
				<label class="field">
					<span><?php esc_html_e( 'I am interested in', 'looma' ); ?></span>
					<select name="order_type">
						<?php foreach ( $types as $t ) : ?>
							<option<?php selected( $t, $default_type ); ?>><?php echo esc_html( $t ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="field"><span><?php esc_html_e( 'Approx. quantity', 'looma' ); ?></span><input type="text" name="quantity" placeholder="<?php esc_attr_e( 'e.g. 50 pcs', 'looma' ); ?>"></label>
			</div>
		<?php endif; ?>
		<label class="field"><span><?php esc_html_e( 'Message', 'looma' ); ?></span><textarea name="message" rows="4" placeholder="<?php esc_attr_e( 'Style, colours, sizes, print type…', 'looma' ); ?>"></textarea></label>

		<div class="form-actions">
			<button type="submit" class="btn btn-dark"><?php esc_html_e( 'Send enquiry', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></button>
			<button type="button" class="btn btn-wa" data-form-wa><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'Send via WhatsApp', 'looma' ); ?></button>
		</div>
	</form>
	<?php
}

/**
 * Size chart tables with tabs.
 */
function looma_size_tables( $open = 'oversized' ) {
	$charts = looma_size_charts();
	?>
	<div class="size-tables" data-tabs>
		<div class="tabs" role="tablist" aria-label="<?php esc_attr_e( 'Fit', 'looma' ); ?>">
			<?php foreach ( $charts as $key => $chart ) : ?>
				<button type="button" role="tab" class="tab" id="tab-<?php echo esc_attr( $key ); ?>" aria-controls="panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $key === $open ? 'true' : 'false'; ?>"><?php echo esc_html( $chart['title'] ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php foreach ( $charts as $key => $chart ) : ?>
			<div class="tab-panel" role="tabpanel" id="panel-<?php echo esc_attr( $key ); ?>" aria-labelledby="tab-<?php echo esc_attr( $key ); ?>"<?php echo $key === $open ? '' : ' hidden'; ?>>
				<p class="tab-tagline"><?php echo esc_html( $chart['tagline'] ); ?></p>
				<div class="table-scroll">
					<table class="table">
						<thead><tr><th><?php esc_html_e( 'Size', 'looma' ); ?></th><th><?php esc_html_e( 'Chest', 'looma' ); ?></th><th><?php esc_html_e( 'Length', 'looma' ); ?></th><th><?php esc_html_e( 'Shoulder', 'looma' ); ?></th><th><?php esc_html_e( 'Sleeve', 'looma' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $chart['rows'] as $r ) : ?>
								<tr><?php foreach ( $r as $i => $cell ) : ?><td><?php echo 0 === $i ? '<strong>' . esc_html( $cell ) . '</strong>' : esc_html( $cell ); ?></td><?php endforeach; ?></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endforeach; ?>
		<p class="fineprint"><?php esc_html_e( 'All measurements in inches. May vary slightly (±0.5") due to fabric and production.', 'looma' ); ?></p>
	</div>
	<?php
}

/**
 * How-to-measure photo.
 */
function looma_measure_diagram() {
	?>
	<figure class="measure">
		<?php looma_img( 'size-diagram.jpg', __( 'How to measure a t-shirt: chest, length and sleeve', 'looma' ) ); ?>
		<figcaption><?php esc_html_e( 'Chest: measured across, 1" below the armhole. Length: from the highest shoulder point to the hem.', 'looma' ); ?></figcaption>
	</figure>
	<?php
}
