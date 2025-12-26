<?php

/**
 * Processes POST requests for downloading files as a ZIP archive.
 * Includes size validation and security filtering.
 * * @param string $physicalPath The physical directory path.
 * @param array $config Configuration array including ignore patterns.
 * @return void
 */
function handleDownloadRequest(string $physicalPath, array $config): void {
    // Maintenance: Remove temporary archives older than 24 hours
    cleanOldTempFiles(86400);

    $toZip = [];
    $totalWeight = 0;
    $maxSizeLimit = 20 * 1024 * 1024 * 1024; // 20GB

    // Determine the requested action
    $action = isset($_POST['zip_all']) ? 'all' : (isset($_POST['zip_selected']) ? 'selected' : null);
    if (!$action) return;

    // Get legitimate files from the current directory to prevent unauthorized access
    $allowedFiles = getFileList($physicalPath, $config['ignorePatterns']);
    $allowedMap = [];
    foreach ($allowedFiles as $f) {
        $allowedMap[$f['name']] = $f['size_raw'] ?? 0;
    }

    if ($action === 'all') {
        // When zipping everything, we just pass the path and set preserveRoot to true
        $folderName = basename($physicalPath) ?: 'home';
        streamZip([], $folderName, $physicalPath, true);
    } else {
        // Selecting specific files
        foreach ($_POST['selected'] as $name) {
            $name = basename($name); // Sanitize to prevent path traversal
            if (isset($allowedMap[$name])) {
                $toZip[] = $physicalPath . DIRECTORY_SEPARATOR . $name;
                $totalWeight += $allowedMap[$name];
            }
        }

        // Validate total archive size before processing
        if ($totalWeight > $maxSizeLimit) {
            $weightInGb = round($totalWeight / 1024 / 1024 / 1024, 2);
            die("Error: Selected payload ($weightInGb GB) exceeds the 20 GB limit.");
        }

        if (!empty($toZip)) {
            $folderName = basename($physicalPath) ?: 'home';
            streamZip($toZip, $folderName, $physicalPath, false);
        }
    }
}

/**
 * Compresses selected files and streams the result.
 * If downloading a single directory (zip_all), it preserves the directory name in the archive.
 * * @param array $files Absolute paths of files/directories to include.
 * @param string $baseName Base name for the generated ZIP file.
 * @param string $currentPath The directory where the files are located.
 * @param bool $preserveRoot Whether to include the current directory name in the archive structure.
 * @return void
 */
function streamZip(array $files, string $baseName, string $currentPath, bool $preserveRoot = false): void {
    set_time_limit(900);

    $timestamp = date('Ymd-His');
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $finalFileName = "{$safeBaseName}_download_{$timestamp}.zip";

    $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('kbindex_') . '.zip';

    $oldDir = getcwd();

    if ($preserveRoot) {
        // Go one level up to include the current folder name in the ZIP
        $parentPath = dirname($currentPath);
        $targetFolderName = basename($currentPath);
        chdir($parentPath);
        $zipTarget = escapeshellarg($targetFolderName);
    } else {
        // Standard behavior: zip only contents
        chdir($currentPath);
        $relativeFiles = array_map('escapeshellarg', array_map('basename', $files));
        $zipTarget = implode(' ', $relativeFiles);
    }

    // -1: Fast, -r: Recursive
    $cmd = "zip -1 -r " . escapeshellarg($tmpZip) . " " . $zipTarget . " 2>&1";

    exec($cmd, $output, $returnCode);
    chdir($oldDir);

    if ($returnCode === 0 && file_exists($tmpZip)) {
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

    if (file_exists($tmpZip)) unlink($tmpZip);
    throw new Exception("ZIP Error: " . implode("\n", $output));
}

/**
 * Removes temporary ZIP files older than the specified threshold.
 * * @param int $seconds Minimum age of files to be removed in seconds.
 * @return void
 */
function cleanOldTempFiles(int $seconds = 86400): void {
    $tmpDir = sys_get_temp_dir();
    $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'kbindex_*.zip');
    $now = time();

    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) > $seconds)) {
            unlink($file);
        }
    }
}
