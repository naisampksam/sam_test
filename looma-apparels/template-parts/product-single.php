<?php
/**
 * Single product page.
 *
 * @package Looma_Apparels
 */

$looma_p   = $args['product'];
$looma_c0  = looma_display_colour( $looma_p );
$looma_mk  = array(
	'shape'  => $looma_p['shape'],
	'colour' => $looma_c0[1],
	'wash'   => $looma_p['wash'],
);
?>
<main id="main" class="product-page" data-product="<?php echo esc_attr( $looma_p['id'] ); ?>" data-prices="<?php echo esc_attr( wp_json_encode( $looma_p['prices'] ) ); ?>">
	<div class="container">
		<nav class="crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'looma' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'looma' ); ?></a>
			<span aria-hidden="true">/</span> <a href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'Shop', 'looma' ); ?></a>
			<span aria-hidden="true">/</span> <span aria-current="page"><?php echo esc_html( $looma_p['name'] . ' ' . $looma_p['gsm'] ); ?></span>
		</nav>

		<div class="pdp">
			<div class="pdp-gallery">
				<div class="pdp-stage" data-stage>
					<div class="pdp-view is-active" data-view="front">
						<?php echo looma_tee( array_merge( $looma_mk, array( 'class' => 'tee pdp-tee', 'title' => $looma_p['name'] . ' — front' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<div class="pdp-view" data-view="back" hidden>
						<?php echo looma_tee( array_merge( $looma_mk, array( 'class' => 'tee pdp-tee', 'view' => 'back', 'title' => $looma_p['name'] . ' — back' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<div class="pdp-view" data-view="print" hidden>
						<?php echo looma_tee( array_merge( $looma_mk, array( 'class' => 'tee pdp-tee', 'view' => 'back', 'art' => looma_art_sunset( 190, 130, 220 ), 'title' => $looma_p['name'] . ' — with A3 back print' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<?php if ( $looma_p['badge'] ) : ?><span class="badge"><?php echo esc_html( $looma_p['badge'] ); ?></span><?php endif; ?>
				</div>
				<div class="pdp-thumbs" role="group" aria-label="<?php esc_attr_e( 'Views', 'looma' ); ?>">
					<button type="button" class="pdp-thumb is-active" data-show="front"><?php echo looma_tee( array_merge( $looma_mk, array( 'class' => 'tee', 'label' => '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Front', 'looma' ); ?></span></button>
					<button type="button" class="pdp-thumb" data-show="back"><?php echo looma_tee( array_merge( $looma_mk, array( 'class' => 'tee', 'view' => 'back' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Back', 'looma' ); ?></span></button>
					<button type="button" class="pdp-thumb" data-show="print"><?php echo looma_tee( array_merge( $looma_mk, array( 'class' => 'tee', 'view' => 'back', 'art' => looma_art_sunset( 190, 130, 220 ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Printed', 'looma' ); ?></span></button>
				</div>
				<p class="pdp-note"><?php esc_html_e( 'Illustration shows the fit and colour. Print shown is an example design.', 'looma' ); ?></p>
			</div>

			<div class="pdp-info">
				<p class="eyebrow"><?php echo esc_html( $looma_p['spec'] ); ?></p>
				<h1 class="pdp-title"><?php echo esc_html( looma_nobreak( $looma_p['name'] ) ); ?> <span><?php echo esc_html( $looma_p['gsm'] ); ?></span></h1>
				<p class="pdp-tagline"><?php echo esc_html( $looma_p['tagline'] ); ?></p>

				<div class="pdp-price">
					<strong data-unit-price><?php echo esc_html( looma_rupee( $looma_p['prices'][0]['price'] ) ); ?></strong>
					<span><?php esc_html_e( 'per piece', 'looma' ); ?> · <?php esc_html_e( '+5% GST', 'looma' ); ?></span>
				</div>

				<div class="tiers" aria-label="<?php esc_attr_e( 'Quantity pricing', 'looma' ); ?>">
					<?php foreach ( $looma_p['prices'] as $looma_i => $looma_t ) : ?>
						<div class="tier<?php echo 0 === $looma_i ? ' is-active' : ''; ?>" data-tier="<?php echo (int) $looma_t['min']; ?>">
							<span><?php echo esc_html( $looma_t['label'] ); ?></span>
							<strong><?php echo esc_html( looma_rupee( $looma_t['price'] ) ); ?></strong>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="opt">
					<p class="opt-label"><?php esc_html_e( 'Colour', 'looma' ); ?>: <strong data-colour-name><?php echo esc_html( $looma_c0[0] ); ?></strong></p>
					<div class="colour-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Colour', 'looma' ); ?>">
						<?php foreach ( $looma_p['colours'] as $looma_c ) : ?>
							<?php $looma_on = $looma_c === $looma_c0; ?>
							<button type="button" role="radio" class="colour-opt<?php echo $looma_on ? ' is-active' : ''; ?>" aria-checked="<?php echo $looma_on ? 'true' : 'false'; ?>" style="--sw: <?php echo esc_attr( $looma_c[1] ); ?>" data-colour="<?php echo esc_attr( $looma_c[1] ); ?>" data-name="<?php echo esc_attr( $looma_c[0] ); ?>" title="<?php echo esc_attr( $looma_c[0] ); ?>"><span class="screen-reader-text"><?php echo esc_html( $looma_c[0] ); ?></span></button>
						<?php endforeach; ?>
					</div>
					<?php if ( $looma_p['note'] ) : ?><p class="opt-hint"><?php echo esc_html( $looma_p['note'] ); ?></p><?php endif; ?>
				</div>

				<div class="opt">
					<p class="opt-label"><?php esc_html_e( 'Size', 'looma' ); ?>: <strong data-size-name>M</strong> <a class="opt-link" href="<?php echo esc_url( looma_page_url( 'size-guide' ) ); ?>"><?php looma_the_icon( 'ruler' ); ?> <?php esc_html_e( 'Size guide', 'looma' ); ?></a></p>
					<div class="size-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Size', 'looma' ); ?>">
						<?php foreach ( looma_sizes() as $looma_s ) : ?>
							<button type="button" role="radio" class="size-opt<?php echo 'M' === $looma_s ? ' is-active' : ''; ?>" aria-checked="<?php echo 'M' === $looma_s ? 'true' : 'false'; ?>" data-size="<?php echo esc_attr( $looma_s ); ?>"><?php echo esc_html( $looma_s ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="opt opt-qty">
					<p class="opt-label"><?php esc_html_e( 'Quantity', 'looma' ); ?></p>
					<div class="qty-row">
						<div class="stepper">
							<button type="button" data-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'looma' ); ?>"><?php looma_the_icon( 'minus' ); ?></button>
							<input type="number" min="1" value="1" inputmode="numeric" data-qty aria-label="<?php esc_attr_e( 'Quantity', 'looma' ); ?>">
							<button type="button" data-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'looma' ); ?>"><?php looma_the_icon( 'plus' ); ?></button>
						</div>
						<p class="qty-total"><span><?php esc_html_e( 'Subtotal', 'looma' ); ?></span> <strong data-subtotal><?php echo esc_html( looma_rupee( $looma_p['prices'][0]['price'] ) ); ?></strong></p>
					</div>
					<p class="opt-hint" data-tier-hint></p>
				</div>

				<div class="pdp-actions">
					<button type="button" class="btn btn-dark btn-lg" data-add-quote><?php looma_the_icon( 'bag' ); ?> <?php esc_html_e( 'Add to quote list', 'looma' ); ?></button>
					<a class="btn btn-wa btn-lg" href="<?php echo esc_url( looma_whatsapp_link() ); ?>" target="_blank" rel="noopener" data-order-wa><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'Order on WhatsApp', 'looma' ); ?></a>
				</div>
				<a class="pdp-customise" href="<?php echo esc_url( add_query_arg( 'product', $looma_p['id'], looma_page_url( 'price-estimator' ) ) ); ?>">
					<?php looma_the_icon( 'printer' ); ?>
					<span><strong><?php esc_html_e( 'Want it printed?', 'looma' ); ?></strong> <?php esc_html_e( 'Add a DTF print and see the full price in the estimator.', 'looma' ); ?></span>
					<?php looma_the_icon( 'arrow' ); ?>
				</a>

				<ul class="pdp-perks">
					<li><?php looma_the_icon( 'truck' ); ?> <?php esc_html_e( 'Pan-India delivery with tracking', 'looma' ); ?></li>
					<li><?php looma_the_icon( 'tag' ); ?> <?php esc_html_e( 'Free neck-label branding with A2/A3/A4 print', 'looma' ); ?></li>
					<li><?php looma_the_icon( 'box' ); ?> <?php esc_html_e( 'In ready stock', 'looma' ); ?></li>
				</ul>

				<div class="pdp-acc">
					<details open>
						<summary><?php esc_html_e( 'Description', 'looma' ); ?><span class="faq-icon"><?php looma_the_icon( 'plus' ); ?></span></summary>
						<p><?php echo esc_html( $looma_p['desc'] ); ?></p>
					</details>
					<details>
						<summary><?php esc_html_e( 'Fabric & features', 'looma' ); ?><span class="faq-icon"><?php looma_the_icon( 'plus' ); ?></span></summary>
						<ul class="feature-list">
							<?php foreach ( $looma_p['features'] as $looma_f ) : ?>
								<li><?php looma_the_icon( 'check' ); ?><span><strong><?php echo esc_html( $looma_f[0] ); ?></strong> <?php echo esc_html( $looma_f[1] ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					</details>
					<details>
						<summary><?php esc_html_e( 'Size chart', 'looma' ); ?><span class="faq-icon"><?php looma_the_icon( 'plus' ); ?></span></summary>
						<?php looma_size_tables( $looma_p['fit'] ); ?>
					</details>
					<details>
						<summary><?php esc_html_e( 'Printing options', 'looma' ); ?><span class="faq-icon"><?php looma_the_icon( 'plus' ); ?></span></summary>
						<p><?php esc_html_e( 'Available with DTF, puff, high density, embroidery and screen printing.', 'looma' ); ?> <a href="<?php echo esc_url( looma_page_url( 'printing' ) ); ?>"><?php esc_html_e( 'Compare techniques and charges', 'looma' ); ?></a>.</p>
					</details>
				</div>
			</div>
		</div>
	</div>

	<section class="section">
		<div class="container">
			<div class="section-head">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'You may also like', 'looma' ); ?></p>
					<h2 class="section-title"><?php esc_html_e( 'More from the range', 'looma' ); ?></h2>
				</div>
				<a class="link-arrow" href="<?php echo esc_url( looma_page_url( 'shop' ) ); ?>"><?php esc_html_e( 'View all', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
			</div>
			<div class="p-grid p-grid-4">
				<?php
				$looma_others = array_values(
					array_filter(
						looma_products(),
						function ( $o ) use ( $looma_p ) {
							return $o['id'] !== $looma_p['id'];
						}
					)
				);
				foreach ( array_slice( $looma_others, 0, 4 ) as $looma_o ) {
					looma_product_card( $looma_o );
				}
				?>
			</div>
		</div>
	</section>

	<?php looma_cta_band(); ?>
</main>
