<?php
/**
 * Bulk orders page.
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'Premium quality apparel for your business', 'looma' ), __( 'Custom T-Shirts <span>in Bulk</span>', 'looma' ), __( 'From 10 pieces to thousands — for clothing brands, events, colleges, teams and retailers.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container feature-split reverse">
			<div class="feature-split-media"><?php looma_img( 'bulk.jpg', __( 'Folded hoodies with a Looma Apparels box', 'looma' ), '', true, 1100, 1300 ); ?></div>
			<div>
				<p class="eyebrow"><?php esc_html_e( 'Why order with us', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Low minimums. Full control.', 'looma' ); ?></h2>
				<ul class="icon-list">
					<li><span class="usp-icon"><?php looma_the_icon( 'box' ); ?></span><div><h3><?php esc_html_e( 'Start with just 10 pieces', 'looma' ); ?></h3><p><?php esc_html_e( 'Bulk pricing kicks in from 10 pieces and keeps dropping as you scale.', 'looma' ); ?></p></div></li>
					<li><span class="usp-icon"><?php looma_the_icon( 'hoodie' ); ?></span><div><h3><?php esc_html_e( 'Full customisation', 'looma' ); ?></h3><p><?php esc_html_e( 'Different designs, colours and sizes for each piece in the same order.', 'looma' ); ?></p></div></li>
					<li><span class="usp-icon"><?php looma_the_icon( 'printer' ); ?></span><div><h3><?php esc_html_e( 'Multiple printing options', 'looma' ); ?></h3><p><?php esc_html_e( 'DTF, screen print, puff print, embroidery, high density and more.', 'looma' ); ?></p></div></li>
					<li><span class="usp-icon"><?php looma_the_icon( 'tag' ); ?></span><div><h3><?php esc_html_e( 'FREE branding', 'looma' ); ?></h3><p><?php esc_html_e( 'No extra charge for neck labels with an A2/A3/A4 print. Custom packaging and labels at extra cost.', 'looma' ); ?></p></div></li>
				</ul>
			</div>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container">
			<div class="section-head center">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Ideal for', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Made for every kind of team', 'looma' ); ?></h2>
				</div>
			</div>
			<?php looma_ideal_for(); ?>
		</div>
	</section>

	<section class="section">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Volume pricing', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'The more you order, the less you pay', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'Price per piece for plain t-shirts. Add printing in the price estimator.', 'looma' ); ?></p>
				</div>
				<a class="btn btn-dark" href="<?php echo esc_url( looma_page_url( 'price-estimator' ) ); ?>"><?php looma_the_icon( 'calc' ); ?> <?php esc_html_e( 'Price estimator', 'looma' ); ?></a>
			</div>
			<div class="table-scroll">
				<table class="table table-matrix">
					<thead>
						<tr><th><?php esc_html_e( 'T-shirt', 'looma' ); ?></th><th>1+</th><th>10+</th><th>25+</th><th>50+</th><th>100+</th></tr>
					</thead>
					<tbody>
						<?php foreach ( looma_products() as $looma_p ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( looma_product_url( $looma_p['id'] ) ); ?>"><strong><?php echo esc_html( $looma_p['name'] ); ?></strong><small><?php echo esc_html( $looma_p['spec'] ); ?></small></a></td>
								<?php
								foreach ( array( 1, 10, 25, 50, 100 ) as $looma_q ) {
									$looma_price = 0;
									foreach ( $looma_p['prices'] as $looma_t ) {
										if ( $looma_q >= $looma_t['min'] ) {
											$looma_price = $looma_t['price'];
										}
									}
									echo '<td>' . esc_html( looma_rupee( $looma_price ) ) . '</td>';
								}
								?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="fineprint"><?php esc_html_e( 'Prices per piece, 5% GST extra. 100+ more colours in production quantity (MOQ 60 pcs).', 'looma' ); ?></p>
		</div>
	</section>

	<section class="section section-tint" id="enquiry">
		<div class="container form-split">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'Request a quote', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Tell us about your order', 'looma' ); ?></h2>
				<p class="section-sub"><?php esc_html_e( 'Share the style, colours, quantity and print details — we will reply with pricing and timelines.', 'looma' ); ?></p>
				<?php looma_faq( array( looma_faqs()[0], looma_faqs()[4], looma_faqs()[2] ) ); ?>
			</div>
			<div class="form-card">
				<?php looma_enquiry_notice(); ?>
				<?php looma_enquiry_form( __( 'Bulk order', 'looma' ) ); ?>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
