<?php
/**
 * Home page.
 *
 * @package Looma_Apparels
 */

get_header();

$looma_products = looma_products();
?>

<main id="main">

	<section class="hero">
		<div class="container hero-grid">
			<div class="hero-copy">
				<p class="eyebrow"><?php esc_html_e( 'Premium blanks · Custom printing · Made in Kerala', 'looma' ); ?></p>
				<h1 class="hero-title"><?php esc_html_e( 'Your brand.', 'looma' ); ?><br><span><?php esc_html_e( 'Our production.', 'looma' ); ?></span></h1>
				<p class="hero-lead"><?php esc_html_e( 'Heavyweight oversized tees, regular fits and acid washes — printed with your designs and shipped anywhere in India. Start with a single piece.', 'looma' ); ?></p>
				<div class="hero-actions">
					<a class="btn btn-dark btn-lg" href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'Shop the range', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
					<a class="btn btn-outline btn-lg" href="<?php echo esc_url( looma_page_url( 'dropshipping' ) ); ?>"><?php esc_html_e( 'Start dropshipping', 'looma' ); ?></a>
				</div>
				<ul class="hero-trust">
					<li><?php looma_the_icon( 'check' ); ?> <?php esc_html_e( 'No minimum on print on demand', 'looma' ); ?></li>
					<li><?php looma_the_icon( 'check' ); ?> <?php esc_html_e( 'Bulk orders from 10 pcs', 'looma' ); ?></li>
					<li><?php looma_the_icon( 'check' ); ?> <?php esc_html_e( 'Free neck-label branding', 'looma' ); ?></li>
				</ul>
			</div>
			<div class="hero-media">
				<?php looma_img( 'hero.jpg', __( 'Folded Looma t-shirts in white, grey and black', 'looma' ), '', true, 1200, 1300 ); ?>
				<a class="hero-chip" href="<?php echo esc_url( looma_product_url( $looma_products[0]['id'] ) ); ?>">
					<span class="hero-chip-dot" style="--sw:#131313"></span>
					<span><strong><?php echo esc_html( $looma_products[0]['name'] . ' ' . $looma_products[0]['gsm'] ); ?></strong><small><?php printf( esc_html__( 'From %s / piece', 'looma' ), esc_html( looma_rupee( looma_from_price( $looma_products[0] ) ) ) ); ?></small></span>
					<?php looma_the_icon( 'arrow' ); ?>
				</a>
			</div>
		</div>
	</section>

	<div class="marquee" aria-hidden="true">
		<div class="marquee-track">
			<?php for ( $looma_i = 0; $looma_i < 2; $looma_i++ ) : ?>
				<span>Oversized Tees</span><span>DTF Printing</span><span>Embroidery</span><span>Acid Wash</span><span>Puff Print</span><span>Screen Print</span><span>High Density</span><span>Pan-India Delivery</span>
			<?php endfor; ?>
		</div>
	</div>

	<section class="section">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Bestsellers', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Blanks built for brands', 'looma' ); ?></h2>
				</div>
				<a class="link-arrow" href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'View all products', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
			</div>
			<div class="p-grid p-grid-4">
				<?php
				foreach ( array_slice( $looma_products, 0, 4 ) as $looma_p ) {
					looma_product_card( $looma_p );
				}
				?>
			</div>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Two ways to work with us', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'One piece or one thousand', 'looma' ); ?></h2>
				</div>
			</div>
			<div class="split-cards">
				<a class="split-card" href="<?php echo esc_url( looma_page_url( 'dropshipping' ) ); ?>">
					<div class="split-card-media"><?php looma_img( 'dropship.jpg', __( 'Folded Looma t-shirts ready to ship', 'looma' ), '', false, 1400, 1000 ); ?></div>
					<div class="split-card-body">
						<p class="eyebrow"><?php esc_html_e( 'For online stores & creators', 'looma' ); ?></p>
						<h3><?php esc_html_e( 'Dropshipping / Print on Demand', 'looma' ); ?></h3>
						<p><?php esc_html_e( 'No minimum order. You sell, we print, pack and ship straight to your customer with your branding.', 'looma' ); ?></p>
						<span class="link-arrow"><?php esc_html_e( 'How it works', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></span>
					</div>
				</a>
				<a class="split-card" href="<?php echo esc_url( looma_page_url( 'bulk-orders' ) ); ?>">
					<div class="split-card-media"><?php looma_img( 'bulk.jpg', __( 'Folded hoodies with a Looma Apparels box', 'looma' ), '', false, 1100, 1300 ); ?></div>
					<div class="split-card-body">
						<p class="eyebrow"><?php esc_html_e( 'For brands, events & teams', 'looma' ); ?></p>
						<h3><?php esc_html_e( 'Bulk Orders', 'looma' ); ?></h3>
						<p><?php esc_html_e( 'Start from just 10 pieces. Mix designs, colours and sizes, with free neck-label branding on A2/A3/A4 prints.', 'looma' ); ?></p>
						<span class="link-arrow"><?php esc_html_e( 'Plan a bulk order', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></span>
					</div>
				</a>
			</div>
		</div>
	</section>

	<section class="section">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Printing & embellishment', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Five ways to bring your design to life', 'looma' ); ?></h2>
				</div>
				<a class="link-arrow" href="<?php echo esc_url( looma_page_url( 'printing' ) ); ?>"><?php esc_html_e( 'Compare techniques', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
			</div>
			<div class="tech-row">
				<?php foreach ( looma_print_techniques() as $looma_t ) : ?>
					<a class="tech-tile" href="<?php echo esc_url( looma_page_url( 'printing' ) . '#' . $looma_t['id'] ); ?>">
						<?php looma_img( $looma_t['image'], $looma_t['name'] . ' example', '', false, 1000, 1000 ); ?>
						<span class="tech-tile-label"><strong><?php echo esc_html( $looma_t['name'] ); ?></strong><small><?php echo esc_html( $looma_t['moq'] ); ?></small></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="estimate-band">
		<div class="container estimate-grid">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'Transparent pricing', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Know your price in 10 seconds', 'looma' ); ?></h2>
				<p class="section-sub"><?php esc_html_e( 'Pick a t-shirt, quantity and print sizes — our estimator shows the per-piece price, GST and total instantly.', 'looma' ); ?></p>
				<a class="btn btn-light btn-lg" href="<?php echo esc_url( looma_page_url( 'price-estimator' ) ); ?>"><?php looma_the_icon( 'calc' ); ?> <?php esc_html_e( 'Open price estimator', 'looma' ); ?></a>
			</div>
			<ul class="estimate-examples">
				<?php foreach ( looma_price_examples() as $looma_ex ) : ?>
					<li><span><?php echo esc_html( $looma_ex['title'] ); ?></span><strong><?php echo esc_html( looma_rupee( $looma_ex['ten'] ) ); ?></strong><small><?php esc_html_e( 'per pc at 10 pcs', 'looma' ); ?></small></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>

	<section class="section">
		<div class="container">
			<div class="section-head center">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Why Looma', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Made properly. Priced fairly.', 'looma' ); ?></h2>
				</div>
			</div>
			<div class="usp-grid">
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'layers' ); ?></span><h3><?php esc_html_e( '100% bio-washed cotton', 'looma' ); ?></h3><p><?php esc_html_e( 'Soft hand-feel from 190 to 250 GSM, with no colour fading.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'shield' ); ?></span><h3><?php esc_html_e( 'Built to last', 'looma' ); ?></h3><p><?php esc_html_e( 'Double-needle stitching with durby or lycra ribs that keep their shape.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'tag' ); ?></span><h3><?php esc_html_e( 'Free branding', 'looma' ); ?></h3><p><?php esc_html_e( 'Your brand name on the neck label, free with any A2/A3/A4 print.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'truck' ); ?></span><h3><?php esc_html_e( 'Pan-India delivery', 'looma' ); ?></h3><p><?php esc_html_e( 'Shipped with trusted couriers, with tracking shared on every order.', 'looma' ); ?></p></div>
			</div>
			<div class="ideal">
				<p class="eyebrow center"><?php esc_html_e( 'Made for', 'looma' ); ?></p>
				<?php looma_ideal_for(); ?>
			</div>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container faq-wrap">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'Good to know', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Questions, answered', 'looma' ); ?></h2>
				<p class="section-sub"><?php esc_html_e( 'Can’t find what you need? Message us — we are happy to help.', 'looma' ); ?></p>
				<a class="btn btn-outline" href="<?php echo esc_url( looma_page_url( 'contact' ) ); ?>"><?php esc_html_e( 'Contact us', 'looma' ); ?></a>
			</div>
			<?php looma_faq( array_slice( looma_faqs(), 0, 5 ) ); ?>
		</div>
	</section>

	<?php looma_cta_band(); ?>

</main>

<?php
get_footer();
