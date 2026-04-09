module.exports = {
	root: true,
	parser: 'vue-eslint-parser',
	parserOptions: {
		parser: '@typescript-eslint/parser',
		ecmaVersion: 'latest',
		sourceType: 'module',
		extraFileExtensions: ['.vue'],
	},
	extends: ['@nextcloud'],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		'vue/max-attributes-per-line': 'off',
		'vue/html-indent': 'off',
		quotes: ['error', 'single', { avoidEscape: true }],
	},
}
