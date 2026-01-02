<?php

/**
 * Processes POST requests for downloading files as a ZIP archive.
 * Includes size validation and security filtering.
 * * @param string $physicalPath The physical directory path.
 * @param array $config Configuration array including ignore patterns.
 * @return void
 */
function handleZipRequest(string $physicalPath, $files, array $config): void {
    // debug($physicalPath);

    // Maintenance: Remove temporary archives older than 24 hours
    cleanOldTempFiles(86400);

    if (!$files) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Błędna lista plików']) . "\n\n";
        exit;
    }

    $name = basename($physicalPath) ?: 'archive';

    // Prevent unauthorized access:
    // - get legitimate files from the current directory to prevent unauthorized access
    $allowedFiles = getFileList($physicalPath, $config);

    // (if user needs all the files, this is the same list as $allowedFiles.)
    if (isset($_GET['all'])) {
        $files = $allowedFiles;
    }

    // - sanitize to prevent path traversal
    $files = array_map('basename', $files);

    // convert $allowedFiles to $map helper array:
    //      $map['file.txt'] = 40 (bytes),
    //      $map['file.mp3'] = 6 543 210 (bytes),
    //      $map['not-allowed-file.git'] = -1 (status: not allowed)
    //      etc.
    $map = [];
    foreach ($allowedFiles as $allowedFile) {
        $map[$allowedFile['name']] = $allowedFile['size'] ?? -1;
    }

    // - remove unAllowed files (not existing in getFileList())
    $files = array_filter($files, function ($filename) use ($map) {
        return isset($map[$filename]) && ($map[$filename] >= 0);
    });

    // - check if every file exists
    $files = array_filter($files, function ($filename) use ($physicalPath) {
        return file_exists($physicalPath . DIRECTORY_SEPARATOR . $filename);
    });
    // THE $FILES LIST IS LEGIT. ALL THE FILES ARE ALLOWED TO ARCHIVE/DOWNLOAD.

    // get the sum of file sizes.
    $totalWeight = 0;
    foreach ($files as $filename) {
        $totalWeight += $map[$filename];
    };

    // add $physicalPath to all the files
    $filesToZip = array_map(function ($filename) use ($physicalPath) {
        return $physicalPath . DIRECTORY_SEPARATOR . $filename;
    }, $files);


    // Validate total archive size before processing
    if ($totalWeight > $config['maxSizeLimit']) {
        die("Error: Selected payload (" . humanSize($totalWeight) . ") exceeds the " . humanSize($config['maxSizeLimit']) . " limit.");
    }
    // Validate that we have enough space to create the archive
    if ($totalWeight > disk_free_space(sys_get_temp_dir())) {
        die("Error: Not enough disk space to create the archive. Required: " . humanSize($totalWeight) . ".");
    }
    if (!empty($filesToZip)) {
        streamZip($filesToZip, $name, $physicalPath, $totalWeight, isset($_GET['all']));
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
function streamZip(array $files, string $baseName, string $currentPath, $totalWeight, bool $preserveRoot = false): void {
    set_time_limit(900);

    $timestamp = date('Ymd-His');
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $finalFileName = "{$safeBaseName}_download_{$timestamp}.zip";           // we use this filename only for Content-Disposition
    $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('kbindex_') . '.zip';
    $doneMarker = $tmpZip . '.done';

    $oldDir = getcwd();

    // if ($preserveRoot) {
    //     // Go one level up to include the current folder name in the ZIP
    //     $parentPath = dirname($currentPath);
    //     $targetFolderName = basename($currentPath);
    //     chdir($parentPath);
    //     $zipTarget = escapeshellarg($targetFolderName);
    // } else {
    // Standard behavior: zip only contents
    chdir($currentPath);
    $relativeFiles = array_map('escapeshellarg', array_map('basename', $files));
    $zipTarget = implode(' ', $relativeFiles);
    // }

    // -1: Fast, -r: Recursive
    $cmd = "(zip -1 -r " . escapeshellarg($tmpZip) . " " . $zipTarget . " && touch " . escapeshellarg($doneMarker) . ") > /dev/null 2>&1 &";

    exec($cmd, $output, $returnCode);

    // Calculate stats for the user feedback
    $stats = array_reduce($files, function ($carry, $item) {
        !empty($item['is_dir']) ? $carry['dirs']++ : $carry['files']++;
        return $carry;
    }, ['dirs' => 0, 'files' => 0]);
    $stats['totalWeight'] = $totalWeight;

    // Now, instead of exit, we enter the SSE loop (if requested via AJAX)
    // if ($isAsyncRequest) {
    sendProgressStream($tmpZip, $finalFileName, $doneMarker, $stats);
    // }

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
 * * @param string $tmpZip Path to the zip file being created.
 * @param int $totalWeight Sum of sizes of files to be packed.
 * @param string $marker Path to the file created when zip finishes.
 * @return void
 */
function sendProgressStream(string $tmpZip, string $finalFileName, string $marker, array $stats) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');

    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);

    while (ob_get_level()) ob_end_clean();

    $startTime = time();
    $timeout = 300; // 5 minutes safety net


    while (true) {
        if (time() - $startTime > $timeout) break;

        $isDone = file_exists($marker);
        $currentSize = file_exists($tmpZip) ? filesize($tmpZip) : 0;

        // Estimate progress based on file size.
        // We cap it at 99% until the marker file actually exists.
        $progress = ($stats['totalWeight'] > 0) ? ($currentSize / $stats['totalWeight']) * 100 : 0;
        $progress = min($isDone ? 100 : 99, round($progress));

        // Inside your SSE loop (send this once at the start or with every update)
        echo "data: " . json_encode([
            'percent' => $progress,
            'status' => $isDone ? 'done' : 'progress',
            'fileName' => basename($tmpZip),
            'stats' => [
                'totalFolders' => $stats['dirs'],
                'totalFiles' => $stats['files']
            ],
            'downloadUrl' => $isDone ? '?action=download&tempFileName=' . basename($tmpZip) . '&finalFileName=' . basename($finalFileName) : null
        ]) . "\n\n";

        flush();

        if ($isDone) {
            unlink($marker); // Cleanup marker
            break;
        }

        usleep(300000); // 300ms heartbeat
    }
}
