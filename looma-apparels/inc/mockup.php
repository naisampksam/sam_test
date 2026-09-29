<?php
/**
 * Vector t-shirt mockups.
 *
 * Draws a shaded t-shirt as inline SVG in any colour, so product images are
 * sharp on every screen and the colour can change live when a swatch is picked
 * (the shirt colour is the CSS variable --tee on the <svg>).
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Outline and detail paths for each shirt shape (viewBox 0 0 600 700).
 */
function looma_tee_shapes() {
	return array(
		'oversized'  => array(
			'body'     => 'M228 58 L112 92 C92 98 80 110 74 126 L22 292 C20 300 24 306 32 308 L112 334 C120 336 126 332 128 324 L136 270 L128 652 C128 660 134 664 142 664 L458 664 C466 664 472 660 472 652 L464 270 L472 324 C474 332 480 336 488 334 L568 308 C576 306 580 300 578 292 L526 126 C520 110 508 98 488 92 L372 58 C352 96 248 96 228 58 Z',
			'neck'     => array( 228, 372, 58 ),
			'seams'    => array( 'M116 94 C126 150 132 210 136 270', 'M484 94 C474 150 468 210 464 270' ),
			'hems'     => array( 'M34 294 L116 320', 'M566 294 L484 320', 'M131 648 L469 648' ),
			'pits'     => array( array( 142, 290 ), array( 458, 290 ) ),
			'cuffs'    => array(),
			'folds'    => array( 'M210 330 C236 430 214 530 240 640', 'M392 300 C372 400 396 520 372 640', 'M300 420 C310 500 296 580 306 650' ),
		),
		'regular'    => array(
			'body'     => 'M236 60 L150 90 C136 95 128 104 122 116 L72 236 C69 244 72 250 80 253 L140 276 C148 279 154 275 156 268 L166 226 L160 640 C160 648 166 652 174 652 L426 652 C434 652 440 648 440 640 L434 226 L444 268 C446 275 452 279 460 276 L520 253 C528 250 531 244 528 236 L478 116 C472 104 464 95 450 90 L364 60 C348 98 252 98 236 60 Z',
			'neck'     => array( 236, 364, 60 ),
			'seams'    => array( 'M150 92 C160 140 166 190 166 226', 'M450 92 C440 140 434 190 434 226' ),
			'hems'     => array( 'M80 240 L150 266', 'M520 240 L450 266', 'M163 636 L437 636' ),
			'pits'     => array( array( 170, 246 ), array( 430, 246 ) ),
			'cuffs'    => array(),
			'folds'    => array( 'M226 300 C246 400 228 520 250 630', 'M380 290 C362 400 382 520 364 630' ),
		),
		'fullsleeve' => array(
			'body'     => 'M228 58 L112 92 C92 98 80 110 76 128 L30 586 C29 594 34 600 42 601 L100 606 C108 607 113 602 114 594 L138 300 L130 652 C130 660 136 664 144 664 L456 664 C464 664 470 660 470 652 L462 300 L486 594 C487 602 492 607 500 606 L558 601 C566 600 571 594 570 586 L524 128 C520 110 508 98 488 92 L372 58 C352 96 248 96 228 58 Z',
			'neck'     => array( 228, 372, 58 ),
			'seams'    => array( 'M116 94 C128 160 134 230 138 300', 'M484 94 C472 160 466 230 462 300' ),
			'hems'     => array( 'M133 648 L467 648' ),
			'pits'     => array( array( 144, 320 ), array( 456, 320 ) ),
			'cuffs'    => array( 'M33 572 L113 579', 'M567 572 L487 579' ),
			'folds'    => array( 'M210 340 C236 440 214 540 240 640', 'M392 320 C372 420 396 530 372 640', 'M70 300 C62 380 58 460 52 540', 'M530 300 C538 380 542 460 548 540' ),
		),
	);
}

/**
 * Relative luminance of a hex colour (0 = black, 1 = white).
 */
function looma_luminance( $hex ) {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	$rgb = array_map( 'hexdec', str_split( $hex, 2 ) );
	return ( 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2] ) / 255;
}

