<?php

/**
 * Copyright 2014
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use Wikimedia\ObjectFactory;

/**
 * Factory class to create Skin objects
 *
 * @since 1.24
 */
class SkinFactory {

	/**
	 * Map of skin name to object factory spec or factory function.
	 *
	 * @var array<string,array|callable>
	 */
	private $factoryFunctions = [];
	/**
	 * Map of name => fallback human-readable name, used when the 'skinname-<skin>' message is not
	 * available
	 *
	 * @var array
	 */
	private $displayNames = [];
	/**
	 * @var ObjectFactory
	 */
	private $objectFactory;

	/**
	 * Array of skins that should not be presented in the list of
	 * available skins in user preferences, while they're still installed.
	 *
	 * @var string[]
	 */
	private $skipSkins;

	/**
	 * @internal For ServiceWiring only
	 *
	 * @param ObjectFactory $objectFactory
	 * @param string[] $skipSkins
	 */
	public function __construct( ObjectFactory $objectFactory, array $skipSkins ) {
		$this->objectFactory = $objectFactory;
		$this->skipSkins = $skipSkins;
	}

	/**
	 * Register a new Skin factory function.
	 *
	 * Will override if it's already registered.
	 *
	 * @param string $name Internal skin name. Should be all-lowercase (technically doesn't have
	 *     to be, but doing so would change the case of i18n message keys).
	 * @param string $displayName For backwards-compatibility with old skin loading system. This is
	 *     the text used as skin's human-readable name when the 'skinname-<skin>' message is not
	 *     available.
	 * @param array|callable $spec Callback that takes the skin name as an argument, or
	 *     object factory spec specifying how to create the skin
	 * @throws InvalidArgumentException If an invalid callback is provided
	 */
	public function register( $name, $displayName, $spec ) {
		if ( !is_callable( $spec ) ) {
			if ( is_array( $spec ) ) {
				if ( !isset( $spec['args'] ) ) {
					// make sure name option is set:
					$spec['args'] = [
						[ 'name' => $name ]
					];
				}
			} else {
				throw new InvalidArgumentException( 'Invalid callback provided' );
			}
		}
		$this->factoryFunctions[$name] = $spec;
		$this->displayNames[$name] = $displayName;
	}

	/**
	 * Returns an associative array of:
	 *  skin name => human readable name
	 * @deprecated since 1.37 use getInstalledSkins instead
	 * @return array
	 */
	public function getSkinNames() {
		return $this->displayNames;
	}

	/**
	 * Create a given Skin using the registered callback for $name.
	 * @param string $name Name of the skin you want
	 * @throws SkinException If a factory function isn't registered for $name
	 * @return Skin
	 */
	public function makeSkin( $name ) {
		if ( !isset( $this->factoryFunctions[$name] ) ) {
			throw new SkinException( "No registered builder available for $name." );
		}

		return $this->objectFactory->createObject(
			$this->factoryFunctions[$name],
			[
				'allowCallable' => true,
				'assertClass' => Skin::class,
			]
		);
	}

	/**
	 * Fetch the list of user-selectable skins in regards to $wgSkipSkins.
	 * Useful for Special:Preferences and other places where you
	 * only want to show skins users _can_ select from preferences page.
	 *
	 * @return string[]
	 * @since 1.36
	 */
	public function getAllowedSkins() {
		$skins = $this->getInstalledSkins();

		// Internal skins not intended for general use
		unset( $skins['fallback'] );
		unset( $skins['apioutput'] );

		foreach ( $this->skipSkins as $skip ) {
			unset( $skins[$skip] );
		}

		return $skins;
	}

	/**
	 * Fetch the list of all installed skins.
	 *
	 * Returns an associative array of skin name => human readable name
	 *
	 * @return string[]
	 * @since 1.37
	 */
	public function getInstalledSkins() {
		return $this->displayNames;
	}
}
