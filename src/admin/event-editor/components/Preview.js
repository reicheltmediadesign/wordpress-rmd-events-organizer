import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { excludeDate, isDate, setCancelled } from '../model';

/**
 * Lists the next dates the schedule produces. The server computes them, so
 * the repetition rules exist in one place only.
 *
 * @param {Object}   props
 * @param {Object}   props.schedule
 * @param {Object}   props.config
 * @param {Function} props.onChange
 */
export default function Preview( { schedule, config, onChange } ) {
	const [ result, setResult ] = useState( null );
	const [ failed, setFailed ] = useState( false );
	const [ loading, setLoading ] = useState( false );

	const hasDate = schedule.entries.some( ( entry ) =>
		isDate( entry.type === 'rule' ? entry.from : entry.start_date )
	);
	const payload = JSON.stringify( schedule );

	useEffect( () => {
		if ( ! hasDate ) {
			setResult( null );
			return undefined;
		}
		let active = true;
		setLoading( true );
		const timer = setTimeout( () => {
			apiFetch( {
				path: config.previewPath,
				method: 'POST',
				data: { schedule: JSON.parse( payload ) },
			} )
				.then( ( response ) => {
					if ( active ) {
						setResult( response );
						setFailed( false );
					}
				} )
				.catch( () => {
					if ( active ) {
						setFailed( true );
					}
				} )
				.finally( () => {
					if ( active ) {
						setLoading( false );
					}
				} );
		}, 400 );
		return () => {
			active = false;
			clearTimeout( timer );
		};
	}, [ payload, hasDate, config.previewPath ] );

	if ( ! hasDate ) {
		return (
			<p className="rmd-eo-editor__hint">
				{ __(
					'Enter a date to see when the event takes place.',
					'rmd-events-organizer'
				) }
			</p>
		);
	}

	return (
		<div className="rmd-eo-editor__preview" aria-live="polite">
			<h3 className="rmd-eo-editor__subheading">
				{ __( 'Next dates', 'rmd-events-organizer' ) }
				{ loading && <Spinner /> }
			</h3>
			{ failed && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The preview could not be loaded. You can still save.',
						'rmd-events-organizer'
					) }
				</Notice>
			) }
			{ result?.errors?.length > 0 && (
				<Notice status="error" isDismissible={ false }>
					<ul>
						{ result.errors.map( ( message ) => (
							<li key={ message }>{ message }</li>
						) ) }
					</ul>
				</Notice>
			) }
			{ result && result.items.length === 0 && (
				<p className="rmd-eo-editor__hint">
					{ result.total > 0
						? __(
								'All dates are in the past.',
								'rmd-events-organizer'
							)
						: __(
								'These settings produce no dates.',
								'rmd-events-organizer'
							) }
				</p>
			) }
			{ result && result.items.length > 0 && (
				<ul className="rmd-eo-editor__dates">
					{ result.items.map( ( item ) => (
						<li
							key={ item.key }
							className={
								item.cancelled ? 'is-cancelled' : undefined
							}
						>
							<span className="rmd-eo-editor__date-label">
								{ item.label }
								{ item.cancelled && (
									<strong>
										{ ' ' }
										{ __(
											'Cancelled',
											'rmd-events-organizer'
										) }
									</strong>
								) }
							</span>
							<Button
								size="small"
								variant="tertiary"
								onClick={ () =>
									onChange(
										setCancelled(
											schedule,
											item.key,
											! item.cancelled
										)
									)
								}
							>
								{ item.cancelled
									? __(
											'Takes place after all',
											'rmd-events-organizer'
										)
									: __(
											'Mark as cancelled',
											'rmd-events-organizer'
										) }
							</Button>
							{ item.isRule && (
								<Button
									size="small"
									variant="tertiary"
									isDestructive
									onClick={ () =>
										onChange(
											excludeDate( schedule, item.key )
										)
									}
								>
									{ __(
										'Leave out',
										'rmd-events-organizer'
									) }
								</Button>
							) }
						</li>
					) ) }
				</ul>
			) }
			{ result && result.upcoming > result.items.length && (
				<p className="rmd-eo-editor__hint">
					{ sprintf(
						/* translators: %d: number of further dates */
						_n(
							'… and %d more date.',
							'… and %d more dates.',
							result.upcoming - result.items.length,
							'rmd-events-organizer'
						),
						result.upcoming - result.items.length
					) }
					{ result.openEnded &&
						' ' +
							__(
								'Repeating dates without an end are continued automatically.',
								'rmd-events-organizer'
							) }
				</p>
			) }
		</div>
	);
}
