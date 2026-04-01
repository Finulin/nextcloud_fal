<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'NextCloud FAL Driver',
    'description' => 'Provides a FAL storage driver for NextCloud via WebDAV',
    'category' => 'be',
    'author' => 'Codeblick',
    'state' => 'alpha',
    'version' => '13.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
    ],
];
