<?php
// config_site_example.php (Rename to config_site.php to use)
$config = array_merge($config, [
    //    'showHiddenFiles' => false,
    //    'titleSuffix'     => ' [kbIndex @ '.$_SERVER['HTTP_HOST'].']',
    'maxSizeLimit'    => 4 * 1024 * 1024 * 1024, // 4 GB
    'maxDiskSpaceLimit' => 1 * 1024 * 1024 * 1024, // 1 GB
    //    'displayNewestItem' => true,
    //    'logFile'        => __DIR__ . '/kbIndex.activity.log',
    'footerUser'      => '{📂} <a href="https://yourcompany.net/">Your Company</a>',
    //    'descriptions'    => ['.broken' => 'Broken symbolic link'],
    //    'ignorePatterns'  => [    // Beware! The ignorePatterns doesn't work when downloading a subdirectory! (whole subdirectory is going to be compressed)
    //        '.',
    //        '..',
    //        'kbIndex',
    //        '.htaccess',
    //        '.git',
    //        '.DS_Store',
    //        'index.php',
    //        '*.log',
    //        'node_modules',
    //        'kbIndex.activity.log',
    //    ],
        'permissions'     => [
        '/'             => ['allowDelete' => false], // Główny folder zablokowany
        '/uploads'      => ['allowDelete' => true],  // Można kasować w /uploads
        '/temp'         => ['allowDelete' => true],
        '/kbIndex/testfolder/2ndfolder' => ['allowDelete' => true],
        '/yt'           => ['allowDelete' => true],
    ],
]);
