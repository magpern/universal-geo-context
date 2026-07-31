( function () {
	'use strict';

	function stickySaveModule() {
		document.querySelectorAll( '[data-ugc-sticky-save]' ).forEach( function ( bar ) {
			var scope = bar.getAttribute( 'data-ugc-sticky-scope' ) || 'default';
			var root = document.querySelector( '[data-ugc-sticky-root="' + scope + '"]' );

			if ( ! root ) {
				return;
			}

			var unsaved = bar.querySelector( '[data-ugc-unsaved-indicator]' );
			var discard = bar.querySelector( '[data-ugc-sticky-discard]' );
			var saved = bar.querySelector( '[data-ugc-sticky-saved]' );
			var initial = serialize( root );

			function serialize( container ) {
				var data = {};
				container.querySelectorAll( 'input, select, textarea' ).forEach( function ( field ) {
					if ( ! field.name || field.disabled ) {
						return;
					}

					if ( 'checkbox' === field.type ) {
						data[ field.name ] = field.checked ? field.value : '0';
						return;
					}

					if ( 'radio' === field.type && ! field.checked ) {
						return;
					}

					data[ field.name ] = field.value;
				} );
				return JSON.stringify( data );
			}

			function setDirty( dirty ) {
				if ( unsaved ) {
					unsaved.hidden = ! dirty;
				}
				if ( discard ) {
					discard.hidden = ! dirty;
				}
				if ( saved ) {
					saved.hidden = dirty;
				}
			}

			root.addEventListener( 'input', function () {
				setDirty( serialize( root ) !== initial );
			} );

			root.addEventListener( 'change', function () {
				setDirty( serialize( root ) !== initial );
			} );

			if ( discard ) {
				discard.addEventListener( 'click', function () {
					window.location.reload();
				} );
			}

			bar.closest( 'form' )?.addEventListener( 'submit', function () {
				setDirty( false );
				if ( saved ) {
					saved.hidden = false;
				}
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', stickySaveModule );
	} else {
		stickySaveModule();
	}
}() );
