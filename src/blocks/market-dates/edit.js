import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
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
				<PanelBody
					title={ __( 'Market dates', 'rmd-events-organizer' ) }
				>
					<ListControls
						attributes={ attributes }
						setAttributes={ setAttributes }
						isMarket
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Filter', 'rmd-events-organizer' ) }
					initialOpen={ false }
				>
					{ choices === null ? (
						<Loading />
					) : (
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
					block="rmd/market-dates"
					attributes={ previewAttributes( attributes ) }
					skipBlockSupportAttributes
				/>
			</div>
		</>
	);
}
