<?php
/**
 * Quote list: products the visitor added, sent as one enquiry.
 * The list is kept in the visitor's browser (see assets/js/main.js).
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'Your selection', 'looma' ), __( 'Quote List', 'looma' ), __( 'Review your items and send them to us — we will confirm pricing, printing and delivery.', 'looma' ) ); ?>

	<section class="section section-tight" id="enquiry">
		<div class="container quote-grid">
			<div>
				<div class="quote-empty" data-quote-empty>
					<?php looma_the_icon( 'bag' ); ?>
					<h2><?php esc_html_e( 'Your quote list is empty', 'looma' ); ?></h2>
					<p><?php esc_html_e( 'Browse the shop and tap “Add to quote list” on any product.', 'looma' ); ?></p>
					<a class="btn btn-dark" href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'Go to shop', 'looma' ); ?></a>
				</div>
				<ul class="quote-list" data-quote-list hidden></ul>
				<div class="quote-summary" data-quote-summary hidden>
					<div><span><?php esc_html_e( 'Estimated subtotal (plain t-shirts)', 'looma' ); ?></span><strong data-quote-sub>—</strong></div>
					<div><span><?php esc_html_e( 'GST (5%)', 'looma' ); ?></span><strong data-quote-gst>—</strong></div>
					<div class="total"><span><?php esc_html_e( 'Estimated total', 'looma' ); ?></span><strong data-quote-total>—</strong></div>
					<p class="fineprint"><?php esc_html_e( 'Quantity pricing is applied per product. Printing is added after we review your designs.', 'looma' ); ?></p>
				</div>
			</div>
			<div class="form-card">
				<h2 class="block-title"><?php esc_html_e( 'Send quote request', 'looma' ); ?></h2>
				<?php looma_enquiry_notice(); ?>
				<?php looma_enquiry_form( '', true ); ?>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
