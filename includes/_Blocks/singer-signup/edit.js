/**
 * Singer Signup Block — editor UI.
 *
 * @package WPKQ
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Edit component for the Singer Signup block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor UI.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { venueId } = attributes;

	const blockProps = useBlockProps( {
		className: 'wpkq-signup-editor-preview',
	} );

	const { venues, isLoading } = useSelect( ( select ) => {
		const { getEntityRecords, isResolving } = select( coreStore );
		const query = { per_page: -1, status: 'publish', _fields: 'id,title' };

		return {
			venues:    getEntityRecords( 'postType', 'karaoke-venue', query ),
			isLoading: isResolving( 'getEntityRecords', [
				'postType',
				'karaoke-venue',
				query,
			] ),
		};
	}, [] );

	const venueOptions = [
		{ label: __( '— Select a venue —', 'wp-karaoke' ), value: 0 },
		...( venues || [] ).map( ( venue ) => ( {
			label: venue.title.rendered,
			value: venue.id,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Singer Signup Settings', 'wp-karaoke' ) }>
					{ isLoading ? (
						<Spinner />
					) : (
						<SelectControl
							label={ __( 'Venue', 'wp-karaoke' ) }
							value={ venueId }
							options={ venueOptions }
							onChange={ ( value ) =>
								setAttributes( { venueId: parseInt( value, 10 ) } )
							}
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ venueId ? (
					<ServerSideRender
						block="wp-karaoke/singer-signup"
						attributes={ attributes }
					/>
				) : (
					<p className="wpkq-signup-editor-preview__notice wpkq-signup-editor-preview__notice--warning">
						{ __(
							'Please select a venue in the block settings panel.',
							'wp-karaoke'
						) }
					</p>
				) }
			</div>
		</>
	);
}
