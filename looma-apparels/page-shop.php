<?php
/**
 * Shop: product grid, or a single product at /shop/{product-id}/.
 *
 * @package Looma_Apparels
 */

get_header();

$looma_product = looma_current_product();

if ( $looma_product ) :
	get_template_part( 'template-parts/product-single', null, array( 'product' => $looma_product ) );
else :
	?>
	<main id="main">
		<?php looma_page_hero( __( 'Ready stock', 'looma' ), __( 'Shop T-Shirts', 'looma' ), __( 'Premium blank t-shirts in 190–250 GSM cotton — order plain, or printed with your design. Prices drop as your quantity grows.', 'looma' ) ); ?>

		<section class="section section-tight">
			<div class="container">
				<div class="shop-bar">
					<div class="filter" role="group" aria-label="<?php esc_attr_e( 'Filter by fit', 'looma' ); ?>">
						<button type="button" class="chip is-active" data-filter="all"><?php esc_html_e( 'All', 'looma' ); ?></button>
						<button type="button" class="chip" data-filter="oversized"><?php esc_html_e( 'Oversized', 'looma' ); ?></button>
						<button type="button" class="chip" data-filter="regular"><?php esc_html_e( 'Regular fit', 'looma' ); ?></button>
					</div>
					<label class="sort">
						<span><?php esc_html_e( 'Sort', 'looma' ); ?></span>
						<select data-sort>
							<option value="featured"><?php esc_html_e( 'Featured', 'looma' ); ?></option>
							<option value="low"><?php esc_html_e( 'Price: low to high', 'looma' ); ?></option>
							<option value="high"><?php esc_html_e( 'Price: high to low', 'looma' ); ?></option>
						</select>
					</label>
				</div>

				<div class="p-grid" data-grid>
					<?php
					foreach ( looma_products() as $looma_p ) {
						looma_product_card( $looma_p );
					}
					?>
				</div>

				<div class="info-row">
					<div class="info-card"><span class="usp-icon"><?php looma_the_icon( 'box' ); ?></span><div><h2><?php esc_html_e( 'Ready stock, ready to ship', 'looma' ); ?></h2><p><?php esc_html_e( 'Every style and colour shown is in ready stock. 100+ more colours available in production quantity (MOQ 60 pcs).', 'looma' ); ?></p></div></div>
					<div class="info-card"><span class="usp-icon"><?php looma_the_icon( 'hoodie' ); ?></span><div><h2><?php esc_html_e( 'Need something else?', 'looma' ); ?></h2><p><?php esc_html_e( 'Different style, fabric, colour or garment — like hoodies? We manufacture to your requirement.', 'looma' ); ?> <a href="<?php echo esc_url( looma_page_url( 'contact' ) ); ?>"><?php esc_html_e( 'Ask us', 'looma' ); ?></a></p></div></div>
				</div>
			</div>
		</section>

		<?php looma_cta_band(); ?>
	</main>
	<?php
endif;

get_footer();
