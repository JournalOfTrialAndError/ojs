<?php

/**
 * @file plugins/metadata/dc11/filter/Dc11SchemaArticleAdapter.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Dc11SchemaArticleAdapter
 * @ingroup plugins_metadata_dc11_filter
 * @see Article
 * @see PKPDc11Schema
 *
 * @brief Abstract base class for meta-data adapters that
 *  injects/extracts Dublin Core schema compliant meta-data into/from
 *  a Submission object.
 */


import('lib.pkp.classes.metadata.MetadataDataObjectAdapter');

class Dc11SchemaArticleAdapter extends MetadataDataObjectAdapter {
	//
	// Implement template methods from Filter
	//
	/**
	 * @see Filter::getClassName()
	 */
	function getClassName() {
		return 'plugins.metadata.dc11.filter.Dc11SchemaArticleAdapter';
	}


	//
	// Implement template methods from MetadataDataObjectAdapter
	//
	/**
	 * @see MetadataDataObjectAdapter::injectMetadataIntoDataObject()
	 * @param $metadataDescription MetadataDescription
	 * @param $targetDataObject Article
	 */
	function &injectMetadataIntoDataObject(&$metadataDescription, &$targetDataObject) {
		// Not implemented
		assert(false);
	}

	/**
	 * @see MetadataDataObjectAdapter::extractMetadataFromDataObject()
	 * @param $article Article
	 * @return MetadataDescription
	 */
	function &extractMetadataFromDataObject(&$article) {
		assert(is_a($article, 'Article'));

		AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_SUBMISSION);

		// Retrieve data that belongs to the article.
		// FIXME: Retrieve this data from the respective entity DAOs rather than
		// from the OAIDAO once we've migrated all OAI providers to the
		// meta-data framework. We're using the OAIDAO here because it
		// contains cached entities and avoids extra database access if this
		// adapter is called from an OAI context.
		$oaiDao = DAORegistry::getDAO('OAIDAO'); /* @var $oaiDao OAIDAO */
		$journal = $oaiDao->getJournal($article->getData('contextId'));
		$section = $oaiDao->getSection($article->getSectionId());

		$dc11Description = $this->instantiateMetadataDescription();

		// Title
		$this->_addLocalizedElements($dc11Description, 'dc:title', $article->getFullTitle(null));

		// Creator
		$authors = $article->getAuthors();
		foreach($authors as $author) {
			$dc11Description->addStatement('dc:creator', $author->getFullName(false, true));
		}

		// Subject
		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
		$submissionSubjectDao = DAORegistry::getDAO('SubmissionSubjectDAO');
		$supportedLocales = array_keys(AppLocale::getSupportedFormLocales());
		$subjects = array_merge_recursive(
			(array) $submissionKeywordDao->getKeywords($article->getCurrentPublication()->getId(), $supportedLocales),
			(array) $submissionSubjectDao->getSubjects($article->getCurrentPublication()->getId(), $supportedLocales)
		);
		$this->_addLocalizedElements($dc11Description, 'dc:subject', $subjects);

		// Description
		$this->_addLocalizedElements($dc11Description, 'dc:description', $article->getAbstract(null));

		// Publisher
		$publisherInstitution = $journal->getData('publisherInstitution');
		if (!empty($publisherInstitution)) {
			$publishers = array($journal->getPrimaryLocale() => $publisherInstitution);
		} else {
			$publishers = $journal->getName(null); // Default
		}
		$this->_addLocalizedElements($dc11Description, 'dc:publisher', $publishers);

		// Contributor
		$contributors = (array) $article->getSponsor(null);
		foreach ($contributors as $locale => $contributor) {
			$contributors[$locale] = array_map('trim', explode(';', $contributor));
		}
		$this->_addLocalizedElements($dc11Description, 'dc:contributor', $contributors);


		// Date
		if (is_a($article, 'Submission')) {
			if ($article->getDatePublished()) $dc11Description->addStatement('dc:date', date('Y-m-d', strtotime($article->getDatePublished())));		}

		// Type
		$driverType = 'info:eu-repo/semantics/preprint';
		$dc11Description->addStatement('dc:type', $driverType, METADATA_DESCRIPTION_UNKNOWN_LOCALE);
		$this->_addLocalizedElements($dc11Description, 'dc:type', $types);
		$driverVersion = 'info:eu-repo/semantics/publishedVersion';
		$dc11Description->addStatement('dc:type', $driverVersion, METADATA_DESCRIPTION_UNKNOWN_LOCALE);

