<?php

namespace WikiMedia\Skins\Common\FeatureManagement;

use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;

class FeaturesHelper {

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
	 * @param array|null $options
	 * @param WebRequest $request
	 * @param Title|null $title
	 *
	 * @return bool
	 */
	public function shouldDisableRequirementFromOptions(
		$options, WebRequest $request, Title $title = null ): bool {
		if ( !$options ) {
			return false;
		}
		$canonicalTitle = $title != null ? $title->getRootTitle() : null;

		$exclusions = $options[ 'exclude' ] ?? [];

		if ( $title != null && $title->isMainPage() ) {
			// only one check to make
			return $exclusions[ 'mainpage' ] ?? false;
		} elseif ( $title != null && $canonicalTitle != null && $canonicalTitle->isSpecialPage() ) {
			$canonicalTitle->fixSpecialName();
		}

		//
		// Check the excluded page titles based on the canonical title
		//
		// Now we have the canonical title and the exclusions link we look for any matches.
		$pageTitles = $exclusions[ 'pagetitles' ] ?? [];
		foreach ( $pageTitles as $titleText ) {
			$excludedTitle = Title::newFromText( $titleText );

			if ( $canonicalTitle != null && $canonicalTitle->equals( $excludedTitle ) ) {
				return true;
			}
		}

		//
		// Check the exclusions
		// If nothing matches the exclusions to determine what should happen
		//
		$excludeNamespaces = $exclusions[ 'namespaces' ] ?? [];
		// Night Mode is disabled on certain namespaces
		if ( $title != null && $title->inNamespaces( $excludeNamespaces ) ) {
			return true;
		}
		$excludeQueryString = $exclusions[ 'querystring' ] ?? [];

		foreach ( $excludeQueryString as $param => $excludedParamPattern ) {
			$paramValue = $request->getRawVal( $param );
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
}
