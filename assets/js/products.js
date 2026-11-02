/* global jQuery, MrkvUampProducts, wp, inlineEditPost */
( function ( $ ) {
	'use strict';

	// "Main image URL" on the product screen: pick from the media library.
	$( '[data-mrkv-uamp-media]' ).each( function () {
		var $input = $( this );
		var $button = $( '<button type="button" class="button" style="margin-left:4px"></button>' ).text( MrkvUampProducts.i18n.choose );
		$input.after( $button );
		$button.on( 'click', function ( event ) {
			event.preventDefault();
			var frame = wp.media( { title: MrkvUampProducts.i18n.choose, button: { text: MrkvUampProducts.i18n.use }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				$input.val( frame.state().get( 'selection' ).first().toJSON().url ).trigger( 'change' );
			} );
			frame.open();
		} );
	} );

	// Quick Edit: copy the marketplace names from the hidden row data into the form.
	if ( typeof inlineEditPost !== 'undefined' ) {
		var edit = inlineEditPost.edit;
		inlineEditPost.edit = function ( id ) {
			edit.apply( this, arguments );
			var postId = typeof id === 'object' ? parseInt( this.getId( id ), 10 ) : id;
			if ( ! postId ) {
				return;
			}
			var $data = $( '#mrkv_uamp_inline_' + postId );
			var $row = $( '#edit-' + postId );
			$.each( MrkvUampProducts.slugs, function ( i, slug ) {
				$row.find( '.mrkv-uamp-qe-' + slug ).val( $data.find( '.' + slug ).text() );
			} );
		};
	}
}( jQuery ) );
