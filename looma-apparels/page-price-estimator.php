<?php
/**
 * Price estimator page.
 *
 * @package Looma_Apparels
 */

get_header();

$looma_products = looma_products();
$looma_pre      = isset( $_GET['product'] ) ? sanitize_title( wp_unslash( $_GET['product'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<main id="main">
	<?php looma_page_hero( __( 'Transparent pricing', 'looma' ), __( 'Price Estimator', 'looma' ), __( 'Pick a t-shirt, quantity and DTF prints to see your price per piece, GST and total — instantly.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container">
			<div class="calc" id="calculator">
				<div class="calc-form">
					<h2 class="block-title"><?php looma_the_icon( 'calc' ); ?> <?php esc_html_e( 'Instant price estimator', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'Pick a t-shirt, quantity and DTF prints to see an estimate.', 'looma' ); ?></p>

					<label class="field">
						<span><?php esc_html_e( 'T-shirt', 'looma' ); ?></span>
						<select id="calc-product">
							<?php foreach ( $looma_products as $looma_p ) : ?>
								<option value="<?php echo esc_attr( $looma_p['id'] ); ?>"<?php selected( $looma_pre, $looma_p['id'] ); ?>><?php echo esc_html( $looma_p['name'] . ' — ' . $looma_p['spec'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>

					<label class="field">
						<span><?php esc_html_e( 'Quantity (pieces)', 'looma' ); ?></span>
						<input type="number" id="calc-qty" min="1" step="1" value="1" inputmode="numeric">
					</label>

					<div class="field-row">
						<label class="field">
							<span><?php esc_html_e( 'Front print', 'looma' ); ?></span>
							<select id="calc-front">
								<option value=""><?php esc_html_e( 'None', 'looma' ); ?></option>
								<option value="logo"><?php esc_html_e( 'Chest logo (2.5 × 2.5")', 'looma' ); ?></option>
								<option value="a4"><?php esc_html_e( 'A4 (8 × 11")', 'looma' ); ?></option>
								<option value="a3"><?php esc_html_e( 'A3 (11 × 16")', 'looma' ); ?></option>
								<option value="a2"><?php esc_html_e( 'A2 (16 × 22")', 'looma' ); ?></option>
							</select>
						</label>
						<label class="field">
							<span><?php esc_html_e( 'Back print', 'looma' ); ?></span>
							<select id="calc-back">
								<option value=""><?php esc_html_e( 'None', 'looma' ); ?></option>
								<option value="logo"><?php esc_html_e( 'Logo (2.5 × 2.5")', 'looma' ); ?></option>
								<option value="a4"><?php esc_html_e( 'A4 (8 × 11")', 'looma' ); ?></option>
								<option value="a3" selected><?php esc_html_e( 'A3 (11 × 16")', 'looma' ); ?></option>
								<option value="a2"><?php esc_html_e( 'A2 (16 × 22")', 'looma' ); ?></option>
							</select>
						</label>
					</div>
				</div>

				<div class="calc-result" aria-live="polite">
					<dl>
						<div><dt><?php esc_html_e( 'T-shirt', 'looma' ); ?></dt><dd id="r-base">—</dd></div>
						<div><dt><?php esc_html_e( 'Printing', 'looma' ); ?></dt><dd id="r-print">—</dd></div>
						<div><dt><?php esc_html_e( 'Neck label', 'looma' ); ?></dt><dd id="r-label">—</dd></div>
						<div class="r-per"><dt><?php esc_html_e( 'Per piece', 'looma' ); ?></dt><dd id="r-per">—</dd></div>
						<div><dt id="r-sub-label"><?php esc_html_e( 'Subtotal', 'looma' ); ?></dt><dd id="r-sub">—</dd></div>
						<div><dt><?php esc_html_e( 'GST (5%)', 'looma' ); ?></dt><dd id="r-gst">—</dd></div>
						<div class="r-total"><dt><?php esc_html_e( 'Estimated total', 'looma' ); ?></dt><dd id="r-total">—</dd></div>
					</dl>
					<a class="btn btn-light btn-block" id="calc-wa" href="<?php echo esc_url( looma_whatsapp_link() ); ?>" target="_blank" rel="noopener"><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'Send this quote on WhatsApp', 'looma' ); ?></a>
					<p class="calc-note"><?php esc_html_e( 'Estimate only. Embroidery, screen, puff and HD printing are quoted per design — contact us.', 'looma' ); ?></p>
				</div>
			</div>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Price guide', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Popular print combinations', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'Examples on our 250 GSM Oversized French Terry tee with DTF printing.', 'looma' ); ?></p>
				</div>
			</div>
			<div class="guide-grid">
				<?php foreach ( looma_price_examples() as $looma_n => $looma_ex ) : ?>
					<article class="guide-card">
						<p class="guide-num"><?php echo esc_html( sprintf( '%02d', $looma_n + 1 ) ); ?></p>
						<h3><?php echo esc_html( $looma_ex['title'] ); ?></h3>
						<div class="guide-media"><?php looma_img( $looma_ex['image'], $looma_ex['title'], '', false, 700, 880 ); ?></div>
						<p class="guide-text"><?php echo esc_html( $looma_ex['text'] ); ?></p>
						<div class="guide-prices">
							<div><span><?php esc_html_e( '1 piece', 'looma' ); ?></span><strong><?php echo esc_html( looma_rupee( $looma_ex['one'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( '10 pieces', 'looma' ); ?></span><strong><?php echo esc_html( looma_rupee( $looma_ex['ten'] ) ); ?></strong><small><?php esc_html_e( 'per piece', 'looma' ); ?></small></div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<p class="fineprint center"><?php esc_html_e( 'GST 5% extra · Prices are per piece · Neck label (your brand name) free with A2/A3/A4 print.', 'looma' ); ?></p>
		</div>
	</section>

	<?php looma_cta_band(); ?>
</main>
<?php
get_footer();
