<?php
/**
 * Site setup: creates the pages, sets the home page, builds the menus
 * and switches on pretty links.
 *
 * Runs automatically when the theme is activated AND the first time the
 * site loads after the theme is installed or updated (uploading a new zip
 * over an active theme does not "activate" it again). It can also be run
 * by hand from Appearance → Looma Setup.
 *
 * Safe to run again — existing pages are reused, never duplicated.
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
		'design'          => 'Design a Product',
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
 * Menus the theme builds (location => [name, page slugs]).
 */
function looma_site_menus() {
	return array(
		'primary' => array( 'Looma Main Menu', array( 'shop', 'design', 'dropshipping', 'bulk-orders', 'printing', 'price-estimator', 'contact' ) ),
		'footer'  => array( 'Looma Footer Menu', array( 'shop', 'design', 'dropshipping', 'bulk-orders', 'printing', 'price-estimator', 'size-guide', 'about', 'contact' ) ),
	);
}

/**
 * Make sure one page exists, is published and has the right address.
 *
 * @return int Page ID (0 on failure).
 */
function looma_ensure_page( $slug, $title ) {
	// Existing page (any status except trash).
	$page = get_page_by_path( $slug, OBJECT, 'page' );

	// A page with this address sitting in the trash → restore it.
	if ( ! $page ) {
		$trashed = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'trash',
				'name'           => $slug . '__trashed',
				'posts_per_page' => 1,
			)
		);
		if ( $trashed ) {
			wp_untrash_post( $trashed[0]->ID );
			wp_update_post(
				array(
					'ID'          => $trashed[0]->ID,
					'post_name'   => $slug,
					'post_status' => 'publish',
				)
			);
			$page = get_post( $trashed[0]->ID );
		}
	}

	if ( $page ) {
		if ( 'publish' !== $page->post_status ) {
			wp_update_post(
				array(
					'ID'          => $page->ID,
					'post_status' => 'publish',
				)
			);
		}
		return (int) $page->ID;
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '',
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/**
 * Create pages, menus and settings.
 *
 * @param bool $replace_menus Assign the Looma menus even if other menus are assigned.
 */
function looma_run_setup( $replace_menus = false ) {
	$ids = array();
	foreach ( looma_site_pages() as $slug => $title ) {
		$ids[ $slug ] = looma_ensure_page( $slug, $title );
	}

	// Home page = the "home" page.
	if ( $ids['home'] ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['home'] );
	}

	// Pretty links (needed for /shop/product-name/ addresses).
	global $wp_rewrite;
	if ( ! get_option( 'permalink_structure' ) ) {
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	// Menus.
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	foreach ( looma_site_menus() as $location => $menu ) {
		$has_menu = ! empty( $locations[ $location ] ) && wp_get_nav_menu_object( $locations[ $location ] );
		$is_ours  = $has_menu && wp_get_nav_menu_object( $locations[ $location ] )->name === $menu[0];
		if ( $has_menu && ! $is_ours && ! $replace_menus ) {
			continue;
		}
		$existing = wp_get_nav_menu_object( $menu[0] );
		if ( $existing ) {
			// Rebuild so every item points at the current pages.
			foreach ( (array) wp_get_nav_menu_items( $existing->term_id ) as $item ) {
				wp_delete_post( $item->ID, true );
			}
			$menu_id = $existing->term_id;
		} else {
			$menu_id = wp_create_nav_menu( $menu[0] );
			if ( is_wp_error( $menu_id ) ) {
				continue;
			}
		}
		foreach ( $menu[1] as $slug ) {
			if ( empty( $ids[ $slug ] ) ) {
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
					'menu-item-title'     => 'design' === $slug ? 'Design' : '',
				)
			);
		}
		$locations[ $location ] = $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $locations );

	looma_rewrite_rules();
	$wp_rewrite->init();
	flush_rewrite_rules();
	update_option( 'looma_setup_done', LOOMA_VERSION );
}

/**
 * On activation: full setup, Looma menus included.
 */
function looma_on_activate() {
	looma_run_setup( ! get_option( 'looma_setup_done' ) );
}
add_action( 'after_switch_theme', 'looma_on_activate' );

/**
 * After an install/update by zip upload (no activation event): run setup once.
 * The very first time Looma sets up a site it also takes over the menus,
 * because menus left over from a previous theme usually point at pages
 * that do not exist here.
 */
function looma_maybe_setup() {
	$done = get_option( 'looma_setup_done' );
	if ( LOOMA_VERSION === $done || wp_installing() ) {
		return;
	}
	looma_run_setup( ! $done );
}
add_action( 'init', 'looma_maybe_setup', 99 );

/* ------------------------------------------------------------------
 * Appearance → Looma Setup
 * ------------------------------------------------------------------ */

function looma_setup_menu() {
	add_theme_page( __( 'Looma Setup', 'looma' ), __( 'Looma Setup', 'looma' ), 'manage_options', 'looma-setup', 'looma_setup_screen' );
}
add_action( 'admin_menu', 'looma_setup_menu' );

/**
 * Status of every theme page.
 */
function looma_page_status() {
	$rows = array();
	foreach ( looma_site_pages() as $slug => $title ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page ) {
			$rows[] = array( $title, $slug, 'missing', '' );
		} elseif ( 'publish' !== $page->post_status ) {
			$rows[] = array( $title, $slug, 'not-published', get_permalink( $page ) );
		} else {
			$rows[] = array( $title, $slug, 'ok', get_permalink( $page ) );
		}
	}
	return $rows;
}

