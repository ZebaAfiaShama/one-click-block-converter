/**
 * One Click Block Converter — admin app.
 *
 * Conversion happens in the browser with wp.blocks.rawHandler(), the exact
 * converter behind the block editor's own "Convert to Blocks" button, then the
 * serialized block markup is saved through a nonce-protected REST endpoint.
 */
( function ( wp ) {
	'use strict';

	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var state = {
		classic: [],
		converted: [],
		running: false,
	};

	function registerBlocks() {
		// The block library must be registered for rawHandler to know the
		// available block types. Guard against double registration.
		if ( ! wp.blocks.getBlockType( 'core/paragraph' ) ) {
			wp.blockLibrary.registerCoreBlocks();
		}
	}

	function fetchAll( status ) {
		var results = [];

		function fetchPage( page ) {
			return apiFetch( {
				path:
					'/ocbc/v1/posts?status=' +
					status +
					'&per_page=' +
					OCBC.perPage +
					'&page=' +
					page,
			} ).then( function ( res ) {
				results = results.concat( res.posts );
				if ( page < res.total_pages ) {
					return fetchPage( page + 1 );
				}
				return results;
			} );
		}

		return fetchPage( 1 );
	}

	function convertContent( html ) {
		// Classic content is stored raw; WordPress adds paragraph tags at
		// render time via wpautop. Apply the same normalization first so
		// double line breaks become separate paragraph blocks. autop is a
		// no-op on content that already has block-level markup.
		var blocks = wp.blocks.rawHandler( { HTML: wp.autop.autop( html ) } );
		if ( ! blocks || ! blocks.length ) {
			return null;
		}
		return wp.blocks.serialize( blocks );
	}

	function convertPost( post ) {
		var serialized;
		try {
			serialized = convertContent( post.content );
		} catch ( err ) {
			return Promise.reject(
				new Error( err && err.message ? err.message : __( 'Conversion failed.', 'one-click-block-converter' ) )
			);
		}

		if ( null === serialized ) {
			return Promise.reject( new Error( __( 'Nothing to convert.', 'one-click-block-converter' ) ) );
		}

		return apiFetch( {
			path: '/ocbc/v1/convert',
			method: 'POST',
			data: { id: post.id, content: serialized },
		} );
	}

	function revertPost( post ) {
		return apiFetch( {
			path: '/ocbc/v1/revert',
			method: 'POST',
			data: { id: post.id },
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */

	var app;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'html' === key ) {
				node.innerHTML = attrs[ key ];
			} else if ( 0 === key.indexOf( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), attrs[ key ] );
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			node.appendChild( child );
		} );
		return node;
	}

	function statusBadge( post ) {
		return el( 'span', {
			class: 'ocbc-badge ocbc-badge-' + ( post.uiState || 'pending' ),
			text: post.uiMessage || post.status,
		} );
	}

	function rowFor( post, isConverted ) {
		var actionButton;

		if ( isConverted ) {
			actionButton = el( 'button', {
				type: 'button',
				class: 'button',
				text: __( 'Revert', 'one-click-block-converter' ),
				onclick: function () {
					post.uiState = 'working';
					post.uiMessage = __( 'Reverting…', 'one-click-block-converter' );
					render();
					revertPost( post )
						.then( function () {
							state.converted = state.converted.filter( function ( p ) {
								return p.id !== post.id;
							} );
							return refresh();
						} )
						.catch( function ( err ) {
							post.uiState = 'error';
							post.uiMessage = err.message || __( 'Revert failed.', 'one-click-block-converter' );
							render();
						} );
				},
			} );
		} else {
			actionButton = el( 'button', {
				type: 'button',
				class: 'button',
				text: __( 'Convert', 'one-click-block-converter' ),
				onclick: function () {
					runQueue( [ post ] );
				},
			} );
		}

		if ( state.running || 'working' === post.uiState ) {
			actionButton.setAttribute( 'disabled', 'disabled' );
		}

		return el( 'tr', { 'data-id': post.id }, [
			el( 'td', {}, [
				el( 'a', { href: post.edit_link, target: '_blank', text: post.title } ),
			] ),
			el( 'td', { text: post.type } ),
			el( 'td', {}, [ statusBadge( post ) ] ),
			el( 'td', { class: 'ocbc-actions' }, [ actionButton ] ),
		] );
	}

	function table( posts, isConverted, emptyText ) {
		if ( ! posts.length ) {
			return el( 'p', { class: 'ocbc-empty', text: emptyText } );
		}

		return el( 'table', { class: 'widefat striped ocbc-table' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', { text: __( 'Title', 'one-click-block-converter' ) } ),
					el( 'th', { text: __( 'Type', 'one-click-block-converter' ) } ),
					el( 'th', { text: __( 'Status', 'one-click-block-converter' ) } ),
					el( 'th', { text: __( 'Action', 'one-click-block-converter' ) } ),
				] ),
			] ),
			el(
				'tbody',
				{},
				posts.map( function ( post ) {
					return rowFor( post, isConverted );
				} )
			),
		] );
	}

	function render() {
		app.innerHTML = '';

		var convertAll = el( 'button', {
			type: 'button',
			class: 'button button-primary button-hero',
			text: state.running
				? sprintf(
						/* translators: 1: done count, 2: total count */
						__( 'Converting… %1$d / %2$d', 'one-click-block-converter' ),
						state.done,
						state.queueTotal
				  )
				: sprintf(
						/* translators: %d: number of posts */
						__( 'Convert All to Blocks (%d)', 'one-click-block-converter' ),
						state.classic.length
				  ),
			onclick: function () {
				runQueue( state.classic.slice() );
			},
		} );

		if ( state.running || ! state.classic.length ) {
			convertAll.setAttribute( 'disabled', 'disabled' );
		}

		var toolbar = el( 'div', { class: 'ocbc-toolbar' }, [ convertAll ] );

		if ( state.running ) {
			var pct = state.queueTotal ? Math.round( ( state.done / state.queueTotal ) * 100 ) : 0;
			toolbar.appendChild(
				el( 'div', { class: 'ocbc-progress' }, [
					el( 'div', { class: 'ocbc-progress-bar', style: 'width:' + pct + '%' } ),
				] )
			);
		}

		app.appendChild( toolbar );

		app.appendChild(
			el( 'h2', {
				text: sprintf(
					/* translators: %d: number of posts */
					__( 'Classic content (%d)', 'one-click-block-converter' ),
					state.classic.length
				),
			} )
		);
		app.appendChild(
			table(
				state.classic,
				false,
				__( 'No classic content found — everything is already blocks. 🎉', 'one-click-block-converter' )
			)
		);

		app.appendChild(
			el( 'h2', {
				text: sprintf(
					/* translators: %d: number of posts */
					__( 'Converted — revert available (%d)', 'one-click-block-converter' ),
					state.converted.length
				),
			} )
		);
		app.appendChild(
			table(
				state.converted,
				true,
				__( 'Nothing converted yet.', 'one-click-block-converter' )
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Conversion queue (bounded concurrency)
	 * ------------------------------------------------------------------ */

	function runQueue( posts ) {
		if ( state.running || ! posts.length ) {
			return;
		}

		state.running = true;
		state.queueTotal = posts.length;
		state.done = 0;

		var queue = posts.slice();

		function next() {
			var post = queue.shift();
			if ( ! post ) {
				return Promise.resolve();
			}

			post.uiState = 'working';
			post.uiMessage = __( 'Converting…', 'one-click-block-converter' );
			render();

			return convertPost( post )
				.then( function () {
					post.uiState = 'done';
					post.uiMessage = __( 'Converted', 'one-click-block-converter' );
				} )
				.catch( function ( err ) {
					post.uiState = 'error';
					post.uiMessage =
						( err && err.message ) || __( 'Failed', 'one-click-block-converter' );
				} )
				.then( function () {
					state.done++;
					render();
					return next();
				} );
		}

		var workers = [];
		for ( var i = 0; i < Math.min( OCBC.concurrency, queue.length ); i++ ) {
			workers.push( next() );
		}

		Promise.all( workers ).then( function () {
			state.running = false;
			refresh();
		} );
	}

	function refresh() {
		return Promise.all( [ fetchAll( 'classic' ), fetchAll( 'converted' ) ] ).then(
			function ( results ) {
				// Keep rows that errored this run visible with their message.
				var errors = state.classic.filter( function ( p ) {
					return 'error' === p.uiState;
				} );
				state.classic = results[ 0 ].map( function ( post ) {
					var errored = errors.find( function ( p ) {
						return p.id === post.id;
					} );
					return errored || post;
				} );
				state.converted = results[ 1 ];
				render();
			}
		);
	}

	wp.domReady( function () {
		app = document.getElementById( 'ocbc-app' );
		if ( ! app ) {
			return;
		}
		registerBlocks();
		refresh().catch( function ( err ) {
			app.innerHTML = '';
			app.appendChild(
				el( 'div', { class: 'notice notice-error' }, [
					el( 'p', { text: err.message || __( 'Failed to load posts.', 'one-click-block-converter' ) } ),
				] )
			);
		} );
	} );
} )( window.wp );
