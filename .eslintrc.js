module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	rules: {
		// @wordpress/* packages are WordPress core externals — Webpack provides
		// them at runtime; they will never live in node_modules.
		'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],
	},
};
