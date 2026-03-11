/**
 * Now Playing Block — editor UI.
 *
 * @package WPKQ
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl, TextControl, Spinner } from '@wordpress/components';
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
	const { venueId, password, tickerQueueLimit } = attributes;

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
					<RangeControl
						label={ __( 'Queue names shown in ticker', 'wp-karaoke' ) }
						value={ tickerQueueLimit }
						onChange={ ( value ) => setAttributes( { tickerQueueLimit: value } ) }
						min={ 1 }
						max={ 20 }
						help={ __(
							'Number of upcoming singers shown after the on-stage performer.',
							'wp-karaoke'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="wpkq-monitor-editor-preview__notice">
					{ venueId
						? __( 'Welcome screen content (shown before a video loads):', 'wp-karaoke' )
						: __( 'Please select a venue in the block settings panel.', 'wp-karaoke' ) }
				</div>
				<InnerBlocks
					template={ [ [ 'core/paragraph', { placeholder: __( 'Add a welcome message…', 'wp-karaoke' ) } ] ] }
					templateLock={ false }
				/>
			</div>
		</>
	);
}
