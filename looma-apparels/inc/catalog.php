<?php
/**
 * Looma Apparels product & pricing data (from the 2026 catalog).
 *
 * Edit prices, colours and products here. The shop, every product page,
 * the price estimator and the quote list all read from this file.
 *
 * - 'id' is also the product page address: yoursite.com/shop/{id}/
 * - Price tiers: 'min' is the smallest quantity that gets that price.
 * - 'display' is the colour shown on product cards (must match a colour name).
 * - Photos: assets/img/products/{id}-{colour}.jpg (e.g. ...-royal-blue.jpg) and {id}-detail.jpg.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standard tier table used by most 5-tier products.
 */
function looma_tiers( $p1, $p10, $p25, $p50, $p100 ) {
	return array(
		array( 'min' => 1, 'label' => '1 – 9 pcs', 'price' => $p1 ),
		array( 'min' => 10, 'label' => '10 – 24 pcs', 'price' => $p10 ),
		array( 'min' => 25, 'label' => '25 – 49 pcs', 'price' => $p25 ),
		array( 'min' => 50, 'label' => '50 – 99 pcs', 'price' => $p50 ),
		array( 'min' => 100, 'label' => '100+ pcs', 'price' => $p100 ),
	);
}

/**
 * Ready-stock products.
 */
function looma_products() {
	$cotton = array( '100% Cotton', 'Bio washed for a soft hand-feel' );
	$stitch = array( 'Double Needled Stitch', 'Strong, clean seams' );
	$fade   = array( 'No Colour Fading', 'Holds colour wash after wash' );

	return array(
		array(
			'id'       => 'oversized-tee-250-gsm-french-terry',
			'display'  => 'Beige', // Colour shown on product cards.
			'name'     => 'Oversized Tee',
			'spec'     => '250 GSM · French Terry',
			'gsm'      => '250 GSM',
			'fabric'   => 'French Terry',
			'fit'      => 'oversized',
			'wash'     => false,
			'badge'    => 'Bestseller',
			'tagline'  => 'Heavyweight, structured and made for streetwear brands.',
			'desc'     => 'Our heaviest oversized tee, knitted in 250 GSM French terry (loopknit) for a thick, premium drape that keeps its shape. Dropped shoulders, a boxy body and durby ribs give it the relaxed streetwear fit customers love — and the dense surface takes DTF prints and embroidery beautifully.',
			'features' => array( array( '250 GSM French Terry', 'Loopknit fabric with a heavy, premium feel' ), $cotton, array( 'Durby Ribs', 'Thick neck rib that stays flat' ), $stitch, $fade ),
			'prices'   => looma_tiers( 290, 265, 260, 255, 250 ),
			'colours'  => array(
				array( 'Black', '#131313' ),
				array( 'White', '#F2EAEA' ),
				array( 'Beige', '#EBE6D4' ),
				array( 'Navy Blue', '#1F273A' ),
				array( 'Royal Blue', '#083D8A' ),
				array( 'Lavender', '#684BA2' ),
				array( 'Red', '#C52E2E' ),
				array( 'Green', '#24572A' ),
				array( 'Brown', '#79472D' ),
			),
			'note'     => '100+ more colours available in production quantity (MOQ 60 pcs).',
		),
		array(
			'id'       => 'acid-wash-oversized-tee-250-gsm',
			'display'  => 'Black', // Colour shown on product cards.
			'name'     => 'Acid Wash Oversized Tee',
			'spec'     => '250 GSM · Acid Wash',
			'gsm'      => '250 GSM',
			'fabric'   => 'Acid Wash French Terry',
			'fit'      => 'oversized',
			'wash'     => true,
			'badge'    => 'Trending',
			'tagline'  => 'Vintage acid-wash texture on heavyweight French terry.',
			'desc'     => 'A vintage-washed version of our 250 GSM French terry oversized tee. Every piece carries the marbled acid-wash texture that makes streetwear drops stand out, with the same boxy fit, durby ribs and double-needle finish.',
			'features' => array( array( '250 GSM Acid Wash', 'French terry / loopknit' ), $cotton, array( 'Durby Ribs', 'Thick neck rib that stays flat' ), $stitch, $fade ),
			'prices'   => looma_tiers( 358, 310, 305, 300, 295 ),
			'colours'  => array(
				array( 'Black', '#313131' ),
				array( 'Green', '#24572A' ),
				array( 'Royal Blue', '#083D8A' ),
			),
			'note'     => '100+ more colours available in production quantity (MOQ 60 pcs).',
		),
		array(
			'id'       => 'full-sleeve-oversized-tee-250-gsm',
			'display'  => 'Black', // Colour shown on product cards.
			'name'     => 'Full Sleeve Oversized Tee',
			'spec'     => '250 GSM · French Terry',
			'gsm'      => '250 GSM',
			'fabric'   => 'French Terry',
			'fit'      => 'oversized',
			'wash'     => false,
			'badge'    => '',
			'tagline'  => 'The heavyweight oversized fit, now with full sleeves.',
			'desc'     => 'Our 250 GSM French terry oversized tee with full-length sleeves — a clean layering piece for cooler months.',
			'features' => array( array( '250 GSM French Terry', 'Loopknit fabric with a heavy, premium feel' ), $cotton, array( 'Durby Ribs', 'Thick neck rib that stays flat' ), $stitch, $fade ),
			'prices'   => looma_tiers( 388, 350, 345, 340, 335 ),
			'colours'  => array(
				array( 'Black', '#131313' ),
			),
			'note'     => '',
		),
		array(
			'id'       => 'oversized-tee-230-gsm',
			'display'  => 'White', // Colour shown on product cards.
			'name'     => 'Oversized Tee',
			'spec'     => '230 GSM · Single Jersey',
			'gsm'      => '230 GSM',
			'fabric'   => 'Single Jersey',
			'fit'      => 'oversized',
			'wash'     => false,
			'badge'    => '',
			'tagline'  => 'Smooth single jersey with a solid, everyday weight.',
			'desc'     => 'A 230 GSM single jersey oversized tee with a smooth face that is ideal for sharp, detailed prints. Heavier than a regular tee, lighter than French terry — the all-rounder for brands and merch.',
			'features' => array( array( '230 GSM', 'Single jersey knitting' ), $cotton, array( 'Durby Ribs', 'Thick neck rib that stays flat' ), $stitch, $fade ),
			'prices'   => looma_tiers( 275, 255, 250, 245, 240 ),
			'colours'  => array(
				array( 'Black', '#131313' ),
				array( 'White', '#F2EAEA' ),
			),
			'note'     => '',
		),
		array(
			'id'       => 'oversized-tee-190-gsm',
			'display'  => 'Black', // Colour shown on product cards.
			'name'     => 'Oversized Tee',
			'spec'     => '190 GSM · Single Jersey',
			'gsm'      => '190 GSM',
			'fabric'   => 'Single Jersey',
			'fit'      => 'oversized',
			'wash'     => false,
			'badge'    => '',
			'tagline'  => 'Lightweight oversized fit for hot days and big volumes.',
			'desc'     => 'A breathable 190 GSM single jersey tee in the relaxed oversized cut. Great value for drops, events and print-on-demand stores that want the oversized look at a lighter weight.',
			'features' => array( array( '190 GSM', 'Single jersey knitting' ), $cotton, array( 'Lycra Ribs', 'Neck rib that springs back' ), $stitch, $fade ),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 9 pcs', 'price' => 245 ),
				array( 'min' => 10, 'label' => '10 – 24 pcs', 'price' => 230 ),
				array( 'min' => 25, 'label' => '25 – 99 pcs', 'price' => 225 ),
				array( 'min' => 100, 'label' => '100+ pcs', 'price' => 220 ),
			),
			'colours'  => array(
				array( 'Black', '#131313' ),
				array( 'White', '#F2EAEA' ),
			),
			'note'     => '',
		),
		array(
			'id'       => 'regular-fit-tee-190-gsm',
			'display'  => 'Red', // Colour shown on product cards.
			'name'     => 'Regular Fit Tee',
			'spec'     => '190 GSM · Single Jersey',
			'gsm'      => '190 GSM',
			'fabric'   => 'Single Jersey',
			'fit'      => 'regular',
			'wash'     => false,
			'badge'    => 'Best Value',
			'tagline'  => 'The classic crew-neck tee, cut to a clean regular fit.',
			'desc'     => 'A timeless regular-fit crew neck in 190 GSM single jersey. The go-to blank for uniforms, events, colleges and bulk merchandise.',
			'features' => array( array( '190 GSM', 'Single jersey knitting' ), $cotton, array( 'Lycra Ribs', 'Neck rib that springs back' ), $stitch, $fade ),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 9 pcs', 'price' => 210 ),
				array( 'min' => 10, 'label' => '10 – 49 pcs', 'price' => 198 ),
				array( 'min' => 50, 'label' => '50+ pcs', 'price' => 192 ),
			),
			'colours'  => array(
				array( 'Black', '#000000' ),
				array( 'White', '#FFFFFF' ),
				array( 'Red', '#DB0C0C' ),
			),
			'note'     => '',
		),
	);
}

