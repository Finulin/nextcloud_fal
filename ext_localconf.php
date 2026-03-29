<?php

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers']['NextcloudFal'] = [
    'class' => \Codeblick\NextcloudFal\Driver\NextcloudDriver::class,
    'shortName' => 'NextcloudFal',
    'label' => 'NextCloud WebDAV',
    'flexFormDS' => 'FILE:EXT:nextcloud_fal/Configuration/FlexForms/NextcloudStorage.xml',
];
