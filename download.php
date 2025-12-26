<?php
// download.php
require_once __DIR__ . '/zip_helper.php';

$baseDir = realpath('/mnt/pliki');
$action = $_POST['action'] ?? '';
$files = $_POST['files'] ?? [];

// Jeśli "pobierz wszystkie", zbieramy wszystkie pliki
if (strpos($action, 'all') === 0) {
    $allFiles = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $allFiles[] = $file->getPathname();
    }
    $files = $allFiles;
}

// Filtr bezpieczeństwa: tylko pliki w katalogu bazowym
$files = array_filter($files, function($f) use ($baseDir) {
    $real = realpath($f);
    return $real && str_starts_with($real, $baseDir);
});

if (empty($files)) {
    die('Brak plików do pobrania.');
}

// Nazwa archiwum
$timestamp = date('Ymd_His');
$archiveName = "pliki_download_$timestamp";

try {
    if (strpos($action, 'zip') !== false) {
        $archivePath = sys_get_temp_dir() . "/$archiveName.zip";
        createZipArchive($files, $archivePath);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.basename($archivePath).'"');
        readfile($archivePath);
        unlink($archivePath);
    } elseif (strpos($action, '7z') !== false) {
        $archivePath = sys_get_temp_dir() . "/$archiveName.7z";
        create7zArchive($files, $archivePath);
        header('Content-Type: application/x-7z-compressed');
        header('Content-Disposition: attachment; filename="'.basename($archivePath).'"');
        readfile($archivePath);
        unlink($archivePath);
    } else {
        die('Nieznana akcja.');
    }
} catch (Exception $e) {
    die('Błąd: ' . htmlspecialchars($e->getMessage()));
}
?>
