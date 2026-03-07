const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: './src/admin/index.js',
		'device-flow': './src/device-flow/index.js',
	},
};