		// Format
		if (is_a($article, 'Submission')) {
			$articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $articleGalleyDao ArticleGalleyDAO */
			$galleys = $articleGalleyDao->getByPublicationId($article->getCurrentPublication()->getId());
			$formats = array();
			while ($galley = $galleys->next()) {
				$dc11Description->addStatement('dc:format', $galley->getFileType());
			}
		}

		// Identifier: URL
		$request = Application::get()->getRequest();
		if (is_a($article, 'Submission')) {
			$dc11Description->addStatement('dc:identifier', $request->url($journal->getPath(), 'preprint', 'view', array($article->getBestId())));
		}

		// Get galleys and supp files.
		$galleys = array();
		if (is_a($article, 'Submission')) {
			$articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $articleGalleyDao ArticleGalleyDAO */
			$galleys = $articleGalleyDao->getByPublicationId($article->getCurrentPublication()->getId())->toArray();
		}

		// Language
		$locales = array();
		if (is_a($article, 'Submission')) {
			foreach ($galleys as $galley) {
				$galleyLocale = $galley->getLocale();
				if(!is_null($galleyLocale) && !in_array($galleyLocale, $locales)) {
					$locales[] = $galleyLocale;
					$dc11Description->addStatement('dc:language', AppLocale::getIso3FromLocale($galleyLocale));
				}
			}
		}
		$articleLanguage = $article->getLanguage();
		if (empty($locales) && !empty($articleLanguage)) {
			$dc11Description->addStatement('dc:language', strip_tags($articleLanguage));
		}

		// Relation
		// full text URLs
		if ($includeUrls) foreach ($galleys as $galley) {
			$relation = $request->url($journal->getPath(), 'article', 'view', array($article->getBestId(), $galley->getBestGalleyId()));
			$dc11Description->addStatement('dc:relation', $relation);
		}

		// Public identifiers
		$pubIdPlugins = (array) PluginRegistry::loadCategory('pubIds', true, $journal->getId());
		foreach ($pubIdPlugins as $pubIdPlugin) {
			if ($pubArticleId = $article->getStoredPubId($pubIdPlugin->getPubIdType())) {
				$dc11Description->addStatement('dc:identifier', $pubArticleId);
			}
			foreach ($galleys as $galley) {
				if ($pubGalleyId = $galley->getStoredPubId($pubIdPlugin->getPubIdType())) {
					$dc11Description->addStatement('dc:relation', $pubGalleyId);
				}
			}
		}

		// Coverage
		$this->_addLocalizedElements($dc11Description, 'dc:coverage', (array) $article->getCoverage(null));

		// Rights: Add both copyright statement and license
		$copyrightHolder = $article->getLocalizedCopyrightHolder();
		$copyrightYear = $article->getCopyrightYear();
		if (!empty($copyrightHolder) && !empty($copyrightYear)) {
			$dc11Description->addStatement('dc:rights', __('submission.copyrightStatement', array('copyrightHolder' => $copyrightHolder, 'copyrightYear' => $copyrightYear)));
		}
		if ($licenseUrl = $article->getLicenseURL()) $dc11Description->addStatement('dc:rights', $licenseUrl);

		HookRegistry::call('Dc11SchemaArticleAdapter::extractMetadataFromDataObject', array($this, $article, $journal, &$dc11Description));

		return $dc11Description;
	}

	/**
	 * @see MetadataDataObjectAdapter::getDataObjectMetadataFieldNames()
	 * @param $translated boolean
	 */
	function getDataObjectMetadataFieldNames($translated = true) {
		// All DC fields are mapped.
		return array();
	}


	//
	// Private helper methods
	//
	/**
	 * Add an array of localized values to the given description.
	 * @param $description MetadataDescription
	 * @param $propertyName string
	 * @param $localizedValues array
	 */
	function _addLocalizedElements(&$description, $propertyName, $localizedValues) {
		foreach(stripAssocArray((array) $localizedValues) as $locale => $values) {
			if (is_scalar($values)) $values = array($values);
			foreach($values as $value) {
				if (!empty($value)) {
					$description->addStatement($propertyName, $value, $locale);
				}
			}
		}
	}
}
