import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import {
	Loading,
	PostSelect,
	previewAttributes,
	useChoices,
} from '../shared/controls';

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'rmd-eo-block-preview' } );
	const choices = useChoices();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Event', 'rmd-events-organizer' ) }>
					{ choices === null ? (
						<Loading />
					) : (
						<PostSelect
							label={ __( 'Event', 'rmd-events-organizer' ) }
							value={ attributes.eventId }
							items={ choices.events }
							noneLabel={ __(
								'— Choose —',
								'rmd-events-organizer'
							) }
							onChange={ ( eventId ) =>
								setAttributes( { eventId } )
							}
						/>
					) }
					<ToggleControl
						label={ __(
							'Show the name of the event',
							'rmd-events-organizer'
						) }
						checked={ attributes.showTitle }
						onChange={ ( showTitle ) =>
							setAttributes( { showTitle } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="rmd/event-details"
					attributes={ previewAttributes( attributes ) }
					skipBlockSupportAttributes
				/>
			</div>
		</>
	);
}
