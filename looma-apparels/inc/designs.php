<?php
/**
 * Design Studio submissions.
 *
 * The Design a Product page uploads the mockup images and the customer's
 * original artwork here before opening WhatsApp, so the WhatsApp message can
 * carry links to the files. Each design is stored in its own folder:
 *   wp-content/uploads/looma-designs/{year-month}/{id}/
 * and listed in WordPress admin under "Design Requests".
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

define( 'LOOMA_DESIGN_MAX_FILES', 12 );
define( 'LOOMA_DESIGN_MAX_BYTES', 15 * MB_IN_BYTES );
define( 'LOOMA_DESIGN_PER_HOUR', 15 );

/**
 * Base folder for designs.
 */
function looma_designs_dir() {
	$up = wp_upload_dir();
	return array(
		'dir' => trailingslashit( $up['basedir'] ) . 'looma-designs',
		'url' => trailingslashit( $up['baseurl'] ) . 'looma-designs',
	);
}

/**
 * Fresh nonce (fetched right before upload so cached pages never hold a stale one).
 */
function looma_design_nonce() {
	wp_send_json_success( array( 'nonce' => wp_create_nonce( 'looma_design' ) ) );
}
add_action( 'wp_ajax_looma_design_nonce', 'looma_design_nonce' );
add_action( 'wp_ajax_nopriv_looma_design_nonce', 'looma_design_nonce' );

/**
 * Re-index $_FILES[ $key ] (multiple upload) into a flat list.
 */
function looma_design_files( $key ) {
	if ( empty( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ]['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return array();
	}
	$f   = $_FILES[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	$out = array();
	foreach ( array_keys( $f['name'] ) as $i ) {
		$out[] = array(
			'name'     => sanitize_file_name( $f['name'][ $i ] ),
			'tmp_name' => $f['tmp_name'][ $i ],
			'error'    => $f['error'][ $i ],
			'size'     => $f['size'][ $i ],
		);
	}
	return $out;
}

/**
 * Handle a design submission.
 */
function looma_design_submit() {
	if ( ! check_ajax_referer( 'looma_design', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Session expired. Please try again.' ), 403 );
	}

	// Simple rate limit per visitor IP.
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rkey = 'looma_ds_' . md5( $ip );
	$hits = (int) get_transient( $rkey );
	if ( $hits >= LOOMA_DESIGN_PER_HOUR ) {
		wp_send_json_error( array( 'message' => 'Too many uploads. Please try again in an hour.' ), 429 );
	}
	set_transient( $rkey, $hits + 1, HOUR_IN_SECONDS );

	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
	$summary = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '';
	$total   = isset( $_POST['total'] ) ? sanitize_text_field( wp_unslash( $_POST['total'] ) ) : '';


	$groups = array(
		'mockup'  => looma_design_files( 'mockups' ),
		'artwork' => looma_design_files( 'artwork' ),
	);
	if ( count( $groups['mockup'] ) + count( $groups['artwork'] ) > LOOMA_DESIGN_MAX_FILES ) {
		wp_send_json_error( array( 'message' => 'Too many files.' ), 400 );
	}

	$id   = strtoupper( wp_generate_password( 4, false ) ) . '-' . strtolower( wp_generate_password( 8, false ) );
	$base = looma_designs_dir();
	$sub  = gmdate( 'Y-m' ) . '/' . $id;
	$dir  = trailingslashit( $base['dir'] ) . $sub;
	$url  = trailingslashit( $base['url'] ) . $sub;
	if ( ! wp_mkdir_p( $dir ) ) {
		wp_send_json_error( array( 'message' => 'Could not save your design. Please send it on WhatsApp directly.' ), 500 );
	}
	foreach ( array( $base['dir'], dirname( $dir ), $dir ) as $d ) {
		if ( ! file_exists( $d . '/index.html' ) ) {
			file_put_contents( $d . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	$allowed = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);
	$saved   = array(
		'mockup'  => array(),
		'artwork' => array(),
	);
	foreach ( $groups as $group => $files ) {
		foreach ( $files as $i => $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] > LOOMA_DESIGN_MAX_BYTES || ! is_uploaded_file( $file['tmp_name'] ) ) {
				continue;
			}
			$info = function_exists( 'finfo_open' ) ? finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file['tmp_name'] ) : '';
			$size = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $size || ! isset( $allowed[ $size['mime'] ] ) || ( $info && $info !== $size['mime'] ) ) {
				continue;
			}
			$label = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( pathinfo( $file['name'], PATHINFO_FILENAME ) ) );
			$label = trim( substr( $label, 0, 40 ), '-' );
			$fname = sprintf( '%s-%02d%s.%s', $group, $i + 1, $label ? '-' . $label : '', $allowed[ $size['mime'] ] );
			if ( move_uploaded_file( $file['tmp_name'], $dir . '/' . $fname ) ) {
				$saved[ $group ][] = $url . '/' . $fname;
			}
		}
	}

	$record = array(
		'id'      => $id,
		'date'    => current_time( 'mysql' ),
		'name'    => $name,
		'phone'   => $phone,
		'notes'   => $notes,
		'summary' => $summary,
		'total'   => $total,
		'mockups' => $saved['mockup'],
		'artwork' => $saved['artwork'],
	);
	file_put_contents( $dir . '/design.json', wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	// Notify the shop.
	$body  = "New design from the Design Studio (customer continues on WhatsApp)\n\nDesign ID: {$id}\n";
	$body .= $total ? "Estimate: {$total}\n" : '';
	$body .= $notes ? "\nNotes:\n{$notes}\n" : '';
	$body .= "\n{$summary}\n\nMockups:\n" . implode( "\n", $saved['mockup'] ) . "\n\nArtwork files:\n" . ( $saved['artwork'] ? implode( "\n", $saved['artwork'] ) : '(text only)' ) . "\n";
	wp_mail( looma_opt( 'looma_email' ), sprintf( 'New design %s — Looma Design Studio', $id ), $body );

	wp_send_json_success(
		array(
			'id'      => $id,
			'mockups' => $saved['mockup'],
			'artwork' => $saved['artwork'],
		)
	);
}
add_action( 'wp_ajax_looma_design_submit', 'looma_design_submit' );
add_action( 'wp_ajax_nopriv_looma_design_submit', 'looma_design_submit' );

/* ------------------------------------------------------------------
 * Admin: Design Requests
 * ------------------------------------------------------------------ */

function looma_designs_menu() {
	add_menu_page( __( 'Design Requests', 'looma' ), __( 'Design Requests', 'looma' ), 'edit_posts', 'looma-designs', 'looma_designs_screen', 'dashicons-art', 26 );
}
add_action( 'admin_menu', 'looma_designs_menu' );

/**
 * All saved designs, newest first.
 */
function looma_designs_all() {
	$base  = looma_designs_dir();
	$files = glob( $base['dir'] . '/*/*/design.json' );
	$out   = array();
	foreach ( (array) $files as $f ) {
		$d = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_array( $d ) ) {
			$d['_dir'] = dirname( $f );
			$out[]     = $d;
		}
	}
	usort(
		$out,
		function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		}
	);
	return $out;
}

