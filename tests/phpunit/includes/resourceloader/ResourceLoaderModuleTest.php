<?php

class ResourceLoaderModuleTest extends ResourceLoaderTestCase {

	/**
	 * @covers ResourceLoaderModule::getVersionHash
	 */
	public function testGetVersionHash() {
		$context = $this->getResourceLoaderContext( [ 'debug' => 'false' ] );

		$baseParams = [
			'scripts' => [ 'foo.js', 'bar.js' ],
			'dependencies' => [ 'jquery', 'mediawiki' ],
			'messages' => [ 'hello', 'world' ],
		];

		$module = new ResourceLoaderFileModule( $baseParams );
		$version = json_encode( $module->getVersionHash( $context ) );

		// Exactly the same
		$module = new ResourceLoaderFileModule( $baseParams );
		$this->assertEquals(
			$version,
			json_encode( $module->getVersionHash( $context ) ),
			'Instance is insignificant'
		);

		// Re-order dependencies
		$module = new ResourceLoaderFileModule( [
			'dependencies' => [ 'mediawiki', 'jquery' ],
		] + $baseParams );
		$this->assertEquals(
			$version,
			json_encode( $module->getVersionHash( $context ) ),
			'Order of dependencies is insignificant'
		);

		// Re-order messages
		$module = new ResourceLoaderFileModule( [
			'messages' => [ 'world', 'hello' ],
		] + $baseParams );
		$this->assertEquals(
			$version,
			json_encode( $module->getVersionHash( $context ) ),
			'Order of messages is insignificant'
		);

		// Re-order scripts
		$module = new ResourceLoaderFileModule( [
			'scripts' => [ 'bar.js', 'foo.js' ],
		] + $baseParams );
		$this->assertNotEquals(
			$version,
			json_encode( $module->getVersionHash( $context ) ),
			'Order of scripts is significant'
		);

		// Subclass
		$module = new ResourceLoaderFileModuleTestingSubclass( $baseParams );
		$this->assertNotEquals(
			$version,
			json_encode( $module->getVersionHash( $context ) ),
			'Class is significant'
		);
	}

	/**
	 * @covers ResourceLoaderModule::getVersionHash
	 */
	public function testGetVersionHash_length() {
		$context = $this->getResourceLoaderContext( [ 'debug' => 'false' ] );
		$module = new ResourceLoaderTestModule( [
			'script' => 'foo();'
		] );
		$version = $module->getVersionHash( $context );
		$this->assertSame( ResourceLoader::HASH_LENGTH, strlen( $version ), 'Hash length' );
	}

