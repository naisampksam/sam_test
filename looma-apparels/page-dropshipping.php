<?php
/**
 * Dropshipping / Print on Demand page.
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'Your design, our production', 'looma' ), __( 'Dropshipping &amp; <span>Print on Demand</span>', 'looma' ), __( 'Sell custom t-shirts without holding stock. You take the order — we print, pack and ship it to your customer under your brand.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container feature-split">
			<div class="feature-split-media"><?php looma_img( 'dropship.jpg', __( 'Folded Looma t-shirts ready to ship', 'looma' ), '', true, 1400, 1000 ); ?></div>
			<div>
				<p class="eyebrow"><?php esc_html_e( 'Simple process. No limits.', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Launch your brand with zero inventory', 'looma' ); ?></h2>
				<ul class="icon-list">
					<li><span class="usp-icon"><?php looma_the_icon( 'box' ); ?></span><div><h3><?php esc_html_e( 'No minimum order quantity', 'looma' ); ?></h3><p><?php esc_html_e( 'Order even a single piece — perfect for testing designs and made-to-order stores.', 'looma' ); ?></p></div></li>
					<li><span class="usp-icon"><?php looma_the_icon( 'printer' ); ?></span><div><h3><?php esc_html_e( 'Customised printing', 'looma' ); ?></h3><p><?php esc_html_e( 'Full-colour DTF prints and premium embroidery on any of our blanks.', 'looma' ); ?></p></div></li>
					<li><span class="usp-icon"><?php looma_the_icon( 'tag' ); ?></span><div><h3><?php esc_html_e( 'Free branding available', 'looma' ); ?></h3><p><?php esc_html_e( 'Add your logo or neck label so every parcel looks like your own brand.', 'looma' ); ?></p></div></li>
					<li><span class="usp-icon"><?php looma_the_icon( 'truck' ); ?></span><div><h3><?php esc_html_e( 'Pan-India delivery', 'looma' ); ?></h3><p><?php esc_html_e( 'Shipped straight to your customer, with tracking details shared with you.', 'looma' ); ?></p></div></li>
				</ul>
			</div>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container">
			<div class="section-head center">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'How it works', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Five simple steps', 'looma' ); ?></h2>
				</div>
			</div>
			<?php looma_steps(); ?>
			<div class="couriers-wrap">
				<p class="eyebrow center"><?php esc_html_e( 'Delivered by', 'looma' ); ?></p>
				<?php looma_couriers(); ?>
			</div>
		</div>
	</section>

	<section class="section">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Popular for print on demand', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Pick your blanks', 'looma' ); ?></h2>
				</div>
				<a class="link-arrow" href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'All products', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
			</div>
			<div class="p-grid p-grid-4">
				<?php
				foreach ( array_slice( looma_products(), 0, 4 ) as $looma_p ) {
					looma_product_card( $looma_p );
				}
				?>
			</div>
		</div>
	</section>

	<section class="section section-tint" id="enquiry">
		<div class="container form-split">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'Get started', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Set up your dropshipping account', 'looma' ); ?></h2>
				<p class="section-sub"><?php esc_html_e( 'Send us your details and we will add you to the order sheet / WhatsApp group and share everything you need to start selling.', 'looma' ); ?></p>
				<?php looma_faq( array( looma_faqs()[5], looma_faqs()[1], looma_faqs()[3] ) ); ?>
			</div>
			<div class="form-card">
				<?php looma_enquiry_notice(); ?>
				<?php looma_enquiry_form( __( 'Dropshipping / Print on demand', 'looma' ) ); ?>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
