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
	<div class="container footer-top">
		<div class="footer-brand">
			<?php looma_logo(); ?>
			<p class="footer-tagline"><?php esc_html_e( 'Ideas into Apparel.', 'looma' ); ?></p>
			<p><?php esc_html_e( 'Premium blank apparel and custom printing for clothing brands, startups, events, colleges and retailers across India.', 'looma' ); ?></p>
			<?php if ( $looma_ig || $looma_fb ) : ?>
				<div class="social">
					<?php if ( $looma_ig ) : ?><a href="<?php echo esc_url( $looma_ig ); ?>" target="_blank" rel="noopener" aria-label="Instagram"><?php looma_the_icon( 'instagram' ); ?></a><?php endif; ?>
					<?php if ( $looma_fb ) : ?><a href="<?php echo esc_url( $looma_fb ); ?>" target="_blank" rel="noopener" aria-label="Facebook"><?php looma_the_icon( 'facebook' ); ?></a><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div>
			<h2 class="footer-title"><?php esc_html_e( 'Shop', 'looma' ); ?></h2>
			<ul class="footer-links">
				<?php foreach ( looma_products() as $looma_p ) : ?>
					<li><a href="<?php echo esc_url( looma_product_url( $looma_p['id'] ) ); ?>"><?php echo esc_html( $looma_p['name'] . ' ' . $looma_p['gsm'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div>
			<h2 class="footer-title"><?php esc_html_e( 'Company', 'looma' ); ?></h2>
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
					<?php foreach ( array( 'dropshipping' => 'Dropshipping', 'bulk-orders' => 'Bulk Orders', 'printing' => 'Printing', 'price-estimator' => 'Price Estimator', 'size-guide' => 'Size Guide', 'about' => 'About Us', 'contact' => 'Contact' ) as $looma_slug => $looma_label ) : ?>
						<li><a href="<?php echo esc_url( looma_page_url( $looma_slug ) ); ?>"><?php echo esc_html( $looma_label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div>
			<h2 class="footer-title"><?php esc_html_e( 'Visit & contact', 'looma' ); ?></h2>
			<ul class="footer-contact">
				<li><?php looma_the_icon( 'pin' ); ?><span><?php echo nl2br( esc_html( looma_opt( 'looma_address' ) ) ); ?></span></li>
				<li><?php looma_the_icon( 'phone' ); ?><a href="<?php echo esc_attr( looma_tel() ); ?>"><?php echo esc_html( looma_opt( 'looma_phone' ) ); ?></a></li>
				<li><?php looma_the_icon( 'mail' ); ?><a href="mailto:<?php echo esc_attr( looma_opt( 'looma_email' ) ); ?>"><?php echo esc_html( looma_opt( 'looma_email' ) ); ?></a></li>
				<?php if ( looma_opt( 'looma_hours' ) ) : ?>
					<li><?php looma_the_icon( 'clock' ); ?><span><?php echo esc_html( looma_opt( 'looma_hours' ) ); ?></span></li>
				<?php endif; ?>
			</ul>
		</div>
	</div>

	<div class="footer-word" aria-hidden="true">LOOMA</div>

	<div class="container footer-bottom">
		<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Looma Apparels. <?php esc_html_e( 'All rights reserved.', 'looma' ); ?></span>
	</div>
</footer>

<a class="wa-float" href="<?php echo esc_url( looma_whatsapp_link( 'Hi Looma Apparels, I would like to know more about your products.' ) ); ?>" target="_blank" rel="noopener" aria-label="<?php esc_attr_e( 'Chat on WhatsApp', 'looma' ); ?>"><?php looma_the_icon( 'whatsapp' ); ?></a>

<div class="toast" role="status" aria-live="polite" data-toast hidden></div>

<?php wp_footer(); ?>
</body>
</html>
