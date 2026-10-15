/* Tacilytics admin: confirm before disconnecting. */
( function () {
	var form = document.querySelector( '.tacilytics-reset[data-confirm]' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'submit', function ( ev ) {
		if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
			ev.preventDefault();
		}
	} );
	// The masked key is shown as "tk_abcd••••". Select it on focus so typing replaces it.
	var key = document.getElementById( 'tacilytics-api-key' );
	if ( key && key.value.indexOf( '•' ) !== -1 ) {
		key.addEventListener( 'focus', function () {
			key.select();
		} );
	}
} )();
