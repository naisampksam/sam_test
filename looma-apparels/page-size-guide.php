<?php
/**
 * Size guide.
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'Ready stock', 'looma' ), __( 'Size Guide', 'looma' ), __( 'Find your perfect fit. Compare with a t-shirt you already love — lay it flat and measure.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container size-grid">
			<?php looma_size_tables(); ?>
			<?php looma_measure_diagram(); ?>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container fit-compare">
			<article class="fit-card">
				<?php echo looma_tee( array( 'shape' => 'oversized', 'colour' => '#131313', 'class' => 'tee' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2><?php esc_html_e( 'Oversized fit', 'looma' ); ?></h2>
				<p><?php esc_html_e( 'Dropped shoulders, a wide boxy body and longer sleeves for a relaxed streetwear look. Take your usual size for the intended oversized drape.', 'looma' ); ?></p>
			</article>
			<article class="fit-card">
				<?php echo looma_tee( array( 'shape' => 'regular', 'colour' => '#F2EAEA', 'class' => 'tee' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2><?php esc_html_e( 'Regular fit', 'looma' ); ?></h2>
				<p><?php esc_html_e( 'A classic, clean crew-neck silhouette with set-in shoulders. The timeless everyday fit for uniforms, events and merch.', 'looma' ); ?></p>
			</article>
		</div>
	</section>

	<?php looma_cta_band(); ?>
</main>
<?php
get_footer();
