<?php
/**
 * Site footer.
 *
 * @package Looma_Apparels
 */

$looma_ig = looma_opt( 'looma_instagram' );
$looma_fb = looma_opt( 'looma_facebook' );
?>

<footer class="site-footer">
	<div class="container footer-grid">
		<div class="footer-brand">
			<?php looma_logo(); ?>
			<p class="footer-tagline"><?php esc_html_e( 'Ideas into Apparel.', 'looma' ); ?></p>
			<p><?php esc_html_e( 'Premium blank apparel and custom printing solutions for clothing brands, startups, events, colleges and retailers across India.', 'looma' ); ?></p>
			<?php if ( $looma_ig || $looma_fb ) : ?>
				<div class="social">
					<?php if ( $looma_ig ) : ?>
						<a href="<?php echo esc_url( $looma_ig ); ?>" target="_blank" rel="noopener" aria-label="Instagram"><?php echo looma_icon( 'instagram' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					<?php endif; ?>
					<?php if ( $looma_fb ) : ?>
						<a href="<?php echo esc_url( $looma_fb ); ?>" target="_blank" rel="noopener" aria-label="Facebook"><?php echo looma_icon( 'facebook' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div>
			<h3 class="footer-title"><?php esc_html_e( 'Explore', 'looma' ); ?></h3>
			<?php if ( has_nav_menu( 'footer' ) ) : ?>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'container'      => false,
						'menu_class'     => 'footer-links',
						'depth'          => 1,
					)
				);
				?>
			<?php else : ?>
				<ul class="footer-links">
					<?php foreach ( looma_nav_items() as $looma_id => $looma_label ) : ?>
						<li><a href="<?php echo esc_url( looma_section_url( $looma_id ) ); ?>"><?php echo esc_html( $looma_label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div>
			<h3 class="footer-title"><?php esc_html_e( 'Get in touch', 'looma' ); ?></h3>
			<ul class="footer-contact">
				<li><?php echo looma_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo nl2br( esc_html( looma_opt( 'looma_address' ) ) ); ?></span></li>
				<li><?php echo looma_icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', looma_opt( 'looma_phone' ) ) ); ?>"><?php echo esc_html( looma_opt( 'looma_phone' ) ); ?></a></li>
				<li><?php echo looma_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><a href="mailto:<?php echo esc_attr( looma_opt( 'looma_email' ) ); ?>"><?php echo esc_html( looma_opt( 'looma_email' ) ); ?></a></li>
			</ul>
		</div>
	</div>
	<div class="container footer-bottom">
		<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Looma Apparels. <?php esc_html_e( 'All rights reserved.', 'looma' ); ?></span>
		<span><?php esc_html_e( 'Prices exclude 5% GST.', 'looma' ); ?></span>
	</div>
</footer>

<a class="wa-float" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like to know more about your products.' ) ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Chat on WhatsApp', 'looma' ); ?>">
	<?php echo looma_icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</a>

<?php wp_footer(); ?>
</body>
</html>
