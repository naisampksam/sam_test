<?php
/**
 * About page.
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'About Looma Apparels', 'looma' ), __( 'Ideas into <span>Apparel.</span>', 'looma' ), __( 'A custom apparel manufacturer from Malappuram, Kerala — making premium blanks and printed t-shirts for brands across India.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container feature-split">
			<div class="feature-split-media"><?php looma_img( 'colours.jpg', __( 'Fabric swatches in the Looma colour range', 'looma' ), '', true, 1400, 1000 ); ?></div>
			<div class="prose">
				<p class="eyebrow"><?php esc_html_e( 'Our story', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Custom manufacture for bigger ideas', 'looma' ); ?></h2>
				<p><?php esc_html_e( 'Looma Apparels started with a simple belief: every brand deserves premium basics — whether it sells one t-shirt or ten thousand. We make heavyweight oversized tees, classic regular fits and acid washes in 100% bio-washed cotton, and print them with your designs in-house.', 'looma' ); ?></p>
				<p><?php esc_html_e( 'Creators use our print-on-demand service to launch without inventory. Clothing brands, startups, colleges and event teams use our bulk service for their drops and merchandise. Either way, you get the same fabric, the same finish and the same care.', 'looma' ); ?></p>
			</div>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container">
			<div class="stats">
				<div><strong>6</strong><span><?php esc_html_e( 'Ready-stock styles', 'looma' ); ?></span></div>
				<div><strong>100+</strong><span><?php esc_html_e( 'Colours in production', 'looma' ); ?></span></div>
				<div><strong>5</strong><span><?php esc_html_e( 'Printing techniques', 'looma' ); ?></span></div>
				<div><strong>1 pc</strong><span><?php esc_html_e( 'Minimum on print on demand', 'looma' ); ?></span></div>
			</div>
		</div>
	</section>

	<section class="section">
		<div class="container">
			<div class="section-head center">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'What we stand for', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Better basics. A brighter tomorrow.', 'looma' ); ?></h2>
				</div>
			</div>
			<div class="usp-grid">
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'layers' ); ?></span><h3><?php esc_html_e( 'Quality first', 'looma' ); ?></h3><p><?php esc_html_e( 'Premium fabric and fine stitching on every piece — plain or printed.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'sparkle' ); ?></span><h3><?php esc_html_e( 'Your brand, front and centre', 'looma' ); ?></h3><p><?php esc_html_e( 'Free neck-label branding, custom packaging and labels on request.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'tag' ); ?></span><h3><?php esc_html_e( 'Honest pricing', 'looma' ); ?></h3><p><?php esc_html_e( 'Clear per-piece prices that drop as you grow — no hidden charges.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'truck' ); ?></span><h3><?php esc_html_e( 'Reliable delivery', 'looma' ); ?></h3><p><?php esc_html_e( 'Pan-India shipping with trusted couriers and tracking on every order.', 'looma' ); ?></p></div>
			</div>
		</div>
	</section>

	<?php looma_cta_band( __( 'Come visit us in Malappuram', 'looma' ), __( 'Watani Complex, Manjeri Rd, Kizhisseri — or reach us on WhatsApp any time.', 'looma' ) ); ?>
</main>
<?php
get_footer();