/**
 * Find one product by id.
 */
function looma_get_product( $id ) {
	foreach ( looma_products() as $p ) {
		if ( $p['id'] === $id ) {
			return $p;
		}
	}
	return null;
}

/**
 * Sizes in ready stock.
 */
function looma_sizes() {
	return array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' );
}

/**
 * Printing techniques.
 */
function looma_print_techniques() {
	return array(
		array(
			'id'      => 'dtf',
			'name'    => 'DTF Print',
			'image'   => 'print-dtf.jpg',
			'text'    => 'Vibrant, full-colour prints with high durability — perfect for detailed artwork and photos.',
			'moq'     => 'No minimum',
			'details' => array( 'Full-colour, photo-quality detail', 'Soft, flexible and long lasting', 'Priced by print size (see table)' ),
		),
		array(
			'id'      => 'puff',
			'name'    => 'Puff Print',
			'image'   => 'print-puff.jpg',
			'text'    => 'A raised 3D effect that makes bold graphics and logos pop off the fabric.',
			'moq'     => 'Price on request',
			'details' => array( 'Raised, textured 3D finish', 'Best for bold shapes and lettering', 'Quoted per design' ),
		),
		array(
			'id'      => 'hd',
			'name'    => 'HD / High Density',
			'image'   => 'print-hd.jpg',
			'text'    => 'A thick, sharp-edged premium finish — ideal for minimal and classic designs.',
			'moq'     => 'MOQ 10 pcs / design',
			'details' => array( 'Minimum 10 pieces per design', 'Price depends on print size', 'Premium, long-lasting finish' ),
		),
		array(
			'id'      => 'embroidery',
			'name'    => 'Embroidery',
			'image'   => 'print-embroidery.jpg',
			'text'    => 'Premium and long lasting. Best for logos, minimal designs and brand identity.',
			'moq'     => 'No minimum',
			'details' => array( '1 piece: ₹7 per 1000 stitches', '10 pieces: ₹5–6 per 1000 stitches', '50+ pieces: ₹3.5–4 per 1000 stitches', 'Digitizing charges extra' ),
		),
		array(
			'id'      => 'screen',
			'name'    => 'Screen Print',
			'image'   => 'print-screen.jpg',
			'text'    => 'Solid, highly durable colour at the lowest cost per piece for large runs.',
			'moq'     => 'MOQ 60 pcs / design',
			'details' => array( 'Minimum 60 pieces per design', 'Price depends on colours and size', 'Best for bulk orders' ),
		),
	);
}

