import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import {
	FilterControls,
	ListControls,
	Loading,
	previewAttributes,
	useChoices,
} from '../shared/controls';

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'rmd-eo-block-preview' } );
	const choices = useChoices();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Dates', 'rmd-events-organizer' ) }>
					<ListControls
						attributes={ attributes }
						setAttributes={ setAttributes }
						isMarket={ attributes.kind === 'market' }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Filter', 'rmd-events-organizer' ) }
					initialOpen={ false }
				>
					{ choices === null && <Loading /> }
					{ choices !== null && choices.modules.markets && (
						<SelectControl
							label={ __( 'Kind', 'rmd-events-organizer' ) }
							value={ attributes.kind }
							options={ [
								{
									value: '',
									label: __(
										'Events and market dates',
										'rmd-events-organizer'
									),
								},
								{
									value: 'event',
									label: __(
										'Events only',
										'rmd-events-organizer'
									),
								},
								{
									value: 'market',
									label: __(
										'Market dates only',
										'rmd-events-organizer'
									),
								},
							] }
							onChange={ ( kind ) => setAttributes( { kind } ) }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ choices !== null && (
						<FilterControls
							attributes={ attributes }
							setAttributes={ setAttributes }
							choices={ choices }
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="rmd/events"
					attributes={ previewAttributes( attributes ) }
					skipBlockSupportAttributes
				/>
			</div>
		</>
	);
}
