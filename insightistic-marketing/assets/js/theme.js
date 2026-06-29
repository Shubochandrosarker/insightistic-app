/**
 * Insightistic Marketing — core interactions (vanilla, no jQuery).
 * Mobile menu, header scroll state. The FAQ uses native <details>.
 */
(function () {
	'use strict';

	var burger = document.getElementById( 'ins-burger' );
	var panel = document.getElementById( 'ins-mobile' );
	var header = document.getElementById( 'ins-header' );

	/* ---- Mobile menu ---- */
	if ( burger && panel ) {
		var setOpen = function ( open ) {
			burger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			burger.setAttribute( 'aria-label', open ? 'Close menu' : 'Open menu' );
			if ( open ) {
				panel.hidden = false;
			} else {
				panel.hidden = true;
			}
		};

		burger.addEventListener( 'click', function () {
			setOpen( panel.hidden );
		} );

		// Close on navigation or Escape.
		panel.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( 'a' ) ) {
				setOpen( false );
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! panel.hidden ) {
				setOpen( false );
				burger.focus();
			}
		} );

		// Reset when resizing up to desktop.
		var mq = window.matchMedia( '(min-width: 1024px)' );
		var onChange = function () {
			if ( mq.matches ) {
				setOpen( false );
			}
		};
		if ( mq.addEventListener ) {
			mq.addEventListener( 'change', onChange );
		} else if ( mq.addListener ) {
			mq.addListener( onChange );
		}
	}

	/* ---- Header scroll shadow ---- */
	if ( header ) {
		var onScroll = function () {
			if ( window.scrollY > 8 ) {
				header.classList.add( 'is-scrolled' );
			} else {
				header.classList.remove( 'is-scrolled' );
			}
		};
		onScroll();
		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}
})();
