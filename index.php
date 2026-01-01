<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);


define('KB_INDEX_URI', '/kbIndex/'); // URL path to the tool folder

require_once __DIR__ . '/config_defaults.php';
if (file_exists(__DIR__ . '/config_site.php')) {
    require_once __DIR__ . '/config_site.php';
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/download.php';

// merge configs from config_defaults and config_site, than parse apache config and merge with default config
// (PS. shouldn't configs be more important than autoindex.conf?)
$config = mergeConfigs($configDefaults, $configSite ?? []);
$config = mergeConfigs(parseAutoindexConf('/etc/apache2/mods-available/autoindex.conf'), $config);

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'zip':
        // PHASE 1: Calculate weight and start SSE stream
        // handleZipAction();
        break;

    case 'download':
        // PHASE 2: Serve the actual binary file
        // handleDownloadAction();
        break;

    case 'list':
    default:
        // PHASE 0: Standard directory listing (your current code)
        handleDirectoryListingRequest($config);
        break;
}

function handleDownloadAction() {
    $tmpZip = $_GET('fileName') || die("No fileName given.");
    //if ($returnCode === 0 && file_exists($tmpZip)) {
    if (file_exists($tmpZip)) {
        if (ob_get_level()) ob_end_clean();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $finalFileName . '"');
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($tmpZip);
        if (file_exists($tmpZip)) unlink($tmpZip);
        exit;
    }
}

function handleDirectoryListingRequest($config) {
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
        die("404 not found.");  // was: directory not found
    }

    $breadcrumbs = getBreadcrumbs();




    // Handle download requests before any HTML is sent
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handleDownloadRequest($physicalPath, $config);
    }

    $sort = $_GET['sort'] ?? 'name';
    $order = $_GET['order'] ?? 'asc';


    // 4. Get and Process File List
    try {
        $fileList = getFileList($physicalPath, $config);
        $fileList = sortFileList($fileList, $sort, $order);
    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }


    // render HTML
    renderHTML($requestUri, $fileList, $config, $breadcrumbs, $sort, $order);
}