	/**
	 * @covers ResourceLoaderModule::getVersionHash
	 */
	public function testGetVersionHash_parentDefinition() {
		$context = $this->getResourceLoaderContext( [ 'debug' => 'false' ] );
		$module = $this->getMockBuilder( ResourceLoaderModule::class )
			->onlyMethods( [ 'getDefinitionSummary' ] )->getMock();
		$module->method( 'getDefinitionSummary' )->willReturn( [ 'a' => 'summary' ] );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'must call parent' );
		$module->getVersionHash( $context );
	}

	/**
	 * @covers ResourceLoaderModule::validateScriptFile
	 */
	public function testValidateScriptFile() {
		$this->setMwGlobals( 'wgResourceLoaderValidateJS', true );

		$context = $this->getResourceLoaderContext();

		$module = new ResourceLoaderTestModule( [
			'mayValidateScript' => true,
			'script' => "var a = 'this is';\n {\ninvalid"
		] );
		$module->setConfig( $context->getResourceLoader()->getConfig() );
		$this->assertEquals(
			'mw.log.error(' .
				'"JavaScript parse error (scripts need to be valid ECMAScript 5): ' .
				'Parse error: Unexpected token; token } expected in file \'input\' on line 3"' .
			');',
			$module->getScript( $context ),
			'Replace invalid syntax with error logging'
		);

		$module = new ResourceLoaderTestModule( [
			'script' => "\n'valid';"
		] );
		$this->assertEquals(
			"\n'valid';",
			$module->getScript( $context ),
			'Leave valid scripts as-is'
		);
	}

	public static function provideBuildContentScripts() {
		return [
			[
				"mw.foo()",
				"mw.foo()\n",
			],
			[
				"mw.foo();",
				"mw.foo();\n",
			],
			[
				"mw.foo();\n",
				"mw.foo();\n",
			],
			[
				"mw.foo()\n",
				"mw.foo()\n",
			],
			[
				"mw.foo()\n// mw.bar();",
				"mw.foo()\n// mw.bar();\n",
			],
			[
				"mw.foo()\n// mw.bar()",
				"mw.foo()\n// mw.bar()\n",
			],
			[
				"mw.foo()// mw.bar();",
				"mw.foo()// mw.bar();\n",
			],
		];
	}

	/**
	 * @dataProvider provideBuildContentScripts
	 * @covers ResourceLoaderModule::buildContent
	 */
	public function testBuildContentScripts( $raw, $build, $message = '' ) {
		$context = $this->getResourceLoaderContext();
		$module = new ResourceLoaderTestModule( [
			'script' => $raw
		] );
		$this->assertEquals( $raw, $module->getScript( $context ), 'Raw script' );
		$this->assertEquals(
			$build,
			$module->getModuleContent( $context )[ 'scripts' ],
			$message
		);
	}

	/**
	 * @covers ResourceLoaderModule::getRelativePaths
	 * @covers ResourceLoaderModule::expandRelativePaths
	 */
	public function testPlaceholderize() {
		$getRelativePaths = new ReflectionMethod( ResourceLoaderModule::class, 'getRelativePaths' );
		$getRelativePaths->setAccessible( true );
		$expandRelativePaths = new ReflectionMethod( ResourceLoaderModule::class, 'expandRelativePaths' );
		$expandRelativePaths->setAccessible( true );

		$this->setMwGlobals( [
			'IP' => '/srv/example/mediawiki/core',
		] );
		$raw = [
				'/srv/example/mediawiki/core/resources/foo.js',
				'/srv/example/mediawiki/core/extensions/Example/modules/bar.js',
				'/srv/example/mediawiki/skins/Example/baz.css',
				'/srv/example/mediawiki/skins/Example/images/quux.png',
		];
		$canonical = [
				'resources/foo.js',
				'extensions/Example/modules/bar.js',
				'../skins/Example/baz.css',
				'../skins/Example/images/quux.png',
		];
		$this->assertEquals(
			$canonical,
			$getRelativePaths->invoke( null, $raw ),
			'Insert placeholders'
		);
		$this->assertEquals(
			$raw,
			$expandRelativePaths->invoke( null, $canonical ),
			'Substitute placeholders'
		);
	}

	/**
	 * @covers ResourceLoaderModule::getHeaders
	 * @covers ResourceLoaderModule::getPreloadLinks
	 */
	public function testGetHeaders() {
		$context = $this->getResourceLoaderContext();

		$module = new ResourceLoaderTestModule();
		$this->assertSame( [], $module->getHeaders( $context ), 'Default' );

		$module = $this->getMockBuilder( ResourceLoaderTestModule::class )
			->onlyMethods( [ 'getPreloadLinks' ] )->getMock();
		$module->method( 'getPreloadLinks' )->willReturn( [
			'https://example.org/script.js' => [ 'as' => 'script' ],
		] );
		$this->assertSame(
			[
				'Link: <https://example.org/script.js>;rel=preload;as=script'
			],
			$module->getHeaders( $context ),
			'Preload one resource'
		);

		$module = $this->getMockBuilder( ResourceLoaderTestModule::class )
			->onlyMethods( [ 'getPreloadLinks' ] )->getMock();
		$module->method( 'getPreloadLinks' )->willReturn( [
			'https://example.org/script.js' => [ 'as' => 'script' ],
			'/example.png' => [ 'as' => 'image' ],
		] );
		$this->assertSame(
			[
				'Link: <https://example.org/script.js>;rel=preload;as=script,' .
					'</example.png>;rel=preload;as=image'
			],
			$module->getHeaders( $context ),
			'Preload two resources'
		);
	}
}
