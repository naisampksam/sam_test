/**
 * Looma Apparels — Design Studio (live t-shirt mockups + quote).
 *
 * Coordinates: product photos are 600 × 680 "photo px". Each print position
 * (front, back) has a zone {cx, cy, w, h, rot} on the photo.
 * Layers are stored in INCHES relative to their zone's centre, so designs keep
 * their real size and place when the customer switches t-shirt.
 * The print is rendered by multiplying the artwork with a shading map made
 * from the photo, so fabric folds show through like a real print.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-studio]' );
	if ( ! root || ! window.LOOMA_DESIGN ) { return; }

	var CFG = window.LOOMA_DESIGN;
	var BASE = window.LOOMA || { dtf: {}, gst: 5, whatsapp: '' };
	var PPI = CFG.ppi;
	var PW = 600, PH = 680, RS = 2;
	var KEY = 'looma_design_v1';
	var POS = [ 'front', 'back' ];
	var POS_LABEL = { front: 'Front', back: 'Back', left: 'Left sleeve', right: 'Right sleeve' };
	var SLEEVE_ZOOM = 2.5;
	var TEXT_COLOURS = [ '#111111', '#ffffff', '#c8322a', '#e8542b', '#f2b705', '#1f8a4c', '#0a3e8c', '#684ba2', '#8a8f98', '#d4af37' ];
	var PRESETS = {
		// [ label, dx, top gap (in), width (in), optional print box W × H (in) the artwork is fitted inside ]
		front: [ [ 'Left chest', 4.2, 1.6, 0, [ 3, 3 ] ], [ 'Centre chest', 0, 1.5, 0, [ 8.2, 8.2 ] ], [ 'A6', 0, 1.5, 0, [ 4.1, 5.8 ] ], [ 'A5', 0, 1.5, 0, [ 5.8, 8.2 ] ], [ 'A4', 0, 1.5, 0, [ 8.2, 11.6 ] ], [ 'Full front · A3', 0, 1.0, 0, [ 11.6, 16.4 ] ], [ 'Max · A2', 0, 0.3, 0, [ 16.4, 23.3 ] ] ],
		back: [ [ 'Below collar', 0, 0.5, 0, [ 2.5, 2.5 ] ], [ 'Upper back', 0, 1.2, 0, [ 11.6, 8.2 ] ], [ 'A6', 0, 1.2, 0, [ 4.1, 5.8 ] ], [ 'A5', 0, 1.2, 0, [ 5.8, 8.2 ] ], [ 'A4', 0, 1.2, 0, [ 8.2, 11.6 ] ], [ 'Full back · A3', 0, 1.0, 0, [ 11.6, 16.4 ] ], [ 'Max · A2', 0, 0.3, 0, [ 16.4, 23.3 ] ] ],
		left: [ [ 'Centre', 0, null, 3 ], [ 'Fill sleeve', 0, null, 99 ] ],
		right: [ [ 'Centre', 0, null, 3 ], [ 'Fill sleeve', 0, null, 99 ] ]
	};

	/* ---------- helpers ---------- */
	function $( s, c ) { return ( c || root ).querySelector( s ); }
	function $$( s, c ) { return Array.prototype.slice.call( ( c || root ).querySelectorAll( s ) ); }
	function rupee( n ) { return '₹' + Math.round( n ).toLocaleString( 'en-IN' ); }
	function esc( s ) { return String( s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }
	function uid() { return Math.random().toString( 36 ).slice( 2, 9 ); }
	function rad( d ) { return d * Math.PI / 180; }
	function clamp( v, a, b ) { return Math.max( a, Math.min( b, v ) ); }
	function inch( v ) { return ( Math.round( v * 10 ) / 10 ).toFixed( 1 ); }
	function lum( hex ) {
		hex = hex.replace( '#', '' );
		return ( 0.2126 * parseInt( hex.substr( 0, 2 ), 16 ) + 0.7152 * parseInt( hex.substr( 2, 2 ), 16 ) + 0.0722 * parseInt( hex.substr( 4, 2 ), 16 ) ) / 255;
	}
	function toast( msg ) {
		var t = document.querySelector( '[data-toast]' );
		if ( ! t ) { return; }
		t.textContent = msg; t.hidden = false;
		clearTimeout( toast.t ); toast.t = setTimeout( function () { t.hidden = true; }, 3500 );
	}
	function product( id ) {
		for ( var i = 0; i < CFG.products.length; i++ ) { if ( CFG.products[ i ].id === id ) { return CFG.products[ i ]; } }
		return CFG.products[ 0 ];
	}
	function colour( p, name ) {
		for ( var i = 0; i < p.colours.length; i++ ) { if ( p.colours[ i ].name === name ) { return p.colours[ i ]; } }
		return p.colours[ 0 ];
	}
	function zone( pos, p ) {
		var z = ( p || product( state.product ) ).zones[ pos ];
		return { cx: z[ 0 ], cy: z[ 1 ], w: z[ 2 ], h: z[ 3 ], rot: z[ 4 ], wi: z[ 2 ] / PPI, hi: z[ 3 ] / PPI };
	}
	function tier( prices, q ) {
		var v = prices[ 0 ].price;
		prices.forEach( function ( t ) { if ( q >= t.min ) { v = t.price; } } );
		return v;
	}
	function activePos() { return state.view; }

	/* ---------- state ---------- */
	var assets = {}; // id -> { img, w, h, name, mime, file, clean, src }
	var state = {
		product: CFG.products[ 0 ].id, colour: CFG.products[ 0 ].display, view: 'front',
		layers: [], method: { front: 'dtf', back: 'dtf', left: 'dtf', right: 'dtf' },
		stitches: {}, sizes: {}, label: true
	};
	CFG.sizes.forEach( function ( s ) { state.sizes[ s ] = s === 'M' ? 1 : 0; } );
	var sel = null; // selected layer id
	var history = [], future = [];

	/* ---------- photos & shading maps ---------- */
	var photos = {}; // url -> { img, shade, ready }
	function photo( url ) {
		if ( photos[ url ] ) { return photos[ url ]; }
		var rec = { img: new Image(), shade: null, ready: false };
		photos[ url ] = rec;
		rec.img.onload = function () { rec.shade = shadingMap( rec.img ); rec.ready = true; render(); };
		rec.img.src = url;
		return rec;
	}
	// Grey map ≈ 1 on flat fabric, darker in folds/shadows. Multiplied into the print.
	function shadingMap( img ) {
		var c = document.createElement( 'canvas' ); c.width = PW; c.height = PH;
		var x = c.getContext( '2d' ); x.drawImage( img, 0, 0, PW, PH );
		var src = x.getImageData( 0, 0, PW, PH );
		var s = document.createElement( 'canvas' ); s.width = 24; s.height = 27;
		var sx = s.getContext( '2d' ); sx.imageSmoothingQuality = 'high'; sx.drawImage( img, 0, 0, 24, 27 );
		var b = document.createElement( 'canvas' ); b.width = PW; b.height = PH;
		var bx = b.getContext( '2d' ); bx.imageSmoothingQuality = 'high'; bx.drawImage( s, 0, 0, PW, PH );
		var blur = bx.getImageData( 0, 0, PW, PH ).data, d = src.data;
		var mid = 0, n = 0;
		for ( var yy = 250; yy < 450; yy += 4 ) { for ( var xx = 220; xx < 380; xx += 4 ) { var k = ( yy * PW + xx ) * 4; mid += d[ k ] * 0.3 + d[ k + 1 ] * 0.59 + d[ k + 2 ] * 0.11; n++; } }
		mid /= n;
		var strength = mid > 110 ? 0.9 : ( mid > 60 ? 0.7 : 0.5 );
		for ( var i = 0; i < d.length; i += 4 ) {
			var L = d[ i ] * 0.3 + d[ i + 1 ] * 0.59 + d[ i + 2 ] * 0.11;
			var B = blur[ i ] * 0.3 + blur[ i + 1 ] * 0.59 + blur[ i + 2 ] * 0.11;
			var v = Math.min( 1, ( L + 6 ) / ( B * 1.04 + 6 ) );
			v = 1 - strength * ( 1 - v );
			d[ i ] = d[ i + 1 ] = d[ i + 2 ] = Math.round( v * 255 ); d[ i + 3 ] = 255;
		}
		x.putImageData( src, 0, 0 );
		return c;
	}

	/* ---------- layer geometry ---------- */
	function aspect( l ) {
		var s = source( l );
		return s ? s.height / s.width : 1;
	}
	function geo( l, p ) {
		var z = zone( l.pos, p ), r = rad( z.rot );
		var px = l.dx * PPI, py = l.dy * PPI;
		return {
			x: z.cx + px * Math.cos( r ) - py * Math.sin( r ),
			y: z.cy + px * Math.sin( r ) + py * Math.cos( r ),
			w: l.w * PPI, h: l.w * aspect( l ) * PPI,
			a: rad( z.rot + l.rot ), z: z
		};
	}
	// Where the visible ink sits inside a source canvas, as fractions of its size, plus how much of it is
	// covered. Transparent margins (PNG padding, space around text) are not printed, so they are not priced.
	function inkOf( c ) {
		if ( c._ink ) { return c._ink; }
		var full = { u0: 0, v0: 0, u1: 1, v1: 1, fill: 1 };
		var k = Math.min( 1, 320 / Math.max( c.width, c.height ) ), w = Math.max( 1, Math.round( c.width * k ) ), h = Math.max( 1, Math.round( c.height * k ) );
		var t = document.createElement( 'canvas' ); t.width = w; t.height = h;
		var x = t.getContext( '2d' ), d;
		x.drawImage( c, 0, 0, w, h );
		try { d = x.getImageData( 0, 0, w, h ).data; } catch ( e ) { return ( c._ink = full ); }
		var u0 = w, v0 = h, u1 = -1, v1 = -1, n = 0;
		for ( var yy = 0; yy < h; yy++ ) {
			for ( var xx = 0; xx < w; xx++ ) {
				if ( d[ ( yy * w + xx ) * 4 + 3 ] > 24 ) { n++; if ( xx < u0 ) { u0 = xx; } if ( xx > u1 ) { u1 = xx; } if ( yy < v0 ) { v0 = yy; } if ( yy > v1 ) { v1 = yy; } }
			}
		}
		c._ink = u1 < 0 ? { u0: 0, v0: 0, u1: 0, v1: 0, fill: 0 } : { u0: u0 / w, v0: v0 / h, u1: ( u1 + 1 ) / w, v1: ( v1 + 1 ) / h, fill: n / ( w * h ) };
		return c._ink;
	}
	// Printed box of one layer, in inches on the zone axes, and its inked area in square inches.
	function layerBox( l ) {
		var src = source( l ), ink = src ? inkOf( src ) : { u0: 0, v0: 0, u1: 1, v1: 1, fill: 1 };
		var W = l.w, H = l.w * aspect( l ), r = rad( l.rot );
		var u0 = l.flip ? 1 - ink.u1 : ink.u0, u1 = l.flip ? 1 - ink.u0 : ink.u1;
		var xs = [ ( u0 - 0.5 ) * W, ( u1 - 0.5 ) * W ], ys = [ ( ink.v0 - 0.5 ) * H, ( ink.v1 - 0.5 ) * H ];
		var minX = 1e9, minY = 1e9, maxX = -1e9, maxY = -1e9;
		[ [ xs[ 0 ], ys[ 0 ] ], [ xs[ 1 ], ys[ 0 ] ], [ xs[ 1 ], ys[ 1 ] ], [ xs[ 0 ], ys[ 1 ] ] ].forEach( function ( c ) {
			var x = l.dx + c[ 0 ] * Math.cos( r ) - c[ 1 ] * Math.sin( r );
			var y = l.dy + c[ 0 ] * Math.sin( r ) + c[ 1 ] * Math.cos( r );
			minX = Math.min( minX, x ); maxX = Math.max( maxX, x ); minY = Math.min( minY, y ); maxY = Math.max( maxY, y );
		} );
		return { x0: minX, y0: minY, x1: maxX, y1: maxY, w: maxX - minX, h: maxY - minY, ink: ink.fill * W * H };
	}
	function unite( a, b ) {
		var x0 = Math.min( a.x0, b.x0 ), y0 = Math.min( a.y0, b.y0 ), x1 = Math.max( a.x1, b.x1 ), y1 = Math.max( a.y1, b.y1 );
		return { x0: x0, y0: y0, x1: x1, y1: y1, w: x1 - x0, h: y1 - y0, ink: a.ink + b.ink };
	}
	// Artwork that sits apart (e.g. a chest logo and a centre print) is cut as separate DTF pieces, so each
	// piece is priced on its own size. Pieces closer than GAP inches print as one.
	var GAP = 0.5;
	function pieces( pos ) {
		var g = state.layers.filter( function ( l ) { return l.pos === pos; } ).map( layerBox ).filter( function ( b ) { return b.w > 0.01 && b.h > 0.01; } );
		var merged = true;
		while ( merged ) {
			merged = false;
			for ( var i = 0; i < g.length && ! merged; i++ ) {
				for ( var j = i + 1; j < g.length; j++ ) {
					var a = g[ i ], b = g[ j ];
					if ( a.x0 - GAP < b.x1 && b.x0 - GAP < a.x1 && a.y0 - GAP < b.y1 && b.y0 - GAP < a.y1 ) { g[ i ] = unite( a, b ); g.splice( j, 1 ); merged = true; break; }
				}
			}
		}
		return g.sort( function ( a, b ) { return a.w * a.h - b.w * b.h; } );
	}
	// Overall box of a position's printed artwork, in inches on the zone axes.
	function bbox( pos ) {
		var g = pieces( pos );
		return g.length ? g.reduce( unite ) : null;
	}
	function overflow( pos ) {
		var b = bbox( pos ), z = zone( pos );
		return b && ( b.x0 < -z.wi / 2 - 0.05 || b.x1 > z.wi / 2 + 0.05 || b.y0 < -z.hi / 2 - 0.05 || b.y1 > z.hi / 2 + 0.05 );
	}

	/* ---------- artwork sources ---------- */
	var textCache = {};
	function source( l ) {
		if ( l.kind === 'text' ) { return textCanvas( l ); }
		var a = assets[ l.asset ];
		if ( ! a ) { return null; }
		var bg = bgOf( l );
		return bg ? keyedCanvas( a, bg ) : a.img;
	}
	function textCanvas( l ) {
		var t = l.t, key = JSON.stringify( t );
		if ( textCache[ key ] ) { return textCache[ key ]; }
		var f = t.font.split( '|' ), size = 160, pad = t.outline !== 'none' ? 26 : 12;
		var str = t.case === 'upper' ? t.text.toUpperCase() : t.text;
		var lines = ( str || ' ' ).split( '\n' );
		var c = document.createElement( 'canvas' ), x = c.getContext( '2d' );
		var font = f[ 0 ] + ' ' + size + 'px "' + f[ 1 ] + '", Arial, sans-serif';
		x.font = font;
		var w = Math.max.apply( null, lines.map( function ( s ) { return x.measureText( s ).width; } ) ) || 10;
		var lh = size * ( f[ 1 ] === 'Allura' ? 1.15 : 1.05 );
		c.width = Math.ceil( w + pad * 2 ); c.height = Math.ceil( lh * lines.length + pad * 2 );
		x.font = font; x.textAlign = 'center'; x.textBaseline = 'middle'; x.lineJoin = 'round';
		lines.forEach( function ( s, i ) {
			var y = pad + lh * ( i + 0.5 );
			if ( t.outline !== 'none' ) { x.strokeStyle = t.outline; x.lineWidth = 22; x.strokeText( s, c.width / 2, y ); }
			x.fillStyle = t.colour; x.fillText( s, c.width / 2, y );
		} );
		textCache[ key ] = c;
		return c;
	}

	/* ---------- background removal ---------- */
	// l.bg = { keys: [ '#rrggbb', … ] colours to remove, tol: strength 1–60, edge: only areas touching the image border }
	function bgOf( l ) {
		if ( l.white && ! l.bg ) { l.bg = { keys: [ '#ffffff' ], tol: 14, edge: false }; } // drafts from older versions
		delete l.white;
		return l.bg && l.bg.keys.length ? l.bg : null;
	}
	function newBg( keys ) { return { keys: keys || [], tol: 14, edge: true }; }
	function hexRgb( h ) { return [ parseInt( h.substr( 1, 2 ), 16 ), parseInt( h.substr( 3, 2 ), 16 ), parseInt( h.substr( 5, 2 ), 16 ) ]; }
	function rgbHex( r, g, b ) { return '#' + [ r, g, b ].map( function ( v ) { return ( '0' + Math.round( v ).toString( 16 ) ).slice( -2 ); } ).join( '' ); }
	function colourDist( a, b ) { var x = hexRgb( a ), y = hexRgb( b ); return Math.sqrt( ( x[ 0 ] - y[ 0 ] ) * ( x[ 0 ] - y[ 0 ] ) + ( x[ 1 ] - y[ 1 ] ) * ( x[ 1 ] - y[ 1 ] ) + ( x[ 2 ] - y[ 2 ] ) * ( x[ 2 ] - y[ 2 ] ) ); }
	// Make the picked colours transparent in RGBA pixel data (in place), with a soft edge so cut-outs are not jagged.
	function keyOut( d, w, h, bg ) {
		var keys = bg.keys.map( hexRgb ), d0 = bg.tol * 3.2, band = 8 + bg.tol * 1.2, n = w * h;
		var lo = d0 * d0, hi = ( d0 + band ) * ( d0 + band ), f = new Uint8Array( n ), i, p; // f: 0 = remove … 255 = keep
		for ( i = 0, p = 0; i < n; i++, p += 4 ) {
			if ( d[ p + 3 ] === 0 ) { continue; }
			var best = 1e9;
			for ( var k = 0; k < keys.length; k++ ) {
				var dr = d[ p ] - keys[ k ][ 0 ], dg = d[ p + 1 ] - keys[ k ][ 1 ], db = d[ p + 2 ] - keys[ k ][ 2 ], dd = dr * dr + dg * dg + db * db;
				if ( dd < best ) { best = dd; }
			}
			f[ i ] = best <= lo ? 0 : best >= hi ? 255 : Math.round( ( Math.sqrt( best ) - d0 ) / band * 255 );
		}
		if ( bg.edge ) {
			// Remove only what is connected to the border, so the same colour inside the design stays.
			var seen = new Uint8Array( n ), stack = new Int32Array( n ), sp = 0, x, y;
			var push = function ( j ) { if ( ! seen[ j ] && f[ j ] < 255 ) { seen[ j ] = 1; stack[ sp++ ] = j; } };
			for ( x = 0; x < w; x++ ) { push( x ); push( ( h - 1 ) * w + x ); }
			for ( y = 0; y < h; y++ ) { push( y * w ); push( y * w + w - 1 ); }
			while ( sp ) {
				i = stack[ --sp ];
				if ( f[ i ] > 128 ) { continue; } // soft edge pixels are removed but do not spread further
				x = i % w; y = ( i - x ) / w;
				if ( x > 0 ) { push( i - 1 ); } if ( x < w - 1 ) { push( i + 1 ); }
				if ( y > 0 ) { push( i - w ); } if ( y < h - 1 ) { push( i + w ); }
			}
			for ( i = 0; i < n; i++ ) { if ( ! seen[ i ] ) { f[ i ] = 255; } }
		}
		for ( i = 0, p = 3; i < n; i++, p += 4 ) { if ( f[ i ] < 255 ) { d[ p ] = Math.round( d[ p ] * f[ i ] / 255 ); } }
	}
	function keyCanvas( src, w, h, bg ) {
		var c = document.createElement( 'canvas' ); c.width = w; c.height = h;
		var x = c.getContext( '2d' ); x.drawImage( src, 0, 0, w, h );
		var im = x.getImageData( 0, 0, w, h ); keyOut( im.data, w, h, bg ); x.putImageData( im, 0, 0 );
		return c;
	}
	// Screen-size result, cached per asset for the last few settings.
	function keyedCanvas( a, bg ) {
		var key = JSON.stringify( bg );
		a.keyed = a.keyed || {};
		if ( ! a.keyed[ key ] ) {
			var ks = Object.keys( a.keyed ); if ( ks.length > 3 ) { delete a.keyed[ ks[ 0 ] ]; }
			a.keyed[ key ] = keyCanvas( a.img, a.img.width, a.img.height, bg );
		}
		return a.keyed[ key ];
	}
	// The artwork at the resolution it was uploaded (SVG at 4000 px), capped so phones can handle it.
	function fullSource( a ) {
		var MAXPX = 16e6;
		return new Promise( function ( resolve ) {
			var fit = function ( img, w, h ) { var k = Math.min( 1, Math.sqrt( MAXPX / ( w * h ) ) ); return rasterise( img, Math.round( w * k ), Math.round( h * k ), 1e9 ); };
			if ( a.svgImg ) { var sc = 4000 / Math.max( a.svgImg.naturalWidth || a.w, a.svgImg.naturalHeight || a.h ); resolve( fit( a.svgImg, Math.round( ( a.svgImg.naturalWidth || a.w ) * sc ), Math.round( ( a.svgImg.naturalHeight || a.h ) * sc ) ) ); return; }
			if ( ! a.file ) { resolve( a.img ); return; }
			var img = new Image(), url = URL.createObjectURL( a.file );
			img.onload = function () { URL.revokeObjectURL( url ); resolve( fit( img, img.naturalWidth, img.naturalHeight ) ); };
			img.onerror = function () { resolve( a.img ); };
			img.src = url;
		} );
	}
	function fullKeyed( a, bg ) {
		return fullSource( a ).then( function ( src ) { return keyCanvas( src, src.width, src.height, bg ); } );
	}
	// Colours along the image border that look like a background (up to 3 distinct ones).
	function borderColours( a ) {
		var c = a.img, x = c.getContext( '2d' ), w = c.width, h = c.height, out = [], d;
		try { d = x.getImageData( 0, 0, w, h ).data; } catch ( e ) { return out; }
		var steps = 24, pts = [];
		for ( var s2 = 0; s2 <= steps; s2++ ) {
			var t = s2 / steps;
			pts.push( [ t * ( w - 1 ), 1 ], [ t * ( w - 1 ), h - 2 ], [ 1, t * ( h - 1 ) ], [ w - 2, t * ( h - 1 ) ] );
		}
		var counts = [];
		pts.forEach( function ( pt ) {
			var p = ( Math.round( pt[ 1 ] ) * w + Math.round( pt[ 0 ] ) ) * 4;
			if ( d[ p + 3 ] < 200 ) { return; }
			var hex = rgbHex( d[ p ], d[ p + 1 ], d[ p + 2 ] ), hit = null;
			counts.forEach( function ( c2 ) { if ( ! hit && colourDist( c2.hex, hex ) < 40 ) { hit = c2; } } );
			if ( hit ) { hit.n++; } else { counts.push( { hex: hex, n: 1 } ); }
		} );
		counts.sort( function ( a2, b2 ) { return b2.n - a2.n; } );
		counts.forEach( function ( c2 ) { if ( out.length < 3 && c2.n >= pts.length * 0.15 ) { out.push( c2.hex ); } } );
		return out;
	}
	// Average colour of the original artwork around (u, v) in 0–1 image coordinates.
	function sampleColour( a, u, v ) {
		var c = a.img, x = c.getContext( '2d' ), px = Math.round( u * ( c.width - 1 ) ), py = Math.round( v * ( c.height - 1 ) );
		var x0 = Math.max( 0, px - 1 ), y0 = Math.max( 0, py - 1 ), w = Math.min( 3, c.width - x0 ), h = Math.min( 3, c.height - y0 );
		var d = x.getImageData( x0, y0, w, h ).data, r = 0, g = 0, b = 0, n = 0;
		for ( var i = 0; i < d.length; i += 4 ) { if ( d[ i + 3 ] > 0 ) { r += d[ i ]; g += d[ i + 1 ]; b += d[ i + 2 ]; n++; } }
		return n ? rgbHex( r / n, g / n, b / n ) : null;
	}
	function addKeys( l, list ) {
		var bg = l.bg && l.bg.keys ? l.bg : ( l.bg = newBg() ), added = 0;
		list.forEach( function ( hex ) {
			if ( hex && ! bg.keys.some( function ( k ) { return colourDist( k, hex ) < 18; } ) ) { bg.keys.push( hex ); added++; }
		} );
		return added;
	}

	/* ---------- rendering ---------- */
	var stage = $( '[data-stage]' ), canvas = $( '[data-canvas]' ), ctx = canvas.getContext( '2d' );
	var cam = { s: 1, tx: 0, ty: 0 }, cssW = 0, cssH = 0, dpr = 1;
	var printCv = document.createElement( 'canvas' ), shadeCv = document.createElement( 'canvas' );
	printCv.width = shadeCv.width = PW * RS; printCv.height = shadeCv.height = PH * RS;

	function photoUrl( view ) {
		var c = colour( product( state.product ), state.colour );
		return view === 'back' ? c.back : c.front;
	}
	function camera( view, w, h ) {
		var s = w / PW;
		if ( view === 'left' || view === 'right' ) {
			var z = zone( view ); s *= SLEEVE_ZOOM;
			return { s: s, tx: clamp( w / 2 - z.cx * s, w - PW * s, 0 ), ty: clamp( h / 2 - z.cy * s, h - PH * s, 0 ) };
		}
		return { s: s, tx: 0, ty: 0 };
	}
	function positionsIn( view ) { return [ view ]; }

	// Artwork for the given positions, multiplied by the fabric shading.
	function buildPrint( view, shade ) {
		var x = printCv.getContext( '2d' ), list = positionsIn( view ), drawn = false;
		x.setTransform( 1, 0, 0, 1, 0, 0 ); x.globalCompositeOperation = 'source-over'; x.globalAlpha = 1;
		x.clearRect( 0, 0, printCv.width, printCv.height );
		state.layers.forEach( function ( l ) {
			if ( list.indexOf( l.pos ) < 0 ) { return; }
			var s = source( l ); if ( ! s ) { return; }
			var g = geo( l );
			x.setTransform( RS, 0, 0, RS, 0, 0 );
			x.translate( g.x, g.y ); x.rotate( g.a ); x.scale( l.flip ? -1 : 1, 1 );
			x.globalAlpha = 0.96;
			x.drawImage( s, -g.w / 2, -g.h / 2, g.w, g.h );
			drawn = true;
		} );
		x.setTransform( 1, 0, 0, 1, 0, 0 ); x.globalAlpha = 1;
		if ( ! drawn ) { return null; }
		if ( shade ) {
			var sx = shadeCv.getContext( '2d' );
			sx.globalCompositeOperation = 'source-over'; sx.clearRect( 0, 0, shadeCv.width, shadeCv.height );
			sx.drawImage( shade, 0, 0, shadeCv.width, shadeCv.height );
			sx.globalCompositeOperation = 'destination-in'; sx.drawImage( printCv, 0, 0 );
			sx.globalCompositeOperation = 'source-over';
			x.globalCompositeOperation = 'multiply'; x.drawImage( shadeCv, 0, 0 );
			x.globalCompositeOperation = 'source-over';
		}
		return printCv;
	}

	function draw( c, view, w, h, opts ) {
		var p = photo( photoUrl( view ) ), cm = camera( view, w, h );
		c.setTransform( 1, 0, 0, 1, 0, 0 );
		c.fillStyle = '#e9e8e4'; c.fillRect( 0, 0, c.canvas.width, c.canvas.height );
		var k = c.canvas.width / w;
		c.setTransform( k * cm.s, 0, 0, k * cm.s, k * cm.tx, k * cm.ty );
		if ( p.ready ) {
			c.drawImage( p.img, 0, 0, PW, PH );
			var pr = buildPrint( view, p.shade );
			if ( pr ) { c.drawImage( pr, 0, 0, PW, PH ); }
		}
		if ( opts.guides ) {
			var pos = view, z = zone( pos ), bad = overflow( pos );
			c.save(); c.translate( z.cx, z.cy ); c.rotate( rad( z.rot ) );
			c.lineWidth = 1.4 / cm.s; c.setLineDash( [ 6 / cm.s, 5 / cm.s ] );
			c.strokeStyle = bad ? 'rgba(232,84,43,.95)' : 'rgba(110,120,140,.75)';
			c.strokeRect( -z.w / 2, -z.h / 2, z.w, z.h );
			if ( opts.snapX ) { c.strokeStyle = 'rgba(232,84,43,.9)'; c.beginPath(); c.moveTo( 0, -z.h / 2 ); c.lineTo( 0, z.h / 2 ); c.stroke(); }
			c.restore();
		}
		return cm;
	}

	var raf = 0, snapX = false;
	function render() {
		if ( raf ) { return; }
		raf = requestAnimationFrame( function () {
			raf = 0;
			resize();
			cam = draw( ctx, state.view, cssW, cssH, { guides: $( '[data-guides]' ).checked, snapX: snapX } );
			placeSel();
			$( '[data-empty]' ).hidden = state.layers.some( function ( l ) { return l.pos === activePos(); } );
			thumbsSoon();
		} );
	}
	function resize() {
		var w = stage.clientWidth, h = Math.round( w * PH / PW );
		dpr = Math.min( window.devicePixelRatio || 1, 2 );
		if ( w !== cssW || h !== cssH || canvas.width !== Math.round( w * dpr ) ) {
			cssW = w; cssH = h;
			canvas.width = Math.round( w * dpr ); canvas.height = Math.round( h * dpr );
			canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
			stage.style.height = h + 'px';
		}
	}
	window.addEventListener( 'resize', render );

	/* ---------- selection box ---------- */
	var selEl = $( '[data-sel]' ), measure = $( '[data-measure]' );
	function selected() {
		for ( var i = 0; i < state.layers.length; i++ ) { if ( state.layers[ i ].id === sel ) { return state.layers[ i ]; } }
		return null;
	}
	function placeSel() {
		var l = selected();
		if ( ! l || l.pos !== activePos() ) { selEl.hidden = true; return; }
		var g = geo( l );
		var w = g.w * cam.s, h = g.h * cam.s;
		selEl.hidden = false;
		selEl.style.width = w + 'px'; selEl.style.height = h + 'px';
		selEl.style.left = ( cam.tx + g.x * cam.s - w / 2 ) + 'px';
		selEl.style.top = ( cam.ty + g.y * cam.s - h / 2 ) + 'px';
		selEl.style.transform = 'rotate(' + ( g.a * 180 / Math.PI ) + 'deg)';
		$( '[data-sel-img]' ).hidden = l.kind !== 'image';
		var bar = $( '.ds-sel-bar' ); bar.classList.toggle( 'inside', cam.ty + g.y * cam.s + h / 2 + 64 > cssH ); bar.style.transform = 'translateX(-50%) rotate(' + ( -g.a * 180 / Math.PI ) + 'deg)';
	}
	function showMeasure( on ) {
		var b = bbox( activePos() );
		if ( ! on || ! b ) { measure.hidden = true; return; }
		measure.hidden = false;
		var g = pieces( activePos() ), emb = state.method[ activePos() ] === 'emb';
		measure.textContent = g.length > 1 && ! emb
			? g.map( function ( pc ) { return DTF_NAME[ sizeClass( pc ) ].replace( ' DTF', '' ) + ' ' + inch( pc.w ) + '×' + inch( pc.h ); } ).join( ' + ' ) + ' in'
			: inch( b.w ) + ' × ' + inch( b.h ) + ' in · ' + printName( activePos() );
	}

	/* ---------- pointer interaction ---------- */
	var drag = null;
	function toPhoto( e ) {
		var r = stage.getBoundingClientRect();
		return { x: ( e.clientX - r.left - cam.tx ) / cam.s, y: ( e.clientY - r.top - cam.ty ) / cam.s };
	}
	function hit( pt ) {
		for ( var i = state.layers.length - 1; i >= 0; i-- ) {
			var l = state.layers[ i ];
			if ( l.pos !== activePos() ) { continue; }
			var g = geo( l ), dx = pt.x - g.x, dy = pt.y - g.y;
			var lx = dx * Math.cos( -g.a ) - dy * Math.sin( -g.a ), ly = dx * Math.sin( -g.a ) + dy * Math.cos( -g.a );
			var pad = 6 / cam.s;
			if ( Math.abs( lx ) <= g.w / 2 + pad && Math.abs( ly ) <= g.h / 2 + pad ) { return l; }
		}
		return null;
	}
	stage.addEventListener( 'pointerdown', function ( e ) {
		if ( e.button > 0 || e.target.closest( '[data-upload-btn]' ) ) { return; }
		var sa = e.target.closest( '[data-sel-act]' );
		if ( sa ) {
			e.preventDefault();
			var cur = selected();
			if ( cur && sa.getAttribute( 'data-sel-act' ) === 'delete' ) { removeLayer( cur ); }
			else if ( cur ) { replaceImage( cur ); }
			return;
		}
		var pt = toPhoto( e ), h = e.target.getAttribute && e.target.getAttribute( 'data-h' );
		var l = h ? selected() : hit( pt );
		if ( ! l ) { select( null ); return; }
		select( l.id );
		var g = geo( l );
		drag = { mode: h || 'move', l: l, start: pt, dx: l.dx, dy: l.dy, w: l.w, rot: l.rot,
			d0: Math.hypot( pt.x - g.x, pt.y - g.y ) || 1, a0: Math.atan2( pt.y - g.y, pt.x - g.x ), g: g, moved: false };
		stage.setPointerCapture( e.pointerId );
		stage.focus( { preventScroll: true } );
		e.preventDefault();
	} );
	stage.addEventListener( 'pointermove', function ( e ) {
		if ( ! drag ) { return; }
		var pt = toPhoto( e ), l = drag.l, z = zone( l.pos ), r = rad( -z.rot );
		drag.moved = true;
		if ( drag.mode === 'move' ) {
			var mx = ( pt.x - drag.start.x ) / PPI, my = ( pt.y - drag.start.y ) / PPI;
			l.dx = drag.dx + mx * Math.cos( r ) - my * Math.sin( r );
			l.dy = drag.dy + mx * Math.sin( r ) + my * Math.cos( r );
			snapX = Math.abs( l.dx ) < 0.18; if ( snapX ) { l.dx = 0; }
		} else if ( drag.mode === 'scale' ) {
			var d = Math.hypot( pt.x - drag.g.x, pt.y - drag.g.y );
			l.w = clamp( drag.w * d / drag.d0, 0.5, z.wi * 1.6 );
		} else if ( drag.mode === 'rotate' ) {
			var a = Math.atan2( pt.y - drag.g.y, pt.x - drag.g.x );
			var deg = drag.rot + ( a - drag.a0 ) * 180 / Math.PI;
			deg = ( ( deg + 540 ) % 360 ) - 180;
			[ -180, -90, 0, 90, 180 ].forEach( function ( s ) { if ( Math.abs( deg - s ) < 4 ) { deg = s; } } );
			l.rot = Math.round( deg );
		}
		showMeasure( true );
		update( false );
	} );
	function endDrag() {
		if ( ! drag ) { return; }
		var moved = drag.moved; drag = null; snapX = false;
		showMeasure( false );
		if ( moved ) { commit(); } else { render(); }
	}
	stage.addEventListener( 'pointerup', endDrag );
	stage.addEventListener( 'pointercancel', endDrag );
	stage.addEventListener( 'keydown', function ( e ) {
		var l = selected(); if ( ! l || l.pos !== activePos() ) { return; }
		var st = e.shiftKey ? 1 : 0.1, k = { ArrowLeft: [ -st, 0 ], ArrowRight: [ st, 0 ], ArrowUp: [ 0, -st ], ArrowDown: [ 0, st ] }[ e.key ];
		if ( k ) { l.dx += k[ 0 ]; l.dy += k[ 1 ]; e.preventDefault(); update( false ); commitSoon(); }
		if ( e.key === 'Delete' || e.key === 'Backspace' ) { removeLayer( l ); e.preventDefault(); }
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.target.matches( 'input, textarea, select' ) ) { return; }
		if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'z' ) { e.preventDefault(); e.shiftKey ? redo() : undo(); }
		if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'y' ) { e.preventDefault(); redo(); }
	} );

	/* ---------- uploads ---------- */
	var fileIn = $( '[data-file]' );
	var replaceId = null;
	$$( '[data-upload-btn]' ).forEach( function ( b ) { b.addEventListener( 'click', function () { replaceId = null; fileIn.multiple = true; fileIn.click(); } ); } );
	function replaceImage( l ) { replaceId = l.id; fileIn.multiple = false; fileIn.click(); }
	fileIn.addEventListener( 'change', function () { handleFiles( fileIn.files ); fileIn.value = ''; } );
	var drop = $( '[data-drop]' );
	[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
		stage.addEventListener( ev, function ( e ) { e.preventDefault(); drop.hidden = false; } );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( ev ) {
		stage.addEventListener( ev, function ( e ) { e.preventDefault(); drop.hidden = true; } );
	} );
	stage.addEventListener( 'drop', function ( e ) { if ( e.dataTransfer && e.dataTransfer.files ) { handleFiles( e.dataTransfer.files ); } } );

	function handleFiles( list ) {
		Array.prototype.forEach.call( list, function ( f ) {
			if ( ! /^image\/(png|jpeg|webp|svg\+xml)$/.test( f.type ) ) { toast( f.name + ': please use PNG, JPG, WEBP or SVG.' ); return; }
			if ( f.size > 40 * 1024 * 1024 ) { toast( f.name + ' is larger than 40 MB.' ); return; }
			var url = URL.createObjectURL( f ), img = new Image();
			img.onload = function () {
				var isSvg = f.type === 'image/svg+xml';
				var nw = img.naturalWidth || 1000, nh = img.naturalHeight || 1000;
				if ( isSvg ) { var sc = 3000 / Math.max( nw, nh ); nw = Math.round( nw * sc ); nh = Math.round( nh * sc ); }
				var disp = rasterise( img, nw, nh, 1800 );
				var a = { id: uid(), img: disp, w: nw, h: nh, name: f.name, mime: f.type, file: isSvg ? null : f, svgImg: isSvg ? img : null };
				assets[ a.id ] = a;
				var white = f.type === 'image/jpeg' && whiteCorners( disp );
				var target = replaceId && state.layers.filter( function ( x ) { return x.id === replaceId; } )[ 0 ];
				if ( target ) {
					// Swap the artwork, keep its place, size and rotation.
					replaceId = null;
					target.kind = 'image'; target.asset = a.id; target.bg = white ? newBg( [ '#ffffff' ] ) : null; delete target.t; delete target.white;
					select( target.id ); commit();
					toast( 'Image replaced.' );
				} else {
					addImageLayer( a, white );
				}
			};
			img.onerror = function () { toast( 'Could not read ' + f.name + '.' ); };
			img.src = url;
		} );
	}
	function rasterise( img, w, h, max ) {
		var k = Math.min( 1, max / Math.max( w, h ) ), c = document.createElement( 'canvas' );
		c.width = Math.max( 1, Math.round( w * k ) ); c.height = Math.max( 1, Math.round( h * k ) );
		var x = c.getContext( '2d' ); x.imageSmoothingQuality = 'high'; x.drawImage( img, 0, 0, c.width, c.height );
		return c;
	}
	function whiteCorners( c ) {
		var x = c.getContext( '2d' ), pts = [ [ 2, 2 ], [ c.width - 3, 2 ], [ 2, c.height - 3 ], [ c.width - 3, c.height - 3 ] ];
		return pts.every( function ( p ) { var d = x.getImageData( p[ 0 ], p[ 1 ], 1, 1 ).data; return Math.min( d[ 0 ], d[ 1 ], d[ 2 ] ) > 235; } );
	}
	function defaultPlacement( l ) {
		var z = zone( l.pos ), asp = aspect( l ), sleeve = l.pos === 'left' || l.pos === 'right';
		var w = sleeve ? Math.min( z.wi * 0.75, z.hi * 0.75 / asp ) : Math.min( l.kind === 'text' ? 10 : 9, z.hi * 0.6 / asp );
		l.w = Math.max( 1, w ); l.dx = 0;
		l.dy = sleeve ? 0 : -z.hi / 2 + ( l.pos === 'back' ? 1.2 : 1.5 ) + l.w * asp / 2;
	}
	function addImageLayer( a, white ) {
		var l = { id: uid(), pos: activePos(), kind: 'image', asset: a.id, dx: 0, dy: 0, w: 6, rot: 0, flip: false, op: 1, bg: white ? newBg( [ '#ffffff' ] ) : null };
		defaultPlacement( l );
		state.layers.push( l ); select( l.id ); commit();
		if ( white ) { toast( 'White background removed — change it under “Remove background”.' ); }
	}
	$( '[data-add-text]' ).addEventListener( 'click', function () {
		var dark = lum( colour( product( state.product ), state.colour ).hex ) < 0.55;
		var l = { id: uid(), pos: activePos(), kind: 'text', t: { text: 'YOUR TEXT', font: '900|Archivo', colour: dark ? '#ffffff' : '#111111', outline: 'none', case: 'none' },
			dx: 0, dy: 0, w: 8, rot: 0, flip: false, op: 1 };
		defaultPlacement( l );
		state.layers.push( l ); select( l.id ); commit();
		setTimeout( function () { var t = $( '[data-tx="text"]' ); if ( t ) { t.focus(); t.select(); } }, 50 );
	} );

	/* ---------- layer list & editor ---------- */
	function select( id ) { sel = id; renderPanel(); render(); }
	function layerName( l ) {
		if ( l.kind === 'text' ) { return '“' + ( l.t.text.split( '\n' )[ 0 ].slice( 0, 22 ) || 'Text' ) + '”'; }
		var a = assets[ l.asset ]; return a ? a.name : 'Image';
	}
	function removeLayer( l ) {
		state.layers.splice( state.layers.indexOf( l ), 1 );
		if ( sel === l.id ) { sel = null; }
		commit();
	}
	function renderLayers() {
		var ul = $( '[data-layers]' ), pos = activePos();
		var list = state.layers.filter( function ( l ) { return l.pos === pos; } );
		ul.innerHTML = list.map( function ( l ) {
			return '<li class="' + ( l.id === sel ? 'is-active' : '' ) + '" data-id="' + l.id + '">' +
				'<button type="button" class="ds-layer" data-pick><canvas width="44" height="44"></canvas><span>' + esc( layerName( l ) ) + '<small>' + inch( l.w ) + ' × ' + inch( l.w * aspect( l ) ) + ' in</small></span></button>' +
				( l.kind === 'image' ? '<button type="button" class="ds-mini" data-rep title="Replace image" aria-label="Replace image">↻</button>' : '' ) +
				'<button type="button" class="ds-mini" data-up title="Bring forward" aria-label="Bring forward">▲</button>' +
				'<button type="button" class="ds-mini" data-down title="Send backward" aria-label="Send backward">▼</button>' +
				'<button type="button" class="ds-mini danger" data-del title="Delete" aria-label="Delete">✕</button></li>';
		} ).reverse().join( '' );
		$$( 'li', ul ).forEach( function ( li ) {
			var l = state.layers.filter( function ( x ) { return x.id === li.getAttribute( 'data-id' ); } )[ 0 ];
			var cv = $( 'canvas', li ), x = cv.getContext( '2d' ), s = source( l );
			if ( s ) { var k = Math.min( 44 / s.width, 44 / s.height ); x.drawImage( s, ( 44 - s.width * k ) / 2, ( 44 - s.height * k ) / 2, s.width * k, s.height * k ); }
			li.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( 'button' ); if ( ! b ) { return; }
				var i = state.layers.indexOf( l );
				if ( b.hasAttribute( 'data-pick' ) ) { select( l.id ); }
				else if ( b.hasAttribute( 'data-del' ) ) { removeLayer( l ); }
				else if ( b.hasAttribute( 'data-rep' ) ) { replaceImage( l ); }
				else if ( b.hasAttribute( 'data-up' ) && i < state.layers.length - 1 ) { state.layers.splice( i, 1 ); state.layers.splice( i + 1, 0, l ); commit(); }
				else if ( b.hasAttribute( 'data-down' ) && i > 0 ) { state.layers.splice( i, 1 ); state.layers.splice( i - 1, 0, l ); commit(); }
			} );
		} );
	}

	var ed = $( '[data-editor]' );
	function renderEditor() {
		var l = selected();
		ed.hidden = ! l || l.pos !== activePos();
		if ( ed.hidden ) { return; }
		var z = zone( l.pos ), sz = $( '[data-size]' );
		$( '[data-edit-name]' ).textContent = layerName( l );
		sz.max = Math.ceil( z.wi * 1.5 ); sz.value = l.w;
		$( '[data-size-out]' ).textContent = inch( l.w ) + ' × ' + inch( l.w * aspect( l ) ) + ' in';
		$( '[data-rot]' ).value = l.rot; $( '[data-rot-out]' ).textContent = l.rot + '°';
		$( '[data-bg-wrap]' ).hidden = l.kind !== 'image';
		$( '[data-replace-btn]' ).hidden = l.kind !== 'image';
		if ( l.kind === 'image' ) { renderBg( l ); }
		var other = { front: 'back', back: 'front', left: 'right', right: 'left' }[ l.pos ];
		$( '[data-copy-label]' ).textContent = 'Copy to ' + POS_LABEL[ other ].toLowerCase();
		var te = $( '[data-text-edit]' ); te.hidden = l.kind !== 'text';
		if ( l.kind === 'text' ) {
			var ta = $( '[data-tx="text"]' ); if ( document.activeElement !== ta ) { ta.value = l.t.text; }
			$( '[data-tx="font"]' ).value = l.t.font; $( '[data-tx="case"]' ).value = l.t.case;
			swatches( $( '[data-tx-colours]' ), TEXT_COLOURS, l.t.colour, false );
			swatches( $( '[data-tx-outline]' ), [ 'none' ].concat( TEXT_COLOURS ), l.t.outline, true );
		}
		$( '[data-presets]' ).innerHTML = PRESETS[ l.pos ].map( function ( p, i ) { return '<button type="button" data-preset="' + i + '">' + esc( p[ 0 ] ) + '</button>'; } ).join( '' );
		var q = $( '[data-quality]' );
		if ( l.kind === 'image' ) {
			var a = assets[ l.asset ], dpi = a ? a.w / l.w : 300;
			q.className = 'ds-quality ' + ( dpi >= 200 ? 'ok' : dpi >= 120 ? 'warn' : 'bad' );
			q.textContent = dpi >= 200 ? 'Print quality: great (' + Math.round( dpi ) + ' DPI)' :
				dpi >= 120 ? 'Print quality: OK (' + Math.round( dpi ) + ' DPI) — a larger file will look sharper.' :
				'Low resolution (' + Math.round( dpi ) + ' DPI) — this may print blurry. Please upload a bigger file.';
		} else { q.className = 'ds-quality ok'; q.textContent = 'Text prints crisp at any size.'; }
	}
	function swatches( el, list, current, outline ) {
		el.innerHTML = list.map( function ( c ) {
			return '<button type="button" class="ds-sw' + ( c === current ? ' is-active' : '' ) + ( c === 'none' ? ' none' : '' ) + '" data-sw="' + c + '" style="--sw:' + ( c === 'none' ? 'transparent' : c ) + '" title="' + ( c === 'none' ? 'No outline' : c ) + '"></button>';
		} ).join( '' ) + ( outline ? '' : '<label class="ds-sw custom" title="Custom colour"><input type="color" value="' + ( current && current[ 0 ] === '#' ? current : '#111111' ) + '" data-sw-custom></label>' );
	}
	ed.addEventListener( 'input', function ( e ) {
		var l = selected(); if ( ! l ) { return; }
		var t = e.target;
		if ( t.matches( '[data-size]' ) ) { l.w = parseFloat( t.value ); }
		else if ( t.matches( '[data-rot]' ) ) { l.rot = parseInt( t.value, 10 ); }
		else if ( t.matches( '[data-tx="text"]' ) ) { l.t.text = t.value || ' '; }
		else if ( t.matches( '[data-sw-custom]' ) ) { l.t.colour = t.value; }
		else if ( t.matches( '[data-bg-tol]' ) ) {
			( l.bg || ( l.bg = newBg() ) ).tol = parseInt( t.value, 10 );
			$( '[data-bg-tol-out]' ).textContent = l.bg.tol;
			clearTimeout( bgTimer ); bgTimer = setTimeout( function () { update( false ); renderBg( l ); }, 90 );
			return;
		}
		else { return; }
		update( false ); commitSoon();
	} );
	ed.addEventListener( 'change', function ( e ) {
		var l = selected(); if ( ! l ) { return; }
		var t = e.target;
		if ( t.matches( '[data-tx="font"]' ) ) { l.t.font = t.value; loadFont( t.value ); }
		else if ( t.matches( '[data-tx="case"]' ) ) { l.t.case = t.value; }
		else if ( t.matches( '[data-bg-edge]' ) ) { ( l.bg || ( l.bg = newBg() ) ).edge = t.checked; }
		else if ( t.matches( '[data-bg-tol]' ) ) { ( l.bg || ( l.bg = newBg() ) ).tol = parseInt( t.value, 10 ); }
		else { return; }
		commit();
	} );
	/* remove-background panel */
	var bgTimer = 0, bgCv = $( '[data-bg-canvas]' );
	function renderBg( l ) {
		var a = assets[ l.asset ]; if ( ! a ) { return; }
		var bg = bgOf( l ), cur = l.bg || newBg();
		$( '[data-bg-keys]' ).innerHTML = cur.keys.length
			? cur.keys.map( function ( k, i ) { return '<button type="button" class="ds-bg-key" data-bg-del="' + i + '" style="--sw:' + k + '" title="Keep ' + k + '" aria-label="Keep colour ' + k + '"><i></i>' + k + ' <b>×</b></button>'; } ).join( '' )
			: '<span class="ds-muted">No colours removed yet.</span>';
		$( '[data-bg-tol]' ).value = cur.tol; $( '[data-bg-tol-out]' ).textContent = cur.tol;
		$( '[data-bg-edge]' ).checked = cur.edge !== false;
		var src = bg ? keyedCanvas( a, bg ) : a.img, max = 520, k = Math.min( max / src.width, max / src.height );
		bgCv.width = Math.max( 1, Math.round( src.width * k ) ); bgCv.height = Math.max( 1, Math.round( src.height * k ) );
		var x = bgCv.getContext( '2d' ), sq = 12;
		for ( var yy = 0; yy < bgCv.height; yy += sq ) { for ( var xx = 0; xx < bgCv.width; xx += sq ) { x.fillStyle = ( ( xx + yy ) / sq ) % 2 ? '#e6e6e6' : '#ffffff'; x.fillRect( xx, yy, sq, sq ); } }
		x.drawImage( src, 0, 0, bgCv.width, bgCv.height );
	}
	bgCv.addEventListener( 'click', function ( e ) {
		var l = selected(); if ( ! l || l.kind !== 'image' ) { return; }
		var a = assets[ l.asset ], r = bgCv.getBoundingClientRect();
		var hex = sampleColour( a, ( e.clientX - r.left ) / r.width, ( e.clientY - r.top ) / r.height );
		if ( ! hex ) { toast( 'That part is already transparent.' ); return; }
		if ( addKeys( l, [ hex ] ) ) { commit(); toast( 'Removed ' + hex + '. Tap another colour to remove more.' ); }
		else { toast( 'That colour is already removed — raise Strength to remove more of it.' ); }
	} );
	function downloadNoBg( l ) {
		var a = assets[ l.asset ], bg = bgOf( l );
		if ( ! bg ) { toast( 'Tap a colour in the picture first.' ); return; }
		toast( 'Preparing full-size PNG…' );
		fullKeyed( a, bg ).then( function ( c ) { return toBlob( c, 'image/png' ).then( function ( b ) { return { b: b, w: c.width, h: c.height }; } ); } ).then( function ( r ) {
			var link = document.createElement( 'a' ); link.href = URL.createObjectURL( r.b );
			link.download = a.name.replace( /\.\w+$/, '' ) + '-no-background.png';
			document.body.appendChild( link ); link.click(); link.remove();
			setTimeout( function () { URL.revokeObjectURL( link.href ); }, 4000 );
			toast( 'Downloaded ' + r.w + ' × ' + r.h + ' px PNG.' );
		} ).catch( function () { toast( 'Could not create the PNG on this device.' ); } );
	}
	ed.addEventListener( 'click', function ( e ) {
		var l = selected(), b = e.target.closest( 'button' ); if ( ! l || ! b ) { return; }
		if ( b.hasAttribute( 'data-bg-del' ) ) { l.bg.keys.splice( +b.getAttribute( 'data-bg-del' ), 1 ); commit(); return; }
		if ( b.hasAttribute( 'data-bg' ) ) {
			var act0 = b.getAttribute( 'data-bg' ), a0 = assets[ l.asset ];
			if ( act0 === 'download' ) { downloadNoBg( l ); return; }
			if ( act0 === 'clear' ) { l.bg = null; commit(); return; }
			var list = act0 === 'white' ? [ '#ffffff' ] : act0 === 'black' ? [ '#000000' ] : borderColours( a0 );
			if ( ! list.length ) { toast( 'No plain background found — tap the colour in the picture instead.' ); return; }
			if ( act0 === 'auto' && l.bg ) { l.bg.edge = true; }
			addKeys( l, list ) ? commit() : toast( 'Already removed.' );
			return;
		}
		var z = zone( l.pos ), asp = aspect( l );
		if ( b.hasAttribute( 'data-sw' ) ) {
			if ( b.closest( '[data-tx-outline]' ) ) { l.t.outline = b.getAttribute( 'data-sw' ); } else { l.t.colour = b.getAttribute( 'data-sw' ); }
		} else if ( b.hasAttribute( 'data-preset' ) ) {
			var p = PRESETS[ l.pos ][ +b.getAttribute( 'data-preset' ) ];
			l.rot = 0;
			// Size the printed part (transparent margins excluded): fit it inside the print box, upright or
			// turned, or give it the preset width. Then place its top edge p[2] inches below the zone top.
			var src = source( l ), ink = src ? inkOf( src ) : { u0: 0, v0: 0, u1: 1, v1: 1 };
			var fu = Math.max( 0.01, ink.u1 - ink.u0 ), fv = Math.max( 0.01, ( ink.v1 - ink.v0 ) * asp );
			if ( p[ 4 ] ) {
				var W = p[ 4 ][ 0 ], H = p[ 4 ][ 1 ];
				l.w = Math.max( Math.min( W / fu, H / fv ), Math.min( H / fu, W / fv ) );
			} else { l.w = p[ 3 ] / fu; }
			l.w = Math.min( l.w, ( z.wi - 0.2 ) / fu, ( z.hi - 0.2 ) / fv );
			l.dy = -z.hi / 2 + p[ 2 ] - ( ink.v0 - 0.5 ) * l.w * asp;
			l.dx = p[ 1 ] - ( ( ink.u0 + ink.u1 ) / 2 - 0.5 ) * ( l.flip ? -1 : 1 ) * l.w;
		} else {
			var act = b.getAttribute( 'data-do' );
			if ( act === 'centre' ) { l.dx = 0; if ( l.pos === 'left' || l.pos === 'right' ) { l.dy = 0; } }
			else if ( act === 'flip' ) { l.flip = ! l.flip; }
			else if ( act === 'fit' ) { l.rot = 0; l.w = Math.min( z.wi - 0.2, ( z.hi - 0.2 ) / asp ); l.dx = 0; l.dy = 0; }
			else if ( act === 'dup' ) { var c = JSON.parse( JSON.stringify( l ) ); c.id = uid(); c.dx += 0.6; c.dy += 0.6; state.layers.push( c ); sel = c.id; }
			else if ( act === 'copyback' ) {
				var other = { front: 'back', back: 'front', left: 'right', right: 'left' }[ l.pos ];
				var d = JSON.parse( JSON.stringify( l ) ); d.id = uid(); d.pos = other;
				if ( other === 'left' || other === 'right' ) { d.dx = -d.dx; }
				var zo = zone( other ); d.w = Math.min( d.w, zo.wi, zo.hi / asp );
				state.layers.push( d ); toast( 'Copied to ' + POS_LABEL[ other ].toLowerCase() + '.' );
			} else if ( act === 'delete' ) { removeLayer( l ); return; }
			else if ( act === 'replace' ) { replaceImage( l ); return; }
			else { return; }
		}
		commit();
	} );
	function loadFont( f ) {
		if ( ! document.fonts || ! document.fonts.load ) { return; }
		var p = f.split( '|' );
		document.fonts.load( p[ 0 ] + ' 40px "' + p[ 1 ] + '"' ).then( function () { textCache = {}; update( false ); } );
	}
	[ '900|Archivo', '700|Archivo', '600|Inter', '400|Allura' ].forEach( loadFont );

	/* ---------- product / colour / views ---------- */
	function renderProducts() {
		var p = product( state.product );
		$( '[data-products]' ).innerHTML = CFG.products.map( function ( pp ) {
			var c = colour( pp, pp.display );
			return '<button type="button" role="radio" aria-checked="' + ( pp.id === p.id ) + '" class="ds-prod' + ( pp.id === p.id ? ' is-active' : '' ) + '" data-prod="' + pp.id + '">' +
				'<img src="' + esc( c.front ) + '" alt="" loading="lazy"><span><strong>' + esc( pp.name ) + '</strong><small>' + esc( pp.spec ) + '</small><em>from ' + rupee( pp.from ) + '</em></span></button>';
		} ).join( '' );
		$( '[data-colours]' ).innerHTML = p.colours.map( function ( c ) {
			return '<button type="button" role="radio" aria-checked="' + ( c.name === state.colour ) + '" class="ds-col' + ( c.name === state.colour ? ' is-active' : '' ) + '" data-col="' + esc( c.name ) + '" style="--sw:' + c.hex + '" title="' + esc( c.name ) + '"><span></span>' + esc( c.name ) + '</button>';
		} ).join( '' );
		$( '[data-colour-name]' ).textContent = state.colour;
	}
	$( '[data-products]' ).addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-prod]' ); if ( ! b ) { return; }
		state.product = b.getAttribute( 'data-prod' );
		var p = product( state.product );
		if ( ! p.colours.some( function ( c ) { return c.name === state.colour; } ) ) { state.colour = p.display; }
		keepTextVisible(); commit();
	} );
	$( '[data-colours]' ).addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-col]' ); if ( ! b ) { return; }
		state.colour = b.getAttribute( 'data-col' ); keepTextVisible(); commit();
	} );
	// Black or white text that would vanish on the new t-shirt colour flips to the other one.
	function keepTextVisible( quiet ) {
		var shirt = lum( colour( product( state.product ), state.colour ).hex ), n = 0;
		state.layers.forEach( function ( l ) {
			if ( l.kind !== 'text' || Math.abs( lum( l.t.colour ) - shirt ) > 0.3 ) { return; }
			var c = l.t.colour.toLowerCase();
			if ( c === '#111111' || c === '#000000' ) { l.t = Object.assign( {}, l.t, { colour: '#ffffff' } ); n++; }
			else if ( c === '#ffffff' ) { l.t = Object.assign( {}, l.t, { colour: '#111111' } ); n++; }
		} );
		if ( n && ! quiet ) { toast( 'Text colour switched so it shows on ' + state.colour.toLowerCase() + '.' ); }
	}
	$$( '[data-view]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () { setView( b.getAttribute( 'data-view' ) ); } );
	} );
	function setView( v ) {
		state.view = v;
		var l = selected(); if ( l && l.pos !== v ) { sel = null; }
		renderPanel(); render(); save();
	}
	$( '[data-guides]' ).addEventListener( 'change', render );

	/* ---------- thumbnails of all views ---------- */
	var thumbTimer = 0;
	function thumbsSoon() { clearTimeout( thumbTimer ); thumbTimer = setTimeout( renderThumbs, 120 ); }
	function renderThumbs() {
		var wrap = $( '[data-thumbs]' );
		if ( ! wrap.children.length ) {
			wrap.innerHTML = POS.map( function ( v ) { return '<button type="button" class="ds-thumb" data-thumb="' + v + '"><canvas width="150" height="170"></canvas><span>' + POS_LABEL[ v ] + '</span></button>'; } ).join( '' );
			$$( '[data-thumb]', wrap ).forEach( function ( b ) { b.addEventListener( 'click', function () { setView( b.getAttribute( 'data-thumb' ) ); } ); } );
		}
		$$( '[data-thumb]', wrap ).forEach( function ( b ) {
			var v = b.getAttribute( 'data-thumb' );
			b.classList.toggle( 'is-active', v === state.view );
			var cv = $( 'canvas', b ); draw( cv.getContext( '2d' ), v, 150, 170, { guides: false } );
		} );
	}

	/* ---------- pricing ---------- */
	function qty() { return CFG.sizes.reduce( function ( s, z ) { return s + ( state.sizes[ z ] || 0 ); }, 0 ); }
	function fits( b, w, h ) { return ( b.w <= w + 0.01 && b.h <= h + 0.01 ) || ( b.w <= h + 0.01 && b.h <= w + 0.01 ); }
	// Logo = up to about 3 × 3 in, or a small strip such as a text logo (max 4.5 in long, 10.5 sq in).
	function sizeClass( b ) {
		if ( fits( b, 3.2, 3.2 ) || ( Math.max( b.w, b.h ) <= 4.5 + 0.01 && b.w * b.h <= 10.5 ) ) { return 'logo'; }
		if ( fits( b, 4.15, 5.85 ) ) { return 'a6'; }
		if ( fits( b, 5.85, 8.3 ) ) { return 'a5'; }
		if ( fits( b, 8.3, 11.7 ) ) { return 'a4'; }
		if ( fits( b, 11.7, 16.5 ) ) { return 'a3'; }
		if ( fits( b, 16.5, 23.4 ) ) { return 'a2'; }
		return 'xl';
	}
	// Rough stitch count: outline/underlay over the piece plus fill over the inked area.
	function autoStitches( b ) { return Math.max( 2000, Math.round( ( b.w * b.h * 400 + b.ink * 1200 ) / 500 ) * 500 ); }
	var DTF_NAME = { logo: 'Logo DTF', a6: 'A6 DTF', a5: 'A5 DTF', a4: 'A4 DTF', a3: 'A3 DTF', a2: 'A2 DTF', xl: 'Custom DTF (over A2)' };
	function printName( pos ) {
		var g = pieces( pos ); if ( ! g.length ) { return ''; }
		if ( state.method[ pos ] === 'emb' ) { return 'Embroidery'; }
		return g.map( function ( b ) { return DTF_NAME[ sizeClass( b ) ]; } ).join( ' + ' );
	}
	function piecesText( l ) {
		return l.pieces.map( function ( pc ) { return ( l.pieces.length > 1 ? pc.name + ' ' : '' ) + inch( pc.b.w ) + ' × ' + inch( pc.b.h ) + ' in'; } ).join( ' + ' );
	}
	// Smallest order size above q where the per-piece price drops (t-shirt tier, DTF 10+ rate or embroidery rate).
	function bulkHint( Q ) {
		if ( ! Q.q ) { return null; }
		var c = {}, next = null, best = null;
		Q.p.prices.forEach( function ( t ) { if ( t.min > Q.q ) { c[ t.min ] = 1; } } );
		CFG.embroidery.forEach( function ( r ) { if ( r.min > Q.q ) { c[ r.min ] = 1; } } );
		if ( Q.q < 10 ) { c[ 10 ] = 1; }
		Object.keys( c ).map( Number ).sort( function ( a, b ) { return a - b; } ).forEach( function ( n ) {
			var N = quote( n );
			if ( N.per < Q.per && ! next ) { next = { n: n, add: n - Q.q, per: N.per }; }
			if ( N.per < Q.per && ( ! best || N.per < best.per ) ) { best = { n: n, per: N.per }; }
		} );
		return { next: next, best: best && next && best.per < next.per ? best : null };
	}
	function renderHint( Q ) {
		var H = bulkHint( Q ), html = '';
		if ( H && H.next ) {
			html = '<span class="ds-hint-ico" aria-hidden="true">%</span><span>Add <b>' + H.next.add + ' more</b> (' + H.next.n + ' pcs) and pay <b>' + rupee( H.next.per ) + '/pc</b> instead of ' + rupee( Q.per ) +
				' — you save ' + rupee( Q.per - H.next.per ) + ' on every piece.' +
				( H.best ? '<small>Best price: ' + rupee( H.best.per ) + '/pc from ' + H.best.n + ' pcs.</small>' : '' ) +
				'</span><button type="button" class="ds-hint-add" data-hint-add="' + H.next.add + '">+' + H.next.add + ' pcs</button>';
		} else if ( Q.q >= 10 ) {
			html = '<span class="ds-hint-ico ok" aria-hidden="true">✓</span><span>You are getting our <b>best bulk price</b> for this design.</span>';
		}
		$$( '[data-q-hint]' ).forEach( function ( el ) { el.innerHTML = html; el.hidden = ! html; } );
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-hint-add]' ); if ( ! b || ! root.contains( b ) ) { return; }
		// Add the extra pieces to the size the customer ordered most of (M when none yet).
		var sz = CFG.sizes.reduce( function ( m, z ) { return ( state.sizes[ z ] || 0 ) > ( state.sizes[ m ] || 0 ) ? z : m; }, 'M' );
		state.sizes[ sz ] = ( state.sizes[ sz ] || 0 ) + parseInt( b.getAttribute( 'data-hint-add' ), 10 );
		commit(); toast( 'Added ' + b.getAttribute( 'data-hint-add' ) + ' × ' + sz + '. Change sizes in step 4.' );
	} );
	function quote( qOverride ) {
		var p = product( state.product ), q = qOverride === undefined ? qty() : qOverride, tee = tier( p.prices, Math.max( 1, q ) );
		var embRate = CFG.embroidery[ 0 ].rate;
		CFG.embroidery.forEach( function ( r ) { if ( q >= r.min ) { embRate = r.rate; } } );
		var prints = [], big = false, custom = false;
		POS.forEach( function ( pos ) {
			var g = pieces( pos ); if ( ! g.length ) { return; }
			var line = { pos: pos, b: g.reduce( unite ), method: state.method[ pos ] };
			if ( line.method === 'emb' ) {
				line.pieces = g.map( function ( b ) { return { b: b, name: 'Embroidery', stitches: autoStitches( b ) }; } );
				line.stitches = state.stitches[ pos ] || line.pieces.reduce( function ( s, pc ) { return s + pc.stitches; }, 0 );
				line.price = Math.round( line.stitches / 1000 * embRate );
				line.name = 'Embroidery · ' + line.stitches.toLocaleString( 'en-IN' ) + ' stitches';
			} else {
				line.pieces = g.map( function ( b ) {
					var k = sizeClass( b ), d = BASE.dtf[ k === 'xl' ? 'a2' : k ];
					if ( /a[234]|xl/.test( k ) ) { big = true; }
					if ( k === 'xl' ) { custom = true; }
					return { b: b, size: k, name: DTF_NAME[ k ], price: d ? ( q >= 10 ? d.bulk : d.single ) : 0 };
				} );
				line.price = line.pieces.reduce( function ( s, pc ) { return s + pc.price; }, 0 );
				line.name = line.pieces.length === 1 ? line.pieces[ 0 ].name : line.pieces.length + ' DTF pieces';
			}
			prints.push( line );
		} );
		var per = tee + prints.reduce( function ( s, l ) { return s + l.price; }, 0 );
		var sub = per * q, gst = sub * BASE.gst / 100;
		return { p: p, q: q, tee: tee, prints: prints, per: per, sub: sub, gst: gst, total: sub + gst, freeLabel: big, custom: custom };
	}

	function renderQuote() {
		var Q = quote();
		$( '[data-q-lines]' ).innerHTML =
			'<li><span>' + esc( Q.p.name + ' ' + Q.p.gsm ) + ' · ' + esc( state.colour ) + '</span><b>' + rupee( Q.tee ) + '</b></li>' +
			Q.prints.map( function ( l ) {
				return '<li><span>' + POS_LABEL[ l.pos ] + ' · ' + esc( l.name ) + '<small>' + esc( piecesText( l ) ) + '</small></span><b>+' + rupee( l.price ) + '</b></li>';
			} ).join( '' ) +
			( state.label ? '<li><span>Neck label (your brand)' + ( Q.freeLabel ? '' : '<small>Free with an A2/A3/A4 print — otherwise we confirm the price</small>' ) + '</span><b>' + ( Q.freeLabel ? 'FREE' : '—' ) + '</b></li>' : '' ) +
			( Q.custom ? '<li class="warn"><span>Print larger than A2 — we will confirm the price.</span></li>' : '' );
		$( '[data-q-per]' ).textContent = rupee( Q.per );
		renderHint( Q );
		$( '[data-q-sub-label]' ).textContent = 'Subtotal (' + Q.q + ' pc' + ( Q.q === 1 ? '' : 's' ) + ')';
		$( '[data-q-sub]' ).textContent = rupee( Q.sub );
		$( '[data-q-gst]' ).textContent = rupee( Q.gst );
		$( '[data-q-total]' ).textContent = rupee( Q.total );
		$( '[data-m-info]' ).textContent = Q.q + ' pcs · incl. GST · shipping extra';
		$( '[data-m-total]' ).textContent = rupee( Q.total );
		$( '[data-qty-total]' ).textContent = Q.q + ' pcs';
		$( '[data-label-note]' ).textContent = Q.freeLabel ? '(free with your print)' : '(free with an A2/A3/A4 print)';
		POS.forEach( function ( pos ) {
			var n = state.layers.filter( function ( l ) { return l.pos === pos; } ).length;
			var b = $( '[data-count="' + pos + '"]' ); b.textContent = n || ''; b.hidden = ! n;
		} );
		return Q;
	}

	function renderMethods() {
		var el = $( '[data-methods]' ), used = POS.filter( function ( p ) { return bbox( p ); } );
		if ( ! used.length ) { el.innerHTML = '<p class="ds-muted">Add a design to choose DTF print or embroidery.</p>'; return; }
		var Q = quote();
		el.innerHTML = used.map( function ( pos ) {
			var b = bbox( pos ), m = state.method[ pos ];
			var line = Q.prints.filter( function ( l ) { return l.pos === pos; } )[ 0 ];
			return '<div class="ds-method" data-mpos="' + pos + '"><div class="ds-method-head"><strong>' + POS_LABEL[ pos ] + '</strong><small>' + esc( piecesText( line ) ) + '</small></div>' +
				'<div class="ds-seg"><button type="button" data-m="dtf" class="' + ( m === 'dtf' ? 'is-active' : '' ) + '">DTF print</button><button type="button" data-m="emb" class="' + ( m === 'emb' ? 'is-active' : '' ) + '">Embroidery</button></div>' +
				( m === 'emb' ? '<label class="ds-stitch">Stitches <input type="number" min="500" step="500" value="' + line.stitches + '" data-st></label><small class="ds-muted">Auto-estimated from size — edit if you know it. Digitizing charged extra.</small>' : '' ) +
				'</div>';
		} ).join( '' );
	}
	$( '[data-methods]' ).addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-m]' ); if ( ! b ) { return; }
		state.method[ b.closest( '[data-mpos]' ).getAttribute( 'data-mpos' ) ] = b.getAttribute( 'data-m' ); commit();
	} );
	$( '[data-methods]' ).addEventListener( 'change', function ( e ) {
		if ( ! e.target.matches( '[data-st]' ) ) { return; }
		state.stitches[ e.target.closest( '[data-mpos]' ).getAttribute( 'data-mpos' ) ] = Math.max( 500, parseInt( e.target.value, 10 ) || 0 ); commit();
	} );

	function renderSizes() {
		var el = $( '[data-sizes]' );
		if ( ! el.children.length ) {
			el.innerHTML = CFG.sizes.map( function ( s ) {
				return '<div class="ds-size"><span>' + s + '</span><div><button type="button" data-ss="-1" data-s="' + s + '" aria-label="Fewer ' + s + '">−</button><input type="number" min="0" inputmode="numeric" data-si="' + s + '" aria-label="' + s + ' quantity"><button type="button" data-ss="1" data-s="' + s + '" aria-label="More ' + s + '">+</button></div></div>';
			} ).join( '' );
			el.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '[data-ss]' ); if ( ! b ) { return; }
				var s = b.getAttribute( 'data-s' ); state.sizes[ s ] = Math.max( 0, ( state.sizes[ s ] || 0 ) + parseInt( b.getAttribute( 'data-ss' ), 10 ) ); commit();
			} );
			el.addEventListener( 'input', function ( e ) {
				if ( ! e.target.matches( '[data-si]' ) ) { return; }
				state.sizes[ e.target.getAttribute( 'data-si' ) ] = Math.max( 0, parseInt( e.target.value, 10 ) || 0 ); update( false ); commitSoon();
			} );
		}
		CFG.sizes.forEach( function ( s ) {
			var i = $( '[data-si="' + s + '"]', el );
			if ( document.activeElement !== i ) { i.value = state.sizes[ s ] || ''; }
			i.placeholder = '0';
			i.closest( '.ds-size' ).classList.toggle( 'has', ( state.sizes[ s ] || 0 ) > 0 );
		} );
	}
	$( '[data-label-opt]' ).addEventListener( 'change', function ( e ) { state.label = e.target.checked; commit(); } );

	/* ---------- update / history / persistence ---------- */
	function renderPanel() {
		$$( '[data-view]' ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-view' ) === state.view;
			b.classList.toggle( 'is-active', on ); b.setAttribute( 'aria-selected', on );
		} );
		$( '[data-pos-label]' ).textContent = '· ' + POS_LABEL[ state.view ];
		$( '[data-label-opt]' ).checked = state.label;
		renderProducts(); renderLayers(); renderEditor(); renderMethods(); renderSizes(); renderQuote();
	}
	function update( full ) {
		if ( full !== false ) { renderPanel(); } else { renderEditor(); renderQuote(); renderSizes(); }
		render();
	}
	function snapshot() {
		return JSON.stringify( { product: state.product, colour: state.colour, layers: state.layers, method: state.method, stitches: state.stitches, sizes: state.sizes, label: state.label } );
	}
	var last = null, cTimer = 0;
	function commit() {
		var s = snapshot();
		if ( s !== last ) { if ( last !== null ) { history.push( last ); if ( history.length > 80 ) { history.shift(); } } future = []; last = s; }
		update(); save();
	}
	function commitSoon() { clearTimeout( cTimer ); cTimer = setTimeout( commit, 400 ); }
	function restore( s ) {
		var o = JSON.parse( s );
		Object.keys( o ).forEach( function ( k ) { state[ k ] = o[ k ]; } );
		state.layers = state.layers.filter( function ( l ) { return l.kind === 'text' || assets[ l.asset ]; } );
		if ( ! selected() ) { sel = null; }
		last = s; update(); save();
	}
	function undo() { if ( history.length ) { future.push( last ); restore( history.pop() ); } }
	function redo() { if ( future.length ) { history.push( last ); restore( future.pop() ); } }
	$( '[data-act="undo"]' ).addEventListener( 'click', undo );
	$( '[data-act="redo"]' ).addEventListener( 'click', redo );

	var saveTimer = 0;
	function save() {
		clearTimeout( saveTimer );
		saveTimer = setTimeout( function () {
			var used = {}, out = {};
			state.layers.forEach( function ( l ) { if ( l.asset ) { used[ l.asset ] = 1; } } );
			Object.keys( used ).forEach( function ( id ) {
				var a = assets[ id ]; if ( ! a ) { return; }
				if ( ! a.src ) { try { a.src = a.img.toDataURL( 'image/png' ); } catch ( e ) { a.src = ''; } }
				out[ id ] = { src: a.src, w: a.w, h: a.h, name: a.name, mime: a.mime };
			} );
			try { localStorage.setItem( KEY, JSON.stringify( { s: JSON.parse( snapshot() ), view: state.view, assets: out } ) ); }
			catch ( e ) { try { localStorage.setItem( KEY, JSON.stringify( { s: Object.assign( JSON.parse( snapshot() ), { layers: state.layers.filter( function ( l ) { return l.kind === 'text'; } ) } ), view: state.view, assets: {} } ) ); } catch ( e2 ) { /* storage full */ } }
		}, 600 );
	}
	function load( done ) {
		var d = null;
		try { d = JSON.parse( localStorage.getItem( KEY ) ); } catch ( e ) { d = null; }
		if ( ! d || ! d.s ) { done( false ); return; }
		var ids = Object.keys( d.assets || {} ), left = ids.length;
		function finish() {
			Object.keys( d.s ).forEach( function ( k ) { state[ k ] = d.s[ k ]; } );
			state.view = d.view || 'front';
			state.layers = state.layers.filter( function ( l ) { return POS.indexOf( l.pos ) >= 0 && ( l.kind === 'text' || assets[ l.asset ] ); } );
			if ( product( state.product ) ) { keepTextVisible( true ); }
			done( state.layers.length > 0 );
		}
		if ( ! left ) { finish(); return; }
		ids.forEach( function ( id ) {
			var a = d.assets[ id ], img = new Image();
			img.onload = function () { assets[ id ] = { id: id, img: rasterise( img, img.width, img.height, 1800 ), w: a.w, h: a.h, name: a.name, mime: a.mime, src: a.src, file: null }; if ( ! --left ) { finish(); } };
			img.onerror = function () { if ( ! --left ) { finish(); } };
			img.src = a.src;
		} );
	}
	$( '[data-reset]' ).addEventListener( 'click', function () {
		if ( ! window.confirm( 'Clear this design and start again?' ) ) { return; }
		state.layers = []; state.stitches = {}; sel = null; commit();
	} );

	/* ---------- export ---------- */
	function exportView( v, w ) {
		var h = Math.round( w * PH / PW ), c = document.createElement( 'canvas' );
		c.width = w; c.height = h;
		draw( c.getContext( '2d' ), v, w, h, { guides: false } );
		var x = c.getContext( '2d' ); x.setTransform( 1, 0, 0, 1, 0, 0 );
		x.font = '600 ' + Math.round( w / 48 ) + 'px Archivo, Arial'; x.fillStyle = 'rgba(11,20,38,.55)'; x.textAlign = 'right';
		x.fillText( 'LOOMA APPARELS · ' + POS_LABEL[ v ].toUpperCase(), w - w / 40, h - w / 40 );
		return c;
	}
	function toBlob( c, type, q ) { return new Promise( function ( res ) { c.toBlob( res, type, q ); } ); }
	$( '[data-act="download"]' ).addEventListener( 'click', function () {
		toBlob( exportView( state.view, 1200 ), 'image/png' ).then( function ( b ) {
			var a = document.createElement( 'a' ); a.href = URL.createObjectURL( b ); a.download = 'looma-mockup-' + state.view + '.png';
			document.body.appendChild( a ); a.click(); a.remove();
		} );
	} );

	function usedViews() {
		var v = [];
		if ( state.layers.some( function ( l ) { return l.pos !== 'back'; } ) || ! state.layers.length ) { v.push( 'front' ); }
		if ( state.layers.some( function ( l ) { return l.pos === 'back'; } ) ) { v.push( 'back' ); }
		return v;
	}
	function artworkBlobs() {
		var jobs = [], seen = {};
		state.layers.forEach( function ( l, i ) {
			if ( l.kind === 'text' ) {
				jobs.push( toBlob( textCanvas( l ), 'image/png' ).then( function ( b ) { return { b: b, n: 'text-' + ( i + 1 ) + '.png' }; } ) );
				return;
			}
			var a = assets[ l.asset ], bg = bgOf( l ), bkey = l.asset + JSON.stringify( bg );
			if ( bg && ! seen[ bkey ] ) {
				// The cut-out the customer made, at full resolution — ready to print.
				seen[ bkey ] = 1;
				jobs.push( fullKeyed( a, bg ).then( function ( c ) { return toBlob( c, 'image/png' ); } ).then( function ( b ) { return { b: b, n: a.name.replace( /\.\w+$/, '' ) + '-no-background.png' }; } ) );
			}
			if ( seen[ l.asset ] ) { return; } seen[ l.asset ] = 1;
			if ( a.file && a.file.size <= CFG.maxUpload ) { jobs.push( Promise.resolve( { b: a.file, n: a.name } ) ); return; }
			var src = a.svgImg ? rasterise( a.svgImg, a.w, a.h, 3000 ) : a.img;
			jobs.push( toBlob( src, 'image/png' ).then( function ( b ) { return { b: b, n: a.name.replace( /\.\w+$/, '' ) + '.png' }; } ) );
		} );
		return Promise.all( jobs );
	}
	function summary( Q, id ) {
		var lines = [];
		if ( id ) { lines.push( 'Design ID: ' + id ); }
		lines.push( 'T-shirt: ' + Q.p.name + ' ' + Q.p.spec + ' — ' + state.colour );
		if ( Q.prints.length ) {
			lines.push( 'Prints:' );
			Q.prints.forEach( function ( l ) {
				var names = state.layers.filter( function ( x ) { return x.pos === l.pos; } ).map( layerName ).join( ', ' );
				lines.push( '• ' + POS_LABEL[ l.pos ] + ': ' + l.name + ' — ' + piecesText( l ) + ' — ' + rupee( l.price ) + '/pc (' + names + ')' );
			} );
		} else { lines.push( 'Prints: none (plain t-shirt)' ); }
		lines.push( 'Sizes: ' + ( CFG.sizes.filter( function ( s ) { return state.sizes[ s ] > 0; } ).map( function ( s ) { return s + '×' + state.sizes[ s ]; } ).join( ', ' ) || '—' ) + ' (' + Q.q + ' pcs)' );
		if ( state.label ) { lines.push( 'Neck label: yes' + ( Q.freeLabel ? ' (free)' : '' ) ); }
		lines.push( 'Estimate: ' + rupee( Q.per ) + '/pc × ' + Q.q + ' = ' + rupee( Q.sub ) + ' + GST ' + rupee( Q.gst ) + ' = ' + rupee( Q.total ) );
		lines.push( 'Shipping charges extra.' );
		return lines.join( '\n' );
	}

	/* ---------- send to WhatsApp ---------- */
	var status = $( '[data-status]' );
	function setStatus( html, kind ) { status.hidden = ! html; status.className = 'ds-send-status ' + ( kind || '' ); status.innerHTML = html; }
	$( '[data-send]' ).addEventListener( 'click', function () {
		var Q = quote();
		if ( ! Q.q ) { setStatus( 'Please add at least 1 piece in sizes.', 'err' ); return; }
		var win = window.open( '', '_blank' );
		if ( win ) { win.document.write( '<p style="font:16px sans-serif;padding:24px">Preparing your design… please wait.</p>' ); }
		var btn = $( '[data-send]' ); btn.disabled = true;
		setStatus( '<span class="spin"></span> Preparing your mockups…', '' );
		var views = usedViews(), files = [];
		Promise.all( [
			fetch( CFG.ajax + '?action=looma_design_nonce', { credentials: 'same-origin' } ).then( function ( r ) { return r.json(); } ),
			Promise.all( views.map( function ( v ) { return toBlob( exportView( v, 1200 ), 'image/jpeg', 0.9 ).then( function ( b ) { return { b: b, n: 'looma-mockup-' + v + '.jpg' }; } ); } ) ),
			artworkBlobs()
		] ).then( function ( r ) {
			files = r[ 1 ].map( function ( f ) { return new File( [ f.b ], f.n, { type: 'image/jpeg' } ); } );
			var fd = new FormData();
			fd.append( 'action', 'looma_design_submit' ); fd.append( 'nonce', r[ 0 ].data.nonce );
			fd.append( 'summary', summary( Q ) ); fd.append( 'total', rupee( Q.total ) + ' incl. GST' );
			r[ 1 ].forEach( function ( f ) { fd.append( 'mockups[]', f.b, f.n ); } );
			// Stay inside the server limits (per file, per design, file count); anything left out is still sent on WhatsApp.
			var budget = 58 * 1024 * 1024 - r[ 1 ].reduce( function ( t, f ) { return t + f.b.size; }, 0 ), slots = 12 - r[ 1 ].length;
			r[ 2 ].forEach( function ( f ) {
				if ( slots > 0 && f.b && f.b.size <= CFG.maxUpload && f.b.size <= budget ) { fd.append( 'artwork[]', f.b, f.n ); budget -= f.b.size; slots--; }
			} );
			return fetch( CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( x ) { return x.json(); } );
		} ).then( function ( res ) {
			if ( ! res || ! res.success ) { throw new Error( res && res.data && res.data.message ? res.data.message : 'Upload failed' ); }
			var d = res.data;
			var msg = 'Hi Looma Apparels! I designed a t-shirt on your website.\n\n' +
				'Mockup images:\n' + d.mockups.join( '\n' ) + '\n\n' + summary( Q, d.id ) +
				( d.artwork.length ? '\n\nArtwork files (print-ready):\n' + d.artwork.join( '\n' ) : '' );

			var url = waUrl( msg );
			if ( win && ! win.closed ) { win.location.href = url; }
			sentPanel( d.id, url, files, ! win || win.closed );
		} ).catch( function ( err ) {
			var msg = 'Hi Looma Apparels! I designed a t-shirt on your website.\n\n' + summary( Q ) + '\n\n(Mockup images attached below.)';
			var url = waUrl( msg );
			if ( win && ! win.closed ) { win.location.href = url; }
			sentPanel( '', url, files, ! win || win.closed, err.message );
		} ).then( function () { btn.disabled = false; } );
	} );
	function waUrl( text ) { return 'https://wa.me/' + BASE.whatsapp + '?text=' + encodeURIComponent( text ); }

	// After sending: step 1 = details (WhatsApp chat with Looma), step 2 = the mockup images themselves.
	function sentPanel( id, url, files, blocked, error ) {
		var canShareFiles = files.length && navigator.canShare && navigator.canShare( { files: files } );
		var html = ( error
			? '<p class="ds-done-h">⚠ We could not save your files online (' + esc( error ) + '), but you can still send everything on WhatsApp:</p>'
			: '<p class="ds-done-h">✔ Design <b>' + esc( id ) + '</b> saved. Send it to Looma in 2 steps:</p>' ) +
			'<ol class="ds-steps">' +
			'<li><span><b>Send your order details</b>' + ( blocked ? '' : ' — WhatsApp has opened with your message. Just press send.' ) + '</span>' +
				'<a class="btn btn-wa btn-sm" target="_blank" rel="noopener" href="' + url + '">' + ( blocked ? 'Open WhatsApp' : 'Open again' ) + '</a></li>' +
			'<li><span><b>Send your mockup images</b> — ' + ( canShareFiles
				? 'tap below, choose <b>WhatsApp</b>, then pick the <b>Looma Apparels</b> chat.'
				: 'download them, then attach them in the Looma Apparels chat (📎 → Photos).' ) + '</span>' +
				( canShareFiles
					? '<button type="button" class="btn btn-wa btn-sm" data-share-imgs>Send ' + files.length + ' image' + ( files.length > 1 ? 's' : '' ) + '</button>'
					: '<button type="button" class="btn btn-light btn-sm" data-dl-imgs>Download ' + files.length + ' image' + ( files.length > 1 ? 's' : '' ) + '</button>' ) +
			'</li></ol>';
		setStatus( html, error ? 'err' : 'ok' );
		var sh = $( '[data-share-imgs]' );
		if ( sh ) {
			sh.addEventListener( 'click', function () {
				navigator.share( { files: files, title: 'Looma design ' + id, text: 'Looma Apparels design' + ( id ? ' ' + id : '' ) } )
					.then( function () { sh.textContent = '✔ Images shared'; } )
					.catch( function () { /* user closed the share sheet */ } );
			} );
		}
		var dl = $( '[data-dl-imgs]' );
		if ( dl ) {
			dl.addEventListener( 'click', function () {
				files.forEach( function ( f, i ) {
					setTimeout( function () {
						var a = document.createElement( 'a' ); a.href = URL.createObjectURL( f ); a.download = f.name;
						document.body.appendChild( a ); a.click(); a.remove();
					}, i * 400 );
				} );
				dl.textContent = '✔ Downloaded';
			} );
		}
	}

	/* ---------- init ---------- */
	var pre = root.getAttribute( 'data-preselect' );
	load( function ( restored ) {
		if ( pre && product( pre ).id === pre ) {
			state.product = pre;
			if ( ! product( pre ).colours.some( function ( c ) { return c.name === state.colour; } ) ) { state.colour = product( pre ).display; }
		}
		if ( POS.indexOf( state.view ) < 0 ) { state.view = 'front'; }
		last = snapshot();
		update();
		if ( restored ) { toast( 'Your last design was restored. Use “Start a new design” to clear it.' ); }
	} );
}() );
