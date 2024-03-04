<?php

/**
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

namespace MediaWiki\Skins\Common\FeatureManagement\Requirements;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\Request\WebRequest;
use MediaWiki\Skins\Common\FeatureManagement\Requirement;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;

/**
 * @package MediaWiki\Skins\Common\FeatureManagement\Requirements
 */
final class UserPreferenceRequirement implements Requirement {

	private User $user;

	private UserOptionsLookup $userOptionsLookup;

	private string $optionName;

	private string $requirementName;

	private ?Title $title;

	private OverrideableRequirementHelper $helper;

	private Config $config;

	/**
	 * This constructor accepts all dependencies needed to determine whether
	 * the overridable config is enabled for the current user and request.
	 *
	 * @param User $user
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param string $optionName The name of the user preference.
	 * @param string $requirementName The name of the requirement presented to FeatureManager.
	 * @param WebRequest $request
	 * @param Config $config
	 * @param Title|null $title
	 */
	public function __construct(
		User $user,
		UserOptionsLookup $userOptionsLookup,
		string $optionName,
		string $requirementName,
		WebRequest $request,
		Config $config,
		Title $title = null
	) {
		$this->user = $user;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->optionName = $optionName;
		$this->requirementName = $requirementName;
		$this->title = $title;
		$this->helper = new OverrideableRequirementHelper( $request, $requirementName );
		$this->config = $config;
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->requirementName;
	}

	/**
	 * Checks whether the user preference is enabled or not. Returns true if
	 * enabled AND title is not null.
	 *
	 * @internal
	 *
	 * @return bool
	 */
	public function isPreferenceEnabled(): bool {
		$user = $this->user;
		$userOptionsLookup = $this->userOptionsLookup;
		$optionValue = $userOptionsLookup->getOption(
			$user,
			$this->optionName
		);
		// Check for 0, '0' or 'disabled'.
		// Any other value will be handled as enabled.
		$isEnabled = $optionValue && $optionValue !== 'disabled';

		return $this->title && $isEnabled;
	}

	/**
	 * Per the $options configuration (for use with $wgVictor${requirementName}Options)
	 * determine whether the requirment should be disabled on the page.
	 * For the main page: Check the value of $options['exclude']['mainpage']
	 * the requirment is disabled if:
	 *  1) The current namespace is listed in array $options['exclude']['namespaces']
	 *  OR
	 *  2) A query string parameter matches one of the regex patterns in $exclusions['querystring'].
	 *  OR
	 *  3) The canonical title matches one of the titles in $options['exclude']['pagetitles']
	 *
	 * @internal only for use inside tests.
	 * @param array|null $options
	 * @return bool
	 */
	private function shouldDisableRequirementFromOptions( $options = null ): bool {
		if ( !$options ) {
			return false;
		}
		$canonicalTitle = $this->title != null ? $this->title->getRootTitle() : null;

		$exclusions = $options['exclude'] ?? [];

		if ( $this->title != null && $this->title->isMainPage() ) {
			// only one check to make
			return $exclusions['mainpage'] ?? false;
		} elseif ( $this->title != null && $canonicalTitle->isSpecialPage() ) {
			$canonicalTitle->fixSpecialName();
		}

		//
		// Check the excluded page titles based on the canonical title
		//
		// Now we have the canonical title and the exclusions link we look for any matches.
		$pageTitles = $exclusions[ 'pagetitles' ] ?? [];
		foreach ( $pageTitles as $titleText ) {
			$excludedTitle = Title::newFromText( $titleText );

			if ( $canonicalTitle->equals( $excludedTitle ) ) {
				return true;
			}
		}

		//
		// Check the exclusions
		// If nothing matches the exclusions to determine what should happen
		//
		$excludeNamespaces = $exclusions['namespaces'] ?? [];
		// Requirment is disabled on certain namespaces
		if ( $this->title != null && $this->title->inNamespaces( $excludeNamespaces ) ) {
			return true;
		}
		$excludeQueryString = $exclusions['querystring'] ?? [];

		foreach ( $excludeQueryString as $param => $excludedParamPattern ) {
			$paramValue = $this->helper->getRequest()->getRawVal( $param );
			if ( $paramValue !== null ) {
				if ( $excludedParamPattern === '*' ) {
					// Backwards compatibility for the '*' wildcard.
					$excludedParamPattern = '.+';
				}
				return (bool)preg_match( "/$excludedParamPattern/", $paramValue );
			}
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function isMet(): bool {
		try {
			$options = $this->config->get( "Victor{$this->requirementName}Options" );
		} catch ( ConfigException $e ) {
			$options = null;
		}
		if ( $this->shouldDisableRequirementFromOptions( $options ) ) {
			return false;
		}
		$override = $this->helper->isMet();
		return $override ?? $this->isPreferenceEnabled();
	}
}
