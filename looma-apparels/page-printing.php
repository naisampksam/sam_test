<?php
/**
 * Printing techniques & charges.
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'Printing options & charges', 'looma' ), __( 'Different techniques. <span>Endless possibilities.</span>', 'looma' ), __( 'From photo-quality DTF to premium embroidery — choose the finish that suits your design and quantity.', 'looma' ) ); ?>

	<nav class="jump" aria-label="<?php esc_attr_e( 'Techniques', 'looma' ); ?>">
		<div class="container">
			<?php foreach ( looma_print_techniques() as $looma_t ) : ?>
				<a href="#<?php echo esc_attr( $looma_t['id'] ); ?>"><?php echo esc_html( $looma_t['name'] ); ?></a>
			<?php endforeach; ?>
			<a href="#dtf-charges"><?php esc_html_e( 'DTF charges', 'looma' ); ?></a>
		</div>
	</nav>

	<section class="section section-tight">
		<div class="container tech-list">
			<?php foreach ( looma_print_techniques() as $looma_i => $looma_t ) : ?>
				<article class="tech-item<?php echo $looma_i % 2 ? ' reverse' : ''; ?>" id="<?php echo esc_attr( $looma_t['id'] ); ?>">
					<div class="tech-item-media"><?php looma_img( $looma_t['image'], $looma_t['name'] . ' example', '', 0 === $looma_i, 1000, 1000 ); ?></div>
					<div class="tech-item-body">
						<span class="pill"><?php echo esc_html( $looma_t['moq'] ); ?></span>
						<h2><?php echo esc_html( $looma_t['name'] ); ?></h2>
						<p class="lead"><?php echo esc_html( $looma_t['text'] ); ?></p>
						<ul class="check-list">
							<?php foreach ( $looma_t['details'] as $looma_d ) : ?>
								<li><?php looma_the_icon( 'check' ); ?> <?php echo esc_html( $looma_d ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( 'dtf' === $looma_t['id'] ) : ?>
							<a class="link-arrow" href="#dtf-charges"><?php esc_html_e( 'See DTF charges', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
						<?php else : ?>
							<a class="link-arrow" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like a quote for ' . $looma_t['name'] . '.' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a quote on WhatsApp', 'looma' ); ?> <?php looma_the_icon( 'arrow' ); ?></a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="section section-tint" id="dtf-charges">
		<div class="container dtf-grid">
			<div>
				<p class="eyebrow"><?php esc_html_e( 'High quality prints. Flexible quantity.', 'looma' ); ?></p>
				<h2 class="section-title"><?php esc_html_e( 'DTF printing charges', 'looma' ); ?></h2>
				<div class="table-scroll">
					<table class="table">
						<thead><tr><th><?php esc_html_e( 'Print size', 'looma' ); ?></th><th><?php esc_html_e( '1 – 9 pcs', 'looma' ); ?></th><th><?php esc_html_e( '10+ pcs', 'looma' ); ?></th><th><?php esc_html_e( 'Size (inches)', 'looma' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( looma_dtf_prices() as $looma_row ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $looma_row['label'] ); ?></strong></td>
									<td><?php echo $looma_row['single'] ? esc_html( looma_rupee( $looma_row['single'] ) ) : '<span class="free">' . esc_html__( 'FREE', 'looma' ) . '</span>'; ?></td>
									<td><?php echo $looma_row['bulk'] ? esc_html( looma_rupee( $looma_row['bulk'] ) ) : '<span class="free">' . esc_html__( 'FREE', 'looma' ) . '</span>'; ?></td>
									<td><?php echo esc_html( $looma_row['size'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="fineprint"><?php esc_html_e( 'Price per print. Free neck label applies only with an A2, A3 or A4 print. Custom sizes are priced by print size. GST extra.', 'looma' ); ?></p>
			</div>
			<aside class="roll-card">
				<span class="pill pill-light"><?php esc_html_e( 'New', 'looma' ); ?></span>
				<h3><?php esc_html_e( 'DTF roll available', 'looma' ); ?></h3>
				<p class="roll-price"><?php echo esc_html( looma_rupee( 240 ) ); ?> <small><?php esc_html_e( '/ meter', 'looma' ); ?></small></p>
				<p><?php esc_html_e( '24 inch width, printed with your artwork — for print shops and brands that press in-house.', 'looma' ); ?></p>
				<a class="btn btn-light" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like to order a DTF roll.' ) ); ?>" target="_blank" rel="noopener"><?php looma_the_icon( 'whatsapp' ); ?> <?php esc_html_e( 'Order DTF roll', 'looma' ); ?></a>
			</aside>
		</div>
	</section>

	<?php looma_cta_band( __( 'Not sure which print to choose?', 'looma' ), __( 'Send us your artwork — we will suggest the best technique for your design and budget.', 'looma' ) ); ?>
</main>
<?php
get_footer();
