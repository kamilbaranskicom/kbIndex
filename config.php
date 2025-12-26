<?php
// config.php
$config = [
    'showHiddenFiles' => false,
    'titleSuffix'     => ' [kbIndex]',
    'footerUser'      => '<a href="https://lukowastudio.com/">Łukowa Studio</a>',
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
