/**
 * Now Playing Block — editor UI.
 *
 * @package WPKQ
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Edit component for the Now Playing block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor UI.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { venueId, password } = attributes;

	const blockProps = useBlockProps( {
		className: 'wpkq-monitor-editor-preview',
	} );

	const { venues, isLoading } = useSelect( ( select ) => {
		const { getEntityRecords, isResolving } = select( coreStore );
		const query = { per_page: -1, status: 'publish', _fields: 'id,title' };

		return {
			venues: getEntityRecords( 'postType', 'karaoke-venue', query ),
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
				<PanelBody title={ __( 'Now Playing Settings', 'wp-karaoke' ) }>
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
					<TextControl
						label={ __( 'Password', 'wp-karaoke' ) }
						value={ password }
						onChange={ ( value ) => setAttributes( { password: value } ) }
						type="text"
						help={ __(
							'Password required to view the monitor on the frontend.',
							'wp-karaoke'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ venueId ? (
					<p className="wpkq-monitor-editor-preview__notice">
						{ __(
							'Now Playing block is configured. This block renders on the frontend only.',
							'wp-karaoke'
						) }
					</p>
				) : (
					<p className="wpkq-monitor-editor-preview__notice wpkq-monitor-editor-preview__notice--warning">
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
