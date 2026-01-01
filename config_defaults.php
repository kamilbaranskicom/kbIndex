<?php
// config_defaults.php
$configDefaults = [
    'showHiddenFiles' => false,
    'titleSuffix'     => ' [kbIndex]',
    'maxSizeLimit'    => 20 * 1024 * 1024 * 1024, // 20 GB
    'footerUser'      => '<a href="https://github.com/kamilbaranskicom/kbIndex/">kbIndex</a> by <a href="https://kamilbaranski.com/">Kamil Barański</a>',
    'descriptions'    => ['.broken' => 'Broken symbolic link'],
    'ignorePatterns'  => [
        '.',
        '..',
        'kbIndex',
        '.htaccess',
        '.git',
        '.DS_Store',
        'index.php',
        '*.log',
        'node_modules'
    ],
];
