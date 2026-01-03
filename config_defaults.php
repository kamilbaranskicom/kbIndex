<?php
// config_defaults.php
$configDefaults = [
    'showHiddenFiles' => false,
    'titleSuffix'     => ' [kbIndex]',
    'maxSizeLimit'    => 20 * 1024 * 1024 * 1024, // 20 GB
    'maxDiskSpaceLimit' => 20 * 1024 * 1024, // 20 MB
    'displayNewestItem' => true,
    'logFile'        => __DIR__ . '/kbIndex.activity.log',
    'footerUser'      => '{📂} <a href="https://github.com/kamilbaranskicom/kbIndex/">kbIndex</a> by <a href="https://kamilbaranski.com/">Kamil Barański</a>',
    'descriptions'    => ['.broken' => 'Broken symbolic link'],
    // 'allowDeletion'   => false,  // TODO - allow deletion and maybe upload?
    'ignorePatterns'  => [  // Beware! The ignorePatterns doesn't work when downloading a subdirectory! (whole subdirectory is going to be compressed)
        '.',
        '..',
        'kbIndex',
        '.htaccess',
        '.git',
        '.DS_Store',
        'index.php',
        '*.log',
        'node_modules',
        'kbIndex.activity.log',
    ],
    'permissions'     => [
        '/'             => ['allowDelete' => false], // Główny folder zablokowany
        '/uploads'      => ['allowDelete' => true],  // Można kasować w /uploads
        '/temp'         => ['allowDelete' => true],
        '/kbIndex/testfolder/2ndfolder' => ['allowDelete' => true],
    ],
];
