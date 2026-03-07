/**
 * Jest configuration for Dispatch admin JavaScript tests.
 *
 * Uses @wordpress/jest-preset-default which ships with @wordpress/scripts
 * and already handles JSX, @wordpress/* module aliases, and coverage.
 *
 * The babel-transform entry is required when the project has no babel.config.*
 * of its own, mirroring what wp-scripts test-unit-js does internally.
 */
const path = require( 'path' );

/** @type {import('jest').Config} */
const config = {
	preset: '@wordpress/jest-preset-default',
	transform: {
		'\\.[jt]sx?$': path.join(
			require.resolve( '@wordpress/scripts/package.json' ),
			'..',
			'config',
			'babel-transform.js'
		),
	},
	testMatch: [ '<rootDir>/tests/js/**/*.test.{js,jsx}' ],
	collectCoverageFrom: [ 'src/admin/store.js', 'src/admin/utils.js' ],
	coverageThreshold: {
		global: {
			lines: 90,
			functions: 90,
		},
	},
};

module.exports = config;
