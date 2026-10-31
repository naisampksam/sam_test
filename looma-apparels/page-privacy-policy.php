<?php
/**
 * Privacy Policy page (slug: privacy-policy).
 *
 * Written for how this site works: contact / quote forms, the Design your Product studio,
 * WhatsApp ordering, browser storage, Hostinger hosting and the Google Map on the Contact page.
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
	<?php looma_page_hero( __( 'Legal', 'looma' ), __( 'Privacy <span>Policy</span>', 'looma' ), __( 'What we collect when you use loomaapparels.com, why, and the choices you have.', 'looma' ) ); ?>

	<section class="section section-tight">
		<div class="container legal">
			<p class="legal-updated"><?php esc_html_e( 'Last updated: 31 October 2026', 'looma' ); ?></p>

			<nav class="legal-toc" aria-label="<?php esc_attr_e( 'On this page', 'looma' ); ?>">
				<a href="#who">Who we are</a><a href="#collect">What we collect</a><a href="#use">How we use it</a><a href="#share">Who we share it with</a>
				<a href="#keep">How long we keep it</a><a href="#browser">Cookies &amp; browser storage</a><a href="#rights">Your rights</a><a href="#contact">Contact &amp; grievances</a>
			</nav>

			<h2 id="who">1. Who we are</h2>
			<p>This website is run by <strong>Looma Apparels</strong>, a custom apparel manufacturer, <?php echo esc_html( str_replace( "\n", ' ', $looma_address ) ); ?> (“Looma”, “we”, “us”). We are responsible for the personal data described here, as the “Data Fiduciary” under India’s Digital Personal Data Protection Act, 2023.</p>

			<h2 id="collect">2. What we collect</h2>
			<p>We only collect what you choose to give us, plus what is needed to run the website:</p>
			<ul>
				<li><strong>Contact and quote forms</strong> — your name, phone number, email (optional), what you are interested in, quantities, your quote list and your message.</li>
				<li><strong>Design your Product</strong> — when you press <em>Send design on WhatsApp</em>, we receive the artwork you uploaded (images or PDFs), the text you added, the mockup pictures, your t-shirt, colour, size and quantity choices, and the price estimate. We do not ask for your name or number in the studio.</li>
				<li><strong>WhatsApp, phone and email</strong> — when you message or call us, we receive your number or email and whatever you send, such as order details, addresses for delivery and payment confirmations.</li>
				<li><strong>Orders</strong> — the delivery name, address, pincode and phone number you give us to ship your order, and records of what you ordered and paid.</li>
				<li><strong>Technical data</strong> — like every website, our server records your IP address and basic browser information when you visit. We also use your IP address, briefly, to stop spam and abuse of our forms and uploads.</li>
			</ul>
			<p>We do not collect card or UPI PINs, passwords or other payment credentials on this website. We do not knowingly collect data from children under 18; if you are under 18, please ask a parent or guardian to contact us for you.</p>

			<h2 id="use">3. How we use it</h2>
			<ul>
				<li>To reply to your enquiry, prepare quotes and confirm prices.</li>
				<li>To produce your order — printing your artwork exactly as you approved it — and to pack, ship and track it.</li>
				<li>To keep records we must keep by law, such as invoices and tax (GST) records.</li>
				<li>To keep the website secure and prevent spam and misuse.</li>
			</ul>
			<p>We do not sell your personal data, and we do not use your artwork for anything other than your order. We will only show your design in our own marketing (for example, on Instagram) if you give us permission first.</p>

			<h2 id="share">4. Who we share it with</h2>
			<p>Only with the services needed to run our business, and only what they need:</p>
			<ul>
				<li><strong>Hostinger</strong> — hosts this website and stores form messages and uploaded designs.</li>
				<li><strong>WhatsApp (Meta)</strong> — when you choose to message us on WhatsApp, that conversation is handled under WhatsApp’s own privacy policy.</li>
				<li><strong>Courier partners</strong> — receive your delivery name, address and phone number to deliver your order.</li>
				<li><strong>Google Maps</strong> — the map on our Contact page is loaded from Google, which may receive your IP address when that page is opened.</li>
				<li><strong>Authorities</strong> — when the law requires it.</li>
			</ul>
			<p>The fonts and images on this site are served from our own server, not from third-party networks. Some providers may process data outside India; we only use established providers with appropriate safeguards.</p>

			<h2 id="keep">5. How long we keep it</h2>
			<ul>
				<li>Enquiries and designs you send: for as long as needed to quote, produce and support your order, then deleted. Designs that never become an order are deleted periodically.</li>
				<li>Order and invoice records: for as long as Indian tax and accounting law requires (generally up to 8 years).</li>
				<li>Server security logs: for a short period, then deleted automatically.</li>
			</ul>

			<h2 id="browser">6. Cookies and browser storage</h2>
			<p>We do not use advertising or tracking cookies. To make the site work, it saves a few things <strong>in your own browser</strong> (not on our server): your Design your Product draft, your quote list and your Price Estimator order, so they are still there if you reload the page. You can clear them at any time by clearing this site’s data in your browser settings, or with the <em>Start over</em> buttons. WordPress may set a cookie if you log in as a site administrator.</p>

			<h2 id="rights">7. Your rights</h2>
			<p>You can ask us to:</p>
			<ul>
				<li>tell you what personal data we hold about you and how we use it;</li>
				<li>correct or update it, or delete it (unless we must keep it by law, such as invoices);</li>
				<li>withdraw consent you gave us, for example to use your design in our marketing;</li>
				<li>name someone to exercise these rights for you if you are unable to.</li>
			</ul>
			<p>Write to us using the details below. We will reply within 30 days. If you are not satisfied with our answer, you may complain to the Data Protection Board of India.</p>

			<h2 id="security">8. Security</h2>
			<p>The site uses HTTPS, checks every uploaded file, limits how often forms can be sent, and keeps uploaded designs in a protected folder. No website is completely secure, so please do not send sensitive documents (such as ID cards or bank details) through the website.</p>

			<h2 id="changes">9. Changes to this policy</h2>
			<p>If we change this policy, we will update it on this page and change the date at the top.</p>

			<h2 id="contact">10. Contact and grievances</h2>
			<p>For any privacy question, request or complaint, contact our grievance officer at Looma Apparels:</p>
			<ul class="legal-contact">
				<li><?php esc_html_e( 'Email:', 'looma' ); ?> <a href="mailto:<?php echo esc_attr( $looma_email ); ?>"><?php echo esc_html( $looma_email ); ?></a></li>
				<li><?php esc_html_e( 'Phone / WhatsApp:', 'looma' ); ?> <a href="<?php echo esc_url( looma_tel() ); ?>"><?php echo esc_html( $looma_phone ); ?></a></li>
				<li><?php esc_html_e( 'Address:', 'looma' ); ?> <?php echo esc_html( str_replace( "\n", ' ', $looma_address ) ); ?></li>
			</ul>
			<p class="legal-see"><?php esc_html_e( 'See also:', 'looma' ); ?> <a href="<?php echo esc_url( looma_page_url( 'terms-of-service' ) ); ?>"><?php esc_html_e( 'Terms of Service', 'looma' ); ?></a></p>
		</div>
	</section>
</main>
<?php
get_footer();
