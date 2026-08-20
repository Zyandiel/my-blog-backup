( function () {
	'use strict';

	var template = document.getElementById( 'uil-dom-template' );
	if ( ! template || ! template.content ) {
		return;
	}

	var selector = template.getAttribute( 'data-selector' );
	var position = template.getAttribute( 'data-position' ) === 'beforeend' ? 'beforeend' : 'afterend';
	var scheduled = false;

	function hasPlacement( target ) {
		if ( target.getAttribute( 'data-uil-labels-inserted' ) === '1' ) {
			return true;
		}

		if ( position === 'beforeend' ) {
			return !! target.querySelector( '.uil-auto-wrap--selector, .uil-labels' );
		}

		var sibling = target.nextElementSibling;
		return !! ( sibling && ( sibling.matches( '.uil-auto-wrap--selector' ) || sibling.querySelector( '.uil-labels' ) ) );
	}

	function placeLabels() {
		scheduled = false;
		var target;
		try {
			target = document.querySelector( selector );
		} catch ( error ) {
			return;
		}

		if ( ! target || hasPlacement( target ) ) {
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );
		target.setAttribute( 'data-uil-labels-inserted', '1' );
		try {
			target.insertAdjacentElement( position, node );
		} catch ( error ) {
			return;
		}
	}

	function schedulePlacement() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( placeLabels );
	}

	placeLabels();

	if ( 'MutationObserver' in window ) {
		var observer = new MutationObserver( schedulePlacement );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () {
			observer.disconnect();
		}, 15000 );
	}
}() );
