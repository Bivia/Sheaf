/**
 * Drag-and-drop chapter reordering on the Books settings screen.
 *
 * Uses WordPress's bundled jquery-ui-sortable (no build step) to reorder the
 * rows of the chapters table. On drop it renumbers the reading positions and
 * saves the new order over AJAX.
 */
( function ( $ ) {
	$( function () {
		var $body = $( '#sheaf-reorder' ); // The sortable <tbody>.
		if ( ! $body.length || typeof SheafReorder === 'undefined' ) {
			return;
		}

		var $status = $( '#sheaf-reorder-status' );

		function renumber() {
			var n = 0;
			$body.children( 'tr' ).each( function () {
				var $num = $( this ).find( '.sheaf-reorder__num' );
				if ( $( this ).hasClass( 'is-section' ) ) {
					$num.text( '·' ); // Sections are not numbered.
				} else {
					n += 1;
					$num.text( n );
				}
			} );
		}

		function save() {
			var order = $body
				.children( 'tr' )
				.map( function () {
					return $( this ).data( 'id' );
				} )
				.get();

			$status.text( SheafReorder.savingText || 'Saving…' );

			$.post( SheafReorder.ajax, {
				action: 'sheaf_reorder',
				nonce: SheafReorder.nonce,
				book: $body.data( 'book' ),
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

		$body.sortable( {
			items: '> tr',
			handle: '.sheaf-reorder__handle',
			axis: 'y',
			cursor: 'grabbing',
			// Drag the row itself (not a clone) with its cell widths pinned, so
			// the columns don't collapse mid-drag. Returning a clone here leaves
			// the original row in place and the drop reverts — which looks like a
			// working drag that never saves.
			helper: function ( event, $row ) {
				$row.children().each( function () {
					$( this ).width( $( this ).width() );
				} );
				return $row;
			},
			placeholder: 'sheaf-reorder__placeholder',
			forcePlaceholderSize: true,
			start: function ( event, ui ) {
				// Give the empty placeholder row a cell so it keeps a row's height.
				ui.placeholder.html( '<td colspan="5">&nbsp;</td>' );
			},
			update: function () {
				renumber();
				save();
			}
		} );
	} );
} )( jQuery );
