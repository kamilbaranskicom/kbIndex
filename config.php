<?php
// config.php
$config = [
    'ignore_files' => ['.', '..', '.git', 'index.php', '.htaccess'],
    'show_hidden'  => false,
    'title'        => 'kbIndex - Lista plików',
    'descriptions' => ['.broken' => 'Broken symbolic link'],
    'ignorePatterns' => [
        'kbIndex',
        '.htaccess',
        '.git',
        '.DS_Store',
        'index.php',
        '*.log',
        'node_modules'
    ],
];
