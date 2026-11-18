import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, RadioControl } from '@wordpress/components';

import { hasRule, newSingle, normalizeData } from '../model';
import DetailsPanel from './DetailsPanel';
import EntryEditor from './EntryEditor';
import Exclusions from './Exclusions';
import LocationPanel from './LocationPanel';
import Preview from './Preview';

export default function App( { config, initial, onChange } ) {
	const [ data, setData ] = useState( () => {
		const normalized = normalizeData( initial );
		if ( normalized.schedule.entries.length === 0 ) {
			// A new event starts with one empty date so the form is not blank.
			normalized.schedule.entries = [ newSingle( '' ) ];
		}
		return normalized;
	} );

	useEffect( () => {
		// Dates that were not filled in are not sent, so they raise no error.
		onChange( {
			...data,
			schedule: {
				...data.schedule,
				entries: data.schedule.entries.filter(
					( entry ) =>
						( entry.type === 'rule'
							? entry.from
							: entry.start_date ) !== ''
				),
			},
		} );
	}, [ data, onChange ] );

	const setSchedule = ( schedule ) =>
		setData( ( current ) => ( { ...current, schedule } ) );
	const { schedule } = data;
	const isMarket = data.kind === 'market';

	const updateEntry = ( index, entry ) =>
		setSchedule( {
			...schedule,
			entries: schedule.entries.map( ( item, i ) =>
				i === index ? entry : item
			),
		} );
	const removeEntry = ( index ) =>
		setSchedule( {
			...schedule,
			entries: schedule.entries.filter( ( item, i ) => i !== index ),
		} );

	return (
		<div className="rmd-eo-editor__app">
			{ config.modules.markets && (
				<section className="rmd-eo-editor__section">
					<RadioControl
						className="rmd-eo-editor__kind"
						label={ __( 'Kind', 'rmd-events-organizer' ) }
						selected={ data.kind }
						options={ [
							{
								value: 'event',
								label: __( 'Event', 'rmd-events-organizer' ),
							},
							{
								value: 'market',
								label: __(
									'Market date (we have a stall there)',
									'rmd-events-organizer'
								),
							},
						] }
						onChange={ ( kind ) =>
							setData( ( current ) => ( { ...current, kind } ) )
						}
					/>
				</section>
			) }

			<section className="rmd-eo-editor__section">
				<h2 className="rmd-eo-editor__heading">
					{ __( 'When', 'rmd-events-organizer' ) }
				</h2>
				{ schedule.entries.map( ( entry, index ) => (
					<EntryEditor
						key={ entry.id }
						entry={ entry }
						config={ config }
						isMarket={ isMarket }
						canRemove={ schedule.entries.length > 1 }
						onChange={ ( next ) => updateEntry( index, next ) }
						onRemove={ () => removeEntry( index ) }
					/>
				) ) }
				{ schedule.entries.length < config.limits.entries && (
					<Button
						variant="secondary"
						onClick={ () =>
							setSchedule( {
								...schedule,
								entries: [
									...schedule.entries,
									newSingle( '' ),
								],
							} )
						}
					>
						{ __( 'Add date', 'rmd-events-organizer' ) }
					</Button>
				) }

				{ ( hasRule( schedule ) || schedule.exclusions.length > 0 ) && (
					<Exclusions
						schedule={ schedule }
						config={ config }
						onChange={ setSchedule }
					/>
				) }

				<Preview
					schedule={ schedule }
					config={ config }
					onChange={ setSchedule }
				/>
			</section>

			{ config.modules.locations && (
				<section className="rmd-eo-editor__section">
					<h2 className="rmd-eo-editor__heading">
						{ __( 'Where', 'rmd-events-organizer' ) }
					</h2>
					<LocationPanel
						location={ data.location }
						config={ config }
						onChange={ ( location ) =>
							setData( ( current ) => ( {
								...current,
								location,
							} ) )
						}
					/>
				</section>
			) }

			<section className="rmd-eo-editor__section">
				<h2 className="rmd-eo-editor__heading">
					{ __( 'Details', 'rmd-events-organizer' ) }
				</h2>
				<DetailsPanel
					details={ data.details }
					isMarket={ isMarket }
					config={ config }
					onChange={ ( details ) =>
						setData( ( current ) => ( { ...current, details } ) )
					}
				/>
			</section>
		</div>
	);
}
