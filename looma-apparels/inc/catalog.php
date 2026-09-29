<?php
/**
 * Catalog data for Looma Apparels (from the 2026 catalog).
 *
 * Edit prices, colours and products here — every section of the site
 * (product cards, detail pop-ups and the price calculator) reads from this file.
 *
 * Price tiers: 'min' is the smallest quantity that gets that price.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ready-stock products.
 */
function looma_products() {
	return array(
		array(
			'id'       => 'oversized-250-ft',
			'name'     => 'Oversized Fit T-Shirt',
			'gsm'      => '250 GSM',
			'fabric'   => 'French Terry',
			'fit'      => 'oversized',
			'badge'    => 'Bestseller',
			'image'    => 'hanger-oversized-250-ft.jpg',
			'photo'    => 'folded-oversized-250-ft.jpg',
			'features' => array(
				array( '250 GSM', 'French Terry / Loopknit' ),
				array( '100% Cotton', 'Bio washed' ),
				array( 'Durby Ribs', '' ),
				array( 'Double Needled Stitch', '' ),
				array( 'No Colour Fading', '' ),
			),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 10', 'price' => 290 ),
				array( 'min' => 10, 'label' => '10 – 25', 'price' => 265 ),
				array( 'min' => 25, 'label' => '25 – 50', 'price' => 260 ),
				array( 'min' => 50, 'label' => '50 – 100', 'price' => 255 ),
				array( 'min' => 100, 'label' => '100+', 'price' => 250 ),
			),
			'colours'  => array(
				array( 'Black', '#131313' ),
				array( 'Royal Blue', '#083D8A' ),
				array( 'Lavender', '#684BA2' ),
				array( 'Red', '#C52E2E' ),
				array( 'Green', '#24572A' ),
				array( 'White', '#F2EAEA' ),
				array( 'Beige', '#EBE6D4' ),
				array( 'Brown', '#79472D' ),
				array( 'Navy Blue', '#1F273A' ),
			),
			'note'     => '100+ colours available in production quantity (MOQ: 60 pcs).',
		),
		array(
			'id'       => 'oversized-250-acid',
			'name'     => 'Oversized Fit T-Shirt',
			'gsm'      => '250 GSM',
			'fabric'   => 'Acid Wash',
			'fit'      => 'oversized',
			'badge'    => 'Trending',
			'image'    => 'hanger-oversized-250-acid.jpg',
			'photo'    => 'folded-oversized-250-acid.jpg',
			'features' => array(
				array( '250 GSM Acid Wash', 'French Terry / Loopknit' ),
				array( '100% Cotton', 'Bio washed' ),
				array( 'Durby Ribs', '' ),
				array( 'Double Needled Stitch', '' ),
				array( 'No Colour Fading', '' ),
			),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 10', 'price' => 358 ),
				array( 'min' => 10, 'label' => '10 – 25', 'price' => 310 ),
				array( 'min' => 25, 'label' => '25 – 50', 'price' => 305 ),
				array( 'min' => 50, 'label' => '50 – 100', 'price' => 300 ),
				array( 'min' => 100, 'label' => '100+', 'price' => 295 ),
			),
			'colours'  => array(
				array( 'Black', '#313131' ),
				array( 'Green', '#24572A' ),
				array( 'Royal Blue', '#083D8A' ),
			),
			'note'     => '100+ colours available in production quantity (MOQ: 60 pcs).',
		),
		array(
			'id'       => 'fullsleeve-250',
			'name'     => 'Fullsleeve Oversized Fit',
			'gsm'      => '250 GSM',
			'fabric'   => 'French Terry',
			'fit'      => 'oversized',
			'badge'    => '',
			'image'    => 'hanger-fullsleeve-250.jpg',
			'photo'    => 'folded-fullsleeve-250.jpg',
			'features' => array(
				array( '250 GSM', 'French Terry / Loopknit' ),
				array( '100% Cotton', 'Bio washed' ),
				array( 'Durby Ribs', '' ),
				array( 'Double Needled Stitch', '' ),
				array( 'No Colour Fading', '' ),
			),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 10', 'price' => 388 ),
				array( 'min' => 10, 'label' => '10 – 25', 'price' => 350 ),
				array( 'min' => 25, 'label' => '25 – 50', 'price' => 345 ),
				array( 'min' => 50, 'label' => '50 – 100', 'price' => 340 ),
				array( 'min' => 100, 'label' => '100+', 'price' => 335 ),
			),
			'colours'  => array(
				array( 'Black', '#131313' ),
			),
			'note'     => '',
		),
		array(
			'id'       => 'oversized-230',
			'name'     => 'Oversized Fit T-Shirt',
			'gsm'      => '230 GSM',
			'fabric'   => 'Single Jersey',
			'fit'      => 'oversized',
			'badge'    => '',
			'image'    => 'hanger-oversized-230.jpg',
			'photo'    => 'folded-oversized-230.jpg',
			'features' => array(
				array( '230 GSM', 'Single Jersey Knitting' ),
				array( '100% Cotton', 'Bio washed' ),
				array( 'Durby Ribs', '' ),
				array( 'Double Needled Stitch', '' ),
				array( 'No Colour Fading', '' ),
			),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 10', 'price' => 275 ),
				array( 'min' => 10, 'label' => '10 – 25', 'price' => 255 ),
				array( 'min' => 25, 'label' => '25 – 50', 'price' => 250 ),
				array( 'min' => 50, 'label' => '50 – 100', 'price' => 245 ),
				array( 'min' => 100, 'label' => '100+', 'price' => 240 ),
			),
			'colours'  => array(
				array( 'Black', '#131313' ),
				array( 'White', '#F2EAEA' ),
			),
			'note'     => '',
		),
		array(
			'id'       => 'oversized-190',
			'name'     => 'Oversized Fit T-Shirt',
			'gsm'      => '190 GSM',
			'fabric'   => 'Single Jersey',
			'fit'      => 'oversized',
			'badge'    => '',
			'image'    => 'hanger-oversized-190.jpg',
			'photo'    => 'folded-oversized-190.jpg',
			'features' => array(
				array( '190 GSM', 'Single Jersey Knitting' ),
				array( '100% Cotton', 'Bio washed' ),
				array( 'Lycra Ribs', '' ),
				array( 'Double Needled Stitch', '' ),
				array( 'No Colour Fading', '' ),
			),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 10', 'price' => 245 ),
				array( 'min' => 10, 'label' => '10 – 25', 'price' => 230 ),
				array( 'min' => 25, 'label' => '25 – 100', 'price' => 225 ),
				array( 'min' => 100, 'label' => '100+', 'price' => 220 ),
			),
			'colours'  => array(
				array( 'Black', '#131313' ),
				array( 'White', '#F2EAEA' ),
			),
			'note'     => '',
		),
		array(
			'id'       => 'regular-190',
			'name'     => 'Regular Fit T-Shirt',
			'gsm'      => '190 GSM',
			'fabric'   => 'Single Jersey',
			'fit'      => 'regular',
			'badge'    => 'Best Value',
			'image'    => 'hanger-regular-190.jpg',
			'photo'    => 'folded-regular-190.jpg',
			'features' => array(
				array( '190 GSM', 'Single Jersey Knitting' ),
				array( '100% Cotton', 'Bio washed' ),
				array( 'Lycra Ribs', '' ),
				array( 'Double Needled Stitch', '' ),
				array( 'No Colour Fading', '' ),
			),
			'prices'   => array(
				array( 'min' => 1, 'label' => '1 – 10', 'price' => 210 ),
				array( 'min' => 10, 'label' => '10 – 50', 'price' => 198 ),
				array( 'min' => 50, 'label' => '50+', 'price' => 192 ),
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
 * Printing techniques.
 */
function looma_print_techniques() {
	return array(
		array(
			'name'    => 'DTF Print',
			'image'   => 'print-dtf.jpg',
			'text'    => 'Vibrant colours, high durability and perfect for detailed designs. No minimum quantity.',
			'details' => array( 'No minimum order quantity', 'Full-colour, photo-quality prints', 'See DTF charges below' ),
		),
		array(
			'name'    => 'Puff Print',
			'image'   => 'print-puff.jpg',
			'text'    => 'Adds a 3D effect to your design. Perfect for bold and premium looks.',
			'details' => array( 'Raised 3D texture', 'Price on request' ),
		),
		array(
			'name'    => 'HD / High Density',
			'image'   => 'print-hd.jpg',
			'text'    => 'Thick and premium finish for a bold look. Ideal for minimal and classic designs.',
			'details' => array( 'Minimum 10 pieces per design (MOQ)', 'Price depending on size', 'Premium and long lasting finish' ),
		),
		array(
			'name'    => 'Embroidery',
			'image'   => 'print-embroidery.jpg',
			'text'    => 'Premium and long lasting. Best for logos, minimal designs and brand identity.',
			'details' => array( 'No minimum order quantity', '₹7 per 1000 stitches for a single piece', '10 pieces: ₹5–6 per 1000 stitches (depending on design)', '50+ pieces: ₹3.5–4 per 1000 stitches (depending on design)', 'Digitizing charges extra' ),
		),
		array(
			'name'    => 'Screen Print',
			'image'   => 'print-screen.jpg',
			'text'    => 'Best for bulk orders with solid colours. Highly durable and cost effective for large quantities.',
			'details' => array( 'Minimum 60 pieces per design (MOQ)', 'Price depending on colours and size', 'Best for bulk orders' ),
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
		'logo'  => array( 'label' => 'Logo', 'single' => 20, 'bulk' => 10, 'size' => '2.5 × 2.5' ),
		'label' => array( 'label' => 'Neck Label (Branding)', 'single' => 0, 'bulk' => 0, 'size' => '—' ),
	);
}

/**
 * Worked pricing examples (250 GSM Oversized French Terry + DTF).
 */
function looma_price_examples() {
	return array(
		array( 'title' => 'Plain T-Shirt', 'image' => 'guide-plain.jpg', 'text' => 'Premium blank. Ready for your brand.', 'one' => 290, 'ten' => 265 ),
		array( 'title' => 'A3 Back Print', 'image' => 'guide-a3.jpg', 'text' => 'Bold back prints. Bigger impact.', 'one' => 425, 'ten' => 365 ),
		array( 'title' => 'A3 Back + Chest Logo', 'image' => 'guide-a3-logo.jpg', 'text' => 'Back print + chest logo. A perfect combination.', 'one' => 445, 'ten' => 375 ),
		array( 'title' => 'A3 Back + A4 Chest Print', 'image' => 'guide-a3-a4.jpg', 'text' => 'Back print + chest print. More space for your ideas.', 'one' => 520, 'ten' => 435 ),
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
