/**
 * Looma Apparels — front-end behaviour.
 * Mobile menu, product filter & details pop-up, size tabs,
 * price estimator and WhatsApp enquiry helpers.
 */
( function () {
	'use strict';

	var data = window.LOOMA || { products: [], dtf: {}, gst: 5, whatsapp: '' };

	function $( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function $$( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }
	function rupee( n ) { return '₹' + Math.round( n ).toLocaleString( 'en-IN' ); }
	function waLink( text ) { return 'https://wa.me/' + data.whatsapp + '?text=' + encodeURIComponent( text ); }

	/* ---------- Mobile menu ---------- */
	var toggle = $( '.nav-toggle' );
	var nav = $( '#primary-nav' );
	if ( toggle && nav ) {
		var setOpen = function ( open ) {
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			document.body.classList.toggle( 'nav-open', open );
		};
		toggle.addEventListener( 'click', function () {
			setOpen( toggle.getAttribute( 'aria-expanded' ) !== 'true' );
		} );
		nav.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( 'a' ) ) { setOpen( false ); }
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { setOpen( false ); }
		} );
	}

	/* ---------- Header shadow on scroll ---------- */
	var header = $( '.site-header' );
	if ( header ) {
		var onScroll = function () { header.classList.toggle( 'is-scrolled', window.scrollY > 10 ); };
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();
	}

	/* ---------- Product filter ---------- */
	$$( '.filter .chip' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			var fit = chip.getAttribute( 'data-filter' );
			$$( '.filter .chip' ).forEach( function ( c ) { c.classList.toggle( 'is-active', c === chip ); } );
			$$( '.product-card' ).forEach( function ( card ) {
				card.hidden = fit !== 'all' && card.getAttribute( 'data-fit' ) !== fit;
			} );
		} );
	} );

	/* ---------- Product details dialog ---------- */
	var dialog = $( '#product-dialog' );
	var dialogContent = dialog ? $( '.dialog-content', dialog ) : null;

	function closeDialog() {
		if ( ! dialog ) { return; }
		if ( typeof dialog.close === 'function' ) { dialog.close(); } else { dialog.removeAttribute( 'open' ); }
		document.body.classList.remove( 'dialog-open' );
	}

	$$( '.product-open' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tpl = document.getElementById( 'tpl-' + btn.getAttribute( 'data-product' ) );
			if ( ! dialog || ! tpl ) { return; }
			dialogContent.innerHTML = '';
			dialogContent.appendChild( tpl.content.cloneNode( true ) );
			if ( typeof dialog.showModal === 'function' ) { dialog.showModal(); } else { dialog.setAttribute( 'open', '' ); }
			document.body.classList.add( 'dialog-open' );
			dialog.scrollTop = 0;
		} );
	} );

	if ( dialog ) {
		dialog.addEventListener( 'click', function ( e ) {
			// Click on the backdrop (outside the content box).
			if ( e.target === dialog ) { closeDialog(); return; }
			var closer = e.target.closest( '[data-close]' );
			if ( ! closer ) { return; }
			var calcId = closer.getAttribute( 'data-calc' );
			if ( calcId ) { setCalcProduct( calcId ); }
			closeDialog();
		} );
		dialog.addEventListener( 'close', function () { document.body.classList.remove( 'dialog-open' ); } );
	}

	/* ---------- Size chart tabs ---------- */
	var tabs = $$( '.tabs .tab' );
	tabs.forEach( function ( tab, i ) {
		tab.addEventListener( 'click', function () { selectTab( tab ); } );
		tab.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
				var next = tabs[ ( i + ( e.key === 'ArrowRight' ? 1 : tabs.length - 1 ) ) % tabs.length ];
				selectTab( next );
				next.focus();
			}
		} );
	} );
	function selectTab( tab ) {
		tabs.forEach( function ( t ) {
			var on = t === tab;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			var panel = document.getElementById( t.getAttribute( 'aria-controls' ) );
			if ( panel ) { panel.hidden = ! on; }
		} );
	}
	if ( tabs.length ) { selectTab( tabs[ 0 ] ); }

	/* ---------- Price estimator ---------- */
	var calc = {
		product: $( '#calc-product' ),
		qty: $( '#calc-qty' ),
		front: $( '#calc-front' ),
		back: $( '#calc-back' ),
		wa: $( '#calc-wa' )
	};

	function findProduct( id ) {
		for ( var i = 0; i < data.products.length; i++ ) {
			if ( data.products[ i ].id === id ) { return data.products[ i ]; }
		}
		return data.products[ 0 ];
	}

	// Price tier for a quantity: the highest tier whose minimum is <= qty.
	function tierPrice( prices, qty ) {
		var price = prices[ 0 ].price;
		prices.forEach( function ( t ) { if ( qty >= t.min ) { price = t.price; } } );
		return price;
	}

	function printPrice( key, qty ) {
		if ( ! key || ! data.dtf[ key ] ) { return 0; }
		return qty >= 10 ? data.dtf[ key ].bulk : data.dtf[ key ].single;
	}

	function setText( id, text ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = text; }
	}

	function updateCalc() {
		if ( ! calc.product ) { return; }
		var product = findProduct( calc.product.value );
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

	function setCalcProduct( id ) {
		if ( ! calc.product ) { return; }
		calc.product.value = id;
		updateCalc();
	}

	if ( calc.product ) {
		[ calc.product, calc.qty, calc.front, calc.back ].forEach( function ( el ) {
			el.addEventListener( 'input', updateCalc );
			el.addEventListener( 'change', updateCalc );
		} );
		updateCalc();
	}

	/* ---------- Enquiry form → WhatsApp ---------- */
	var formWa = $( '#form-wa' );
	var form = $( '#enquiry-form' );
	if ( formWa && form ) {
		formWa.addEventListener( 'click', function () {
			var f = form.elements;
			var lines = [ 'Hi Looma Apparels, new enquiry from the website:' ];
			if ( f.name.value ) { lines.push( 'Name: ' + f.name.value ); }
			if ( f.phone.value ) { lines.push( 'Phone: ' + f.phone.value ); }
			if ( f.email.value ) { lines.push( 'Email: ' + f.email.value ); }
			lines.push( 'Interested in: ' + f.order_type.value );
			if ( f.quantity.value ) { lines.push( 'Quantity: ' + f.quantity.value ); }
			if ( f.message.value ) { lines.push( 'Message: ' + f.message.value ); }
			window.open( waLink( lines.join( '\n' ) ), '_blank', 'noopener' );
		} );
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
		}, { rootMargin: '0px 0px -8% 0px' } );
		$$( '.section-head, .product-card, .service-card, .tech-card, .guide-card, .steps li, .calc, .size-wrap' ).forEach( function ( el ) {
			el.classList.add( 'reveal' );
			io.observe( el );
		} );
	}
}() );
