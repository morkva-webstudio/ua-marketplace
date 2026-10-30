/* global jQuery, MrkvUamp */
( function ( $ ) {
	'use strict';

	var i18n = MrkvUamp.i18n;

	// Copy a feed URL.
	$( document ).on( 'click', '[data-mrkv-uamp-copy]', function () {
		var $button = $( this );
		var text = $button.data( 'mrkvUampCopy' );
		var done = function () {
			var label = $button.text();
			$button.text( i18n.copied );
			setTimeout( function () {
				$button.text( label );
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			$button.siblings( 'input' ).trigger( 'select' );
			document.execCommand( 'copy' );
			done();
		}
	} );

	// Rows shown only for one value of a select or a ticked checkbox ("1"):
	// data-mrkv-uamp-show="group:value". A locked (Pro) control shows all its rows as a preview.
	function toggleRows() {
		$( '[data-mrkv-uamp-toggle]' ).not( ':disabled' ).each( function () {
			var group = $( this ).data( 'mrkvUampToggle' );
			var value = 'checkbox' === this.type ? ( this.checked ? '1' : '' ) : $( this ).val();
			$( '[data-mrkv-uamp-show^="' + group + ':"]' ).each( function () {
				this.hidden = $( this ).data( 'mrkvUampShow' ) !== group + ':' + value;
			} );
		} );
	}
	$( document ).on( 'change', '[data-mrkv-uamp-toggle]', toggleRows );
	toggleRows();

	// Changing the folder or file name changes the URL the marketplace downloads.
	$( '.mrkv-uamp-settings' ).on( 'submit', function () {
		var changed = $( this ).find( '[data-mrkv-uamp-url-field]' ).filter( function () {
			return this.value !== String( $( this ).data( 'mrkvUampUrlField' ) );
		} ).length;
		return ! changed || window.confirm( i18n.urlChanges );
	} );

	// Rozetka category picker: search the local directory; a typed number can be used as is.
	if ( $.fn.selectWoo ) {
		$( '.mrkv-uamp-rz-picker' ).selectWoo( {
			allowClear: true,
			placeholder: { id: '', text: '—' },
			minimumInputLength: 2,
			tags: true,
			createTag: function ( params ) {
				var term = $.trim( params.term );
				return /^\d+$/.test( term ) ? { id: term, text: i18n.useId.replace( '%s', term ), newTag: true } : null;
			},
			ajax: {
				url: MrkvUamp.ajaxUrl,
				delay: 250,
				dataType: 'json',
				data: function ( params ) {
					return { action: 'mrkv_uamp_rozetka_search', _ajax_nonce: MrkvUamp.nonce, term: params.term };
				},
				processResults: function ( data ) {
					return { results: data && data.results ? data.results : [] };
				}
			},
			language: {
				inputTooShort: function () {
					return i18n.typeMore;
				},
				searching: function () {
					return i18n.searching;
				},
				noResults: function () {
					return i18n.noResults;
				}
			}
		} );
	}

	// Build progress on the Feeds tab, while at least one feed is building.
	var $running = $( 'tr[data-mrkv-uamp-running]' );
	if ( $running.length ) {
		var poll = function () {
			$.get( MrkvUamp.ajaxUrl, { action: 'mrkv_uamp_feed_status', _ajax_nonce: MrkvUamp.nonce } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					return;
				}
				var stillRunning = false;
				$.each( response.data, function ( slug, feed ) {
					var $row = $( 'tr[data-mrkv-uamp-feed="' + slug + '"]' );
					if ( ! $row.length ) {
						return;
					}
					if ( feed.running ) {
						stillRunning = true;
						$row.find( '.mrkv-uamp-progress' ).prop( 'hidden', false );
						$row.find( 'progress' ).attr( { max: Math.max( 1, feed.total ), value: feed.products } );
						$row.find( '.mrkv-uamp-progress__text' ).text( feed.progress );
					} else if ( $row.is( '[data-mrkv-uamp-running]' ) ) {
						// Finished: reload once to show the new file, its time and any error.
						window.location.reload();
					}
				} );
				if ( stillRunning ) {
					setTimeout( poll, 3000 );
				}
			} );
		};
		setTimeout( poll, 3000 );
	}
}( jQuery ) );
