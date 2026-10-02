/**
 * Looma Apparels — front-end behaviour.
 */
( function () {
	'use strict';

	var data = window.LOOMA || { products: [], dtf: {}, gst: 5, whatsapp: '', quoteUrl: '/quote/' };

	function $( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function $$( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }
	function rupee( n ) { return '₹' + Math.round( n ).toLocaleString( 'en-IN' ); }
	function waLink( text ) { return 'https://wa.me/' + data.whatsapp + ( text ? '?text=' + encodeURIComponent( text ) : '' ); }
	function findProduct( id ) {
		for ( var i = 0; i < data.products.length; i++ ) {
			if ( data.products[ i ].id === id ) { return data.products[ i ]; }
		}
		return null;
	}
	// Price tier for a quantity: the highest tier whose minimum is <= qty.
	function tierPrice( prices, qty ) {
		var price = prices[ 0 ].price;
		prices.forEach( function ( t ) { if ( qty >= t.min ) { price = t.price; } } );
		return price;
	}
	function preload( url ) { if ( url ) { var i = new Image(); i.src = url; } }

	/* ---------- Toast ---------- */
	var toastEl = $( '[data-toast]' ), toastTimer;
	function toast( html ) {
		if ( ! toastEl ) { return; }
		toastEl.innerHTML = html;
		toastEl.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { toastEl.hidden = true; }, 4500 );
	}

	/* ---------- Mobile menu ---------- */
	var toggle = $( '.nav-toggle' );
	if ( toggle ) {
		var setOpen = function ( open ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			document.body.classList.toggle( 'nav-open', open );
		};
		toggle.addEventListener( 'click', function () { setOpen( toggle.getAttribute( 'aria-expanded' ) !== 'true' ); } );
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { setOpen( false ); } } );
		document.addEventListener( 'click', function ( e ) {
			if ( document.body.classList.contains( 'nav-open' ) && ! e.target.closest( '.primary-nav, .nav-toggle' ) ) { setOpen( false ); }
		} );
	}

	/* ---------- Header shadow ---------- */
	var header = $( '.site-header' );
	if ( header ) {
		var onScroll = function () { header.classList.toggle( 'is-scrolled', window.scrollY > 8 ); };
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();
	}

	/* ---------- Product cards: swatch preview ---------- */
	$$( '.p-card' ).forEach( function ( card ) {
		var photo = $( '.p-card-photo', card );
		$$( '.swatch-dot', card ).forEach( function ( dot ) {
			var show = function () {
				if ( photo && dot.getAttribute( 'data-photo' ) ) { photo.src = dot.getAttribute( 'data-photo' ); }
				$$( '.swatch-dot', card ).forEach( function ( d ) { d.classList.toggle( 'is-active', d === dot ); } );
			};
			dot.addEventListener( 'mouseenter', function () { preload( dot.getAttribute( 'data-photo' ) ); show(); } );
			dot.addEventListener( 'focus', show );
			dot.addEventListener( 'click', show );
		} );
	} );

	/* ---------- Shop filter & sort ---------- */
	var grid = $( '[data-grid]' );
	$$( '.filter .chip' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			var fit = chip.getAttribute( 'data-filter' );
			$$( '.filter .chip' ).forEach( function ( c ) { c.classList.toggle( 'is-active', c === chip ); } );
			$$( '.p-card', grid || document ).forEach( function ( card ) {
				card.hidden = fit !== 'all' && card.getAttribute( 'data-fit' ) !== fit;
			} );
		} );
	} );
	var sortSel = $( '[data-sort]' );
	if ( sortSel && grid ) {
		var original = $$( '.p-card', grid );
		sortSel.addEventListener( 'change', function () {
			var cards = original.slice();
			if ( sortSel.value !== 'featured' ) {
				cards.sort( function ( a, b ) {
					var d = +a.getAttribute( 'data-price' ) - +b.getAttribute( 'data-price' );
					return sortSel.value === 'low' ? d : -d;
				} );
			}
			cards.forEach( function ( c ) { grid.appendChild( c ); } );
		} );
	}

	/* ---------- Tabs (size charts) ---------- */
	$$( '[data-tabs]' ).forEach( function ( group ) {
		var tabs = $$( '.tab', group );
		function select( tab ) {
			tabs.forEach( function ( t ) {
				var on = t === tab;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				var panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
				if ( panel ) { panel.hidden = ! on; }
			} );
		}
		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () { select( tab ); } );
			tab.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
					var next = tabs[ ( i + ( e.key === 'ArrowRight' ? 1 : tabs.length - 1 ) ) % tabs.length ];
					select( next );
					next.focus();
				}
			} );
		} );
		var initial = tabs.filter( function ( t ) { return t.getAttribute( 'aria-selected' ) === 'true'; } )[ 0 ] || tabs[ 0 ];
		if ( initial ) { select( initial ); }
	} );

	/* ---------- Quote list storage (this browser only) ---------- */
	var KEY = 'looma_quote_v1';
	function readQuote() {
		try { return JSON.parse( localStorage.getItem( KEY ) ) || []; } catch ( e ) { return memoryQuote; }
	}
	var memoryQuote = [];
	function writeQuote( items ) {
		memoryQuote = items;
		try { localStorage.setItem( KEY, JSON.stringify( items ) ); } catch ( e ) { /* storage unavailable */ }
		updateCount();
	}
	function updateCount() {
		var n = readQuote().reduce( function ( s, it ) { return s + it.qty; }, 0 );
		$$( '[data-quote-count]' ).forEach( function ( el ) { el.textContent = n > 99 ? '99+' : n; el.hidden = n === 0; } );
	}
	function quoteText( items ) {
		return items.map( function ( it, i ) {
			return ( i + 1 ) + '. ' + it.name + ' — ' + it.colour + ', size ' + it.size + ', ' + it.qty + ' pcs';
		} ).join( '\n' );
	}
	updateCount();

	/* ---------- Product page ---------- */
	var pdp = $( '.product-page' );
	if ( pdp ) {
		var product = findProduct( pdp.getAttribute( 'data-product' ) );
		var prices = JSON.parse( pdp.getAttribute( 'data-prices' ) );
		var state = { colour: '', colourHex: '', size: 'M', qty: 1 };
		var qtyInput = $( '[data-qty]', pdp );

		// Views.
		$$( '.pdp-thumb', pdp ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var view = btn.getAttribute( 'data-show' );
				$$( '.pdp-thumb', pdp ).forEach( function ( b ) { b.classList.toggle( 'is-active', b === btn ); } );
				$$( '.pdp-view', pdp ).forEach( function ( v ) {
					var on = v.getAttribute( 'data-view' ) === view;
					v.hidden = ! on;
					v.classList.toggle( 'is-active', on );
				} );
			} );
		} );

		// Colour.
		$$( '.colour-opt', pdp ).forEach( function ( opt ) {
			if ( opt.classList.contains( 'is-active' ) ) {
				state.colour = opt.getAttribute( 'data-name' );
				state.colourHex = opt.getAttribute( 'data-colour' );
				state.photo = opt.getAttribute( 'data-photo' );
			}
			preload( opt.getAttribute( 'data-photo' ) );
			opt.addEventListener( 'click', function () {
				state.colour = opt.getAttribute( 'data-name' );
				state.colourHex = opt.getAttribute( 'data-colour' );
				$$( '.colour-opt', pdp ).forEach( function ( o ) {
					var on = o === opt;
					o.classList.toggle( 'is-active', on );
					o.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				} );
				state.photo = opt.getAttribute( 'data-photo' );
				$$( '[data-colour-photo]', pdp ).forEach( function ( img ) {
					img.src = state.photo;
					img.alt = ( product ? product.name : '' ) + ' in ' + state.colour;
				} );
				// Show the product photo when a colour is picked.
				var front = $( '.pdp-thumb[data-show="front"]', pdp );
				if ( front ) { front.click(); }
				$( '[data-colour-name]', pdp ).textContent = state.colour;
				updatePdp();
			} );
		} );

		// Size.
		$$( '.size-opt', pdp ).forEach( function ( opt ) {
			opt.addEventListener( 'click', function () {
				state.size = opt.getAttribute( 'data-size' );
				$$( '.size-opt', pdp ).forEach( function ( o ) {
					var on = o === opt;
					o.classList.toggle( 'is-active', on );
					o.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				} );
				$( '[data-size-name]', pdp ).textContent = state.size;
				updatePdp();
			} );
		} );

		// Quantity.
		$$( '[data-step]', pdp ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				qtyInput.value = Math.max( 1, ( parseInt( qtyInput.value, 10 ) || 1 ) + parseInt( b.getAttribute( 'data-step' ), 10 ) );
				updatePdp();
			} );
		} );
		qtyInput.addEventListener( 'input', updatePdp );

		function updatePdp() {
			state.qty = Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 );
			var unit = tierPrice( prices, state.qty );
			$( '[data-unit-price]', pdp ).textContent = rupee( unit );
			$( '[data-subtotal]', pdp ).textContent = rupee( unit * state.qty );

			var active = null, next = null;
			prices.forEach( function ( t ) {
				if ( state.qty >= t.min ) { active = t; } else if ( ! next ) { next = t; }
			} );
			$$( '.tier', pdp ).forEach( function ( el ) {
				el.classList.toggle( 'is-active', active && +el.getAttribute( 'data-tier' ) === active.min );
			} );
			var hint = $( '[data-tier-hint]', pdp );
			if ( hint ) {
				hint.innerHTML = next
					? 'Order <strong>' + ( next.min - state.qty ) + ' more</strong> to pay ' + rupee( next.price ) + ' per piece.'
					: 'You are getting our <strong>best price</strong>.';
			}
			var wa = $( '[data-order-wa]', pdp );
			if ( wa && product ) {
				wa.href = waLink(
					'Hi Looma Apparels, I would like to order:\n' +
					'• ' + product.name + '\n' +
					'• Colour: ' + state.colour + '\n' +
					'• Size: ' + state.size + '\n' +
					'• Quantity: ' + state.qty + ' pcs\n' +
					'Website price: ' + rupee( unit ) + ' per piece (+5% GST).\n' +
					window.location.href
				);
			}
		}
		updatePdp();

		// Add to quote.
		var addBtn = $( '[data-add-quote]', pdp );
		if ( addBtn && product ) {
			addBtn.addEventListener( 'click', function () {
				var items = readQuote();
				var existing = items.filter( function ( it ) {
					return it.id === product.id && it.colour === state.colour && it.size === state.size;
				} )[ 0 ];
				if ( existing ) {
					existing.qty += state.qty;
				} else {
					items.push( { id: product.id, name: product.name, url: product.url, colour: state.colour, hex: state.colourHex, photo: state.photo, size: state.size, qty: state.qty } );
				}
				writeQuote( items );
				toast( 'Added to your quote list. <a href="' + data.quoteUrl + '">View list →</a>' );
			} );
		}
	}

	/* ---------- Quote page ---------- */
	var qList = $( '[data-quote-list]' );
	function renderQuote() {
		if ( ! qList ) { return; }
		var items = readQuote();
		var empty = $( '[data-quote-empty]' ), summary = $( '[data-quote-summary]' );
		empty.hidden = items.length > 0;
		qList.hidden = summary.hidden = items.length === 0;
		qList.innerHTML = '';

		// Quantity pricing per product (all colours/sizes of a product count together).
		var totals = {};
		items.forEach( function ( it ) { totals[ it.id ] = ( totals[ it.id ] || 0 ) + it.qty; } );
		var sub = 0;

		items.forEach( function ( it, idx ) {
			var p = findProduct( it.id );
			var unit = p ? tierPrice( p.prices, totals[ it.id ] ) : 0;
			sub += unit * it.qty;
			var li = document.createElement( 'li' );
			li.className = 'q-item';
			li.innerHTML =
				'<div class="q-thumb"><img alt="" loading="lazy"><span></span></div>' +
				'<div class="q-info"><h3><a></a></h3><p></p></div>' +
				'<div class="q-side"><strong></strong><div class="q-controls">' +
				'<input type="number" min="1" aria-label="Quantity">' +
				'<button type="button" class="q-remove" aria-label="Remove"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg></button>' +
				'</div></div>';
			var qimg = $( '.q-thumb img', li ), qsw = $( '.q-thumb span', li );
			if ( it.photo ) { qimg.src = it.photo; qsw.remove(); } else { qimg.remove(); qsw.style.setProperty( '--sw', it.hex || '#999' ); }
			var a = $( 'h3 a', li );
			a.textContent = it.name;
			a.href = it.url || '#';
			$( '.q-info p', li ).textContent = it.colour + ' · Size ' + it.size + ' · ' + rupee( unit ) + ' / pc';
			$( '.q-side strong', li ).textContent = rupee( unit * it.qty );
			var input = $( 'input', li );
			input.value = it.qty;
			input.addEventListener( 'change', function () {
				var list = readQuote();
				list[ idx ].qty = Math.max( 1, parseInt( input.value, 10 ) || 1 );
				writeQuote( list );
				renderQuote();
			} );
			$( '.q-remove', li ).addEventListener( 'click', function () {
				var list = readQuote();
				list.splice( idx, 1 );
				writeQuote( list );
				renderQuote();
			} );
			qList.appendChild( li );
		} );

		var gst = sub * data.gst / 100;
		$( '[data-quote-sub]' ).textContent = rupee( sub );
		$( '[data-quote-gst]' ).textContent = rupee( gst );
		$( '[data-quote-total]' ).textContent = rupee( sub + gst );
		$$( '[data-quote-items]' ).forEach( function ( f ) {
			f.value = quoteText( items ) + ( items.length ? '\nEstimated total: ' + rupee( sub + gst ) + ' incl. GST (plain t-shirts)' : '' );
		} );
	}
	renderQuote();

	/* ---------- Enquiry forms → WhatsApp ---------- */
	$$( '[data-enquiry]' ).forEach( function ( form ) {
		var btn = $( '[data-form-wa]', form );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			var f = form.elements;
			var lines = [ 'Hi Looma Apparels, new enquiry from the website:' ];
			if ( f.name.value ) { lines.push( 'Name: ' + f.name.value ); }
			if ( f.phone.value ) { lines.push( 'Phone: ' + f.phone.value ); }
			if ( f.email.value ) { lines.push( 'Email: ' + f.email.value ); }
			if ( f.order_type ) { lines.push( 'Interested in: ' + f.order_type.value ); }
			if ( f.quantity && f.quantity.value ) { lines.push( 'Quantity: ' + f.quantity.value ); }
			if ( f.items && f.items.value ) { lines.push( 'Quote list:\n' + f.items.value ); }
			if ( f.message.value ) { lines.push( 'Message: ' + f.message.value ); }
			window.open( waLink( lines.join( '\n' ) ), '_blank', 'noopener' );
		} );
		// Clear the quote list once it has been emailed.
		if ( $( '[data-quote-items]', form ) && /[?&]enquiry=sent/.test( window.location.search ) ) {
			writeQuote( [] );
			renderQuote();
		}
	} );

	/* ---------- Price estimator ---------- */
	var calc = { product: $( '#calc-product' ), qty: $( '#calc-qty' ), front: $( '#calc-front' ), back: $( '#calc-back' ), wa: $( '#calc-wa' ) };
	function printPrice( key, qty ) {
		if ( ! key || ! data.dtf[ key ] ) { return 0; }
		return qty >= 10 ? data.dtf[ key ].bulk : data.dtf[ key ].single;
	}
	function setText( id, text ) { var el = document.getElementById( id ); if ( el ) { el.textContent = text; } }
	function updateCalc() {
		var product = findProduct( calc.product.value ) || data.products[ 0 ];
		var qty = Math.max( 1, parseInt( calc.qty.value, 10 ) || 1 );
		var base = tierPrice( product.prices, qty );
		var front = printPrice( calc.front.value, qty );
		var back = printPrice( calc.back.value, qty );
		var bigPrint = /^a[234]$/.test( calc.front.value ) || /^a[234]$/.test( calc.back.value );
		var per = base + front + back;
		var sub = per * qty;
		var gst = sub * data.gst / 100;

		setText( 'r-base', rupee( base ) + ' / pc' );
		setText( 'r-print', front + back ? rupee( front + back ) + ' / pc' : '—' );
		setText( 'r-label', bigPrint ? 'FREE' : 'Free with A2/A3/A4 print' );
		setText( 'r-per', rupee( per ) );
		setText( 'r-sub-label', 'Subtotal (' + qty + ' pc' + ( qty > 1 ? 's' : '' ) + ')' );
		setText( 'r-sub', rupee( sub ) );
		setText( 'r-gst', rupee( gst ) );
		setText( 'r-total', rupee( sub + gst ) );

		if ( calc.wa ) {
			var optText = function ( sel ) { return sel.value ? sel.options[ sel.selectedIndex ].text : 'None'; };
			calc.wa.href = waLink(
				'Hi Looma Apparels, I would like a quote:\n' +
				'• T-shirt: ' + product.name + '\n' +
				'• Quantity: ' + qty + ' pcs\n' +
				'• Front print: ' + optText( calc.front ) + '\n' +
				'• Back print: ' + optText( calc.back ) + '\n' +
				'Website estimate: ' + rupee( per ) + ' per piece, ' + rupee( sub + gst ) + ' total incl. GST.'
			);
		}
	}
	if ( calc.product ) {
		[ calc.product, calc.qty, calc.front, calc.back ].forEach( function ( el ) {
			el.addEventListener( 'input', updateCalc );
			el.addEventListener( 'change', updateCalc );
		} );
		updateCalc();
	}

	/* ---------- Reveal on scroll ---------- */
	if ( 'IntersectionObserver' in window && ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-visible' );
					io.unobserve( entry.target );
				}
			} );
		}, { rootMargin: '0px 0px -6% 0px' } );
		$$( '.section-head, .split-card, .usp, .steps li, .tech-item, .guide-card, .stats > div, .contact-card, .ideal-list li' ).forEach( function ( el ) {
			el.classList.add( 'reveal' );
			io.observe( el );
		} );
	}
}() );
