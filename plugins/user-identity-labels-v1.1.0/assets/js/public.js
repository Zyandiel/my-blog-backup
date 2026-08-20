( function () {
	'use strict';

	var templates = Array.prototype.slice.call(
		document.querySelectorAll( 'template.uil-dom-template, template#uil-dom-template' )
	);
	if ( ! templates.length ) {
		return;
	}

	var scheduled = false;

	function placeTemplate( template ) {
		if ( ! template.content ) {
			return;
		}

		var selector = template.getAttribute( 'data-selector' );
		var position = template.getAttribute( 'data-position' ) === 'beforeend' ? 'beforeend' : 'afterend';
		var templateId = template.id || 'uil-dom-template';
		var target;

		try {
			target = document.querySelector( selector );
		} catch ( error ) {
			return;
		}

		if ( ! target || target.getAttribute( 'data-uil-placement-' + templateId ) === '1' ) {
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );
		node.setAttribute( 'data-uil-placement-source', templateId );
		target.setAttribute( 'data-uil-placement-' + templateId, '1' );

		try {
			target.insertAdjacentElement( position, node );
		} catch ( error ) {
			target.removeAttribute( 'data-uil-placement-' + templateId );
		}
	}

	function placeLabels() {
		scheduled = false;
		templates.forEach( placeTemplate );
	}

	function schedulePlacement() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( placeLabels );
	}

	placeLabels();

	// Argon uses PJAX and can rebuild the sidebar without reloading this script.
	if ( 'MutationObserver' in window ) {
		var observer = new MutationObserver( schedulePlacement );
		observer.observe( document.body, { childList: true, subtree: true } );
	}
}() );
