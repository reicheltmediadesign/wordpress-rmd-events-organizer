/* eslint-disable camelcase -- Field names mirror the data shape defined in PHP. */
import { __, _x } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	RadioControl,
	SelectControl,
	TextControl,
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

import { convertEntry, weekdayOf } from '../model';

const NTH_LABELS = () => [
	{ value: 1, label: __( 'first', 'rmd-events-organizer' ) },
	{ value: 2, label: __( 'second', 'rmd-events-organizer' ) },
	{ value: 3, label: __( 'third', 'rmd-events-organizer' ) },
	{ value: 4, label: __( 'fourth', 'rmd-events-organizer' ) },
	{ value: 5, label: __( 'fifth', 'rmd-events-organizer' ) },
	{ value: -1, label: __( 'last', 'rmd-events-organizer' ) },
];

function Times( { entry, multiDay, onChange } ) {
	const set = ( patch ) => onChange( { ...entry, ...patch } );

	return (
		<>
			<div className="rmd-eo-editor__row">
				<CheckboxControl
					label={ __( 'All day', 'rmd-events-organizer' ) }
					checked={ entry.all_day }
					onChange={ ( all_day ) => set( { all_day } ) }
					__nextHasNoMarginBottom
				/>
				{ ! entry.all_day && (
					<>
						<TextControl
							type="time"
							label={ __( 'Starts at', 'rmd-events-organizer' ) }
							value={ entry.start_time }
							onChange={ ( start_time ) => set( { start_time } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							type="time"
							label={ __( 'Ends at', 'rmd-events-organizer' ) }
							value={ entry.end_time }
							onChange={ ( end_time ) => set( { end_time } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</>
				) }
			</div>
			{ multiDay && ! entry.all_day && entry.start_time !== '' && (
				<RadioControl
					className="rmd-eo-editor__mode"
					label={ __( 'The times apply', 'rmd-events-organizer' ) }
					selected={ entry.time_mode }
					options={ [
						{
							value: 'daily',
							label: __(
								'to every day (same hours every day)',
								'rmd-events-organizer'
							),
						},
						{
							value: 'continuous',
							label: __(
								'to the first and last day (runs through)',
								'rmd-events-organizer'
							),
						},
					] }
					onChange={ ( time_mode ) => set( { time_mode } ) }
				/>
			) }
		</>
	);
}

function SingleFields( { entry, isMarket, onChange } ) {
	const set = ( patch ) => onChange( { ...entry, ...patch } );

	return (
		<>
			<div className="rmd-eo-editor__row">
				<TextControl
					type="date"
					label={ __( 'Date', 'rmd-events-organizer' ) }
					value={ entry.start_date }
					onChange={ ( start_date ) =>
						set( {
							start_date,
							end_date:
								entry.end_date === '' ||
								entry.end_date === entry.start_date ||
								entry.end_date < start_date
									? start_date
									: entry.end_date,
						} )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<TextControl
					type="date"
					label={ __(
						'Last day (if several days)',
						'rmd-events-organizer'
					) }
					value={
						entry.end_date === entry.start_date
							? ''
							: entry.end_date
					}
					min={ entry.start_date }
					onChange={ ( end_date ) =>
						set( {
							end_date: end_date || entry.start_date,
							// A stall on a market is usually open at the same hours every day.
							...( isMarket &&
							end_date > entry.start_date &&
							entry.end_date === entry.start_date
								? { time_mode: 'daily' }
								: {} ),
						} )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>
			<Times
				entry={ entry }
				multiDay={ entry.end_date > entry.start_date }
				onChange={ onChange }
			/>
		</>
	);
}

function RuleFields( { entry, config, onChange } ) {
	const set = ( patch ) => onChange( { ...entry, ...patch } );
	const startWeekday = weekdayOf( entry.from );
	const weekdayLabel = ( id ) =>
		config.weekdays.find( ( day ) => day.id === id )?.long || id;

	let ends = 'never';
	if ( entry.count > 0 ) {
		ends = 'count';
	} else if ( entry.until !== '' ) {
		ends = 'until';
	}

	const units = {
		daily: _x( 'days', 'every N days', 'rmd-events-organizer' ),
		weekly: _x( 'weeks', 'every N weeks', 'rmd-events-organizer' ),
		monthly: _x( 'months', 'every N months', 'rmd-events-organizer' ),
		yearly: _x( 'years', 'every N years', 'rmd-events-organizer' ),
	};
	const byMonth = entry.freq === 'monthly' || entry.freq === 'yearly';
	const nthWeekday = entry.weekdays[ 0 ] || startWeekday;

	return (
		<>
			<div className="rmd-eo-editor__row">
				<TextControl
					type="date"
					label={ __( 'First date', 'rmd-events-organizer' ) }
					value={ entry.from }
					onChange={ ( from ) => set( { from } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<SelectControl
					label={ __( 'Repeats', 'rmd-events-organizer' ) }
					value={ entry.freq }
					options={ [
						{
							value: 'daily',
							label: __( 'Daily', 'rmd-events-organizer' ),
						},
						{
							value: 'weekly',
							label: __( 'Weekly', 'rmd-events-organizer' ),
						},
						{
							value: 'monthly',
							label: __( 'Monthly', 'rmd-events-organizer' ),
						},
						{
							value: 'yearly',
							label: __( 'Yearly', 'rmd-events-organizer' ),
						},
					] }
					onChange={ ( freq ) => set( { freq } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<NumberControl
					label={ __( 'Every', 'rmd-events-organizer' ) }
					suffix={
						<span className="rmd-eo-editor__suffix">
							{ units[ entry.freq ] }
						</span>
					}
					value={ entry.interval }
					min={ 1 }
					max={ config.limits.interval }
					onChange={ ( interval ) =>
						set( { interval: parseInt( interval, 10 ) || 1 } )
					}
					__next40pxDefaultSize
				/>
			</div>

			{ entry.freq === 'weekly' && (
				<fieldset className="rmd-eo-editor__weekdays">
					<legend>{ __( 'On', 'rmd-events-organizer' ) }</legend>
					{ config.weekdays.map( ( day ) => {
						const checked =
							entry.weekdays.length === 0
								? day.id === startWeekday
								: entry.weekdays.includes( day.id );
						return (
							<CheckboxControl
								key={ day.id }
								label={ day.short }
								checked={ checked }
								onChange={ ( on ) => {
									const current =
										entry.weekdays.length === 0 &&
										startWeekday
											? [ startWeekday ]
											: entry.weekdays;
									set( {
										weekdays: config.weekdays
											.map( ( item ) => item.id )
											.filter( ( id ) =>
												id === day.id
													? on
													: current.includes( id )
											),
									} );
								} }
								__nextHasNoMarginBottom
							/>
						);
					} ) }
				</fieldset>
			) }

			{ byMonth && (
				<div className="rmd-eo-editor__row">
					<SelectControl
						label={ __( 'On', 'rmd-events-organizer' ) }
						value={ entry.month_mode }
						options={ [
							{
								value: 'day_of_month',
								label: __(
									'the same day of the month as the first date',
									'rmd-events-organizer'
								),
							},
							{
								value: 'nth_weekday',
								label: __(
									'a weekday, e.g. the first Saturday',
									'rmd-events-organizer'
								),
							},
						] }
						onChange={ ( month_mode ) => set( { month_mode } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ entry.month_mode === 'nth_weekday' && (
						<>
							<SelectControl
								label={ __(
									'Which one',
									'rmd-events-organizer'
								) }
								value={ entry.nth || 1 }
								options={ NTH_LABELS() }
								onChange={ ( nth ) =>
									set( { nth: parseInt( nth, 10 ) || 1 } )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<SelectControl
								label={ __(
									'Weekday',
									'rmd-events-organizer'
								) }
								value={ nthWeekday }
								options={ config.weekdays.map( ( day ) => ( {
									value: day.id,
									label: weekdayLabel( day.id ),
								} ) ) }
								onChange={ ( weekday ) =>
									set( {
										weekdays: [ weekday ],
										nth: entry.nth || 1,
									} )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</>
					) }
				</div>
			) }

			<div className="rmd-eo-editor__row">
				<SelectControl
					label={ __( 'Ends', 'rmd-events-organizer' ) }
					value={ ends }
					options={ [
						{
							value: 'never',
							label: __( 'Never', 'rmd-events-organizer' ),
						},
						{
							value: 'until',
							label: __( 'On a date', 'rmd-events-organizer' ),
						},
						{
							value: 'count',
							label: __(
								'After a number of dates',
								'rmd-events-organizer'
							),
						},
					] }
					onChange={ ( next ) => {
						if ( next === 'until' ) {
							set( {
								count: 0,
								until: entry.until || entry.from,
							} );
						} else if ( next === 'count' ) {
							set( { until: '', count: entry.count || 10 } );
						} else {
							set( { until: '', count: 0 } );
						}
					} }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				{ ends === 'until' && (
					<TextControl
						type="date"
						label={ __( 'Last date', 'rmd-events-organizer' ) }
						value={ entry.until }
						min={ entry.from }
						onChange={ ( until ) => set( { until } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				) }
				{ ends === 'count' && (
					<NumberControl
						label={ __(
							'Number of dates',
							'rmd-events-organizer'
						) }
						value={ entry.count }
						min={ 1 }
						max={ config.limits.count }
						onChange={ ( count ) =>
							set( { count: parseInt( count, 10 ) || 1 } )
						}
						__next40pxDefaultSize
					/>
				) }
				<NumberControl
					label={ __(
						'Each date lasts (days)',
						'rmd-events-organizer'
					) }
					value={ entry.duration_days }
					min={ 1 }
					max={ config.limits.duration }
					onChange={ ( duration_days ) =>
						set( {
							duration_days: parseInt( duration_days, 10 ) || 1,
						} )
					}
					__next40pxDefaultSize
				/>
			</div>

			<Times
				entry={ entry }
				multiDay={ entry.duration_days > 1 }
				onChange={ onChange }
			/>
		</>
	);
}

export default function EntryEditor( {
	entry,
	config,
	isMarket,
	canRemove,
	onChange,
	onRemove,
} ) {
	return (
		<div className="rmd-eo-editor__entry">
			<div className="rmd-eo-editor__entry-head">
				<ToggleGroupControl
					label={ __( 'Type of date', 'rmd-events-organizer' ) }
					hideLabelFromVision
					value={ entry.type }
					isBlock={ false }
					onChange={ ( type ) =>
						onChange( convertEntry( entry, type, config.today ) )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				>
					<ToggleGroupControlOption
						value="single"
						label={ __( 'One date', 'rmd-events-organizer' ) }
					/>
					<ToggleGroupControlOption
						value="rule"
						label={ __( 'Repeats', 'rmd-events-organizer' ) }
					/>
				</ToggleGroupControl>
				{ canRemove && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ onRemove }
					>
						{ __( 'Remove', 'rmd-events-organizer' ) }
					</Button>
				) }
			</div>
			{ entry.type === 'rule' ? (
				<RuleFields
					entry={ entry }
					config={ config }
					onChange={ onChange }
				/>
			) : (
				<SingleFields
					entry={ entry }
					isMarket={ isMarket }
					onChange={ onChange }
				/>
			) }
		</div>
	);
}
