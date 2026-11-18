import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';

import { isDate } from '../model';

/**
 * Days and periods on which repeating dates do not take place.
 *
 * @param {Object}   props
 * @param {Object}   props.schedule
 * @param {Object}   props.config
 * @param {Function} props.onChange
 */
export default function Exclusions( { schedule, config, onChange } ) {
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const canAdd =
		isDate( from ) && schedule.exclusions.length < config.limits.exclusions;

	const add = () => {
		const last = isDate( to ) && to > from ? to : from;
		onChange( {
			...schedule,
			exclusions: [ ...schedule.exclusions, { from, to: last } ],
		} );
		setFrom( '' );
		setTo( '' );
	};

	return (
		<div className="rmd-eo-editor__exclusions">
			<h3 className="rmd-eo-editor__subheading">
				{ __( 'Not on these days', 'rmd-events-organizer' ) }
			</h3>
			{ schedule.exclusions.length > 0 && (
				<ul className="rmd-eo-editor__chips">
					{ schedule.exclusions.map( ( range, index ) => (
						<li key={ range.from + range.to + index }>
							{ range.from === range.to
								? range.from
								: `${ range.from } – ${ range.to }` }
							<Button
								size="small"
								variant="tertiary"
								isDestructive
								onClick={ () =>
									onChange( {
										...schedule,
										exclusions: schedule.exclusions.filter(
											( item, i ) => i !== index
										),
									} )
								}
							>
								{ __( 'Remove', 'rmd-events-organizer' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
			<div className="rmd-eo-editor__row">
				<TextControl
					type="date"
					label={ __( 'Day or first day', 'rmd-events-organizer' ) }
					value={ from }
					onChange={ setFrom }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					type="date"
					label={ __(
						'Last day (optional)',
						'rmd-events-organizer'
					) }
					value={ to }
					min={ from }
					onChange={ setTo }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<Button
					variant="secondary"
					disabled={ ! canAdd }
					onClick={ add }
					__next40pxDefaultSize
				>
					{ __( 'Add exception', 'rmd-events-organizer' ) }
				</Button>
			</div>
		</div>
	);
}
