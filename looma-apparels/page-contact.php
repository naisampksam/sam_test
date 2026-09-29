<?php
/**
 * Contact page.
 *
 * @package Looma_Apparels
 */

get_header();
?>
<main id="main">
	<?php looma_page_hero( __( 'Get in touch', 'looma' ), __( 'Contact Us', 'looma' ), __( 'We are here to help — custom apparel, print on demand, bigger possibilities.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container contact-cards">
			<a class="contact-card" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels!' ) ); ?>" target="_blank" rel="noopener"><span class="usp-icon wa"><?php looma_the_icon( 'whatsapp' ); ?></span><small><?php esc_html_e( 'WhatsApp', 'looma' ); ?></small><strong><?php esc_html_e( 'Chat with us', 'looma' ); ?></strong></a>
			<a class="contact-card" href="<?php echo esc_attr( looma_tel() ); ?>"><span class="usp-icon"><?php looma_the_icon( 'phone' ); ?></span><small><?php esc_html_e( 'Phone', 'looma' ); ?></small><strong><?php echo esc_html( looma_opt( 'looma_phone' ) ); ?></strong></a>
			<a class="contact-card" href="mailto:<?php echo esc_attr( looma_opt( 'looma_email' ) ); ?>"><span class="usp-icon"><?php looma_the_icon( 'mail' ); ?></span><small><?php esc_html_e( 'Email', 'looma' ); ?></small><strong><?php echo esc_html( looma_opt( 'looma_email' ) ); ?></strong></a>
			<a class="contact-card" href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( looma_opt( 'looma_map' ) ) ); ?>" target="_blank" rel="noopener"><span class="usp-icon"><?php looma_the_icon( 'pin' ); ?></span><small><?php esc_html_e( 'Visit', 'looma' ); ?></small><strong><?php echo esc_html( str_replace( "\n", ' ', looma_opt( 'looma_address' ) ) ); ?></strong></a>
		</div>
	</section>

	<section class="section section-tint" id="enquiry">
		<div class="container form-split">
			<div class="form-card">
				<h2 class="block-title"><?php esc_html_e( 'Send us an enquiry', 'looma' ); ?></h2>
				<p class="section-sub"><?php esc_html_e( 'Tell us what you need and we will get back to you on WhatsApp or phone.', 'looma' ); ?></p>
				<?php looma_enquiry_notice(); ?>
				<?php looma_enquiry_form(); ?>
			</div>
			<div class="map-card">
				<iframe title="<?php esc_attr_e( 'Looma Apparels on Google Maps', 'looma' ); ?>" src="<?php echo esc_url( 'https://www.google.com/maps?q=' . rawurlencode( looma_opt( 'looma_map' ) ) . '&output=embed' ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
				<div class="map-info">
					<strong>Looma Apparels</strong>
					<span><?php echo nl2br( esc_html( looma_opt( 'looma_address' ) ) ); ?></span>
					<?php if ( looma_opt( 'looma_hours' ) ) : ?><span><?php looma_the_icon( 'clock' ); ?> <?php echo esc_html( looma_opt( 'looma_hours' ) ); ?></span><?php endif; ?>
				</div>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
