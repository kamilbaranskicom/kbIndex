<?php
// config_site_example.php (Rename to config_site.php to use)
$config = array_merge($config, [
//    'showHiddenFiles' => false,
//    'titleSuffix'     => ' [kbIndex @ '.$_SERVER['HTTP_HOST'].']',
    'maxSizeLimit'    => 4 * 1024 * 1024 * 1024, // 4 GB
    'footerUser'      => '<a href="https://yourcompany.net/">Your Company</a>',
//    'descriptions'    => ['.broken' => 'Broken symbolic link'],
//    'ignorePatterns'  => [
//        '.',
//        '..',
//        'kbIndex',
//        '.htaccess',
//        '.git',
//        '.DS_Store',
//        'index.php',
//        '*.log',
//        'node_modules'
//    ],
]);
