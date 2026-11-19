/**
 * Event editor: a React app below the title of the classic edit screen. It
 * keeps dates, location and details in memory and mirrors them into a hidden
 * JSON field; PHP validates on save.
 */
import { createRoot, StrictMode } from '@wordpress/element';

import App from './components/App';
import './editor.scss';

function mount() {
	const root = document.getElementById( 'rmd-eo-editor-root' );
	const config = window.rmdEoEditorConfig;
	if ( ! root || ! config ) {
		return;
	}
	const field = document.getElementById( config.fieldId );
	if ( ! field ) {
		return;
	}

	let initial = {};
	try {
		initial = JSON.parse( field.value || '{}' );
	} catch {
		initial = {};
	}

	root.innerHTML = '';
	createRoot( root ).render(
		<StrictMode>
			<App
				config={ config }
				initial={ initial }
				onChange={ ( data ) => {
					field.value = JSON.stringify( data );
				} }
			/>
		</StrictMode>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
