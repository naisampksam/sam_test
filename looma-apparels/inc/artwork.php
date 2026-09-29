<?php
/**
 * Print artwork used on t-shirt mockups (price guide, printing page).
 * All coordinates are in the 600 × 700 t-shirt viewBox.
 *
 * @package Looma_Apparels
 */

defined( 'ABSPATH' ) || exit;

/**
 * Big back print: sun, mountains and "BETTER DAYS AHEAD" (A3 size).
 */
function looma_art_sunset( $x = 170, $y = 150, $w = 260, $ink = 'var(--ink, #f4efe6)' ) {
	static $n = 0;
	$n++;
	$k  = $w / 260;
	$id = 'sun' . $n;
	return sprintf(
		'<g transform="translate(%1$s %2$s) scale(%3$s)">
			<defs><linearGradient id="%4$s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff9a4d"/><stop offset="1" stop-color="#e2452b"/></linearGradient></defs>
			<text x="130" y="44" text-anchor="middle" font-family="Archivo, Arial, sans-serif" font-weight="900" font-size="46" letter-spacing="-1" style="fill: %5$s">BETTER</text>
			<text x="130" y="88" text-anchor="middle" font-family="Archivo, Arial, sans-serif" font-weight="900" font-size="46" letter-spacing="-1" style="fill: %5$s">DAYS</text>
			<text x="130" y="132" text-anchor="middle" font-family="Archivo, Arial, sans-serif" font-weight="900" font-size="46" letter-spacing="-1" style="fill: %5$s">AHEAD</text>
			<circle cx="150" cy="190" r="58" fill="url(#%4$s)"/>
			<path d="M0 300 L62 214 L88 238 L132 170 L176 232 L204 206 L260 300 Z" fill="#1d1f24"/>
			<path d="M132 170 L118 190 L128 188 L136 198 L146 186 L154 194 Z M62 214 L54 226 L64 224 L70 230 Z M204 206 L196 216 L206 214 L212 220 Z" fill="#f4f4f2"/>
			<path d="M0 300 L40 262 L70 280 L110 240 L150 282 L190 256 L260 300 Z" fill="#3a3d45"/>
			<path d="M110 240 L100 254 L112 250 L118 258 L126 250 Z" fill="#eaeaea"/>
		</g>',
		$x,
		$y,
		round( $k, 4 ),
		$id,
		$ink
	);
}

/**
 * Small chest logo (≈2.5").
 */
function looma_art_logo( $x = 330, $y = 170, $ink = 'var(--ink, #f4efe6)' ) {
	return sprintf(
		'<g transform="translate(%1$s %2$s)"><text x="0" y="0" font-family="Archivo, Arial, sans-serif" font-weight="900" font-size="22" letter-spacing=".5" style="fill: %3$s">LOOMA</text><text x="1" y="10" font-family="Archivo, Arial, sans-serif" font-weight="600" font-size="6.4" letter-spacing="3.1" style="fill: %3$s">APPARELS</text></g>',
		$x,
		$y,
		$ink
	);
}

/**
 * A4 chest print: framed mountain "EXPLORE MORE".
 */
function looma_art_a4( $x = 205, $y = 150, $w = 190 ) {
	$k = $w / 190;
	return sprintf(
		'<g transform="translate(%1$s %2$s) scale(%3$s)">
			<rect width="190" height="200" fill="#d9dadd"/>
			<rect x="10" y="10" width="170" height="150" fill="#9ea3ab"/>
			<circle cx="120" cy="62" r="24" fill="#f1f1f1" opacity=".9"/>
			<path d="M10 160 L60 88 L84 112 L112 70 L150 120 L180 96 L180 160 Z" fill="#23262c"/>
			<path d="M112 70 L100 88 L110 86 L116 94 L124 84 Z M60 88 L52 100 L62 98 L66 104 Z" fill="#fafafa"/>
			<text x="95" y="186" text-anchor="middle" font-family="Archivo, Arial, sans-serif" font-weight="800" font-size="17" letter-spacing="1" fill="#1d1f24">EXPLORE MORE</text>
		</g>',
		$x,
		$y,
		round( $k, 4 )
	);
}