/**
 * DTF printing charges (price per print). 'bulk' applies from 10 pieces.
 */
function looma_dtf_prices() {
	return array(
		'a2'    => array( 'label' => 'A2 Size', 'single' => 200, 'bulk' => 175, 'size' => '16 × 22' ),
		'a3'    => array( 'label' => 'A3 Size', 'single' => 135, 'bulk' => 100, 'size' => '11 × 16' ),
		'a4'    => array( 'label' => 'A4 Size', 'single' => 95, 'bulk' => 70, 'size' => '8 × 11' ),
		'a5'    => array( 'label' => 'A5 Size', 'single' => 60, 'bulk' => 45, 'size' => '5.8 × 8.3' ),
		'a6'    => array( 'label' => 'A6 Size', 'single' => 40, 'bulk' => 25, 'size' => '4.1 × 5.8' ),
		'logo'  => array( 'label' => 'Logo', 'single' => 20, 'bulk' => 10, 'size' => '2.5 × 2.5' ),
		'label' => array( 'label' => 'Neck Label (Branding)', 'single' => 0, 'bulk' => 0, 'size' => '—' ),
	);
}

/**
 * Embroidery rate per 1000 stitches by total order quantity.
 * The catalog gives ranges (₹5–6, ₹3.5–4); the estimator uses the higher end.
 */
function looma_embroidery_rates() {
	return array(
		array( 'min' => 1, 'rate' => 7 ),
		array( 'min' => 10, 'rate' => 6 ),
		array( 'min' => 50, 'rate' => 4 ),
	);
}

/**
 * Print areas on the product photos for the Design Studio (600 × 680 photo pixels).
 * Measured at 18.2 px per inch (size M). front/back ≈ 19 × 24 in printable area; right/left = sleeves
 * (right sleeve is on the left of the photo, as when facing the wearer).
 */
