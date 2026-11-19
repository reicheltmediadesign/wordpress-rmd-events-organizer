import { __ } from '@wordpress/i18n';
import { CheckboxControl, TextControl } from '@wordpress/components';

export default function DetailsPanel( {
	details,
	isMarket,
	config,
	onChange,
} ) {
	const set = ( patch ) => onChange( { ...details, ...patch } );
	const text = ( key, label, help ) => (
		<TextControl
			label={ label }
			help={ help }
			value={ details[ key ] || '' }
			maxLength={ config.limits.text }
			onChange={ ( value ) => set( { [ key ]: value } ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
	const url = ( key, label ) => (
		<TextControl
			// Not type="url": the browser would block saving the whole post; PHP reports a bad address instead.
			type="text"
			inputMode="url"
			label={ label }
			placeholder="https://"
			value={ details[ key ] || '' }
			onChange={ ( value ) => set( { [ key ]: value } ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);

	return (
		<>
			<div className="rmd-eo-editor__grid">
				{ isMarket && (
					<>
						{ text(
							'stall',
							__( 'Where our stall is', 'rmd-events-organizer' ),
							__(
								'For example “Stall 12, next to the fountain”.',
								'rmd-events-organizer'
							)
						) }
						{ text(
							'stall_note',
							__( 'Note', 'rmd-events-organizer' ),
							__(
								'For example what you bring along.',
								'rmd-events-organizer'
							)
						) }
					</>
				) }
				{ text(
					'organizer_name',
					__( 'Organiser', 'rmd-events-organizer' )
				) }
				{ url(
					'organizer_url',
					__( 'Organiser website', 'rmd-events-organizer' )
				) }
				{ text(
					'price_text',
					__( 'Admission', 'rmd-events-organizer' ),
					__(
						'Free text, for example “free” or “8 €, reduced 5 €”.',
						'rmd-events-organizer'
					)
				) }
				{ url(
					'info_url',
					__( 'Link for more information', 'rmd-events-organizer' )
				) }
			</div>
			{ isMarket && (
				<CheckboxControl
					label={ __(
						'We are not taking part',
						'rmd-events-organizer'
					) }
					help={ __(
						'The market takes place, but without your stall. It stays visible and is marked accordingly. For a single date use the list of next dates above.',
						'rmd-events-organizer'
					) }
					checked={ details.status === 'withdrawn' }
					onChange={ ( on ) =>
						set( { status: on ? 'withdrawn' : 'scheduled' } )
					}
					__nextHasNoMarginBottom
				/>
			) }
			<CheckboxControl
				label={ __(
					'The whole event is cancelled',
					'rmd-events-organizer'
				) }
				help={ __(
					'The organiser has called it off. It stays visible and is marked as cancelled. To cancel a single date use the list of next dates above.',
					'rmd-events-organizer'
				) }
				checked={ details.status === 'cancelled' }
				onChange={ ( on ) =>
					set( { status: on ? 'cancelled' : 'scheduled' } )
				}
				__nextHasNoMarginBottom
			/>
		</>
	);
}
