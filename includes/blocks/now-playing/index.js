/**
 * Now Playing Block — editor registration.
 *
 * @package WPKQ
 */

import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

import Edit from './edit';
import metadata from './block.json';
import './editor.css';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