function looma_setup_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$rows    = looma_page_status();
	$broken  = count( array_filter( $rows, function ( $r ) { return 'ok' !== $r[2]; } ) );
	$pretty  = (bool) get_option( 'permalink_structure' );
	$front   = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) ( get_page_by_path( 'home' ) ? get_page_by_path( 'home' )->ID : -1 );
	$locs    = get_theme_mod( 'nav_menu_locations', array() );
	$menu    = ! empty( $locs['primary'] ) ? wp_get_nav_menu_object( $locs['primary'] ) : null;
	$is_ours = $menu && 'Looma Main Menu' === $menu->name;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Looma Setup', 'looma' ); ?></h1>

		<?php if ( isset( $_GET['looma_fixed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success"><p><strong><?php esc_html_e( 'Done! Pages, menus and links have been set up.', 'looma' ); ?></strong> <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php esc_html_e( 'View your website', 'looma' ); ?></a>. <?php esc_html_e( 'If you use a caching plugin (e.g. LiteSpeed Cache on Hostinger), purge the cache once.', 'looma' ); ?></p></div>
		<?php endif; ?>

		<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<div class="notice notice-warning"><p><strong><?php esc_html_e( 'WooCommerce is active.', 'looma' ); ?></strong> <?php esc_html_e( 'WooCommerce also uses the /shop/ address, which can hide the Looma shop. This theme does not need WooCommerce — deactivate it under Plugins if you are not using it.', 'looma' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'This screen checks everything the website needs. If anything is not green, click “Fix everything”.', 'looma' ); ?></p>

		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php esc_html_e( 'Page', 'looma' ); ?></th><th><?php esc_html_e( 'Address', 'looma' ); ?></th><th><?php esc_html_e( 'Status', 'looma' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r[0] ); ?></strong></td>
						<td><?php echo $r[3] ? '<a href="' . esc_url( $r[3] ) . '" target="_blank">' . esc_html( $r[3] ) . '</a>' : '<code>/' . esc_html( $r[1] ) . '/</code>'; ?></td>
						<td><?php echo 'ok' === $r[2] ? '<span style="color:#1a7f37">✔ OK</span>' : ( 'missing' === $r[2] ? '<span style="color:#b32d2e">✖ Missing</span>' : '<span style="color:#b32d2e">✖ Not published</span>' ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr><td><strong><?php esc_html_e( 'Pretty links', 'looma' ); ?></strong></td><td><?php esc_html_e( 'Settings → Permalinks', 'looma' ); ?></td><td><?php echo $pretty ? '<span style="color:#1a7f37">✔ OK</span>' : '<span style="color:#b32d2e">✖ Off</span>'; ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Home page', 'looma' ); ?></strong></td><td><?php esc_html_e( 'Settings → Reading', 'looma' ); ?></td><td><?php echo $front ? '<span style="color:#1a7f37">✔ OK</span>' : '<span style="color:#b32d2e">✖ Not set to “Home”</span>'; ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Main menu', 'looma' ); ?></strong></td><td><?php echo esc_html( $menu ? $menu->name : __( 'none', 'looma' ) ); ?></td><td><?php echo $is_ours ? '<span style="color:#1a7f37">✔ Looma menu</span>' : '<span style="color:#996800">● Custom menu — check its links</span>'; ?></td></tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px">
			<input type="hidden" name="action" value="looma_fix">
			<?php wp_nonce_field( 'looma_fix' ); ?>
			<p><label><input type="checkbox" name="replace_menus" value="1" <?php checked( ! $is_ours ); ?>> <?php esc_html_e( 'Use the Looma menus for the main and footer menu (your other menus are kept under Appearance → Menus)', 'looma' ); ?></label></p>
			<p><button type="submit" class="button button-primary button-hero"><?php echo $broken || ! $pretty || ! $front ? esc_html__( 'Fix everything', 'looma' ) : esc_html__( 'Run setup again', 'looma' ); ?></button></p>
		</form>
	</div>
	<?php
}

function looma_handle_fix() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'looma' ) );
	}
	check_admin_referer( 'looma_fix' );
	looma_run_setup( ! empty( $_POST['replace_menus'] ) );
	wp_safe_redirect( admin_url( 'themes.php?page=looma-setup&looma_fixed=1' ) );
	exit;
}
add_action( 'admin_post_looma_fix', 'looma_handle_fix' );

/**
 * Admin notice: point to the setup screen when something is missing.
 */
function looma_setup_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'appearance_page_looma-setup' === $screen->id ) {
		return;
	}
	$broken = array_filter( looma_page_status(), function ( $r ) { return 'ok' !== $r[2]; } );
	if ( $broken || ! get_option( 'permalink_structure' ) ) {
		printf(
			'<div class="notice notice-error"><p><strong>Looma Apparels:</strong> some website pages are missing, so visitors may see “Page not found”. <a class="button button-primary" href="%s">Fix it now</a></p></div>',
			esc_url( admin_url( 'themes.php?page=looma-setup' ) )
		);
	} elseif ( 'themes' === $screen->id ) {
		printf(
			'<div class="notice notice-success"><p><strong>Looma Apparels is ready.</strong> <a href="%1$s" target="_blank">View your website</a> · <a href="%2$s">Edit phone, WhatsApp &amp; email</a> · <a href="%3$s">Looma Setup</a></p></div>',
			esc_url( home_url( '/' ) ),
			esc_url( admin_url( 'customize.php?autofocus[section]=looma_business' ) ),
			esc_url( admin_url( 'themes.php?page=looma-setup' ) )
		);
	}
}
add_action( 'admin_notices', 'looma_setup_notice' );
