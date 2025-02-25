( function () {
	var specialCharactersPageName,
		// Can't mock SITENAME since jqueryMsg caches it at load
		siteName = mw.config.get( 'wgSiteName' );

	// Since QUnitTestResources.php loads both mediawiki and mediawiki.jqueryMsg as
	// dependencies, this only tests the monkey-patched behavior with the two of them combined.

	// See mediawiki.jqueryMsg.test.js for unit tests for jqueryMsg-specific functionality.

	QUnit.module( 'mediawiki', QUnit.newMwEnvironment( {
		setup: function () {
			specialCharactersPageName = '"Who" wants to be a millionaire & live on \'Exotic Island\'?';
		},
		config: {
			wgArticlePath: '/wiki/$1',

			// For formatnum tests
			wgUserLanguage: 'en'
		},
		// Messages used in multiple tests
		messages: {
			'other-message': 'Other Message',
			'mediawiki-test-pagetriage-del-talk-page-notify-summary': 'Notifying author of deletion nomination for [[$1]]',
			'gender-plural-msg': '{{GENDER:$1|he|she|they}} {{PLURAL:$2|is|are}} awesome',
			'grammar-msg': 'Przeszukaj {{GRAMMAR:grammar_case_foo|{{SITENAME}}}}',
			'formatnum-msg': '{{formatnum:$1}}',
			'int-msg': 'Some {{int:other-message}}',
			'mediawiki-test-version-entrypoints-index-php': '[https://www.mediawiki.org/wiki/Manual:index.php index.php]',
			'external-link-replace': 'Foo [$1 bar]'
		}
	} ) );

	QUnit.test( 'Initial check', function ( assert ) {
		assert.ok( window.jQuery, 'jQuery defined' );
		assert.ok( window.$, '$ defined' );
		assert.strictEqual( window.$, window.jQuery, '$ alias to jQuery' );

		this.suppressWarnings();
		assert.ok( window.$j, '$j defined' );
		assert.strictEqual( window.$j, window.jQuery, '$j alias to jQuery' );
		this.restoreWarnings();

		// window.mw and window.mediaWiki are not deprecated, but for some reason
		// PhantomJS is triggerring the accessors on all mw.* properties in this test,
		// and with that lots of unrelated deprecation notices.
		this.suppressWarnings();
		assert.ok( window.mediaWiki, 'mediaWiki defined' );
		assert.ok( window.mw, 'mw defined' );
		assert.strictEqual( window.mw, window.mediaWiki, 'mw alias to mediaWiki' );
		this.restoreWarnings();
	} );

	QUnit.test( 'mw.format', function ( assert ) {
		assert.strictEqual(
			mw.format( 'Format $1 $2', 'foo', 'bar' ),
			'Format foo bar',
			'Simple parameters'
		);
		assert.strictEqual(
			mw.format( 'Format $1 $2' ),
			'Format $1 $2',
			'Missing parameters'
		);
	} );

	QUnit.test( 'mw.now', function ( assert ) {
		assert.strictEqual( typeof mw.now(), 'number', 'Return a number' );
		assert.strictEqual(
			String( Math.round( mw.now() ) ).length,
			String( +new Date() ).length,
			'Match size of current timestamp'
		);
	} );

	QUnit.test( 'mw.message & mw.messages', function ( assert ) {
		var goodbye, hello;

		// Convenience method for asserting the same result for multiple formats
		function assertMultipleFormats( messageArguments, formats, expectedResult, assertMessage ) {
			var format, i,
				len = formats.length;

			for ( i = 0; i < len; i++ ) {
				format = formats[ i ];
				assert.strictEqual( mw.message.apply( null, messageArguments )[ format ](), expectedResult, assertMessage + ' when format is ' + format );
			}
		}

		assert.ok( mw.messages, 'messages defined' );
		assert.ok( mw.messages.set( 'hello', 'Hello <b>awesome</b> world' ), 'mw.messages.set: Register' );

		hello = mw.message( 'hello' );

		// https://phabricator.wikimedia.org/T46459
		assert.strictEqual( hello.format, 'text', 'Message property "format" defaults to "text"' );

		assert.strictEqual( hello.map, mw.messages, 'Message property "map" defaults to the global instance in mw.messages' );
		assert.strictEqual( hello.key, 'hello', 'Message property "key" (currect key)' );
		assert.deepEqual( hello.parameters, [], 'Message property "parameters" defaults to an empty array' );

		// TODO
		assert.ok( hello.params, 'Message prototype "params"' );

		hello.format = 'plain';
		assert.strictEqual( hello.toString(), 'Hello <b>awesome</b> world', 'Message.toString returns the message as a string with the current "format"' );

		assert.strictEqual( hello.escaped(), 'Hello &lt;b&gt;awesome&lt;/b&gt; world', 'Message.escaped returns the escaped message' );
		assert.strictEqual( hello.format, 'escaped', 'Message.escaped correctly updated the "format" property' );

		assert.ok( mw.messages.set( 'multiple-curly-brace', '"{{SITENAME}}" is the home of {{int:other-message}}' ), 'mw.messages.set: Register' );
		assertMultipleFormats( [ 'multiple-curly-brace' ], [ 'text', 'parse' ], '"' + siteName + '" is the home of Other Message', 'Curly brace format works correctly' );
		assert.strictEqual( mw.message( 'multiple-curly-brace' ).plain(), mw.messages.get( 'multiple-curly-brace' ), 'Plain format works correctly for curly brace message' );
		assert.strictEqual( mw.message( 'multiple-curly-brace' ).escaped(), mw.html.escape( '"' + siteName + '" is the home of Other Message' ), 'Escaped format works correctly for curly brace message' );

		assert.ok( mw.messages.set( 'multiple-square-brackets-and-ampersand', 'Visit the [[Project:Community portal|community portal]] & [[Project:Help desk|help desk]]' ), 'mw.messages.set: Register' );
		assertMultipleFormats( [ 'multiple-square-brackets-and-ampersand' ], [ 'plain', 'text' ], mw.messages.get( 'multiple-square-brackets-and-ampersand' ), 'Square bracket message is not processed' );
		assert.strictEqual( mw.message( 'multiple-square-brackets-and-ampersand' ).escaped(), 'Visit the [[Project:Community portal|community portal]] &amp; [[Project:Help desk|help desk]]', 'Escaped format works correctly for square bracket message' );
		assert.htmlEqual( mw.message( 'multiple-square-brackets-and-ampersand' ).parse(), 'Visit the ' +
			'<a title="Project:Community portal" href="/wiki/Project:Community_portal">community portal</a>' +
			' &amp; <a title="Project:Help desk" href="/wiki/Project:Help_desk">help desk</a>', 'Internal links work with parse' );

		assertMultipleFormats( [ 'mediawiki-test-version-entrypoints-index-php' ], [ 'plain', 'text', 'escaped' ], mw.messages.get( 'mediawiki-test-version-entrypoints-index-php' ), 'External link markup is unprocessed' );
		assert.htmlEqual( mw.message( 'mediawiki-test-version-entrypoints-index-php' ).parse(), '<a href="https://www.mediawiki.org/wiki/Manual:index.php" class="external">index.php</a>', 'External link works correctly in parse mode' );

		assertMultipleFormats( [ 'external-link-replace', 'http://example.org/?x=y&z' ], [ 'plain', 'text' ], 'Foo [http://example.org/?x=y&z bar]', 'Parameters are substituted but external link is not processed' );
		assert.strictEqual( mw.message( 'external-link-replace', 'http://example.org/?x=y&z' ).escaped(), 'Foo [http://example.org/?x=y&amp;z bar]', 'In escaped mode, parameters are substituted and ampersand is escaped, but external link is not processed' );
		assert.htmlEqual( mw.message( 'external-link-replace', 'http://example.org/?x=y&z' ).parse(), 'Foo <a href="http://example.org/?x=y&amp;z" class="external">bar</a>', 'External link with replacement works in parse mode without double-escaping' );

		hello.parse();
		assert.strictEqual( hello.format, 'parse', 'Message.parse correctly updated the "format" property' );

		hello.plain();
		assert.strictEqual( hello.format, 'plain', 'Message.plain correctly updated the "format" property' );

		hello.text();
		assert.strictEqual( hello.format, 'text', 'Message.text correctly updated the "format" property' );

		assert.strictEqual( hello.exists(), true, 'Message.exists returns true for existing messages' );

		goodbye = mw.message( 'goodbye' );
		assert.strictEqual( goodbye.exists(), false, 'Message.exists returns false for nonexistent messages' );

		assertMultipleFormats( [ 'good<>bye' ], [ 'plain', 'text', 'parse', 'escaped' ], '⧼good&lt;&gt;bye⧽', 'Message.toString returns ⧼key⧽ if key does not exist' );

		assert.ok( mw.messages.set( 'plural-test-msg', 'There {{PLURAL:$1|is|are}} $1 {{PLURAL:$1|result|results}}' ), 'mw.messages.set: Register' );
		assertMultipleFormats( [ 'plural-test-msg', 6 ], [ 'text', 'parse', 'escaped' ], 'There are 6 results', 'plural get resolved' );
		assert.strictEqual( mw.message( 'plural-test-msg', 6 ).plain(), 'There {{PLURAL:6|is|are}} 6 {{PLURAL:6|result|results}}', 'Parameter is substituted but plural is not resolved in plain' );

		assert.ok( mw.messages.set( 'plural-test-msg-explicit', 'There {{plural:$1|is one car|are $1 cars|0=are no cars|12=are a dozen cars}}' ), 'mw.messages.set: Register message with explicit plural forms' );
		assertMultipleFormats( [ 'plural-test-msg-explicit', 12 ], [ 'text', 'parse', 'escaped' ], 'There are a dozen cars', 'explicit plural get resolved' );

		assert.ok( mw.messages.set( 'plural-test-msg-explicit-beginning', 'Basket has {{plural:$1|0=no eggs|12=a dozen eggs|6=half a dozen eggs|one egg|$1 eggs}}' ), 'mw.messages.set: Register message with explicit plural forms' );
		assertMultipleFormats( [ 'plural-test-msg-explicit-beginning', 1 ], [ 'text', 'parse', 'escaped' ], 'Basket has one egg', 'explicit plural given at beginning get resolved for singular' );
		assertMultipleFormats( [ 'plural-test-msg-explicit-beginning', 4 ], [ 'text', 'parse', 'escaped' ], 'Basket has 4 eggs', 'explicit plural given at beginning get resolved for plural' );
		assertMultipleFormats( [ 'plural-test-msg-explicit-beginning', 6 ], [ 'text', 'parse', 'escaped' ], 'Basket has half a dozen eggs', 'explicit plural given at beginning get resolved for 6' );
		assertMultipleFormats( [ 'plural-test-msg-explicit-beginning', 0 ], [ 'text', 'parse', 'escaped' ], 'Basket has no eggs', 'explicit plural given at beginning get resolved for 0' );

		assertMultipleFormats( [ 'mediawiki-test-pagetriage-del-talk-page-notify-summary' ], [ 'plain', 'text' ], mw.messages.get( 'mediawiki-test-pagetriage-del-talk-page-notify-summary' ), 'Double square brackets with no parameters unchanged' );

		assertMultipleFormats( [ 'mediawiki-test-pagetriage-del-talk-page-notify-summary', specialCharactersPageName ], [ 'plain', 'text' ], 'Notifying author of deletion nomination for [[' + specialCharactersPageName + ']]', 'Double square brackets with one parameter' );

		assert.strictEqual( mw.message( 'mediawiki-test-pagetriage-del-talk-page-notify-summary', specialCharactersPageName ).escaped(), 'Notifying author of deletion nomination for [[' + mw.html.escape( specialCharactersPageName ) + ']]', 'Double square brackets with one parameter, when escaped' );

		assert.ok( mw.messages.set( 'mediawiki-test-categorytree-collapse-bullet', '[<b>−</b>]' ), 'mw.messages.set: Register' );
		assert.strictEqual( mw.message( 'mediawiki-test-categorytree-collapse-bullet' ).plain(), mw.messages.get( 'mediawiki-test-categorytree-collapse-bullet' ), 'Single square brackets unchanged in plain mode' );

		assert.ok( mw.messages.set( 'mediawiki-test-wikieditor-toolbar-help-content-signature-result', '<a href=\'#\' title=\'{{#special:mypage}}\'>Username</a> (<a href=\'#\' title=\'{{#special:mytalk}}\'>talk</a>)' ), 'mw.messages.set: Register' );
		assert.strictEqual( mw.message( 'mediawiki-test-wikieditor-toolbar-help-content-signature-result' ).plain(), mw.messages.get( 'mediawiki-test-wikieditor-toolbar-help-content-signature-result' ), 'HTML message with curly braces is not changed in plain mode' );

		assertMultipleFormats( [ 'gender-plural-msg', 'male', 1 ], [ 'text', 'parse', 'escaped' ], 'he is awesome', 'Gender and plural are resolved' );
		assert.strictEqual( mw.message( 'gender-plural-msg', 'male', 1 ).plain(), '{{GENDER:male|he|she|they}} {{PLURAL:1|is|are}} awesome', 'Parameters are substituted, but gender and plural are not resolved in plain mode' );

		assert.strictEqual( mw.message( 'grammar-msg' ).plain(), mw.messages.get( 'grammar-msg' ), 'Grammar is not resolved in plain mode' );
		assertMultipleFormats( [ 'grammar-msg' ], [ 'text', 'parse' ], 'Przeszukaj ' + siteName, 'Grammar is resolved' );
		assert.strictEqual( mw.message( 'grammar-msg' ).escaped(), 'Przeszukaj ' + siteName, 'Grammar is resolved in escaped mode' );

		assertMultipleFormats( [ 'formatnum-msg', '987654321.654321' ], [ 'text', 'parse', 'escaped' ], '987,654,321.654', 'formatnum is resolved' );
		assert.strictEqual( mw.message( 'formatnum-msg' ).plain(), mw.messages.get( 'formatnum-msg' ), 'formatnum is not resolved in plain mode' );

		assertMultipleFormats( [ 'int-msg' ], [ 'text', 'parse', 'escaped' ], 'Some Other Message', 'int is resolved' );
		assert.strictEqual( mw.message( 'int-msg' ).plain(), mw.messages.get( 'int-msg' ), 'int is not resolved in plain mode' );

		assert.ok( mw.messages.set( 'mediawiki-italics-msg', '<i>Very</i> important' ), 'mw.messages.set: Register' );
		assertMultipleFormats( [ 'mediawiki-italics-msg' ], [ 'plain', 'text', 'parse' ], mw.messages.get( 'mediawiki-italics-msg' ), 'Simple italics unchanged' );
		assert.htmlEqual(
			mw.message( 'mediawiki-italics-msg' ).escaped(),
			'&lt;i&gt;Very&lt;/i&gt; important',
			'Italics are escaped in escaped mode'
		);

		assert.ok( mw.messages.set( 'mediawiki-italics-with-link', 'An <i>italicized [[link|wiki-link]]</i>' ), 'mw.messages.set: Register' );
		assertMultipleFormats( [ 'mediawiki-italics-with-link' ], [ 'plain', 'text' ], mw.messages.get( 'mediawiki-italics-with-link' ), 'Italics with link unchanged' );
		assert.htmlEqual(
			mw.message( 'mediawiki-italics-with-link' ).escaped(),
			'An &lt;i&gt;italicized [[link|wiki-link]]&lt;/i&gt;',
			'Italics and link unchanged except for escaping in escaped mode'
		);
		assert.htmlEqual(
			mw.message( 'mediawiki-italics-with-link' ).parse(),
			'An <i>italicized <a title="link" href="' + mw.util.getUrl( 'link' ) + '">wiki-link</i>',
			'Italics with link inside in parse mode'
		);

		assert.ok( mw.messages.set( 'mediawiki-script-msg', '<script  >alert( "Who put this script here?" );</script>' ), 'mw.messages.set: Register' );
		assertMultipleFormats( [ 'mediawiki-script-msg' ], [ 'plain', 'text' ], mw.messages.get( 'mediawiki-script-msg' ), 'Script unchanged' );
		assert.htmlEqual(
			mw.message( 'mediawiki-script-msg' ).escaped(),
			'&lt;script  &gt;alert( "Who put this script here?" );&lt;/script&gt;',
			'Script escaped when using escaped format'
		);
		assert.htmlEqual(
			mw.message( 'mediawiki-script-msg' ).parse(),
			'&lt;script  &gt;alert( "Who put this script here?" );&lt;/script&gt;',
			'Script escaped when using parse format'
		);

		mw.config.set( 'wgUserLanguage', 'qqx' );

		mw.messages.set( 'qqx-message', '(qqx-message)' );
		mw.messages.set( 'non-qqx-message', 'hello world' );

		assert.strictEqual( mw.message( 'missing-message' ).plain(), '⧼missing-message⧽', 'qqx message (missing)' );
		assert.strictEqual( mw.message( 'missing-message', 'bar', 'baz' ).plain(), '⧼missing-message⧽', 'qqx message (missing) with parameters' );
		assert.strictEqual( mw.message( 'qqx-message' ).plain(), '(qqx-message)', 'qqx message (defined)' );
		assert.strictEqual( mw.message( 'qqx-message', 'bar', 'baz' ).plain(), '(qqx-message: bar, baz)', 'qqx message (defined) with parameters' );
		assert.strictEqual( mw.message( 'non-qqx-message' ).plain(), 'hello world', 'non-qqx message in qqx mode' );
	} );

	QUnit.test( 'mw.msg', function ( assert ) {
		assert.ok( mw.messages.set( 'hello', 'Hello <b>awesome</b> world' ), 'mw.messages.set: Register' );
		assert.strictEqual( mw.msg( 'hello' ), 'Hello <b>awesome</b> world', 'Gets message with default options (existing message)' );
		assert.strictEqual( mw.msg( 'goodbye' ), '⧼goodbye⧽', 'Gets message with default options (nonexistent message)' );

		assert.ok( mw.messages.set( 'plural-item', 'Found $1 {{PLURAL:$1|item|items}}' ), 'mw.messages.set: Register' );
		assert.strictEqual( mw.msg( 'plural-item', 5 ), 'Found 5 items', 'Apply plural for count 5' );
		assert.strictEqual( mw.msg( 'plural-item', 0 ), 'Found 0 items', 'Apply plural for count 0' );
		assert.strictEqual( mw.msg( 'plural-item', 1 ), 'Found 1 item', 'Apply plural for count 1' );

		assert.strictEqual( mw.msg( 'mediawiki-test-pagetriage-del-talk-page-notify-summary', specialCharactersPageName ), 'Notifying author of deletion nomination for [[' + specialCharactersPageName + ']]', 'Double square brackets in mw.msg one parameter' );

		assert.strictEqual( mw.msg( 'gender-plural-msg', 'male', 1 ), 'he is awesome', 'Gender test for male, plural count 1' );
		assert.strictEqual( mw.msg( 'gender-plural-msg', 'female', '1' ), 'she is awesome', 'Gender test for female, plural count 1' );
		assert.strictEqual( mw.msg( 'gender-plural-msg', 'unknown', 10 ), 'they are awesome', 'Gender test for neutral, plural count 10' );

		assert.strictEqual( mw.msg( 'grammar-msg' ), 'Przeszukaj ' + siteName, 'Grammar is resolved' );

		assert.strictEqual( mw.msg( 'formatnum-msg', '987654321.654321' ), '987,654,321.654', 'formatnum is resolved' );

		assert.strictEqual( mw.msg( 'int-msg' ), 'Some Other Message', 'int is resolved' );
	} );
}() );
