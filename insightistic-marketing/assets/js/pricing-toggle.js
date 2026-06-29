/**
 * Pricing monthly/yearly toggle (vanilla). Swaps each plan's displayed amount
 * from its data-monthly / data-yearly attributes.
 */
(function () {
	'use strict';

	var toggles = document.querySelectorAll( '.ins-pricing-toggle' );
	if ( ! toggles.length ) {
		return;
	}

	function apply( period ) {
		document.querySelectorAll( '.ins-plan__amount' ).forEach( function ( el ) {
			var val = el.getAttribute( 'data-' + period );
			if ( val ) {
				el.textContent = val;
			}
		} );
		document.querySelectorAll( '.ins-plan__per' ).forEach( function ( el ) {
			el.textContent = 'yearly' === period ? '/mo billed yearly' : '/mo';
		} );
	}

	toggles.forEach( function ( toggle ) {
		toggle.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button[data-period]' );
			if ( ! btn ) {
				return;
			}
			var period = btn.getAttribute( 'data-period' );
			toggle.setAttribute( 'data-period', period );

			toggle.querySelectorAll( 'button' ).forEach( function ( b ) {
				var active = b === btn;
				b.classList.toggle( 'is-active', active );
				b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );

			apply( period );
		} );
	} );
})();
