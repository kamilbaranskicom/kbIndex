<?php

/***
 * todo:
 * - blokada w / (może po prostu nie nazywajmy tego index.php, tylko zróbmy na te wszystkie pliki folder typu include)
 * - ignore files (.ds_store, .htaccess, etc)
 * - fix downloads
 * - podepnij na cały serwis
 * - i sprawdź, czy działa w różnych scenariuszach
 */

define('KB_INDEX_URI', '/kbIndex/'); // URL path to the tool folder
define('KB_INDEX_PATH', __DIR__ . '/'); // Physical path to the tool folder

// 3. Detect where we are
// When called via DirectoryIndex, getcwd() is the target folder
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
// debug($fileList);

// render HTML
renderHTML($dir, $fileList, $config, $breadcrumbs);

function debug($var) {
    echo '<hr><pre>';
    //var_dump($var);
    print_r($var);
    echo '</pre></hr>';
}