/**
 * Build a t-shirt SVG.
 *
 * @param array $args {
 *     @type string $shape  oversized | regular | fullsleeve.
 *     @type string $colour Hex colour.
 *     @type bool   $wash   Acid-wash mottling.
 *     @type string $view   front | back.
 *     @type string $label  Neck label text ('' for none).
 *     @type string $art    Extra SVG drawn on the shirt (prints), under the shading.
 *     @type string $class  CSS class for the <svg>.
 *     @type string $title  Accessible name.
 * }
 */
function looma_tee( $args = array() ) {
	static $n = 0;
	$n++;
	$a = wp_parse_args(
		$args,
		array(
			'shape'  => 'oversized',
			'colour' => '#131313',
			'wash'   => false,
			'view'   => 'front',
			'label'  => 'LOOMA',
			'art'    => '',
			'class'  => 'tee',
			'title'  => '',
		)
	);

	$shapes = looma_tee_shapes();
	$s      = isset( $shapes[ $a['shape'] ] ) ? $shapes[ $a['shape'] ] : $shapes['oversized'];
	$id     = 'tee' . $n;
	list( $nl, $nr, $ny ) = $s['neck'];
	$mid    = ( $nl + $nr ) / 2;
	$half   = ( $nr - $nl ) / 2;

	// Neck curves: front dips deep; back view shows only a shallow neckline.
	$front_dip = 'back' === $a['view'] ? $ny + 14 : $ny + 46;
	$neck_front = sprintf( 'M%1$d %2$d C%3$d %4$d %5$d %4$d %6$d %2$d', $nl, $ny, $nl + $half * 0.28, $front_dip, $nr - $half * 0.28, $nr );
	$neck_back  = sprintf( 'M%1$d %2$d C%3$d %4$d %5$d %4$d %6$d %2$d', $nl, $ny, $nl + $half * 0.45, $ny - 18, $nr - $half * 0.45, $nr );

	$role = $a['title'] ? ' role="img" aria-label="' . esc_attr( $a['title'] ) . '"' : ' aria-hidden="true"';

	ob_start();
	?>
<svg class="<?php echo esc_attr( $a['class'] ); ?>" viewBox="0 0 600 700" xmlns="http://www.w3.org/2000/svg" style="--tee: <?php echo esc_attr( $a['colour'] ); ?>; --ink: <?php echo looma_luminance( $a['colour'] ) > .55 ? '#1d1f24' : '#f4efe6'; ?>"<?php echo $role; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<defs>
		<clipPath id="<?php echo esc_attr( $id ); ?>c"><path d="<?php echo esc_attr( $s['body'] ); ?>"/></clipPath>
		<linearGradient id="<?php echo esc_attr( $id ); ?>v" x1="0" y1="0" x2="0" y2="1">
			<stop offset="0" stop-color="#fff" stop-opacity=".10"/>
			<stop offset=".45" stop-color="#fff" stop-opacity="0"/>
			<stop offset="1" stop-color="#000" stop-opacity=".16"/>
		</linearGradient>
		<linearGradient id="<?php echo esc_attr( $id ); ?>h" x1="0" y1="0" x2="1" y2="0">
			<stop offset="0" stop-color="#000" stop-opacity=".30"/>
			<stop offset=".2" stop-color="#000" stop-opacity=".04"/>
			<stop offset=".42" stop-color="#fff" stop-opacity=".07"/>
			<stop offset=".6" stop-color="#000" stop-opacity="0"/>
			<stop offset=".8" stop-color="#000" stop-opacity=".05"/>
			<stop offset="1" stop-color="#000" stop-opacity=".32"/>
		</linearGradient>
		<filter id="<?php echo esc_attr( $id ); ?>b" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="9"/></filter>
		<filter id="<?php echo esc_attr( $id ); ?>b2" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="3"/></filter>
		<filter id="<?php echo esc_attr( $id ); ?>n" x="0" y="0" width="100%" height="100%">
			<feTurbulence type="fractalNoise" baseFrequency="1.1" numOctaves="1" seed="<?php echo (int) $n; ?>" result="t"/>
			<feColorMatrix in="t" type="matrix" values="0 0 0 0 .5  0 0 0 0 .5  0 0 0 0 .5  0 0 0 1.4 -.55"/>
		</filter>
		<?php if ( $a['wash'] ) : ?>
		<filter id="<?php echo esc_attr( $id ); ?>w" x="0" y="0" width="100%" height="100%">
			<feTurbulence type="fractalNoise" baseFrequency=".022 .03" numOctaves="4" seed="7" result="c"/>
			<feColorMatrix in="c" type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 -2.6 1.55" result="cloud"/>
			<feTurbulence type="turbulence" baseFrequency=".035 .05" numOctaves="4" seed="11" result="t"/>
			<feColorMatrix in="t" type="matrix" values="0 0 0 0 1  0 0 0 0 1  0 0 0 0 1  0 0 0 -3.4 .95" result="veins"/>
			<feGaussianBlur in="veins" stdDeviation="1.1" result="v2"/>
			<feComposite in="v2" in2="cloud" operator="arithmetic" k2=".7" k3=".55"/>
		</filter>
		<?php endif; ?>
		<filter id="<?php echo esc_attr( $id ); ?>s" x="-15%" y="-10%" width="130%" height="130%">
			<feGaussianBlur in="SourceAlpha" stdDeviation="14"/>
			<feOffset dy="18"/>
			<feComponentTransfer><feFuncA type="linear" slope=".22"/></feComponentTransfer>
			<feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge>
		</filter>
	</defs>
	<?php if ( $a['title'] ) : ?><title><?php echo esc_html( $a['title'] ); ?></title><?php endif; ?>

	<g filter="url(#<?php echo esc_attr( $id ); ?>s)">
		<path d="<?php echo esc_attr( $s['body'] ); ?>" style="fill: var(--tee)"/>
	</g>

	<g clip-path="url(#<?php echo esc_attr( $id ); ?>c)">
		<?php if ( $a['wash'] ) : ?>
			<rect width="600" height="700" filter="url(#<?php echo esc_attr( $id ); ?>w)" opacity=".5"/>
		<?php endif; ?>

		<?php echo $a['art']; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted theme artwork. ?>

		<rect width="600" height="700" fill="url(#<?php echo esc_attr( $id ); ?>v)"/>
		<rect width="600" height="700" fill="url(#<?php echo esc_attr( $id ); ?>h)"/>

		<g filter="url(#<?php echo esc_attr( $id ); ?>b)">
			<?php foreach ( $s['pits'] as $p ) : ?>
				<ellipse cx="<?php echo (int) $p[0]; ?>" cy="<?php echo (int) $p[1]; ?>" rx="20" ry="64" fill="#000" opacity=".15"/>
			<?php endforeach; ?>
			<?php foreach ( $s['folds'] as $i => $f ) : ?>
				<path d="<?php echo esc_attr( $f ); ?>" stroke="<?php echo $i % 2 ? '#fff' : '#000'; ?>" stroke-width="16" fill="none" opacity="<?php echo $i % 2 ? '.09' : '.10'; ?>"/>
				<path d="<?php echo esc_attr( $f ); ?>" transform="translate(12 0)" stroke="<?php echo $i % 2 ? '#000' : '#fff'; ?>" stroke-width="10" fill="none" opacity=".07"/>
			<?php endforeach; ?>
			<ellipse cx="<?php echo (int) $mid; ?>" cy="<?php echo (int) ( $front_dip + 22 ); ?>" rx="<?php echo (int) ( $half * 1.05 ); ?>" ry="16" fill="#000" opacity=".16"/>
		</g>

		<g fill="none" stroke-linecap="round" filter="url(#<?php echo esc_attr( $id ); ?>b2)">
			<?php foreach ( $s['seams'] as $seam ) : ?>
				<path d="<?php echo esc_attr( $seam ); ?>" stroke="#000" stroke-width="5" opacity=".22"/>
				<path d="<?php echo esc_attr( $seam ); ?>" transform="translate(4 0)" stroke="#fff" stroke-width="3" opacity=".08"/>
			<?php endforeach; ?>
		</g>
		<g fill="none" stroke-dasharray="5 4" stroke-width="1.6">
			<?php foreach ( $s['hems'] as $hem ) : ?>
				<path d="<?php echo esc_attr( $hem ); ?>" stroke="#000" opacity=".28"/>
				<path d="<?php echo esc_attr( $hem ); ?>" transform="translate(0 7)" stroke="#000" opacity=".28"/>
				<path d="<?php echo esc_attr( $hem ); ?>" transform="translate(0 1.5)" stroke="#fff" opacity=".14"/>
			<?php endforeach; ?>
		</g>
		<?php foreach ( $s['cuffs'] as $cuff ) : ?>
			<path d="<?php echo esc_attr( $cuff ); ?>" stroke="#000" stroke-width="30" opacity=".12" fill="none"/>
			<path d="<?php echo esc_attr( $cuff ); ?>" stroke="#000" stroke-width="30" opacity=".10" fill="none" stroke-dasharray="1.5 3"/>
		<?php endforeach; ?>

		<rect width="600" height="700" filter="url(#<?php echo esc_attr( $id ); ?>n)" opacity=".16" style="mix-blend-mode: overlay"/>
	</g>

	<?php if ( 'front' === $a['view'] ) : ?>
		<!-- inside of the back neck -->
		<path d="<?php echo esc_attr( $neck_back . ' ' . preg_replace( '/^M[\d.]+ [\d.]+ /', '', looma_reverse_curve( $neck_front ) ) ); ?>" style="fill: var(--tee)"/>
		<path d="<?php echo esc_attr( $neck_back . ' ' . preg_replace( '/^M[\d.]+ [\d.]+ /', '', looma_reverse_curve( $neck_front ) ) ); ?>" fill="#000" opacity=".42"/>
		<?php if ( $a['label'] ) : ?>
			<g transform="translate(<?php echo (int) $mid; ?> <?php echo (int) ( $ny - 2 ); ?>)">
				<rect x="-26" y="0" width="52" height="24" rx="2" fill="#f4f2ec"/>
				<rect x="-26" y="0" width="52" height="24" rx="2" fill="none" stroke="#000" stroke-opacity=".12"/>
				<text x="0" y="12.5" text-anchor="middle" font-family="Archivo, Arial, sans-serif" font-weight="900" font-size="10.5" letter-spacing=".5" fill="#111"><?php echo esc_html( $a['label'] ); ?></text>
				<text x="0" y="19.5" text-anchor="middle" font-family="Archivo, Arial, sans-serif" font-weight="600" font-size="3.6" letter-spacing="1.6" fill="#333">APPARELS</text>
			</g>
		<?php endif; ?>
		<path d="<?php echo esc_attr( $neck_back ); ?>" style="stroke: var(--tee)" stroke-width="15" fill="none"/>
		<path d="<?php echo esc_attr( $neck_back ); ?>" stroke="#000" stroke-width="15" fill="none" opacity=".12"/>
	<?php endif; ?>

	<?php if ( 'back' === $a['view'] ) : ?>
		<path d="<?php echo esc_attr( sprintf( '%1$s C%2$d %3$d %4$d %3$d %5$d %6$d Z', $neck_back, $nr - 20, $ny + 40, $nl + 20, $nl, $ny ) ); ?>" style="fill: var(--tee)"/>
	<?php endif; ?>

	<!-- rib collar -->
	<path d="<?php echo esc_attr( $neck_front ); ?>" style="stroke: var(--tee)" stroke-width="18" fill="none" stroke-linecap="round"/>
	<path d="<?php echo esc_attr( $neck_front ); ?>" stroke="#000" stroke-width="18" fill="none" opacity=".16" stroke-linecap="round"/>
	<path d="<?php echo esc_attr( $neck_front ); ?>" stroke="#000" stroke-width="18" fill="none" opacity=".10" stroke-dasharray="1 2.2"/>
	<path d="<?php echo esc_attr( $neck_front ); ?>" transform="translate(0 -8)" stroke="#fff" stroke-width="1.5" fill="none" opacity=".22"/>
	<path d="<?php echo esc_attr( $neck_front ); ?>" transform="translate(0 10)" stroke="#000" stroke-width="3" fill="none" opacity=".30"/>
</svg>
	<?php
	return trim( ob_get_clean() );
}

/**
 * Reverse a single cubic "M x y C x1 y1 x2 y2 x y" path so it can close a shape.
 */
function looma_reverse_curve( $d ) {
	if ( ! preg_match_all( '/-?[\d.]+/', $d, $m ) || count( $m[0] ) < 8 ) {
		return $d;
	}
	list( $x0, $y0, $x1, $y1, $x2, $y2, $x3, $y3 ) = $m[0];
	return "M$x3 $y3 C$x2 $y2 $x1 $y1 $x0 $y0";
}