function looma_designs_screen() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$designs = looma_designs_all();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Design Requests', 'looma' ); ?></h1>
		<p><?php esc_html_e( 'Designs customers created in the Design Studio and sent to you. Click a mockup to open it full size.', 'looma' ); ?></p>
		<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Design deleted.', 'looma' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! $designs ) : ?>
			<p><em><?php esc_html_e( 'No designs yet.', 'looma' ); ?></em></p>
		<?php endif; ?>
		<?php foreach ( $designs as $d ) : ?>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:14px 0;max-width:1100px">
				<div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap">
					<div>
						<h2 style="margin:0 0 4px"><?php echo esc_html( $d['name'] ? $d['name'] : __( 'Design', 'looma' ) . ' ' . $d['id'] ); ?> <small style="font-weight:400;color:#646970">· <?php echo esc_html( $d['id'] ); ?> · <?php echo esc_html( mysql2date( 'j M Y, g:i a', $d['date'] ) ); ?></small></h2>
						<p style="margin:0">
							<?php if ( $d['phone'] ) : ?><a href="<?php echo esc_url( 'https://wa.me/' . preg_replace( '/\D+/', '', $d['phone'] ) ); ?>" target="_blank"><?php echo esc_html( $d['phone'] ); ?></a><?php else : ?><?php esc_html_e( 'Customer sends from WhatsApp — match by Design ID', 'looma' ); ?><?php endif; ?>
							<?php if ( ! empty( $d['total'] ) ) : ?> · <strong><?php echo esc_html( $d['total'] ); ?></strong><?php endif; ?>
						</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this design and its files?');">
						<input type="hidden" name="action" value="looma_design_delete">
						<input type="hidden" name="design" value="<?php echo esc_attr( basename( dirname( $d['_dir'] ) ) . '/' . basename( $d['_dir'] ) ); ?>">
						<?php wp_nonce_field( 'looma_design_delete' ); ?>
						<button class="button button-link-delete"><?php esc_html_e( 'Delete', 'looma' ); ?></button>
					</form>
				</div>
				<div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0">
					<?php foreach ( array_merge( (array) $d['mockups'], (array) $d['artwork'] ) as $u ) : ?>
						<a href="<?php echo esc_url( $u ); ?>" target="_blank"><img src="<?php echo esc_url( $u ); ?>" alt="" style="width:140px;height:140px;object-fit:contain;background:#f0f0f1;border-radius:6px"></a>
					<?php endforeach; ?>
				</div>
				<details><summary><?php esc_html_e( 'Order details', 'looma' ); ?></summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:6px"><?php echo esc_html( $d['summary'] . ( $d['notes'] ? "\n\nNotes: " . $d['notes'] : '' ) ); ?></pre></details>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

function looma_design_delete() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'looma' ) );
	}
	check_admin_referer( 'looma_design_delete' );
	$rel  = isset( $_POST['design'] ) ? sanitize_text_field( wp_unslash( $_POST['design'] ) ) : '';
	$base = looma_designs_dir();
	if ( preg_match( '#^\d{4}-\d{2}/[A-Za-z0-9-]+$#', $rel ) ) {
		$dir = $base['dir'] . '/' . $rel;
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			wp_delete_file( $f );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
	wp_safe_redirect( admin_url( 'admin.php?page=looma-designs&deleted=1' ) );
	exit;
}
add_action( 'admin_post_looma_design_delete', 'looma_design_delete' );
