<?php

/***
 * todo:
 * - fix downloads
 */

define('KB_INDEX_URI', '/kbIndex/'); // URL path to the tool folder

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/autoindex_helper.php';
require_once __DIR__ . '/zip_helper.php';


// 1. Get the logical URI path (e.g., /pliki/a/)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = rawurldecode($requestUri); // Essential for folders with spaces!

// 2. Map it to the physical file system
// DOCUMENT_ROOT is /var/www/html
// $requestUri is /pliki/a/
// Result: /var/www/html/pliki/a/
$physicalPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $requestUri;

// 3. Security Check: Ensure the path is actually a directory
if (!is_dir($physicalPath)) {
    // Optional: if someone accesses /pliki/a/file.txt directly, 
    // and your .htaccess sends it here, you might want to handle it.
    header("HTTP/1.1 404 Not Found");
    die("Directory not found.");
}

$breadcrumbs = getBreadcrumbs();


// parse apache config and merge with default config
$config = array_replace_recursive($config, parseAutoindexConf('/etc/apache2/mods-available/autoindex.conf'));




// Handle download requests before any HTML is sent
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleDownloadRequest($physicalPath, $config);
}



/*
// obsługa ZIP
if (isset($_POST['zip_all']) || isset($_POST['zip_selected'])) {
    $files = [];
    if (isset($_POST['zip_all'])) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $f) $files[] = $f;
    } elseif (!empty($_POST['selected'])) {
        $files = $_POST['selected'];
    }
    createZip($dir, $files);
}
*/


// 4. Get and Process File List
try {
    $fileList = getFileList($physicalPath, $config['ignorePatterns'] ?? [], $config['show_hidden'] ?? false);
    $fileList = resolveIconsAndDescriptions($fileList, $config);
    $fileList = sortFileList($fileList, $_GET['sort'] ?? 'name', $_GET['order'] ?? 'asc');
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}


// render HTML
renderHTML($dir, $fileList, $config, $breadcrumbs);
