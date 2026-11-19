/**
 * Front-end behaviour of RMD Events Organizer. Everything works without this
 * script; it adds comfort on top of the server-rendered markup:
 *
 * - hides dates that have ended since a cached page was generated,
 * - switches calendar months without reloading the page,
 * - opens the day list of the small calendar as a popover.
 */
( function () {
	'use strict';

	function hidePast( root ) {
		const now = Math.floor( Date.now() / 1000 );
		root.querySelectorAll( '[data-rmd-eo-upcoming]' ).forEach(
			function ( list ) {
				const items = list.querySelectorAll( '[data-end]' );
				let visible = 0;
				items.forEach( function ( item ) {
					const over =
						parseInt( item.getAttribute( 'data-end' ), 10 ) < now;
					item.hidden = over;
					if ( ! over ) {
						visible++;
					}
				} );
				// Headings and lists whose dates are all over go as well.
				list.querySelectorAll( '.rmd-eo-list__items' ).forEach(
					function ( group ) {
						const empty = ! group.querySelector(
							'[data-end]:not([hidden])'
						);
						group.hidden = empty;
						const heading = group.previousElementSibling;
						if (
							heading &&
							heading.classList.contains( 'rmd-eo-list__group' )
						) {
							heading.hidden = empty;
						}
					}
				);
				if ( items.length > 0 && visible === 0 ) {
					list.classList.add( 'is-all-past' );
				}
			}
		);
	}

	function localToday() {
		const now = new Date();
		return (
			now.getFullYear() +
			'-' +
			String( now.getMonth() + 1 ).padStart( 2, '0' ) +
			'-' +
			String( now.getDate() ).padStart( 2, '0' )
		);
	}

	function markToday( calendar ) {
		const today = localToday();
		calendar.querySelectorAll( '.rmd-eo-day' ).forEach( function ( day ) {
			day.classList.toggle(
				'is-today',
				day.getAttribute( 'data-date' ) === today
			);
		} );
	}

	function closePopovers( except ) {
		document
			.querySelectorAll( '.rmd-eo-day__popover:not([hidden])' )
			.forEach( function ( popover ) {
				if ( popover !== except ) {
					popover.hidden = true;
					const link =
						popover.parentNode.querySelector( '.rmd-eo-day__link' );
					if ( link ) {
						link.setAttribute( 'aria-expanded', 'false' );
					}
				}
			} );
	}

	function loadMonth( calendar, month ) {
		const endpoint = calendar.getAttribute( 'data-endpoint' );
		let query = {};
		try {
			query = JSON.parse( calendar.getAttribute( 'data-query' ) || '{}' );
		} catch {
			query = {};
		}
		if ( ! endpoint || ! window.fetch ) {
			return false;
		}

		const url = new URL( endpoint, window.location.href );
		url.searchParams.set( 'month', month );
		Object.keys( query ).forEach( function ( key ) {
			url.searchParams.set( key, query[ key ] );
		} );

		calendar.classList.add( 'is-loading' );
		window
			.fetch( url.toString(), { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.text();
			} )
			.then( function ( text ) {
				// A stray byte order mark from another file of the site must not break the calendar.
				let clean = text;
				while ( clean.charCodeAt( 0 ) === 0xfeff ) {
					clean = clean.slice( 1 );
				}
				const data = JSON.parse( clean );
				const holder = document.createElement( 'div' );
				holder.innerHTML = data.html || '';
				const fresh = holder.querySelector( '[data-rmd-eo-calendar]' );
				if ( ! fresh ) {
					throw new Error( 'No calendar in response' );
				}
				// Keep classes the theme or block editor added to the wrapper.
				fresh.className = calendar.className.replace(
					/\s*is-loading/,
					''
				);
				// The links in the response point at the REST URL; aim them at this page.
				fresh
					.querySelectorAll( 'a[data-month]' )
					.forEach( function ( link ) {
						const target = new URL( window.location.href );
						target.searchParams.set(
							'eo_month',
							link.getAttribute( 'data-month' )
						);
						link.href = target.toString();
					} );
				calendar.replaceWith( fresh );
				markToday( fresh );
			} )
			.catch( function () {
				// Fall back to the plain link.
				const target = new URL( window.location.href );
				target.searchParams.set( 'eo_month', month );
				window.location.href = target.toString();
			} );
		return true;
	}

	document.addEventListener( 'click', function ( event ) {
		const target = event.target;
		if ( ! ( target instanceof window.Element ) ) {
			return;
		}

		const nav = target.closest( '.rmd-eo-calendar a[data-month]' );
		if ( nav ) {
			const calendar = nav.closest( '[data-rmd-eo-calendar]' );
			if (
				calendar &&
				loadMonth( calendar, nav.getAttribute( 'data-month' ) )
			) {
				event.preventDefault();
			}
			return;
		}

		const dayLink = target.closest( '.rmd-eo-day__link' );
		if ( dayLink ) {
			const popover = dayLink.parentNode.querySelector(
				'.rmd-eo-day__popover'
			);
			if ( popover ) {
				event.preventDefault();
				const open = popover.hidden;
				closePopovers( popover );
				popover.hidden = ! open;
				dayLink.setAttribute(
					'aria-expanded',
					open ? 'true' : 'false'
				);
			}
			return;
		}

		if ( ! target.closest( '.rmd-eo-day__popover' ) ) {
			closePopovers( null );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			closePopovers( null );
		}
	} );

	function init() {
		hidePast( document );
		document
			.querySelectorAll( '[data-rmd-eo-calendar]' )
			.forEach( markToday );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
