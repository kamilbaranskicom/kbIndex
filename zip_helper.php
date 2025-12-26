<?php

/**
 * Processes POST requests for downloading files as ZIP.
 */
function handleDownloadRequest(string $physicalPath, array $config): void {
    $toZip = [];
    $action = null;

    if (isset($_POST['zip_all'])) {
        $action = 'all';
    } elseif (isset($_POST['zip_selected']) && !empty($_POST['selected'])) {
        $action = 'selected';
    }

    if (!$action) return;

    if ($action === 'all') {
        $files = getFileList($physicalPath, $config['ignorePatterns']);
        foreach ($files as $f) {
            $toZip[] = $physicalPath . DIRECTORY_SEPARATOR . $f['name'];
        }
    } else {
        foreach ($_POST['selected'] as $name) {
            // Security: strip directory separators to prevent path traversal
            $safeName = str_replace(['/', '\\'], '', $name);
            $fullPath = $physicalPath . DIRECTORY_SEPARATOR . $safeName;

            if (file_exists($fullPath)) {
                $toZip[] = $fullPath;
            }
        }
    }

    if (!empty($toZip)) {
        // Get the folder name or 'root' if empty
        $folderName = basename($physicalPath) ?: 'home';
        streamZip($toZip, $folderName, $physicalPath);
}
}

/**
 * Creates a ZIP archive from the given files and streams it to the client.
 * Executes system zip and streams the output directly to the browser.
 * Uses fast compression (-1) and timestamped naming.
 * @param array $files Array of full file paths to include in the ZIP.
 * @param string $baseName Base name for the ZIP file.
 * @param string $currentPath The directory context for relative paths.
 * @throws Exception if ZIP creation fails.
 * @return void
 */
function streamZip(array $files, string $baseName, string $currentPath): void {
    set_time_limit(900); // 15 minutes for larger sessions

    // Generate Google Drive-style filename: name-YYYYMMDD-HHII.zip
    $timestamp = date('Ymd-Hi');
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $finalFileName = "{$safeBaseName}-{$timestamp}.zip";

    $tmpZip = tempnam(sys_get_temp_dir(), 'kbindex_');
    $escapedTmpZip = escapeshellarg($tmpZip);

    $oldDir = getcwd();
    chdir($currentPath);

    // Prepare relative paths
    $relativeFiles = array_map(function ($path) {
        return escapeshellarg(basename($path));
    }, $files);

    // -1: Fastest compression (efficient for silence/empty space)
    // -r: Recursive for directories
    // -q: Quiet mode
    $cmd = "zip -1 -r $escapedTmpZip " . implode(' ', $relativeFiles);

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
        unlink($tmpZip);
        exit;
    }

    if (file_exists($tmpZip)) unlink($tmpZip);
    throw new Exception("ZIP creation failed. Check if 'zip' is installed and permissions are correct.");
}
