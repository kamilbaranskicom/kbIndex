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

    // Determine the requested action
    $action = isset($_POST['zip_all']) ? 'all' : (isset($_POST['zip_selected']) ? 'selected' : null);
    if (!$action) return;

    // Get legitimate files from the current directory to prevent unauthorized access
    $allowedFiles = getFileList($physicalPath, $config);
    $allowedMap = [];
    foreach ($allowedFiles as $f) {
        $allowedMap[$f['name']] = $f['size_raw'] ?? 0;
    }

    if ($action === 'all') {
        // When zipping everything, we just pass the path and set preserveRoot to true
        $folderName = basename($physicalPath) ?: 'home';

        streamZip([], $folderName, $physicalPath, true, $totalWeight);      // TODO: total weight is 0 since we don't precompute it here!
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
        if ($totalWeight > $config['maxSizeLimit']) {
            die("Error: Selected payload (" . humanSize($totalWeight) . ") exceeds the " . humanSize($config['maxSizeLimit']) . " limit.");
        }
        // Validate that we have enough space to create the archive
        if ($totalWeight > disk_free_space(sys_get_temp_dir())) {
            die("Error: Not enough disk space to create the archive. Required: " . humanSize($totalWeight) . ".");
        }

        if (!empty($toZip)) {
            $folderName = basename($physicalPath) ?: 'home';
            streamZip($toZip, $folderName, $physicalPath, false, $totalWeight);
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
function streamZip(array $files, string $baseName, string $currentPath, bool $preserveRoot = false, $totalWeight): void {
    set_time_limit(900);

    $timestamp = date('Ymd-His');
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $finalFileName = "{$safeBaseName}_download_{$timestamp}.zip";
    $doneMarker = $finalFileName . '.done';

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
    $cmd = "(zip -1 -r " . escapeshellarg($tmpZip) . " " . $zipTarget . " && touch " . escapeshellarg($doneMarker) . ") > /dev/null 2>&1 &";

    exec($cmd, $output, $returnCode);


    // Now, instead of exit, we enter the SSE loop (if requested via AJAX)
    if ($isAsyncRequest) {
        sendProgressStream($finalFileName, $totalWeight, $doneMarker);
    }

    chdir($oldDir);


    /*
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
    */
}


/**
 * Removes temporary ZIP files older than the specified threshold.
 * * @param int $seconds Minimum age of files to be removed in seconds. (86400 = 24 hours.)
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

/**
 * Streams progress updates to the client using SSE.
 * * @param string $filePath Path to the zip file being created.
 * @param int $totalWeight Sum of sizes of files to be packed.
 * @param string $marker Path to the file created when zip finishes.
 */
function sendProgressStream($filePath, $totalWeight, $marker) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    while (ob_get_level()) ob_end_clean();

    $startTime = time();
    $timeout = 300; // 5 minutes safety net

    while (true) {
        if (time() - $startTime > $timeout) break;

        $isDone = file_exists($marker);
        $currentSize = file_exists($filePath) ? filesize($filePath) : 0;

        // Estimate progress based on file size.
        // We cap it at 99% until the marker file actually exists.
        $progress = ($totalWeight > 0) ? ($currentSize / $totalWeight) * 100 : 0;
        $progress = min($isDone ? 100 : 99, round($progress));

        echo "data: " . json_encode([
            'percent' => $progress,
            'status' => $isDone ? 'completed' : 'compressing',
            'fileName' => basename($filePath)
        ]) . "\n\n";

        flush();

        if ($isDone) {
            unlink($marker); // Cleanup marker
            break;
        }

        usleep(300000); // 300ms heartbeat
    }
}
