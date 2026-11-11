/**
 * Now Playing Block — editor UI.
 *
 * @package WPKQ
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	InnerBlocks,
	PanelColorSettings,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
	Spinner,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Maps ticker color attributes to the CSS custom properties used in style.css.
 *
 * @type {Object<string, string>}
 */
const TICKER_COLOR_VARS = {
	tickerBackgroundColor: '--wpkq-ticker-bg',
	tickerItemBackgroundColor: '--wpkq-ticker-item-bg',
	tickerItemTextColor: '--wpkq-ticker-item-color',
	tickerOnStageBackgroundColor: '--wpkq-ticker-on-stage-bg',
	tickerOnStageTextColor: '--wpkq-ticker-on-stage-color',
};

/**
 * Singer names for the ticker preview, on-stage singer first. Localized from
 * NowPlaying::get_fake_singers() (filterable with `wpkq_fake_singers`).
 *
 * @type {string[]}
 */
const PREVIEW_SINGERS = ( window.WPKQNowPlaying?.fakeSingers || [] ).map(
	( singer ) => singer.singer_name
);

/**
 * Static (non-animated) preview of the upcoming singers ticker, so editors
 * can see the ticker colors. Uses the same markup and classes as view.js.
 *
 * @param {Object} props            Component props.
 * @param {Object} props.attributes Block attributes.
 * @return {Element} Ticker preview.
 */
function TickerPreview( { attributes } ) {
	const style = {};

	Object.entries( TICKER_COLOR_VARS ).forEach( ( [ attribute, cssVar ] ) => {
		if ( attributes[ attribute ] ) {
			style[ cssVar ] = attributes[ attribute ];
		}
	} );

	return (
		<div className="wpkq-monitor-editor-preview__ticker" style={ style }>
			<div className="wpkq-monitor-editor-preview__notice">
				{ __( 'Upcoming singers ticker preview:', 'wp-karaoke' ) }
			</div>
			<div className="wpkq-monitor__ticker-wrap" aria-hidden="true">
				<div className="wpkq-monitor__ticker">
					<div className="wpkq-monitor__ticker-inner">
						{ PREVIEW_SINGERS.map( ( name, index ) => {
							if ( 0 === index ) {
								return (
									<span
										className="wpkq-monitor__ticker-item wpkq-monitor__ticker-item--on-stage"
										key={ index }
									>
										{ `${ __( 'On Stage:', 'wp-karaoke' ) } ${ name }` }
									</span>
								);
							}

							return (
								<span className="wpkq-monitor__ticker-item" key={ index }>
									{ 1 === index ? `${ __( 'Up Next:', 'wp-karaoke' ) } ${ name }` : name }
								</span>
							);
						} ) }
					</div>
				</div>
			</div>
		</div>
	);
}

/**
 * Edit component for the Now Playing block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Editor UI.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		venueId,
		password,
		tickerQueueLimit,
		showFakeSingersButton,
		monitorBackgroundColor,
		tickerBackgroundColor,
		tickerItemBackgroundColor,
		tickerItemTextColor,
		tickerOnStageBackgroundColor,
		tickerOnStageTextColor,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'wpkq-monitor-editor-preview',
		style: monitorBackgroundColor ? { background: monitorBackgroundColor } : undefined,
	} );

	/**
	 * Builds a color setting entry that saves to the given attribute.
	 *
	 * @param {string} attribute Attribute name.
	 * @param {string} label     Control label.
	 * @return {Object} Color setting for PanelColorSettings.
	 */
	const colorSetting = ( attribute, label ) => ( {
		label,
		value: attributes[ attribute ],
		onChange: ( value ) => setAttributes( { [ attribute ]: value || '' } ),
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
					<ToggleControl
						label={ __( 'Show "Load Fake Singers" button', 'wp-karaoke' ) }
						checked={ showFakeSingersButton }
						onChange={ ( value ) => setAttributes( { showFakeSingersButton: value } ) }
						help={ __(
							'For testing only. The button replaces the venue\'s entire queue with fake singers.',
							'wp-karaoke'
						) }
					/>
				</PanelBody>
				<PanelColorSettings
					title={ __( 'Monitor Colors', 'wp-karaoke' ) }
					initialOpen={ false }
					enableAlpha={ false }
					colorSettings={ [
						colorSetting( 'monitorBackgroundColor', __( 'Background', 'wp-karaoke' ) ),
						colorSetting( 'progressBarColor', __( 'Progress bar', 'wp-karaoke' ) ),
					] }
				/>
				<PanelColorSettings
					title={ __( 'Upcoming Singers Ticker Colors', 'wp-karaoke' ) }
					initialOpen={ false }
					enableAlpha={ false }
					colorSettings={ [
						colorSetting( 'tickerBackgroundColor', __( 'Ticker background', 'wp-karaoke' ) ),
						colorSetting( 'tickerItemBackgroundColor', __( 'Singer background', 'wp-karaoke' ) ),
						colorSetting( 'tickerItemTextColor', __( 'Singer text', 'wp-karaoke' ) ),
						colorSetting( 'tickerOnStageBackgroundColor', __( 'On-stage singer background', 'wp-karaoke' ) ),
						colorSetting( 'tickerOnStageTextColor', __( 'On-stage singer text', 'wp-karaoke' ) ),
					] }
				/>
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
				<TickerPreview attributes={ attributes } />
			</div>
		</>
	);
}
