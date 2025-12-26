<?php
/***
 * todo:
 * - blokada w / (może po prostu nie nazywajmy tego index.php, tylko zróbmy na te wszystkie pliki folder typu include)
 * - ignore files (.ds_store, .htaccess, etc)
 * - fix downloads
 * - podepnij na cały serwis
 * - i sprawdź, czy działa w różnych scenariuszach
 */


$dir = realpath('.') ?: $baseDir;

require_once __DIR__.'/autoindex_helper.php';
require_once __DIR__.'/zip_helper.php';

// parsowanie autoindex.conf
list($iconMap,$mimeMap,$encodingMap,$altMap,$ignorePatterns) = parseAutoindexConf('/etc/apache2/mods-available/autoindex.conf');

// obsługa ZIP
if(isset($_POST['zip_all']) || isset($_POST['zip_selected'])){
    $files=[];
    if(isset($_POST['zip_all'])){
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $f) $files[] = $f;
    } elseif(!empty($_POST['selected'])){
        $files = $_POST['selected'];
    }
    createZip($dir,$files);
}

// wczytanie i sortowanie katalogu
$data = readDirectory($dir,$ignorePatterns);
$data = sortDirectoryData($data,'name','asc');

// render HTML
renderHTML($dir,$data,$iconMap,$mimeMap);
