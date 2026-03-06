/**
 * Webpack Configuration
 *
 * @package WPKQ
 */

const path                       = require( 'path' );
const MiniCssExtractPlugin       = require( 'mini-css-extract-plugin' );
const DependencyExtractionPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

module.exports = ( env, argv ) => {
	const isProduction = argv.mode === 'production';

	return {
		entry: {
			// Component 1 — admin queue manager.
			'admin':    './assets/js/admin/queue-admin.js',
			'frontend': './assets/js/frontend/frontend.js',

			// Component 2 — Now Playing block.
			'block-now-playing-editor': './includes/blocks/now-playing/index.js',
			'block-now-playing-view':   './includes/blocks/now-playing/view.js',
		},

		output: {
			filename: 'js/[name].js',
			path: path.resolve( __dirname, 'dist' ),
			clean: true,
		},

		module: {
			rules: [
				// JavaScript: transpile with Babel (includes JSX for blocks).
				{
					test: /\.js$/,
					exclude: /node_modules/,
					use: {
						loader: 'babel-loader',
						options: {
							presets: [
								[
									'@babel/preset-env',
									{
										targets: '> 0.5%, last 2 versions, not dead',
										useBuiltIns: 'usage',
										corejs: 3,
									},
								],
								'@babel/preset-react',
							],
						},
					},
				},

				// CSS: extract to separate files.
				{
					test: /\.css$/,
					use: [
						MiniCssExtractPlugin.loader,
						'css-loader',
					],
				},
			],
		},

		plugins: [
			new MiniCssExtractPlugin( {
				filename: 'css/[name].css',
			} ),

			// Generates .asset.php dependency files for block scripts and
			// externalises all @wordpress/* imports as window.wp.* globals.
			new DependencyExtractionPlugin(),
		],

		// Import CSS from JS entry points.
		resolve: {
			extensions: [ '.js', '.css' ],
		},

		devtool: isProduction ? false : 'source-map',

		optimization: {
			minimize: isProduction,
		},

		// Treat SortableJS as an external (loaded separately via WP).
		externals: {
			sortablejs: 'Sortable',
		},
	};
};
