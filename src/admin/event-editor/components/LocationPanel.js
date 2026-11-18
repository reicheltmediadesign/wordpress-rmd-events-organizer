import { __ } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';

const NEW = 'new';

/**
 * Picks an existing location or collects the fields of a new one, which PHP
 * creates together with the event.
 *
 * @param {Object}   props
 * @param {Object}   props.location
 * @param {Object}   props.config
 * @param {Function} props.onChange
 */
export default function LocationPanel( { location, config, onChange } ) {
	const isNew = location.new !== null;
	const fresh = location.new || {};
	const setNew = ( patch ) =>
		onChange( { id: 0, new: { ...fresh, ...patch } } );

	return (
		<>
			<SelectControl
				label={ __( 'Location', 'rmd-events-organizer' ) }
				value={ isNew ? NEW : location.id }
				options={ [
					{
						value: 0,
						label: __( '— No location —', 'rmd-events-organizer' ),
					},
					...config.locations.map( ( item ) => ( {
						value: item.id,
						label: item.name,
					} ) ),
					{
						value: NEW,
						label: __(
							'+ Enter a new location',
							'rmd-events-organizer'
						),
					},
				] }
				onChange={ ( value ) =>
					value === NEW
						? onChange( {
								id: 0,
								new: {
									name: '',
									street: '',
									postcode: '',
									city: '',
								},
							} )
						: onChange( {
								id: parseInt( value, 10 ) || 0,
								new: null,
							} )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ isNew && (
				<div className="rmd-eo-editor__new-location">
					<TextControl
						label={ __(
							'Name of the location',
							'rmd-events-organizer'
						) }
						value={ fresh.name || '' }
						maxLength={ config.limits.text }
						onChange={ ( name ) => setNew( { name } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __(
							'Street and number',
							'rmd-events-organizer'
						) }
						value={ fresh.street || '' }
						maxLength={ config.limits.text }
						onChange={ ( street ) => setNew( { street } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<div className="rmd-eo-editor__row">
						<TextControl
							label={ __( 'Postcode', 'rmd-events-organizer' ) }
							value={ fresh.postcode || '' }
							onChange={ ( postcode ) => setNew( { postcode } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'Town', 'rmd-events-organizer' ) }
							value={ fresh.city || '' }
							maxLength={ config.limits.text }
							onChange={ ( city ) => setNew( { city } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>
					<p className="rmd-eo-editor__hint">
						{ __(
							'The location is saved with the event and can be chosen for other events afterwards.',
							'rmd-events-organizer'
						) }
					</p>
				</div>
			) }
		</>
	);
}
