/**
 * Looma Apparels — order builder / price estimator.
 *
 * One order = many lines. Each line: product, colour, size breakdown,
 * front print and back print. Pricing rules:
 *  - T-shirt price tier = total pieces of that product across the order.
 *  - DTF print rate = 10+ rate when the whole order is 10 pieces or more.
 *  - Embroidery = stitches / 1000 × rate (rate by whole-order quantity).
 *  - Neck label free on lines with an A2/A3/A4 print.
 *  - (Staff) discount and shipping, then 5% GST.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-builder]' );
	if ( ! root || ! window.LOOMA_BUILDER ) { return; }

	var B = window.LOOMA_BUILDER;
	var D = window.LOOMA || { dtf: {}, gst: 5, whatsapp: '' };
	var SIZES = B.sizes;
	var KEY = 'looma_builder_v1';
	var PRINTS = [
		{ key: 'none', label: 'None', sub: '' },
		{ key: 'logo', label: 'Logo', sub: '2.5 × 2.5"' },
		{ key: 'a6', label: 'A6', sub: '4.1 × 5.8"' },
		{ key: 'a5', label: 'A5', sub: '5.8 × 8.3"' },
		{ key: 'a4', label: 'A4', sub: '8 × 11"' },
		{ key: 'a3', label: 'A3', sub: '11 × 16"' },
		{ key: 'a2', label: 'A2', sub: '16 × 22"' },
		{ key: 'emb', label: 'Embroidery', sub: 'by stitches' }
	];
	var PLACES = [ { key: 'front', label: 'Front' }, { key: 'back', label: 'Back' } ];

	/* ---------- helpers ---------- */
	function $( s, c ) { return ( c || document ).querySelector( s ); }
	function $$( s, c ) { return Array.prototype.slice.call( ( c || document ).querySelectorAll( s ) ); }
	function rupee( n ) { return '₹' + Math.round( n ).toLocaleString( 'en-IN' ); }
	function esc( s ) { return String( s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }
	function el( html ) { var t = document.createElement( 'template' ); t.innerHTML = html.trim(); return t.content.firstChild; }
	function uid() { return 'l' + Math.random().toString( 36 ).slice( 2, 9 ); }
	function product( id ) {
		for ( var i = 0; i < B.products.length; i++ ) { if ( B.products[ i ].id === id ) { return B.products[ i ]; } }
		return B.products[ 0 ];
	}
	function colour( p, name ) {
		for ( var i = 0; i < p.colours.length; i++ ) { if ( p.colours[ i ].name === name ) { return p.colours[ i ]; } }
		return p.colours[ 0 ];
	}
	function tierPrice( prices, qty ) {
		var price = prices[ 0 ].price;
		prices.forEach( function ( t ) { if ( qty >= t.min ) { price = t.price; } } );
		return price;
	}
	function nextTier( prices, qty ) {
		for ( var i = 0; i < prices.length; i++ ) { if ( prices[ i ].min > qty ) { return prices[ i ]; } }
		return null;
	}
	function lineQty( line ) {
		return SIZES.reduce( function ( s, z ) { return s + ( line.sizes[ z ] || 0 ); }, 0 );
	}
	function icon( name ) {
		var paths = {
			copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/>',
			trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
			chev: '<path d="m6 9 6 6 6-6"/>',
			minus: '<path d="M5 12h14"/>',
			plus: '<path d="M12 5v14M5 12h14"/>',
			check: '<path d="m5 12 4.5 4.5L19 7"/>'
		};
		return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths[ name ] + '</svg>';
	}

	/* ---------- state ---------- */
	function newLine( pid ) {
		var p = product( pid || B.products[ 0 ].id );
		var sizes = {};
		SIZES.forEach( function ( z ) { sizes[ z ] = 0; } );
		sizes.M = 1;
		return {
			id: uid(), product: p.id, colour: p.display || p.colours[ 0 ].name, sizes: sizes,
			front: { type: 'none', stitches: 5000 }, back: { type: 'none', stitches: 8000 }, open: true
		};
	}

	var state = null;
	try { state = JSON.parse( localStorage.getItem( KEY ) ); } catch ( e ) { state = null; }
	if ( ! state || ! state.lines || ! state.lines.length ) {
		state = { lines: [ newLine() ], staff: {} };
	}
	state.staff = state.staff || {};
	var pre = root.getAttribute( 'data-preselect' );
	if ( pre && product( pre ).id === pre ) {
		var last = state.lines[ state.lines.length - 1 ];
		if ( state.lines.length === 1 && lineQty( last ) <= 1 && last.front.type === 'none' && last.back.type === 'none' ) {
			state.lines = [ newLine( pre ) ];
		} else if ( last.product !== pre ) {
			state.lines.forEach( function ( l ) { l.open = false; } );
			state.lines.push( newLine( pre ) );
		}
	}

	function save() {
		try { localStorage.setItem( KEY, JSON.stringify( state ) ); } catch ( e ) { /* storage unavailable */ }
	}

	/* ---------- pricing ---------- */
	function compute() {
		var orderQty = 0, perProduct = {};
		state.lines.forEach( function ( l ) {
			var q = lineQty( l );
			orderQty += q;
			perProduct[ l.product ] = ( perProduct[ l.product ] || 0 ) + q;
		} );
		var embRate = B.embroidery[ 0 ].rate;
		B.embroidery.forEach( function ( r ) { if ( orderQty >= r.min ) { embRate = r.rate; } } );
		var dtfBulk = orderQty >= 10;

		function printCost( pr ) {
			if ( ! pr || pr.type === 'none' ) { return 0; }
			if ( pr.type === 'emb' ) { return Math.round( ( Math.max( 0, pr.stitches || 0 ) / 1000 ) * embRate ); }
			var d = D.dtf[ pr.type ];
			return d ? ( dtfBulk ? d.bulk : d.single ) : 0;
		}

		var sub = 0;
		var lines = state.lines.map( function ( l ) {
			var p = product( l.product );
			var qty = lineQty( l );
			var tee = tierPrice( p.prices, perProduct[ l.product ] );
			var front = printCost( l.front ), back = printCost( l.back );
			var per = tee + front + back;
			var total = per * qty;
			sub += total;
			return {
				line: l, p: p, qty: qty, tee: tee, front: front, back: back, per: per, total: total,
				label: /^a[234]$/.test( l.front.type ) || /^a[234]$/.test( l.back.type ),
				next: nextTier( p.prices, perProduct[ l.product ] ), productQty: perProduct[ l.product ]
			};
		} );

		var s = state.staff, discount = 0;
		var dv = Math.max( 0, parseFloat( s.discount ) || 0 );
		if ( B.staff && dv ) { discount = s.discountType === 'amt' ? Math.min( dv, sub ) : sub * Math.min( dv, 100 ) / 100; }
		var ship = B.staff ? Math.max( 0, parseFloat( s.shipping ) || 0 ) : 0;
		var taxable = sub - discount + ship;
		var gst = taxable * D.gst / 100;
		return {
			lines: lines, orderQty: orderQty, sub: sub, discount: discount, ship: ship, gst: gst, total: taxable + gst,
			embRate: embRate, dtfBulk: dtfBulk, printCost: printCost
		};
	}

	function printLabel( pr ) {
		if ( ! pr || pr.type === 'none' ) { return 'None'; }
		if ( pr.type === 'emb' ) { return 'Embroidery (' + ( pr.stitches || 0 ).toLocaleString( 'en-IN' ) + ' stitches)'; }
		var o = PRINTS.filter( function ( x ) { return x.key === pr.type; } )[ 0 ];
		return o.label + ' DTF (' + o.sub + ')';
	}
	function sizeText( l ) {
		return SIZES.filter( function ( z ) { return l.sizes[ z ] > 0; } ).map( function ( z ) { return z + '×' + l.sizes[ z ]; } ).join( ', ' ) || '—';
	}

	/* ---------- line UI ---------- */
	var linesEl = $( '[data-lines]', root );

	function lineHTML( l, n ) {
		var p = product( l.product );
		var prods = B.products.map( function ( pp ) {
			var c = colour( pp, pp.display );
			return '<button type="button" role="radio" class="prod-opt" data-prod="' + esc( pp.id ) + '">' +
				'<img src="' + esc( c.photo ) + '" alt="" loading="lazy">' +
				'<span class="prod-opt-name">' + esc( pp.name ) + '</span>' +
				'<span class="prod-opt-spec">' + esc( pp.spec ) + '</span>' +
				'<span class="prod-opt-price">from ' + rupee( pp.from ) + '</span>' +
				'<span class="tick">' + icon( 'check' ) + '</span></button>';
		} ).join( '' );
		var sizes = SIZES.map( function ( z ) {
			return '<div class="size-cell"><span class="size-name">' + z + '</span>' +
				'<div class="mini-stepper"><button type="button" data-size-step="-1" data-size="' + z + '" aria-label="Fewer ' + z + '">' + icon( 'minus' ) + '</button>' +
				'<input type="number" min="0" inputmode="numeric" data-size-input="' + z + '" aria-label="' + z + ' quantity">' +
				'<button type="button" data-size-step="1" data-size="' + z + '" aria-label="More ' + z + '">' + icon( 'plus' ) + '</button></div></div>';
		} ).join( '' );
		var places = PLACES.map( function ( pl ) {
			var chips = PRINTS.map( function ( o ) {
				return '<button type="button" class="print-chip" data-place="' + pl.key + '" data-print="' + o.key + '">' +
					'<strong>' + o.label + '</strong><small>' + ( o.sub || '&nbsp;' ) + '</small><em data-print-price></em></button>';
			} ).join( '' );
			return '<div class="place"><div class="place-label">' + pl.label + '</div><div class="print-chips" role="radiogroup" aria-label="' + pl.label + ' print">' + chips + '</div>' +
				'<label class="stitch-row" data-stitch-row="' + pl.key + '" hidden><span>Stitch count</span><input type="number" min="500" step="500" inputmode="numeric" data-stitches="' + pl.key + '"><small>Typical: chest logo 4,000–6,000 · large back 15,000+</small></label></div>';
		} ).join( '' );

		return '<article class="line" data-line="' + l.id + '">' +
			'<header class="line-head">' +
				'<button type="button" class="line-toggle" data-toggle aria-expanded="true">' +
					'<img class="line-thumb" alt="" data-thumb>' +
					'<span class="line-title"><small>Item <b data-no>' + n + '</b></small><strong data-title></strong><span data-meta></span></span>' +
				'</button>' +
				'<strong class="line-total" data-line-total></strong>' +
				'<div class="line-tools">' +
					'<button type="button" class="tool" data-dup title="Duplicate item" aria-label="Duplicate item">' + icon( 'copy' ) + '</button>' +
					'<button type="button" class="tool" data-del title="Remove item" aria-label="Remove item">' + icon( 'trash' ) + '</button>' +
					'<button type="button" class="tool chev" data-toggle aria-label="Show or hide details">' + icon( 'chev' ) + '</button>' +
				'</div>' +
			'</header>' +
			'<div class="line-body">' +
				'<section class="step"><h3><span class="step-no">1</span> T-shirt</h3><div class="prod-picker" role="radiogroup" aria-label="T-shirt">' + prods + '</div></section>' +
				'<section class="step"><h3><span class="step-no">2</span> Colour <b data-colour-name></b></h3><div class="colour-chips" data-colours role="radiogroup" aria-label="Colour"></div></section>' +
				'<section class="step"><h3><span class="step-no">3</span> Sizes &amp; quantity <span class="step-aside" data-qty-label></span></h3>' +
					'<div class="size-grid">' + sizes + '</div>' +
					'<div class="quick"><span>Quick fill:</span>' +
						'<button type="button" data-fill="5">5 each</button><button type="button" data-fill="10">10 each</button>' +
						'<button type="button" data-fill="25">25 each</button><button type="button" data-fill="0">Clear</button></div>' +
					'<p class="tier-hint" data-tier-hint></p></section>' +
				'<section class="step"><h3><span class="step-no">4</span> Prints <span class="step-aside">optional</span></h3>' + places +
					'<p class="label-note" data-label-note></p></section>' +
			'</div></article>';
	}

	function renderColours( node, l ) {
		var p = product( l.product );
		var wrap = $( '[data-colours]', node );
		wrap.innerHTML = p.colours.map( function ( c ) {
			return '<button type="button" role="radio" class="colour-chip" data-colour="' + esc( c.name ) + '" style="--sw:' + esc( c.hex ) + '">' +
				'<span class="dot"></span><span>' + esc( c.name ) + '</span></button>';
		} ).join( '' );
	}

	function syncLine( node, l ) {
		var p = product( l.product );
		var c = colour( p, l.colour );
		l.colour = c.name;
		$( '[data-thumb]', node ).src = c.photo;
		$( '[data-title]', node ).textContent = p.name + ' ' + p.gsm;
		$( '[data-colour-name]', node ).textContent = c.name;
		$$( '.prod-opt', node ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-prod' ) === l.product;
			b.classList.toggle( 'is-active', on ); b.setAttribute( 'aria-checked', on );
		} );
		$$( '.colour-chip', node ).forEach( function ( b ) {
			var on = b.getAttribute( 'data-colour' ) === c.name;
			b.classList.toggle( 'is-active', on ); b.setAttribute( 'aria-checked', on );
		} );
		SIZES.forEach( function ( z ) {
			var inp = $( '[data-size-input="' + z + '"]', node );
			if ( document.activeElement !== inp ) { inp.value = l.sizes[ z ] || ''; }
			inp.placeholder = '0';
			inp.closest( '.size-cell' ).classList.toggle( 'has-qty', ( l.sizes[ z ] || 0 ) > 0 );
		} );
		PLACES.forEach( function ( pl ) {
			var pr = l[ pl.key ];
			$$( '.print-chip[data-place="' + pl.key + '"]', node ).forEach( function ( b ) {
				var on = b.getAttribute( 'data-print' ) === pr.type;
				b.classList.toggle( 'is-active', on ); b.setAttribute( 'aria-checked', on );
			} );
			var row = $( '[data-stitch-row="' + pl.key + '"]', node );
			row.hidden = pr.type !== 'emb';
			var st = $( '[data-stitches="' + pl.key + '"]', node );
			if ( document.activeElement !== st ) { st.value = pr.stitches; }
		} );
		node.classList.toggle( 'is-open', !! l.open );
		$$( '[data-toggle]', node ).forEach( function ( b ) { b.setAttribute( 'aria-expanded', !! l.open ); } );
	}

	function bindLine( node, l ) {
		node.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( 'button' );
			if ( ! t ) { return; }
			if ( t.hasAttribute( 'data-toggle' ) ) { l.open = ! l.open; }
			else if ( t.hasAttribute( 'data-dup' ) ) {
				var copy = JSON.parse( JSON.stringify( l ) );
				copy.id = uid(); copy.open = true; l.open = false;
				state.lines.splice( state.lines.indexOf( l ) + 1, 0, copy );
				rebuild(); focusLine( copy.id ); return;
			} else if ( t.hasAttribute( 'data-del' ) ) {
				if ( state.lines.length === 1 ) { state.lines = [ newLine() ]; }
				else { state.lines.splice( state.lines.indexOf( l ), 1 ); }
				rebuild(); return;
			} else if ( t.hasAttribute( 'data-prod' ) ) {
				l.product = t.getAttribute( 'data-prod' );
				var p = product( l.product );
				if ( ! p.colours.some( function ( c ) { return c.name === l.colour; } ) ) { l.colour = p.display; }
				renderColours( node, l );
			} else if ( t.hasAttribute( 'data-colour' ) ) {
				l.colour = t.getAttribute( 'data-colour' );
			} else if ( t.hasAttribute( 'data-size-step' ) ) {
				var z = t.getAttribute( 'data-size' );
				l.sizes[ z ] = Math.max( 0, ( l.sizes[ z ] || 0 ) + parseInt( t.getAttribute( 'data-size-step' ), 10 ) );
			} else if ( t.hasAttribute( 'data-fill' ) ) {
				var f = parseInt( t.getAttribute( 'data-fill' ), 10 );
				SIZES.forEach( function ( s ) { l.sizes[ s ] = f; } );
			} else if ( t.hasAttribute( 'data-print' ) ) {
				l[ t.getAttribute( 'data-place' ) ].type = t.getAttribute( 'data-print' );
			} else { return; }
			update();
		} );
		node.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( t.hasAttribute( 'data-size-input' ) ) {
				l.sizes[ t.getAttribute( 'data-size-input' ) ] = Math.max( 0, parseInt( t.value, 10 ) || 0 );
			} else if ( t.hasAttribute( 'data-stitches' ) ) {
				l[ t.getAttribute( 'data-stitches' ) ].stitches = Math.max( 0, parseInt( t.value, 10 ) || 0 );
			} else { return; }
			update();
		} );
		node.addEventListener( 'focusin', function ( e ) {
			if ( e.target.matches( 'input[type=number]' ) ) { e.target.select(); }
		} );
	}

	function rebuild() {
		linesEl.innerHTML = '';
		state.lines.forEach( function ( l, i ) {
			var node = el( lineHTML( l, i + 1 ) );
			renderColours( node, l );
			bindLine( node, l );
			linesEl.appendChild( node );
		} );
		update();
	}

	function focusLine( id ) {
		var node = $( '[data-line="' + id + '"]', linesEl );
		if ( node ) { node.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
	}

	/* ---------- update everything ---------- */
	var R = null;
	// Smallest number of extra pieces on this line that lowers its per-piece price (t-shirt tier for the
	// style, DTF 10+ rate or embroidery rate for the whole order). Worked out by trying each price break.
	function nextDrop( line, base ) {
		var r0 = base.lines.filter( function ( r ) { return r.line === line; } )[ 0 ];
		if ( ! r0 || ! r0.qty ) { return null; }
		var p = product( line.product ), c = {};
		p.prices.forEach( function ( t ) { if ( t.min > r0.productQty ) { c[ t.min - r0.productQty ] = 1; } } );
		B.embroidery.forEach( function ( e ) { if ( e.min > base.orderQty ) { c[ e.min - base.orderQty ] = 1; } } );
		if ( base.orderQty < 10 ) { c[ 10 - base.orderQty ] = 1; }
		var sz = SIZES.reduce( function ( m, z ) { return ( line.sizes[ z ] || 0 ) > ( line.sizes[ m ] || 0 ) ? z : m; }, 'M' );
		var adds = Object.keys( c ).map( Number ).sort( function ( a, b ) { return a - b; } ), found = null;
		for ( var i = 0; i < adds.length && ! found; i++ ) {
			line.sizes[ sz ] = ( line.sizes[ sz ] || 0 ) + adds[ i ];
			var T = compute(), r1 = T.lines.filter( function ( r ) { return r.line === line; } )[ 0 ];
			line.sizes[ sz ] -= adds[ i ];
			if ( r1.per < r0.per ) {
				// What the pieces already in the order would cost at the new prices.
				var before = base.sub, after = T.sub - r1.per * adds[ i ];
				found = { add: adds[ i ], size: sz, per: r1.per, old: r0.per, orderSave: Math.max( 0, before - after ) };
			}
		}
		return found;
	}
	root.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-hint-line]' ); if ( ! b ) { return; }
		var l = state.lines.filter( function ( x ) { return x.id === b.getAttribute( 'data-hint-line' ); } )[ 0 ];
		if ( ! l ) { return; }
		var sz = b.getAttribute( 'data-hint-size' ), n = parseInt( b.getAttribute( 'data-hint-n' ), 10 );
		l.sizes[ sz ] = ( l.sizes[ sz ] || 0 ) + n;
		update();
		if ( window.LoomaToast ) { window.LoomaToast( 'Added ' + n + ' × ' + sz + '.' ); }
	} );
	function update() {
		R = compute();
		R.lines.forEach( function ( r, i ) {
			var node = $( '[data-line="' + r.line.id + '"]', linesEl );
			if ( ! node ) { return; }
			syncLine( node, r.line );
			$( '[data-no]', node ).textContent = i + 1;
			$( '[data-meta]', node ).textContent = r.line.colour + ' · ' + r.qty + ' pcs · ' + rupee( r.per ) + '/pc';
			$( '[data-line-total]', node ).textContent = rupee( r.total );
			$( '[data-qty-label]', node ).textContent = r.qty + ' pcs';
			$$( '.print-chip', node ).forEach( function ( b ) {
				var cost = R.printCost( { type: b.getAttribute( 'data-print' ), stitches: r.line[ b.getAttribute( 'data-place' ) ].stitches } );
				$( '[data-print-price]', b ).textContent = b.getAttribute( 'data-print' ) === 'none' ? '' : '+' + rupee( cost );
			} );
			var hint = $( '[data-tier-hint]', node ), nd = nextDrop( r.line, R );
			hint.innerHTML = nd
				? '<b>' + rupee( r.per ) + '</b>/pc now. Add <b>' + nd.add + ' more</b> and pay <b>' + rupee( nd.per ) + '</b>/pc. <button type="button" class="hint-add" data-hint-line="' + r.line.id + '" data-hint-n="' + nd.add + '" data-hint-size="' + nd.size + '">+' + nd.add + ' pcs</button>'
				: r.qty ? '<b>' + rupee( r.per ) + '</b>/pc — best bulk price for this line.' : '';
			$( '[data-label-note]', node ).innerHTML = r.label
				? icon( 'check' ) + ' Free neck label (your brand name) included.'
				: 'Add an A2, A3 or A4 print to get a free neck label.';
		} );
		renderSummary();
		save();
	}

	/* ---------- summary ---------- */
	function renderSummary() {
		var list = $( '[data-sum-lines]', root );
		list.innerHTML = R.lines.map( function ( r, i ) {
			return '<li><button type="button" data-goto="' + r.line.id + '">' +
				'<img src="' + esc( colour( r.p, r.line.colour ).photo ) + '" alt="">' +
				'<span><strong>' + ( i + 1 ) + '. ' + esc( r.p.name ) + ' ' + esc( r.p.gsm ) + '</strong>' +
				'<small>' + esc( r.line.colour ) + ' · ' + r.qty + ' pcs × ' + rupee( r.per ) + '</small>' +
				( r.line.front.type !== 'none' || r.line.back.type !== 'none'
					? '<small>' + ( r.line.front.type !== 'none' ? 'Front: ' + esc( printLabel( r.line.front ) ) : '' ) + ( r.line.front.type !== 'none' && r.line.back.type !== 'none' ? ' · ' : '' ) + ( r.line.back.type !== 'none' ? 'Back: ' + esc( printLabel( r.line.back ) ) : '' ) + '</small>'
					: '' ) +
				'</span><b>' + rupee( r.total ) + '</b></button></li>';
		} ).join( '' );
		$$( '[data-goto]', list ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var l = state.lines.filter( function ( x ) { return x.id === b.getAttribute( 'data-goto' ); } )[ 0 ];
				if ( l ) { l.open = true; update(); focusLine( l.id ); }
			} );
		} );
		var set = function ( sel, v ) { var n = $( sel ); if ( n ) { n.textContent = v; } };
		// Whole-order tip: the line where the fewest extra pieces bring the price down.
		var oh = $( '[data-order-hint]', root ), bestTip = null;
		R.lines.forEach( function ( r, i ) {
			var nd = nextDrop( r.line, R );
			if ( nd && ( ! bestTip || nd.add < bestTip.nd.add ) ) { bestTip = { nd: nd, r: r, i: i }; }
		} );
		if ( oh ) {
			oh.hidden = ! bestTip;
			oh.innerHTML = bestTip
				? '<span class="ds-hint-ico" aria-hidden="true">%</span><span>Add <b>' + bestTip.nd.add + ' more</b> to line ' + ( bestTip.i + 1 ) + ' (' + esc( bestTip.r.p.name ) + ') and pay <b>' + rupee( bestTip.nd.per ) + '/pc</b> instead of ' + rupee( bestTip.nd.old ) + '.' +
					( bestTip.nd.orderSave > 0 ? '<small>Your current pieces get ' + rupee( bestTip.nd.orderSave ) + ' cheaper in total.</small>' : '' ) +
					'</span><button type="button" class="ds-hint-add" data-hint-line="' + bestTip.r.line.id + '" data-hint-n="' + bestTip.nd.add + '" data-hint-size="' + bestTip.nd.size + '">+' + bestTip.nd.add + ' pcs</button>'
				: '';
		}
		set( '[data-sum-count]', R.orderQty + ' pcs' );
		set( '[data-sum-sub]', rupee( R.sub ) );
		set( '[data-sum-discount]', '−' + rupee( R.discount ) );
		set( '[data-sum-ship]', rupee( R.ship ) );
		set( '[data-sum-gst]', rupee( R.gst ) );
		set( '[data-sum-total]', rupee( R.total ) );
		$( '[data-sum-discount-row]', root ).hidden = ! R.discount;
		$( '[data-sum-ship-row]', root ).hidden = ! R.ship;
		set( '[data-sum-avg]', R.orderQty ? 'Average ' + rupee( R.total / R.orderQty ) + ' per piece incl. GST' : 'Add quantities to see your price.' );
		set( '[data-mob-count]', R.orderQty + ' pcs · incl. GST' + ( R.ship ? '' : ' · shipping extra' ) );
		var sn = $( '[data-ship-note]', root ); if ( sn ) { sn.hidden = !! R.ship; }
		set( '[data-mob-total]', rupee( R.total ) );

		var text = quoteText();
		var wa = $( '[data-sum-wa]', root );
		wa.href = 'https://wa.me/' + D.whatsapp + '?text=' + encodeURIComponent( 'Hi Looma Apparels, I would like a quote for this order:\n\n' + text );
		$$( '[data-quote-items]' ).forEach( function ( f ) { f.value = text; } );
	}

	function quoteText() {
		var out = [];
		R.lines.forEach( function ( r, i ) {
			out.push( ( i + 1 ) + '. ' + r.p.name + ' ' + r.p.spec + ' — ' + r.line.colour );
			out.push( '   Sizes: ' + sizeText( r.line ) + ' (' + r.qty + ' pcs)' );
			out.push( '   Front: ' + printLabel( r.line.front ) + ' · Back: ' + printLabel( r.line.back ) );
			out.push( '   ' + rupee( r.per ) + '/pc × ' + r.qty + ' = ' + rupee( r.total ) );
		} );
		out.push( '' );
		out.push( 'Total pieces: ' + R.orderQty );
		out.push( 'Subtotal: ' + rupee( R.sub ) );
		if ( R.discount ) { out.push( 'Discount: −' + rupee( R.discount ) ); }
		if ( R.ship ) { out.push( 'Shipping: ' + rupee( R.ship ) ); }
		out.push( 'GST (5%): ' + rupee( R.gst ) );
		out.push( 'Estimated total: ' + rupee( R.total ) );
		if ( ! R.ship ) { out.push( 'Shipping charges extra (as per actual).' ); }
		return out.join( '\n' );
	}

	/* ---------- summary actions ---------- */
	$( '[data-add-line]', root ).addEventListener( 'click', function () {
		state.lines.forEach( function ( l ) { l.open = false; } );
		var l = newLine();
		state.lines.push( l );
		rebuild();
		focusLine( l.id );
	} );

	$( '[data-sum-email]', root ).addEventListener( 'click', function () {
		var f = document.getElementById( 'enquiry' );
		if ( f ) { f.scrollIntoView( { behavior: 'smooth' } ); var n = $( 'input[name=name]', f ); if ( n ) { setTimeout( function () { n.focus(); }, 500 ); } }
	} );

	$( '[data-sum-copy]', root ).addEventListener( 'click', function () {
		var t = quoteText();
		var done = function () { toast( 'Order summary copied.' ); };
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( t ).then( done, function () { fallbackCopy( t ); done(); } );
		} else { fallbackCopy( t ); done(); }
	} );
	function fallbackCopy( t ) {
		var ta = document.createElement( 'textarea' ); ta.value = t; document.body.appendChild( ta ); ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) { /* ignore */ }
		ta.remove();
	}

	$( '[data-sum-reset]', root ).addEventListener( 'click', function () {
		if ( ! window.confirm( 'Clear this order and start over?' ) ) { return; }
		state = { lines: [ newLine() ], staff: state.staff };
		rebuild();
	} );

	$( '[data-sum-print]', root ).addEventListener( 'click', function () {
		buildQuoteDoc();
		document.body.classList.add( 'printing-quote' );
		window.print();
		setTimeout( function () { document.body.classList.remove( 'printing-quote' ); }, 500 );
	} );

	function toast( msg ) {
		var t = document.querySelector( '[data-toast]' );
		if ( ! t ) { return; }
		t.textContent = msg; t.hidden = false;
		setTimeout( function () { t.hidden = true; }, 3000 );
	}

	/* ---------- staff tools ---------- */
	$$( '[data-staff]', root ).forEach( function ( inp ) {
		var k = inp.getAttribute( 'data-staff' );
		if ( state.staff[ k ] !== undefined ) { inp.value = state.staff[ k ]; }
		inp.addEventListener( 'input', function () { state.staff[ k ] = inp.value; update(); } );
		inp.addEventListener( 'change', function () { state.staff[ k ] = inp.value; update(); } );
	} );

	/* ---------- printable quotation ---------- */
	function buildQuoteDoc() {
		var doc = $( '[data-quote-doc]' );
		if ( doc.parentNode !== document.body ) { document.body.appendChild( doc ); }
		var now = new Date();
		var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };
		var no = 'LA-' + String( now.getFullYear() ).slice( 2 ) + pad( now.getMonth() + 1 ) + pad( now.getDate() ) + '-' + pad( now.getHours() ) + pad( now.getMinutes() );
		var valid = new Date( now.getTime() + 7 * 864e5 );
		var s = state.staff;
		var rows = R.lines.map( function ( r, i ) {
			return '<tr><td>' + ( i + 1 ) + '</td><td><strong>' + esc( r.p.name + ' ' + r.p.spec ) + '</strong><br>' + esc( r.line.colour ) + ' · ' + esc( sizeText( r.line ) ) +
				'<br><small>Front: ' + esc( printLabel( r.line.front ) ) + ' · Back: ' + esc( printLabel( r.line.back ) ) + ( r.label ? ' · Free neck label' : '' ) + '</small></td>' +
				'<td>' + r.qty + '</td><td>' + rupee( r.tee ) + '</td><td>' + ( r.front + r.back ? rupee( r.front + r.back ) : '—' ) + '</td><td>' + rupee( r.per ) + '</td><td><strong>' + rupee( r.total ) + '</strong></td></tr>';
		} ).join( '' );
		doc.innerHTML =
			'<div class="qd-head"><div><div class="qd-logo">LOOMA</div><div class="qd-sub">APPARELS</div></div>' +
			'<div class="qd-meta"><h1>Quotation</h1><p>No. ' + no + '<br>Date: ' + now.toLocaleDateString( 'en-IN' ) + '<br>Valid until: ' + valid.toLocaleDateString( 'en-IN' ) + '</p></div></div>' +
			'<div class="qd-parties"><div><small>From</small><strong>Looma Apparels</strong><br>' + esc( B.business.address ) + '<br>' + esc( B.business.phone ) + ' · ' + esc( B.business.email ) + '</div>' +
			( s.customer || s.phone ? '<div><small>Prepared for</small><strong>' + esc( s.customer || '' ) + '</strong><br>' + esc( s.phone || '' ) + '</div>' : '' ) + '</div>' +
			'<table class="qd-table"><thead><tr><th>#</th><th>Item</th><th>Qty</th><th>T-shirt</th><th>Prints</th><th>Per pc</th><th>Amount</th></tr></thead><tbody>' + rows + '</tbody></table>' +
			'<table class="qd-totals"><tr><td>Subtotal (' + R.orderQty + ' pcs)</td><td>' + rupee( R.sub ) + '</td></tr>' +
			( R.discount ? '<tr><td>Discount</td><td>−' + rupee( R.discount ) + '</td></tr>' : '' ) +
			( R.ship ? '<tr><td>Shipping</td><td>' + rupee( R.ship ) + '</td></tr>' : '' ) +
			'<tr><td>GST (5%)</td><td>' + rupee( R.gst ) + '</td></tr><tr class="grand"><td>Total</td><td>' + rupee( R.total ) + '</td></tr>' +
			( R.ship ? '' : '<tr><td colspan="2" style="text-align:right;font-size:11px;color:#555">+ Shipping charges extra (as per actual)</td></tr>' ) + '</table>' +
			( s.notes ? '<p class="qd-notes"><strong>Notes:</strong> ' + esc( s.notes ) + '</p>' : '' ) +
			'<p class="qd-terms">Estimate based on the Looma Apparels 2026 price list. Final price confirmed after artwork review. Embroidery digitizing, puff, HD and screen printing quoted separately. Pan-India delivery.</p>';
	}

	rebuild();
}() );