function looma_mockup_zones() {
	return array(
		'oversized-tee-250-gsm-french-terry' => array( 'front' => array( 300, 375, 300, 420, 0 ), 'back' => array( 300, 357, 300, 450, 0 ), 'right' => array( 100, 258, 72, 72, 24 ), 'left' => array( 500, 258, 72, 72, -24 ) ),
		'acid-wash-oversized-tee-250-gsm' => array( 'front' => array( 300, 378, 300, 420, 0 ), 'back' => array( 300, 357, 300, 450, 0 ), 'right' => array( 98, 258, 72, 72, 24 ), 'left' => array( 502, 258, 72, 72, -24 ) ),
		'full-sleeve-oversized-tee-250-gsm' => array( 'front' => array( 300, 375, 300, 420, 0 ), 'back' => array( 300, 357, 300, 450, 0 ), 'right' => array( 110, 290, 52, 140, 10 ), 'left' => array( 490, 290, 52, 140, -10 ) ),
		'oversized-tee-230-gsm' => array( 'front' => array( 300, 375, 300, 420, 0 ), 'back' => array( 300, 357, 300, 450, 0 ), 'right' => array( 100, 258, 72, 72, 24 ), 'left' => array( 500, 258, 72, 72, -24 ) ),
		'oversized-tee-190-gsm' => array( 'front' => array( 300, 375, 300, 420, 0 ), 'back' => array( 300, 357, 300, 450, 0 ), 'right' => array( 100, 258, 72, 72, 24 ), 'left' => array( 500, 258, 72, 72, -24 ) ),
		'regular-fit-tee-190-gsm' => array( 'front' => array( 300, 372, 290, 415, 0 ), 'back' => array( 300, 350, 290, 445, 0 ), 'right' => array( 98, 228, 60, 60, 28 ), 'left' => array( 502, 228, 60, 60, -28 ) ),
	);
}

/**
 * Worked pricing examples (250 GSM Oversized French Terry + DTF).
 */
function looma_price_examples() {
	return array(
		array( 'title' => 'Plain T-Shirt', 'image' => 'guide-plain.jpg', 'text' => 'Premium blank, ready for your brand.', 'one' => 290, 'ten' => 265 ),
		array( 'title' => 'A3 Back Print', 'image' => 'guide-a3.jpg', 'text' => 'Bold back print. Bigger impact.', 'one' => 425, 'ten' => 365 ),
		array( 'title' => 'A3 Back + Chest Logo', 'image' => 'guide-a3-logo.jpg', 'text' => 'The classic streetwear combination.', 'one' => 445, 'ten' => 375 ),
		array( 'title' => 'A3 Back + A4 Chest', 'image' => 'guide-a3-a4.jpg', 'text' => 'More space for your ideas.', 'one' => 520, 'ten' => 435 ),
	);
}

/**
 * Size charts (inches).
 */
function looma_size_charts() {
	return array(
		'oversized' => array(
			'title'   => 'Oversized Fit',
			'tagline' => 'Relaxed fit. Everyday comfort.',
			'rows'    => array(
				array( 'XS', '40', '25.5', '19', '8.5' ),
				array( 'S', '42', '26.5', '20', '9' ),
				array( 'M', '44', '27.5', '21', '9.5' ),
				array( 'L', '46', '28.5', '22', '10' ),
				array( 'XL', '48', '29', '23', '10.5' ),
				array( 'XXL', '50', '30.5', '24', '11' ),
			),
		),
		'regular'   => array(
			'title'   => 'Regular Fit',
			'tagline' => 'Classic fit. Timeless style.',
			'rows'    => array(
				array( 'XS', '36', '25', '15', '6.25' ),
				array( 'S', '38', '26', '16', '6.5' ),
				array( 'M', '40', '27', '17', '6.75' ),
				array( 'L', '42', '28', '18', '7' ),
				array( 'XL', '44', '29', '19', '7.25' ),
				array( 'XXL', '46', '29.5', '20', '7.25' ),
			),
		),
	);
}

/**
 * Frequently asked questions (built from the catalog terms).
 */
function looma_faqs() {
	return array(
		array( 'Is there a minimum order quantity?', 'No minimum for dropshipping / print on demand — you can order a single piece. Bulk orders start from just 10 pieces. Screen printing needs 60 pieces per design and HD printing 10 pieces per design.' ),
		array( 'Can I add my own brand label?', 'Yes. Neck-label branding (your brand name printed inside the neck) is free with any A2, A3 or A4 print. Custom packaging and woven labels can be added at extra cost.' ),
		array( 'Do you deliver outside Kerala?', 'Yes — we deliver across India through Delhivery, Ecom Express, Blue Dart, India Post, DTDC, Speed & Safe and more, and share tracking details with you.' ),
		array( 'Are prices inclusive of GST?', 'No. All prices are per piece and 5% GST is extra.' ),
		array( 'Can you make colours or garments that are not listed?', 'Yes. 100+ colours are available in production quantity (MOQ 60 pcs), and we can manufacture other styles, fabrics and garments — like hoodies — to your requirement.' ),
		array( 'How do I place a dropshipping order?', 'Choose your products, fill in the order details in the shared Google Sheet or WhatsApp group with your design files, make the payment and share the screenshot. We print, pack and ship directly to your customer.' ),
	);
}
