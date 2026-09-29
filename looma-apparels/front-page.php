<?php
/**
 * Home page — the full Looma Apparels catalog.
 *
 * @package Looma_Apparels
 */

get_header();

$looma_products = looma_products();
$looma_dtf      = looma_dtf_prices();
$looma_enquiry  = isset( $_GET['enquiry'] ) ? sanitize_key( wp_unslash( $_GET['enquiry'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>

<main id="main">

	<!-- ============ HERO ============ -->
	<section class="hero">
		<div class="container hero-grid">
			<div class="hero-copy">
				<p class="eyebrow"><?php esc_html_e( 'Looma Apparels · Catalog 2026', 'looma' ); ?></p>
				<h1 class="hero-title"><?php esc_html_e( 'Ideas into', 'looma' ); ?> <span><?php esc_html_e( 'Apparel.', 'looma' ); ?></span></h1>
				<p class="hero-lead"><?php esc_html_e( 'Premium blank t-shirts and custom printing for your brand. Print on demand from a single piece, bulk orders from just 10 pieces — delivered anywhere in India.', 'looma' ); ?></p>
				<div class="hero-actions">
					<a class="btn btn-dark btn-lg" href="#products"><?php esc_html_e( 'View Products', 'looma' ); ?> <?php echo looma_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					<a class="btn btn-outline btn-lg" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like a quote.' ) ); ?>" target="_blank" rel="noopener"><?php echo looma_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'WhatsApp Us', 'looma' ); ?></a>
				</div>
				<ul class="hero-stats">
					<li><strong><?php esc_html_e( '1 pc', 'looma' ); ?></strong><span><?php esc_html_e( 'No MOQ on print on demand', 'looma' ); ?></span></li>
					<li><strong><?php esc_html_e( '10 pcs', 'looma' ); ?></strong><span><?php esc_html_e( 'Bulk orders start here', 'looma' ); ?></span></li>
					<li><strong><?php esc_html_e( '100%', 'looma' ); ?></strong><span><?php esc_html_e( 'Cotton, bio washed', 'looma' ); ?></span></li>
				</ul>
			</div>
			<div class="hero-media">
				<?php looma_img( 'hero-stack.jpg', __( 'Folded Looma Apparels t-shirts in white, grey and black', 'looma' ), '', true ); ?>
				<div class="hero-badge">
					<span class="script"><?php esc_html_e( 'From Ideas to Apparel', 'looma' ); ?></span>
				</div>
			</div>
		</div>
	</section>

	<!-- ============ MARQUEE ============ -->
	<div class="strip" aria-hidden="true">
		<div class="strip-track">
			<?php for ( $looma_i = 0; $looma_i < 2; $looma_i++ ) : ?>
				<span>Premium Blank Apparel</span><span>DTF Printing</span><span>Embroidery</span><span>Screen Print</span><span>Puff Print</span><span>High Density</span><span>Free Neck Label Branding</span><span>Pan-India Delivery</span>
			<?php endfor; ?>
		</div>
	</div>

	<!-- ============ PRODUCTS ============ -->
	<section class="section" id="products">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Our bestsellers', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Top Products', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'Premium basics for every brand — quality, comfort, versatility. All styles below are available in ready stock.', 'looma' ); ?></p>
				</div>
				<div class="filter" role="group" aria-label="<?php esc_attr_e( 'Filter by fit', 'looma' ); ?>">
					<button type="button" class="chip is-active" data-filter="all"><?php esc_html_e( 'All', 'looma' ); ?></button>
					<button type="button" class="chip" data-filter="oversized"><?php esc_html_e( 'Oversized', 'looma' ); ?></button>
					<button type="button" class="chip" data-filter="regular"><?php esc_html_e( 'Regular', 'looma' ); ?></button>
				</div>
			</div>

			<div class="product-grid">
				<?php foreach ( $looma_products as $looma_p ) : ?>
					<article class="product-card" data-fit="<?php echo esc_attr( $looma_p['fit'] ); ?>">
						<button type="button" class="product-open" data-product="<?php echo esc_attr( $looma_p['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: product name */ __( 'View details: %s', 'looma' ), $looma_p['name'] . ' ' . $looma_p['gsm'] . ' ' . $looma_p['fabric'] ) ); ?>">
							<span class="product-media">
								<?php looma_img( $looma_p['image'], $looma_p['name'] . ' ' . $looma_p['gsm'] . ' ' . $looma_p['fabric'] ); ?>
								<?php if ( $looma_p['badge'] ) : ?>
									<span class="badge"><?php echo esc_html( $looma_p['badge'] ); ?></span>
								<?php endif; ?>
							</span>
						</button>
						<div class="product-body">
							<p class="product-kicker"><?php echo esc_html( $looma_p['gsm'] . ' · ' . $looma_p['fabric'] ); ?></p>
							<h3 class="product-name"><?php echo esc_html( looma_nobreak( $looma_p['name'] ) ); ?></h3>
							<div class="product-meta">
								<p class="product-price"><span><?php esc_html_e( 'from', 'looma' ); ?></span> <?php echo esc_html( looma_rupee( looma_from_price( $looma_p ) ) ); ?><small>/<?php esc_html_e( 'pc', 'looma' ); ?></small></p>
								<ul class="swatches" aria-label="<?php esc_attr_e( 'Colours in ready stock', 'looma' ); ?>">
									<?php foreach ( array_slice( $looma_p['colours'], 0, 6 ) as $looma_c ) : ?>
										<li style="--sw: <?php echo esc_attr( $looma_c[1] ); ?>" title="<?php echo esc_attr( $looma_c[0] ); ?>"></li>
									<?php endforeach; ?>
									<?php if ( count( $looma_p['colours'] ) > 6 ) : ?>
										<li class="more">+<?php echo esc_html( count( $looma_p['colours'] ) - 6 ); ?></li>
									<?php endif; ?>
								</ul>
							</div>
							<button type="button" class="btn btn-outline btn-block product-open" data-product="<?php echo esc_attr( $looma_p['id'] ); ?>"><?php esc_html_e( 'Prices & details', 'looma' ); ?></button>
						</div>

						<template id="tpl-<?php echo esc_attr( $looma_p['id'] ); ?>">
							<div class="pd">
								<div class="pd-media"><?php looma_img( $looma_p['photo'], $looma_p['name'] . ' ' . $looma_p['gsm'] ); ?></div>
								<div class="pd-body">
									<p class="eyebrow"><?php esc_html_e( 'Premium basic', 'looma' ); ?></p>
									<h2 class="pd-title"><?php echo esc_html( looma_nobreak( $looma_p['name'] ) ); ?> <span><?php echo esc_html( $looma_p['gsm'] ); ?></span></h2>
									<p class="pd-fabric"><?php echo esc_html( $looma_p['fabric'] ); ?> · <?php esc_html_e( 'Premium fabric and fine stitching', 'looma' ); ?></p>

									<ul class="pd-features">
										<?php foreach ( $looma_p['features'] as $looma_f ) : ?>
											<li><?php echo looma_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><strong><?php echo esc_html( $looma_f[0] ); ?></strong><?php echo $looma_f[1] ? ' — ' . esc_html( $looma_f[1] ) : ''; ?></span></li>
										<?php endforeach; ?>
									</ul>

									<h3 class="pd-h"><?php esc_html_e( 'Price chart', 'looma' ); ?></h3>
									<table class="table">
										<thead><tr><th><?php esc_html_e( 'Quantity', 'looma' ); ?></th><th><?php esc_html_e( 'Price per pc', 'looma' ); ?></th></tr></thead>
										<tbody>
											<?php foreach ( $looma_p['prices'] as $looma_t ) : ?>
												<tr><td><?php echo esc_html( $looma_t['label'] ); ?></td><td><strong><?php echo esc_html( looma_rupee( $looma_t['price'] ) ); ?></strong></td></tr>
											<?php endforeach; ?>
										</tbody>
									</table>
									<p class="fineprint"><?php esc_html_e( '5% GST extra.', 'looma' ); ?> <?php echo esc_html( $looma_p['note'] ); ?></p>

									<h3 class="pd-h"><?php esc_html_e( 'Colours — ready stock', 'looma' ); ?></h3>
									<ul class="pd-colours">
										<?php foreach ( $looma_p['colours'] as $looma_c ) : ?>
											<li><span style="--sw: <?php echo esc_attr( $looma_c[1] ); ?>"></span><?php echo esc_html( $looma_c[0] ); ?></li>
										<?php endforeach; ?>
									</ul>

									<div class="pd-actions">
										<a class="btn btn-dark" href="<?php echo esc_url( looma_whatsapp_link( sprintf( 'Hi Looma Apparels, I am interested in the %s %s (%s). Please share details.', $looma_p['name'], $looma_p['gsm'], $looma_p['fabric'] ) ) ); ?>" target="_blank" rel="noopener"><?php echo looma_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Enquire on WhatsApp', 'looma' ); ?></a>
										<a class="btn btn-outline" href="#calculator" data-close data-calc="<?php echo esc_attr( $looma_p['id'] ); ?>"><?php echo looma_icon( 'calc' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Estimate with print', 'looma' ); ?></a>
									</div>
								</div>
							</div>
						</template>
					</article>
				<?php endforeach; ?>
			</div>

			<div class="info-row">
				<div class="info-card">
					<span class="icon-bubble"><?php echo looma_icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h3><?php esc_html_e( 'Ready stock available now', 'looma' ); ?></h3>
						<p><?php esc_html_e( 'These styles and colours are available in ready stock. 100+ colours available in production quantity (MOQ: 60 pcs).', 'looma' ); ?></p>
					</div>
				</div>
				<div class="info-card">
					<span class="icon-bubble"><?php echo looma_icon( 'shirt' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h3><?php esc_html_e( 'We can make any garment on order', 'looma' ); ?></h3>
						<p><?php esc_html_e( 'Need a different style, fabric, colour or garment — like hoodies? We can customise and manufacture as per your requirement.', 'looma' ); ?></p>
					</div>
				</div>
			</div>
		</div>
	</section>

	<dialog class="product-dialog" id="product-dialog" aria-label="<?php esc_attr_e( 'Product details', 'looma' ); ?>">
		<button type="button" class="dialog-close" data-close aria-label="<?php esc_attr_e( 'Close', 'looma' ); ?>"><?php echo looma_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		<div class="dialog-content"></div>
	</dialog>

	<!-- ============ SERVICES: DROPSHIPPING + BULK ============ -->
	<section class="section section-alt" id="services">
		<div class="container">
			<div class="section-head center">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Your design, our production', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Two ways to work with us', 'looma' ); ?></h2>
				</div>
			</div>

			<div class="service-grid">
				<article class="service-card" id="dropshipping">
					<div class="service-media"><?php looma_img( 'dropship-stack.jpg', __( 'Stack of plain t-shirts ready for print on demand', 'looma' ) ); ?></div>
					<div class="service-body">
						<p class="eyebrow"><?php esc_html_e( 'Simple process. No limits.', 'looma' ); ?></p>
						<h3 class="service-title"><?php esc_html_e( 'Dropshipping / POD', 'looma' ); ?></h3>
						<ul class="feature-list">
							<li><span class="icon-bubble"><?php echo looma_icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'No minimum order quantity', 'looma' ); ?></strong><span><?php esc_html_e( 'Even a single piece.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'shirt' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Customised printing', 'looma' ); ?></strong><span><?php esc_html_e( 'DTF & Embroidery.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Free branding available', 'looma' ); ?></strong><span><?php esc_html_e( 'Add your logo / neck label.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Pan-India delivery', 'looma' ); ?></strong><span><?php esc_html_e( 'Delhivery, Ecom Express, Blue Dart, India Post, DTDC, Speed & Safe, etc.', 'looma' ); ?></span></div></li>
						</ul>
					</div>
				</article>

				<article class="service-card" id="bulk">
					<div class="service-media"><?php looma_img( 'bulk-hoodies.jpg', __( 'Folded hoodies in grey, beige and black', 'looma' ) ); ?></div>
					<div class="service-body">
						<p class="eyebrow"><?php esc_html_e( 'Premium quality apparel for your business', 'looma' ); ?></p>
						<h3 class="service-title"><?php esc_html_e( 'Bulk Order', 'looma' ); ?></h3>
						<ul class="feature-list">
							<li><span class="icon-bubble"><?php echo looma_icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Low minimum order quantity', 'looma' ); ?></strong><span><?php esc_html_e( 'Start with just 10 pieces.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'hoodie' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Full customisation', 'looma' ); ?></strong><span><?php esc_html_e( 'Different designs, colours and sizes for each piece.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'printer' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Multiple printing options', 'looma' ); ?></strong><span><?php esc_html_e( 'DTF, Screen Print, Puff Print, Embroidery, High Density and more.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'FREE branding', 'looma' ); ?></strong><span><?php esc_html_e( 'No extra charge for neck labels with an A2/A3/A4 print. Custom packaging / labels at extra cost.', 'looma' ); ?></span></div></li>
							<li><span class="icon-bubble"><?php echo looma_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><strong><?php esc_html_e( 'Pan-India delivery', 'looma' ); ?></strong><span><?php esc_html_e( 'Delhivery, Ecom Express, Blue Dart, India Post, DTDC, Speed & Safe, etc.', 'looma' ); ?></span></div></li>
						</ul>
					</div>
				</article>
			</div>

			<div class="steps-wrap">
				<h3 class="steps-title"><?php esc_html_e( 'How it works', 'looma' ); ?> <span><?php esc_html_e( 'A simple 5-step process', 'looma' ); ?></span></h3>
				<ol class="steps">
					<li><span class="step-icon"><?php echo looma_icon( 'shirt' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><h4><?php esc_html_e( 'Choose products', 'looma' ); ?></h4><p><?php esc_html_e( 'Select t-shirts, colours and sizes from our catalogue.', 'looma' ); ?></p></li>
					<li><span class="step-icon"><?php echo looma_icon( 'sheet' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><h4><?php esc_html_e( 'Place orders', 'looma' ); ?></h4><p><?php esc_html_e( 'Fill in the order details in the shared Google Sheet or WhatsApp group. Share design files and mockups.', 'looma' ); ?></p></li>
					<li><span class="step-icon"><?php echo looma_icon( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><h4><?php esc_html_e( 'Make payment', 'looma' ); ?></h4><p><?php esc_html_e( 'Make the payment and share the screenshot.', 'looma' ); ?></p></li>
					<li><span class="step-icon"><?php echo looma_icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><h4><?php esc_html_e( 'We produce & pack', 'looma' ); ?></h4><p><?php esc_html_e( 'We print, quality check and pack your orders with care.', 'looma' ); ?></p></li>
					<li><span class="step-icon"><?php echo looma_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><h4><?php esc_html_e( 'Dispatch & update', 'looma' ); ?></h4><p><?php esc_html_e( 'We ship the order directly to your customer and share tracking details with you.', 'looma' ); ?></p></li>
				</ol>
			</div>

			<div class="ideal">
				<h3 class="ideal-title"><?php esc_html_e( 'Ideal for', 'looma' ); ?></h3>
				<ul class="ideal-list">
					<li><?php echo looma_icon( 'hoodie' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Clothing Brands', 'looma' ); ?></li>
					<li><?php echo looma_icon( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Startups & Small Businesses', 'looma' ); ?></li>
					<li><?php echo looma_icon( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Events & Merchandise', 'looma' ); ?></li>
					<li><?php echo looma_icon( 'bank' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Colleges & Organisations', 'looma' ); ?></li>
					<li><?php echo looma_icon( 'store' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Retail Chains & Distributors', 'looma' ); ?></li>
				</ul>
			</div>
		</div>
	</section>

	<!-- ============ PRINTING ============ -->
	<section class="section" id="printing">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Printing options and charges', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Different techniques. Endless possibilities.', 'looma' ); ?></h2>
				</div>
			</div>

			<div class="tech-grid">
				<?php foreach ( looma_print_techniques() as $looma_tech ) : ?>
					<article class="tech-card">
						<div class="tech-media"><?php looma_img( $looma_tech['image'], $looma_tech['name'] . ' example' ); ?></div>
						<div class="tech-body">
							<h3><?php echo esc_html( $looma_tech['name'] ); ?></h3>
							<p><?php echo esc_html( $looma_tech['text'] ); ?></p>
							<ul>
								<?php foreach ( $looma_tech['details'] as $looma_d ) : ?>
									<li><?php echo esc_html( $looma_d ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<div class="dtf-wrap">
				<div class="dtf-table">
					<h3 class="block-title"><?php esc_html_e( 'DTF printing charges', 'looma' ); ?></h3>
					<p class="section-sub"><?php esc_html_e( 'High quality prints. Flexible quantity.', 'looma' ); ?></p>
					<div class="table-scroll">
						<table class="table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Print size', 'looma' ); ?></th>
									<th><?php esc_html_e( '1 – 10 pcs', 'looma' ); ?><small><?php esc_html_e( 'price per print', 'looma' ); ?></small></th>
									<th><?php esc_html_e( '10+ pcs', 'looma' ); ?><small><?php esc_html_e( 'price per print', 'looma' ); ?></small></th>
									<th><?php esc_html_e( 'Dimension', 'looma' ); ?><small><?php esc_html_e( 'inches', 'looma' ); ?></small></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $looma_dtf as $looma_row ) : ?>
									<tr>
										<td><?php echo esc_html( $looma_row['label'] ); ?></td>
										<td><strong><?php echo $looma_row['single'] ? esc_html( looma_rupee( $looma_row['single'] ) ) : esc_html__( 'FREE', 'looma' ); ?></strong></td>
										<td><strong><?php echo $looma_row['bulk'] ? esc_html( looma_rupee( $looma_row['bulk'] ) ) : esc_html__( 'FREE', 'looma' ); ?></strong></td>
										<td><?php echo esc_html( $looma_row['size'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<ul class="notes">
						<li><?php esc_html_e( 'Free branding / neck label applies only when there is an A2, A3 or A4 print.', 'looma' ); ?></li>
						<li><?php esc_html_e( 'Custom size pricing will vary based on the print size.', 'looma' ); ?></li>
						<li><?php esc_html_e( 'GST extra.', 'looma' ); ?></li>
					</ul>
				</div>
				<div class="dtf-side">
					<?php looma_img( 'print-dtf-sample.jpg', __( 'DTF mountain print on a black t-shirt', 'looma' ) ); ?>
					<div class="roll-card">
						<span class="new"><?php esc_html_e( 'New', 'looma' ); ?></span>
						<div>
							<strong><?php esc_html_e( 'DTF roll available', 'looma' ); ?></strong>
							<span><?php esc_html_e( '24 inches width · ₹240 per meter', 'looma' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<!-- ============ PRICE GUIDE + CALCULATOR ============ -->
	<section class="section section-alt" id="pricing">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'DTF printing price guide', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Same quality. More possibilities.', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'Example: a 250 GSM Oversized French Terry t-shirt with these print combinations.', 'looma' ); ?></p>
				</div>
			</div>

			<div class="guide-grid">
				<?php foreach ( looma_price_examples() as $looma_n => $looma_ex ) : ?>
					<article class="guide-card">
						<p class="guide-num"><?php echo esc_html( sprintf( '%02d', $looma_n + 1 ) ); ?></p>
						<h3><?php echo esc_html( $looma_ex['title'] ); ?></h3>
						<div class="guide-media"><?php looma_img( $looma_ex['image'], $looma_ex['title'] ); ?></div>
						<p class="guide-text"><?php echo esc_html( $looma_ex['text'] ); ?></p>
						<div class="guide-prices">
							<div><span><?php esc_html_e( '1 piece', 'looma' ); ?></span><strong><?php echo esc_html( looma_rupee( $looma_ex['one'] ) ); ?></strong></div>
							<div><span><?php esc_html_e( '10 pieces', 'looma' ); ?></span><strong><?php echo esc_html( looma_rupee( $looma_ex['ten'] ) ); ?></strong><small><?php esc_html_e( 'per piece', 'looma' ); ?></small></div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<p class="fineprint center"><?php esc_html_e( 'GST 5% extra · Prices are per piece · Neck label (your brand name) free with A2/A3/A4 print.', 'looma' ); ?></p>

			<div class="calc" id="calculator">
				<div class="calc-form">
					<h3 class="block-title"><?php echo looma_icon( 'calc' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Instant price estimator', 'looma' ); ?></h3>
					<p class="section-sub"><?php esc_html_e( 'Pick a t-shirt, quantity and DTF prints to see an estimate.', 'looma' ); ?></p>

					<label class="field">
						<span><?php esc_html_e( 'T-shirt', 'looma' ); ?></span>
						<select id="calc-product">
							<?php foreach ( $looma_products as $looma_p ) : ?>
								<option value="<?php echo esc_attr( $looma_p['id'] ); ?>"><?php echo esc_html( $looma_p['name'] . ' — ' . $looma_p['gsm'] . ' ' . $looma_p['fabric'] ); ?></option>
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
					<a class="btn btn-light btn-block" id="calc-wa" href="<?php echo esc_url( looma_whatsapp_link() ); ?>" target="_blank" rel="noopener"><?php echo looma_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Send this quote on WhatsApp', 'looma' ); ?></a>
					<p class="calc-note"><?php esc_html_e( 'Estimate only. Embroidery, screen, puff and HD printing are quoted per design — contact us.', 'looma' ); ?></p>
				</div>
			</div>
		</div>
	</section>

	<!-- ============ SIZE CHART ============ -->
	<section class="section" id="size">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Ready stock', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'Size Chart', 'looma' ); ?></h2>
					<p class="section-sub"><?php esc_html_e( 'All measurements are in inches. Measurements may vary slightly (±0.5") due to fabric and production.', 'looma' ); ?></p>
				</div>
			</div>

			<div class="size-wrap">
				<div class="size-tables">
					<div class="tabs" role="tablist" aria-label="<?php esc_attr_e( 'Fit', 'looma' ); ?>">
						<?php $looma_first = true; ?>
						<?php foreach ( looma_size_charts() as $looma_key => $looma_chart ) : ?>
							<button type="button" role="tab" class="tab" id="tab-<?php echo esc_attr( $looma_key ); ?>" aria-controls="panel-<?php echo esc_attr( $looma_key ); ?>" aria-selected="<?php echo $looma_first ? 'true' : 'false'; ?>"><?php echo esc_html( $looma_chart['title'] ); ?></button>
							<?php $looma_first = false; ?>
						<?php endforeach; ?>
					</div>
					<?php $looma_first = true; ?>
					<?php foreach ( looma_size_charts() as $looma_key => $looma_chart ) : ?>
						<div class="tab-panel" role="tabpanel" id="panel-<?php echo esc_attr( $looma_key ); ?>" aria-labelledby="tab-<?php echo esc_attr( $looma_key ); ?>"<?php echo $looma_first ? '' : ' hidden'; ?>>
							<p class="tab-tagline"><?php echo esc_html( $looma_chart['tagline'] ); ?></p>
							<div class="table-scroll">
								<table class="table table-size">
									<thead><tr><th><?php esc_html_e( 'Size', 'looma' ); ?></th><th><?php esc_html_e( 'Chest', 'looma' ); ?></th><th><?php esc_html_e( 'Length', 'looma' ); ?></th><th><?php esc_html_e( 'Shoulder', 'looma' ); ?></th><th><?php esc_html_e( 'Sleeve', 'looma' ); ?></th></tr></thead>
									<tbody>
										<?php foreach ( $looma_chart['rows'] as $looma_r ) : ?>
											<tr>
												<?php foreach ( $looma_r as $looma_ci => $looma_cell ) : ?>
													<td><?php echo 0 === $looma_ci ? '<strong>' . esc_html( $looma_cell ) . '</strong>' : esc_html( $looma_cell ); ?></td>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
						<?php $looma_first = false; ?>
					<?php endforeach; ?>
				</div>
				<figure class="size-figure">
					<?php looma_img( 'size-diagram.jpg', __( 'How to measure: chest, length and sleeve', 'looma' ) ); ?>
					<figcaption><?php esc_html_e( 'Find your perfect fit. Better basics, a brighter tomorrow.', 'looma' ); ?></figcaption>
				</figure>
			</div>
		</div>
	</section>

	<!-- ============ CONTACT ============ -->
	<section class="section section-dark" id="contact">
		<div class="container contact-grid">
			<div class="contact-info">
				<p class="eyebrow"><?php esc_html_e( 'Get in touch', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'Contact Us', 'looma' ); ?></h2>
				<p class="section-sub"><?php esc_html_e( 'We are here to help you. Custom apparel, print on demand, bigger possibilities.', 'looma' ); ?></p>

				<ul class="contact-list">
					<li><span class="icon-box"><?php echo looma_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><small><?php esc_html_e( 'Our address', 'looma' ); ?></small><strong>Looma Apparels</strong><span><?php echo nl2br( esc_html( looma_opt( 'looma_address' ) ) ); ?></span></div></li>
					<li><span class="icon-box"><?php echo looma_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><small><?php esc_html_e( 'Phone', 'looma' ); ?></small><a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', looma_opt( 'looma_phone' ) ) ); ?>"><?php echo esc_html( looma_opt( 'looma_phone' ) ); ?></a></div></li>
					<li><span class="icon-box"><?php echo looma_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><small><?php esc_html_e( 'Email', 'looma' ); ?></small><a href="mailto:<?php echo esc_attr( looma_opt( 'looma_email' ) ); ?>"><?php echo esc_html( looma_opt( 'looma_email' ) ); ?></a></div></li>
					<?php if ( looma_opt( 'looma_hours' ) ) : ?>
						<li><span class="icon-box"><?php echo looma_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><div><small><?php esc_html_e( 'Working hours', 'looma' ); ?></small><span><?php echo esc_html( looma_opt( 'looma_hours' ) ); ?></span></div></li>
					<?php endif; ?>
				</ul>

				<div class="map">
					<iframe title="<?php esc_attr_e( 'Looma Apparels location on Google Maps', 'looma' ); ?>" src="<?php echo esc_url( 'https://www.google.com/maps?q=' . rawurlencode( looma_opt( 'looma_map' ) ) . '&output=embed' ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
				</div>
			</div>

			<div class="contact-form-wrap">
				<h3 class="form-title"><?php esc_html_e( 'Send us an enquiry', 'looma' ); ?></h3>
				<p class="form-sub"><?php esc_html_e( 'Tell us what you need and we will get back to you on WhatsApp or phone.', 'looma' ); ?></p>

				<?php if ( 'sent' === $looma_enquiry ) : ?>
					<p class="notice notice-ok" role="status"><?php esc_html_e( 'Thank you! Your enquiry has been sent. We will get back to you soon.', 'looma' ); ?></p>
				<?php elseif ( 'missing' === $looma_enquiry ) : ?>
					<p class="notice notice-err" role="alert"><?php esc_html_e( 'Please enter your name and phone number.', 'looma' ); ?></p>
				<?php elseif ( 'error' === $looma_enquiry ) : ?>
					<p class="notice notice-err" role="alert"><?php esc_html_e( 'Sorry, the message could not be sent. Please use the WhatsApp button instead.', 'looma' ); ?></p>
				<?php endif; ?>

				<form class="contact-form" id="enquiry-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="looma_enquiry">
					<?php wp_nonce_field( 'looma_enquiry', 'looma_nonce' ); ?>
					<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

					<div class="field-row">
						<label class="field"><span><?php esc_html_e( 'Name *', 'looma' ); ?></span><input type="text" name="name" required autocomplete="name"></label>
						<label class="field"><span><?php esc_html_e( 'Phone / WhatsApp *', 'looma' ); ?></span><input type="tel" name="phone" required autocomplete="tel"></label>
					</div>
					<label class="field"><span><?php esc_html_e( 'Email', 'looma' ); ?></span><input type="email" name="email" autocomplete="email"></label>
					<div class="field-row">
						<label class="field">
							<span><?php esc_html_e( 'I am interested in', 'looma' ); ?></span>
							<select name="order_type">
								<option><?php esc_html_e( 'Bulk order', 'looma' ); ?></option>
								<option><?php esc_html_e( 'Dropshipping / Print on demand', 'looma' ); ?></option>
								<option><?php esc_html_e( 'Plain t-shirts (no print)', 'looma' ); ?></option>
								<option><?php esc_html_e( 'Custom garment manufacturing', 'looma' ); ?></option>
								<option><?php esc_html_e( 'DTF roll', 'looma' ); ?></option>
								<option><?php esc_html_e( 'Other', 'looma' ); ?></option>
							</select>
						</label>
						<label class="field"><span><?php esc_html_e( 'Approx. quantity', 'looma' ); ?></span><input type="text" name="quantity" placeholder="<?php esc_attr_e( 'e.g. 50 pcs', 'looma' ); ?>"></label>
					</div>
					<label class="field"><span><?php esc_html_e( 'Message', 'looma' ); ?></span><textarea name="message" rows="4" placeholder="<?php esc_attr_e( 'Product, colours, sizes, print type…', 'looma' ); ?>"></textarea></label>

					<div class="form-actions">
						<button type="submit" class="btn btn-light"><?php esc_html_e( 'Send enquiry', 'looma' ); ?> <?php echo looma_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
						<button type="button" class="btn btn-wa" id="form-wa"><?php echo looma_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Send via WhatsApp', 'looma' ); ?></button>
					</div>
				</form>
			</div>
		</div>
	</section>

</main>

<?php
get_footer();
