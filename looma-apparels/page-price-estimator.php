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
	<?php looma_page_hero( __( 'Transparent pricing · Bulk orders welcome', 'looma' ), __( 'Price Estimator', 'looma' ), __( 'Build your order — one tee or a full bulk order with different styles, colours, sizes and prints — and see the exact estimate instantly.', 'looma' ) ); ?>


	<section class="section section-tight builder-section" id="calculator">
		<div class="container">
			<div class="builder-intro">
				<div class="builder-steps" aria-hidden="true">
					<span><b>1</b> <?php esc_html_e( 'Pick a t-shirt', 'looma' ); ?></span>
					<span><b>2</b> <?php esc_html_e( 'Colour & sizes', 'looma' ); ?></span>
					<span><b>3</b> <?php esc_html_e( 'Add prints', 'looma' ); ?></span>
					<span><b>4</b> <?php esc_html_e( 'Send your quote', 'looma' ); ?></span>
				</div>
				<p class="builder-hint"><?php esc_html_e( 'Mix as many styles, colours and prints as you like in one order — quantity discounts are applied automatically.', 'looma' ); ?></p>
			</div>

			<div class="builder" data-builder data-preselect="<?php echo esc_attr( $looma_pre ); ?>">
				<div class="builder-main">
					<?php if ( current_user_can( 'edit_posts' ) ) : ?>
						<details class="staff-panel" open>
							<summary><?php looma_the_icon( 'shield' ); ?> <?php esc_html_e( 'Staff tools', 'looma' ); ?> <small><?php esc_html_e( 'only visible to logged-in staff', 'looma' ); ?></small></summary>
							<div class="staff-grid">
								<label class="field"><span><?php esc_html_e( 'Customer name', 'looma' ); ?></span><input type="text" data-staff="customer" placeholder="<?php esc_attr_e( 'e.g. Nova Streetwear', 'looma' ); ?>"></label>
								<label class="field"><span><?php esc_html_e( 'Customer phone', 'looma' ); ?></span><input type="tel" data-staff="phone"></label>
								<label class="field"><span><?php esc_html_e( 'Discount', 'looma' ); ?></span>
									<span class="input-group"><input type="number" min="0" step="1" value="0" data-staff="discount" inputmode="decimal"><select data-staff="discountType"><option value="pct">%</option><option value="amt">₹</option></select></span>
								</label>
								<label class="field"><span><?php esc_html_e( 'Shipping (₹)', 'looma' ); ?></span><input type="number" min="0" step="1" value="0" data-staff="shipping" inputmode="decimal"></label>
								<label class="field staff-notes"><span><?php esc_html_e( 'Notes on quotation', 'looma' ); ?></span><input type="text" data-staff="notes" placeholder="<?php esc_attr_e( 'e.g. Delivery in 7–10 working days after artwork approval', 'looma' ); ?>"></label>
							</div>
						</details>
					<?php endif; ?>

					<div class="lines" data-lines></div>

					<button type="button" class="add-line" data-add-line>
						<?php looma_the_icon( 'plus' ); ?>
						<span><strong><?php esc_html_e( 'Add another product', 'looma' ); ?></strong><small><?php esc_html_e( 'Different style, colour or print', 'looma' ); ?></small></span>
					</button>

					<details class="rules">
						<summary><?php esc_html_e( 'How prices are calculated', 'looma' ); ?><span class="faq-icon"><?php looma_the_icon( 'plus' ); ?></span></summary>
						<ul>
							<li><?php esc_html_e( 'T-shirt price is based on the total pieces of that style in your order (all colours and sizes together).', 'looma' ); ?></li>
							<li><?php esc_html_e( 'DTF print rates drop to the 10+ rate when your whole order is 10 pieces or more.', 'looma' ); ?></li>
							<li><?php esc_html_e( 'Embroidery: ₹7 per 1000 stitches (1–9 pcs), ₹6 (10–49 pcs), ₹4 (50+ pcs). Digitizing charges extra.', 'looma' ); ?></li>
							<li><?php esc_html_e( 'Neck label (your brand name) is free on items with an A2, A3 or A4 print.', 'looma' ); ?></li>
							<li><?php esc_html_e( 'Puff, HD and screen printing are quoted per design — mention them in your message.', 'looma' ); ?></li>
							<li><?php esc_html_e( 'Shipping charges are extra, as per actual weight and delivery location.', 'looma' ); ?></li>
							<li><?php esc_html_e( 'All prices per piece; 5% GST extra. This is an estimate — final price is confirmed after artwork review.', 'looma' ); ?></li>
						</ul>
					</details>
				</div>

				<aside class="summary" id="order-summary" aria-live="polite">
					<div class="summary-card">
						<div class="summary-head">
							<h2><?php esc_html_e( 'Your order', 'looma' ); ?></h2>
							<span class="summary-count" data-sum-count>0 pcs</span>
						</div>
						<ul class="summary-lines" data-sum-lines></ul>
						<dl class="summary-totals">
							<div><dt><?php esc_html_e( 'Subtotal', 'looma' ); ?></dt><dd data-sum-sub>₹0</dd></div>
							<div data-sum-discount-row hidden><dt><?php esc_html_e( 'Discount', 'looma' ); ?></dt><dd data-sum-discount>−₹0</dd></div>
							<div data-sum-ship-row hidden><dt><?php esc_html_e( 'Shipping', 'looma' ); ?></dt><dd data-sum-ship>₹0</dd></div>
							<div><dt><?php esc_html_e( 'GST (5%)', 'looma' ); ?></dt><dd data-sum-gst>₹0</dd></div>
							<div class="grand"><dt><?php esc_html_e( 'Estimated total', 'looma' ); ?></dt><dd data-sum-total>₹0</dd></div>
						</dl>
						<p class="summary-avg" data-sum-avg></p>
						<p class="ship-note" data-ship-note><?php looma_the_icon( 'truck' ); ?> <span><?php esc_html_e( 'Shipping charges extra — calculated on actual weight & location at dispatch.', 'looma' ); ?></span></p>
						<div class="summary-actions">
							<a class="btn btn-wa btn-block" href="#" target="_blank" rel="noopener" data-sum-wa><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'Send on WhatsApp', 'looma' ); ?></a>
							<div class="summary-actions-row">
								<button type="button" class="btn btn-light btn-sm" data-sum-email><?php looma_the_icon( 'mail' ); ?> <?php esc_html_e( 'Email', 'looma' ); ?></button>
								<button type="button" class="btn btn-light btn-sm" data-sum-print><?php looma_the_icon( 'printer' ); ?> <?php esc_html_e( 'PDF', 'looma' ); ?></button>
								<button type="button" class="btn btn-light btn-sm" data-sum-copy><?php looma_the_icon( 'sheet' ); ?> <?php esc_html_e( 'Copy', 'looma' ); ?></button>
							</div>
							<button type="button" class="summary-reset" data-sum-reset><?php esc_html_e( 'Start over', 'looma' ); ?></button>
						</div>
					</div>
				</aside>
			</div>

			<div class="mobile-total" data-mobile-total>
				<div><small data-mob-count>0 pcs</small><strong data-mob-total>₹0</strong></div>
				<a class="btn btn-light btn-sm" href="#order-summary" data-mob-view><?php esc_html_e( 'View order', 'looma' ); ?></a>
			</div>

			<div class="form-split builder-send" id="enquiry">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Send by email', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Get this quote confirmed', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'Your full order list is attached automatically. We will confirm pricing, artwork and delivery time.', 'looma' ); ?></p>
				</div>
				<div class="form-card">
					<?php looma_enquiry_notice(); ?>
					<?php looma_enquiry_form( '', true ); ?>
				</div>
			</div>
		</div>
	</section>

	<!-- Printable quotation (filled by estimator.js) -->
	<div class="quote-doc" data-quote-doc aria-hidden="true"></div>

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
