import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	CheckboxControl,
	BaseControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

let choicesPromise = null;

const EMPTY = {
	events: [],
	locations: [],
	categories: [],
	tags: [],
	modules: {},
};

/**
 * Loads events, locations, categories and tags once per editor session.
 *
 * @return {Promise<Object>} Choices.
 */
function loadChoices() {
	if ( ! choicesPromise ) {
		choicesPromise = apiFetch( {
			path: '/rmd-events-organizer/v1/choices',
		} ).catch( () => EMPTY );
	}
	return choicesPromise;
}

export function useChoices() {
	const [ choices, setChoices ] = useState( null );

	useEffect( () => {
		let mounted = true;
		loadChoices().then( ( result ) => {
			if ( mounted ) {
				setChoices( { ...EMPTY, ...( result || {} ) } );
			}
		} );
		return () => {
			mounted = false;
		};
	}, [] );

	return choices;
}

/**
 * Select for a category or tag, stored as its slug.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {string}   props.value
 * @param {Array}    props.terms
 * @param {Function} props.onChange
 */
export function TermSelect( { label, value, terms, onChange } ) {
	if ( terms.length === 0 ) {
		return null;
	}
	return (
		<SelectControl
			label={ label }
			value={ value }
			options={ [
				{ value: '', label: __( 'All', 'rmd-events-organizer' ) },
				...terms.map( ( term ) => ( {
					value: term.slug,
					label: term.name,
				} ) ),
			] }
			onChange={ onChange }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * Select for a post (event or location), stored as its id.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {number}   props.value
 * @param {Array}    props.items
 * @param {string}   props.noneLabel
 * @param {Function} props.onChange
 */
export function PostSelect( { label, value, items, noneLabel, onChange } ) {
	return (
		<SelectControl
			label={ label }
			value={ value }
			options={ [
				{ value: 0, label: noneLabel },
				...items.map( ( item ) => ( {
					value: item.id,
					label: item.title,
				} ) ),
			] }
			onChange={ ( next ) => onChange( parseInt( next, 10 ) || 0 ) }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * Filters shared by lists and calendars: category, tag, location.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes
 * @param {Function} props.setAttributes
 * @param {Object}   props.choices
 */
export function FilterControls( { attributes, setAttributes, choices } ) {
	return (
		<>
			<TermSelect
				label={ __( 'Category', 'rmd-events-organizer' ) }
				value={ attributes.category }
				terms={ choices.categories }
				onChange={ ( category ) => setAttributes( { category } ) }
			/>
			<TermSelect
				label={ __( 'Tag', 'rmd-events-organizer' ) }
				value={ attributes.tag }
				terms={ choices.tags }
				onChange={ ( tag ) => setAttributes( { tag } ) }
			/>
			{ choices.locations.length > 0 && (
				<PostSelect
					label={ __( 'Location', 'rmd-events-organizer' ) }
					value={ attributes.location }
					items={ choices.locations }
					noneLabel={ __( 'All', 'rmd-events-organizer' ) }
					onChange={ ( location ) => setAttributes( { location } ) }
				/>
			) }
		</>
	);
}

const PARTS = [
	{ id: 'date', label: __( 'Date', 'rmd-events-organizer' ) },
	{ id: 'time', label: __( 'Time', 'rmd-events-organizer' ) },
	{ id: 'location', label: __( 'Location', 'rmd-events-organizer' ) },
	{ id: 'stall', label: __( 'Stall position', 'rmd-events-organizer' ) },
	{ id: 'category', label: __( 'Category', 'rmd-events-organizer' ) },
	{ id: 'excerpt', label: __( 'Excerpt', 'rmd-events-organizer' ) },
	{ id: 'image', label: __( 'Image', 'rmd-events-organizer' ) },
];

/**
 * Controls of a list of dates.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes
 * @param {Function} props.setAttributes
 * @param {boolean}  props.isMarket      True for the market dates block.
 */
export function ListControls( { attributes, setAttributes, isMarket } ) {
	const defaults = isMarket
		? [ 'date', 'time', 'location', 'stall' ]
		: [ 'date', 'time', 'location' ];
	const shown = attributes.show.length > 0 ? attributes.show : defaults;
	const parts = PARTS.filter( ( part ) => isMarket || part.id !== 'stall' );

	const togglePart = ( id, on ) => {
		const next = parts
			.map( ( part ) => part.id )
			.filter( ( part ) =>
				part === id ? on : shown.includes( part )
			);
		setAttributes( { show: next } );
	};

	return (
		<>
			<SelectControl
				label={ __( 'Which dates', 'rmd-events-organizer' ) }
				value={ attributes.scope }
				options={ [
					{
						value: 'upcoming',
						label: __( 'Upcoming', 'rmd-events-organizer' ),
					},
					{
						value: 'past',
						label: __( 'Past', 'rmd-events-organizer' ),
					},
					{
						value: 'all',
						label: __( 'All', 'rmd-events-organizer' ),
					},
					{
						value: 'today',
						label: __( 'Today', 'rmd-events-organizer' ),
					},
					{
						value: 'month',
						label: __( 'This month', 'rmd-events-organizer' ),
					},
					{
						value: 'next-month',
						label: __( 'Next month', 'rmd-events-organizer' ),
					},
				] }
				onChange={ ( scope ) => setAttributes( { scope } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<NumberControl
				label={ __( 'Number of dates', 'rmd-events-organizer' ) }
				help={ __(
					'0 uses the number from the settings.',
					'rmd-events-organizer'
				) }
				value={ attributes.limit }
				min={ 0 }
				max={ 100 }
				onChange={ ( limit ) =>
					setAttributes( { limit: parseInt( limit, 10 ) || 0 } )
				}
				__next40pxDefaultSize
			/>
			<ToggleControl
				label={ __(
					'Each event only once, with its next date',
					'rmd-events-organizer'
				) }
				checked={ attributes.unique }
				onChange={ ( unique ) => setAttributes( { unique } ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Headings', 'rmd-events-organizer' ) }
				value={ attributes.group }
				options={ [
					{ value: '', label: __( 'None', 'rmd-events-organizer' ) },
					{
						value: 'month',
						label: __( 'Per month', 'rmd-events-organizer' ),
					},
					{
						value: 'year',
						label: __( 'Per year', 'rmd-events-organizer' ),
					},
				] }
				onChange={ ( group ) => setAttributes( { group } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<BaseControl
				id="rmd-eo-parts"
				label={ __( 'Show', 'rmd-events-organizer' ) }
				__nextHasNoMarginBottom
			>
				{ parts.map( ( part ) => (
					<CheckboxControl
						key={ part.id }
						label={ part.label }
						checked={ shown.includes( part.id ) }
						onChange={ ( on ) => togglePart( part.id, on ) }
						__nextHasNoMarginBottom
					/>
				) ) }
			</BaseControl>
			<ToggleControl
				label={ __(
					'Links to earlier and later dates',
					'rmd-events-organizer'
				) }
				checked={ attributes.pagination }
				onChange={ ( pagination ) => setAttributes( { pagination } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __(
					'Text when there are no dates',
					'rmd-events-organizer'
				) }
				value={ attributes.emptyText }
				onChange={ ( emptyText ) => setAttributes( { emptyText } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</>
	);
}

/**
 * Attributes for the server-rendered preview. The preview request leaves out
 * the class name, so the chosen block style travels in its own attribute.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} Attributes for ServerSideRender.
 */
export function previewAttributes( attributes ) {
	const match = /(?:^|\s)is-style-([a-z0-9-]+)/.exec(
		attributes.className || ''
	);
	return {
		...attributes,
		isPreview: true,
		previewStyle: match ? match[ 1 ] : '',
	};
}

export function Loading() {
	return <Spinner />;
}
