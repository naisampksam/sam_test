<?php
/**
 * Terms of Service page (slug: terms-of-service).
 *
 * Written for how Looma sells: quotes on WhatsApp / the website, blank tees, DTF / embroidery /
 * screen printing, bulk orders and print-on-demand dropshipping.
 * Review with the business before relying on it — it is not legal advice.
 *
 * @package Looma_Apparels
 */

get_header();
$looma_email   = looma_opt( 'looma_email' );
$looma_phone   = looma_opt( 'looma_phone' );
$looma_address = looma_opt( 'looma_address' );
?>
<main id="main">
	<?php looma_page_hero( __( 'Legal', 'looma' ), __( 'Terms of <span>Service</span>', 'looma' ), __( 'The rules for ordering blank and custom printed apparel from Looma Apparels.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container legal">
			<p class="legal-updated"><?php esc_html_e( 'Last updated: 31 October 2026', 'looma' ); ?></p>

			<nav class="legal-toc" aria-label="<?php esc_attr_e( 'On this page', 'looma' ); ?>">
				<a href="#about">About these terms</a><a href="#prices">Quotes &amp; prices</a><a href="#payment">Payment</a><a href="#artwork">Your artwork</a>
				<a href="#production">Production &amp; delivery</a><a href="#cancel">Cancellations</a><a href="#returns">Defects &amp; returns</a><a href="#law">Liability &amp; law</a>
			</nav>

			<h2 id="about">1. About these terms</h2>
			<p>These terms apply when you use loomaapparels.com or order from <strong>Looma Apparels</strong>, <?php echo esc_html( str_replace( "\n", ' ', $looma_address ) ); ?> (“Looma”, “we”, “us”) — on the website, on WhatsApp, by phone, email or in person. By placing an order you agree to them. Our <a href="<?php echo esc_url( looma_page_url( 'privacy-policy' ) ); ?>">Privacy Policy</a> explains how we handle your personal data.</p>

			<h2 id="prices">2. Quotes, estimates and prices</h2>
			<ul>
				<li>Prices on the website, in the Price Estimator and in Design your Product are <strong>estimates</strong>. Your order is confirmed only when we send you a final quote after checking your artwork, sizes and quantities, and you accept it.</li>
				<li>Prices are in Indian Rupees, per piece, and <strong>exclude 5% GST</strong> and shipping unless the quote says otherwise.</li>
				<li>Bulk prices depend on the total quantity in one order. If you reduce quantities before production, the price per piece may change to match the new tier.</li>
				<li>Print prices depend on the real print size. Embroidery is priced by stitch count; digitizing or very detailed designs may cost extra, and we will tell you before production.</li>
				<li>We may correct obvious mistakes in prices or product details. If a correction changes your price, we will ask you before going ahead.</li>
			</ul>

			<h2 id="payment">3. Payment</h2>
			<ul>
				<li>Custom and bulk orders go into production after the advance stated in your quote is paid; the balance is due before dispatch. Blank stock orders are paid in full before dispatch, unless we agree otherwise in writing.</li>
				<li>Pay only to the UPI ID, QR code or bank account we give you directly, and share the payment confirmation with us on WhatsApp. We will never ask for your UPI PIN, OTP or card details.</li>
				<li>Dropshipping partners follow the payment terms agreed when they sign up.</li>
			</ul>

			<h2 id="artwork">4. Your artwork and designs</h2>
			<ul>
				<li>You confirm you own, or have permission to use, every logo, image, font, name and text you send us. You are responsible for any claim that your design infringes someone else’s copyright, trademark or other rights.</li>
				<li>We may refuse or cancel any design that we believe infringes rights, or is illegal, hateful, obscene or offensive. If we cancel for this reason, we refund any amount paid for work not yet done.</li>
				<li>You keep the rights to your artwork. You allow us to use it only to make, check and deliver your order. We will only show it in our marketing with your permission.</li>
				<li>Please check the mockup and proof we send. Production follows the approved proof; we are not responsible for spelling or design mistakes you approved.</li>
			</ul>

			<h2 id="quality">5. Colours, sizes and print results</h2>
			<ul>
				<li>Screens show colours differently, and mockups are previews. Fabric and print colours can vary slightly from what you see on screen, and between production batches.</li>
				<li>Print placement and size can vary by up to about half an inch between pieces and sizes. Small images may print blurry; we will warn you if your file is low resolution.</li>
				<li>DTF and embroidery cannot print shadows, glows or similar screen effects. Embroidery is limited to about 10 × 10 inches per design.</li>
				<li>Measurements in our Size Guide are approximate and may vary by about half an inch. Please check the guide before ordering; we do not exchange correctly made pieces because of the wrong size choice.</li>
			</ul>

			<h2 id="production">6. Production and delivery</h2>
			<ul>
				<li>Production time starts after your proof is approved and payment is received. Times we give are estimates and can change in busy seasons; we will keep you updated.</li>
				<li>We ship across India through courier partners. Shipping charges are extra unless your quote includes them. Delivery times depend on the courier and location.</li>
				<li>Please check the address you give us. Re-delivery caused by a wrong address or a refused parcel is charged to you.</li>
				<li>You may also collect your order from our unit in Malappuram by arrangement.</li>
			</ul>

			<h2 id="cancel">7. Changes and cancellations</h2>
			<ul>
				<li>You can change or cancel an order free of charge <strong>before production starts</strong>.</li>
				<li>Custom printed or embroidered pieces are made for you and cannot be cancelled, returned or resold once production has started. If you cancel after that, we may keep the cost of the work done and materials used.</li>
			</ul>

			<h2 id="returns">8. Defects, damage and returns</h2>
			<ul>
				<li>Please check your order as soon as it arrives. Report any defect, damage, missing item or wrong item <strong>within 48 hours of delivery</strong> on WhatsApp, with photos and an unboxing video.</li>
				<li>If the mistake is ours — a manufacturing defect, a print that does not match the approved proof, a wrong or missing item — we will reprint, replace or refund the affected pieces, at our choice. Unused, unwashed pieces may need to be returned first.</li>
				<li>Custom pieces made correctly to the approved proof are not returnable. Blank stock in its original condition may be exchanged within 7 days, if you pay the shipping.</li>
				<li>In a large order, a small number of pieces with minor faults may happen; we replace those pieces rather than the whole order.</li>
				<li>Approved refunds are paid to the original payment method within 7 working days.</li>
			</ul>

			<h2 id="care">9. Washing and care</h2>
			<p>Follow the care instructions we give you (wash inside out in cold water, do not iron directly on the print, do not bleach or tumble dry hot). We are not responsible for fading, cracking or shrinkage caused by not following them.</p>

			<h2 id="site">10. Using this website</h2>
			<ul>
				<li>Do not misuse the website: no spam, harmful files, attempts to break in, or automated copying.</li>
				<li>The website’s text, photos and design belong to Looma Apparels and may not be copied without permission. Fonts in Design your Product are used under their own open licences.</li>
				<li>Tools such as the Price Estimator, Design your Product and Remove background are provided as they are, to help you plan your order.</li>
			</ul>

			<h2 id="law">11. Liability and law</h2>
			<ul>
				<li>Our total liability for any order is limited to the amount you paid for that order. We are not liable for indirect losses such as lost profits or missed events, or for delays caused by couriers or events outside our control (such as strikes, floods or power cuts).</li>
				<li>Nothing in these terms takes away your rights under the Consumer Protection Act, 2019 or other Indian law that cannot be excluded.</li>
				<li>These terms are governed by the laws of India. Any dispute that we cannot settle together will be handled by the courts at Malappuram, Kerala.</li>
				<li>We may update these terms. The version on this page when you place your order applies to that order.</li>
			</ul>

			<h2 id="contact">12. Contact</h2>
			<p>Questions or complaints about an order or these terms:</p>
			<ul class="legal-contact">
				<li><?php esc_html_e( 'Email:', 'looma' ); ?> <a href="mailto:<?php echo esc_attr( $looma_email ); ?>"><?php echo esc_html( $looma_email ); ?></a></li>
				<li><?php esc_html_e( 'Phone / WhatsApp:', 'looma' ); ?> <a href="<?php echo esc_url( looma_tel() ); ?>"><?php echo esc_html( $looma_phone ); ?></a></li>
				<li><?php esc_html_e( 'Address:', 'looma' ); ?> <?php echo esc_html( str_replace( "\n", ' ', $looma_address ) ); ?></li>
			</ul>
			<p class="legal-see"><?php esc_html_e( 'See also:', 'looma' ); ?> <a href="<?php echo esc_url( looma_page_url( 'privacy-policy' ) ); ?>"><?php esc_html_e( 'Privacy Policy', 'looma' ); ?></a></p>
		</div>
	</section>
</main>
<?php
get_footer();
