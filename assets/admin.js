/**
 * GuardLMS settings screen behaviour.
 *
 * Enqueued only on the plugin's own screen, see
 * GuardLMS_Settings::enqueue_assets(). Forms that carry a
 * data-guardlms-confirm attribute ask for confirmation before submitting;
 * the attribute holds the (already translated) prompt.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.hasAttribute || ! form.hasAttribute( 'data-guardlms-confirm' ) ) {
			return;
		}

		if ( ! window.confirm( form.getAttribute( 'data-guardlms-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
} )();
