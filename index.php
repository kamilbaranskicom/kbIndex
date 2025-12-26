<?php

define('KB_INDEX_URI', '/kbIndex/'); // URL path to the tool folder

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/download.php';


// 1. Get the logical URI path (e.g., /files/a/)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = rawurldecode($requestUri); // Essential for folders with spaces!

// 2. Map it to the physical file system
// DOCUMENT_ROOT is /var/www/html
// $requestUri is /files/a/
// Result: /var/www/html/files/a/
$physicalPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $requestUri;

// 3. Security Check: Ensure the path is actually a directory
if (!is_dir($physicalPath)) {
    // Optional: if someone accesses /files/a/file.txt directly, 
    // and your .htaccess sends it here, you might want to handle it.
    header("HTTP/1.1 404 Not Found");
    die("Directory not found.");
}

$breadcrumbs = getBreadcrumbs();


// parse apache config and merge with default config
$config = mergeConfigs(parseAutoindexConf('/etc/apache2/mods-available/autoindex.conf'), $config);

// Handle download requests before any HTML is sent
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleDownloadRequest($physicalPath, $config);
}



// 4. Get and Process File List
try {
    $fileList = getFileList($physicalPath, $config);
    $fileList = sortFileList($fileList, $_GET['sort'] ?? 'name', $_GET['order'] ?? 'asc');
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}


// render HTML
renderHTML($requestUri, $fileList, $config, $breadcrumbs);
