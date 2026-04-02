<?php

defined('TYPO3') or die();

use Codeblick\NextcloudFal\Driver\NextcloudDriver;
use Codeblick\NextcloudFal\Index\NextcloudImageMetaDataExtractor;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Resource\Index\ExtractorRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// FAL Driver registration
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['NextcloudFal'] = [
    'class' => NextcloudDriver::class,
    'shortName' => 'NextcloudFal',
    'label' => 'NextCloud WebDAV',
    'flexFormDS' => 'FILE:EXT:nextcloud_fal/Configuration/FlexForms/NextcloudStorage.xml',
];

// Persistent cache for WebDAV metadata
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nextcloud_fal'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => FileBackend::class,
    'options' => [
        'defaultLifetime' => 1800,
    ],
    'groups' => ['system'],
];

// Image metadata extractor for NextCloud files
$extractorRegistry = GeneralUtility::makeInstance(ExtractorRegistry::class);
$extractorRegistry->registerExtractionService(NextcloudImageMetaDataExtractor::class);
unset($extractorRegistry);
