/**
 * Drag-and-drop chapter reordering on the Books settings screen.
 *
 * Uses WordPress's bundled jquery-ui-sortable (no build step). On drop it
 * renumbers the list and saves the new order over AJAX.
 */
( function ( $ ) {
	$( function () {
		var $list = $( '#sheaf-reorder' );
		if ( ! $list.length || typeof SheafReorder === 'undefined' ) {
			return;
		}

		var $status = $( '#sheaf-reorder-status' );

		function renumber() {
			$list.children( 'li' ).each( function ( index ) {
				$( this ).find( '.sheaf-reorder__num' ).text( index + 1 );
			} );
		}

		function save() {
			var order = $list
				.children( 'li' )
				.map( function () {
					return $( this ).data( 'id' );
				} )
				.get();

			$status.text( SheafReorder.savingText || 'Saving…' );

			$.post( SheafReorder.ajax, {
				action: 'sheaf_reorder',
				nonce: SheafReorder.nonce,
				book: $list.data( 'book' ),
				order: order
			} )
				.done( function ( res ) {
					$status.text(
						res && res.success
							? SheafReorder.savedText || 'Order saved.'
							: SheafReorder.failedText || 'Save failed.'
					);
				} )
				.fail( function () {
					$status.text( SheafReorder.failedText || 'Save failed.' );
				} );
		}

		$list.sortable( {
			handle: '.sheaf-reorder__handle',
			placeholder: 'sheaf-reorder__placeholder',
			forcePlaceholderSize: true,
			axis: 'y',
			update: function () {
				renumber();
				save();
			}
		} );
	} );
} )( jQuery );
