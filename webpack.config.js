/**
 * Extends the @wordpress/scripts default config with the admin event editor
 * entry. Blocks are discovered automatically from src/blocks/*\/block.json.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const baseEntry =
	typeof defaultConfig.entry === 'function' ? defaultConfig.entry() : defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...baseEntry,
		'admin/event-editor': path.resolve(
			__dirname,
			'src/admin/event-editor/index.js'
		),
	},
};
