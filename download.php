<?php

/**
 * Processes POST requests for downloading files as a ZIP archive.
 * Includes size validation and security filtering.
 * * @param string $physicalPath The physical directory path.
 * @param array $config Configuration array including ignore patterns.
 * @return void
 */
function handleZipRequest(string $physicalPath, $files, bool $allFiles, array $config, bool $isAsyncRequest): void {
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
    if ($allFiles) {
        $files = array_column($allowedFiles, 'name');
    }

    // reject the unallowed files from $files
    $files = filterAllowedFiles($files, $allowedFiles, $physicalPath, $map);
    // THE $FILES LIST IS LEGIT. ALL THE FILES ARE ALLOWED TO ARCHIVE/DOWNLOAD.
    // TODO: shall we check the subdirectories and use allowed files only? At the moment we allow for all the files (subdirectory/*)

    // If only one file is requested and it is a regular file, do not make a ZIP!
    if (!$allFiles && count($files) === 1) {
        $singleFile = reset($files);
        $fullPath = $physicalPath . DIRECTORY_SEPARATOR . $singleFile;
        if (is_file($fullPath)) {
            if ($isAsyncRequest) {
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                while (ob_get_level()) {
                    ob_end_clean();
                }
                echo "data: " . json_encode([
                    'percent' => 100,
                    'status' => 'done',
                    'downloadUrl' => '?action=download&file=' . rawurlencode($singleFile)
                ]) . "\n\n";
                flush();
                exit;
            } else {
                sendDirectFile($fullPath, $singleFile);
                exit;
            }
        }
    }

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
        $msg = "Error: Selected payload (" . humanSize($totalWeight) . ") exceeds the " . humanSize($config['maxSizeLimit']) . " limit.";
        if ($isAsyncRequest) {
            echo "data: " . json_encode(['type' => 'error', 'message' => $msg]) . "\n\n";
            exit;
        }
        die($msg);
    }
    // Validate that we have enough space to create the archive
    if ($totalWeight > (disk_free_space(sys_get_temp_dir()) - $config['maxDiskSpaceLimit'])) {
        $msg = "Error: Not enough disk space to create the archive. Required: " . humanSize($totalWeight) . " + additional " . humanSize($config['maxDiskSpaceLimit']) . ".";
        if ($isAsyncRequest) {
            echo "data: " . json_encode(['type' => 'error', 'message' => $msg]) . "\n\n";
            exit;
        }
        die($msg);
    }
    if (!empty($filesToZip)) {
        streamZip($filesToZip, $name, $physicalPath, $totalWeight, $allFiles, $isAsyncRequest);
    }
}


function filterAllowedFiles($files, $allowedFiles, $physicalPath, &$map) {
    // - sanitize to prevent path traversal (just in case of a refactor, 'cause it is done also on $_GET['files'] in index.php)
    $files = array_map('basename', $files);

    // convert $allowedFiles to $map helper array:
    //      $map['file.txt'] = 40               // (bytes),
    //      $map['file.mp3'] = 6 543 210        // (bytes),
    //      $map['not-allowed-file.git'] = -1   // (status: not allowed)
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

    return $files;
};


/**
 * Compresses selected files and streams the result.
 * If downloading a single directory (zip_all), it preserves the directory name in the archive.
 * * @param array $files Absolute paths of files/directories to include.
 * @param string $baseName Base name for the generated ZIP file.
 * @param string $currentPath The directory where the files are located.
 * @param bool $preserveRoot Whether to include the current directory name in the archive structure.
 * @return void
 */
function streamZip(array $files, string $baseName, string $currentPath, $totalWeight, bool $preserveRoot = false, bool $isAsyncRequest = false): void {
    set_time_limit(900);    // 15 minutes max

    $timestamp = date('Ymd-His');
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $finalFileName = "{$safeBaseName}_download_{$timestamp}.zip";           // we use this filename only for Content-Disposition
    $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('kbindex_') . '.zip';
    $doneMarker = $tmpZip . '.done';

    //debug($tmpZip);
    //die();

    $oldDir = getcwd();

    if ($preserveRoot) {
        // Go one level up to include the current folder name in the ZIP
        $parentPath = dirname($currentPath);
        $targetFolderName = basename($currentPath);
        chdir($parentPath);
        $zipTarget = escapeshellarg($targetFolderName);
    } else {
        if (count($files) === 1) {
            // we can change the name to the selected file/folder.
            $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', basename($files[0]));
            $finalFileName = "{$safeBaseName}_download_{$timestamp}.zip";           // we use this filename only for Content-Disposition
        }
        // Standard behavior: zip only contents
        chdir($currentPath);
        $relativeFiles = array_map('escapeshellarg', array_map('basename', $files));
        $zipTarget = implode(' ', $relativeFiles);
    }

    // -1: Fast, -r: Recursive
    //    $cmd = "(zip -1 -r " . escapeshellarg($tmpZip) . " " . $zipTarget . " && touch " . escapeshellarg($doneMarker) . ") > /dev/null 2>&1 &";
    $zipCmd = 'zip -1 -r - ' . $zipTarget . ' > ' . escapeshellarg($tmpZip);
    $touchCmd = 'touch ' . escapeshellarg($doneMarker);
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kbIndex.log.txt';

    logToFile("\n\n" . date('Ymd-His') . " Creating " . $finalFileName . " (" . $tmpZip . ") :\n", $logFile);

    $cmd = '(' . $zipCmd . ' && ' . $touchCmd . ') >> ' . $logFile . ' 2>&1';

    // $cmd = "(zip -1 -r " . escapeshellarg($tmpZip) . " " . $zipTarget . " && touch " . escapeshellarg($doneMarker) . ") >> /tmp/mojlog.txt 2>&1";

    if ($isAsyncRequest) {
        $cmd .= ' &'; // send to background
    }

    exec($cmd, $output, $returnCode);

    // Calculate stats for the user feedback
    $stats = array_reduce($files, function ($carry, $item) {
        is_dir($item) ? $carry['dirs']++ : $carry['files']++;
        return $carry;
    }, ['dirs' => 0, 'files' => 0]);
    $stats['totalWeight'] = $totalWeight;

    // Now, instead of exit, we enter the SSE loop (if requested via AJAX)
    if ($isAsyncRequest) {
        sendProgressStream($tmpZip, $finalFileName, $doneMarker, $stats);
    } else {
        // no JS; POST method
        if ($returnCode === 0 && file_exists($tmpZip)) {
            sendZipFile($tmpZip, $finalFileName);
            exit;
        }
        if (file_exists($tmpZip)) unlink($tmpZip);
        throw new Exception("ZIP Error: " . implode("\n", $output));
    }

    chdir($oldDir);
}


/**
 * Sends a file directly to the client as an attachment download (not inline).
 *
 * @param string $filePath The absolute path to the file on disk.
 * @param string|null $downloadName The filename for Content-Disposition.
 * @return void
 */
function sendDirectFile(string $filePath, ?string $downloadName = null): void {
    if (file_exists($filePath) && is_file($filePath)) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        $downloadName = $downloadName ?: basename($filePath);
        $safeAsciiName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $downloadName);
        $encodedName = rawurlencode($downloadName);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeAsciiName . '"; filename*=UTF-8\'\'' . $encodedName);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($filePath);
        exit;
    }
}


/**
 * Sends the generated ZIP file to the client for download and deletes the file.
 *
 * @param string $tmpZip The temporary path to the generated ZIP file.
 * @param string $finalFileName The desired filename for the download.
 * returns true on success
 */
function sendZipFile(string $tmpZip, string $finalFileName, bool $deleteAfter = true): bool {
    if (file_exists($tmpZip)) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        $safeAsciiName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $finalFileName);
        $encodedName = rawurlencode($finalFileName);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $safeAsciiName . '"; filename*=UTF-8\'\'' . $encodedName);
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($tmpZip);
        if ($deleteAfter) {
            unlink($tmpZip);
        }
        return true;
    } else {
        return false;
    }
}


/**
 * log to file
 * * @param string $message Message to log
 * @param string $logFile File to append the log to.
 */
function logToFile(string $message, string $logFile = '') {
    if ($logFile == '') {
        $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kbIndex.log.txt';
    }
    file_put_contents($logFile, $message, FILE_APPEND);
};


/**
 * Removes temporary ZIP files older than the specified threshold.
 * * @param int $seconds Minimum age of files to be removed in seconds. (86400 = 24 hours.)
 * @return void
 */
function cleanOldTempFiles(int $seconds = 86400): void {
    $tmpDir = sys_get_temp_dir();
    unlinkFilesOlderThan($seconds, $tmpDir . DIRECTORY_SEPARATOR . 'kbindex_*.zip');
    unlinkFilesOlderThan($seconds, $tmpDir . DIRECTORY_SEPARATOR . 'kbindex_*.zip.done');
}

/**
 * Actually remove the files
 * * @param int $seconds Minimum age of files to be removed in seconds. (86400 = 24 hours.)
 * * @param string $mask Path and mask (eg: '/tmp/kbindex_*.zip')
 * @return void
 */
function unlinkFilesOlderThan(int $seconds, string $mask) {
    $files = glob($mask);
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

    // +2kB of meaningful motto as a padding
    echo ": " . str_repeat("Don't waste time listening to bad music! ", 2050 / 41) . "\n\n";
    flush();

    while (true) {
        if (time() - $startTime > $timeout) break;

        clearstatcache(true, $tmpZip);
        $isDone = file_exists($marker);
        $currentSize = file_exists($tmpZip) ? filesize($tmpZip) : 0;

        // debug([$tmpZip, $finalFileName, $marker, $stats, $isDone, $currentSize, file_exists($tmpZip), filesize($tmpZip)]);


        // Estimate progress based on file size.
        // We cap it at 99% until the marker file actually exists.
        $progress = ($stats['totalWeight'] > 0) ? ($currentSize / $stats['totalWeight']) * 100 : 0;
        $progress = $isDone ? 100 : min(99, round($progress));

        // Inside your SSE loop (send this once at the start or with every update)
        echo "data: " . json_encode([
            'percent' => $progress,
            'status' => $isDone ? 'done' : 'progress',
            // 'fileName' => basename($tmpZip),
            // 'finalFileName' => $finalFileName,
            // 'stats' => [
            //     'totalFolders' => $stats['dirs'],
            //     'totalFiles' => $stats['files']
            // ],
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


/**
 * Handles the download of either a generated ZIP file or a single direct file.
 *
 * @param string $physicalPath The physical path of the current directory.
 * @param array $config The configuration array.
 * @return void
 */
function handleDownloadAction(string $physicalPath = '', array $config = []): void {
    // 1. Direct single file download
    if (!empty($_GET['file'])) {
        if (!empty($config) && empty($config['allowDownload'])) {
            header("HTTP/1.1 403 Forbidden");
            die("Download not allowed.");
        }

        $fileName = basename($_GET['file']);
        $allowedFiles = getFileList($physicalPath, $config);
        $filtered = filterAllowedFiles([$fileName], $allowedFiles, $physicalPath, $map);

        if (empty($filtered)) {
            header("HTTP/1.1 404 Not Found");
            die("File not found or not allowed.");
        }

        $fullPath = $physicalPath . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($fullPath)) {
            header("HTTP/1.1 404 Not Found");
            die("File not found.");
        }

        sendDirectFile($fullPath, $fileName);
        exit;
    }

    // 2. Temporary ZIP download
    $tmpZip = basename($_GET['tempFileName'] ?? '');
    if (!$tmpZip) {
        die("No fileName given.");
    }
    $finalFileName = basename($_GET['finalFileName'] ?? 'download_' . date('Ymd-His') . '.zip');

    $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tmpZip;

    if (file_exists($tmpZip)) {
        sendZipFile($tmpZip, $finalFileName);
        exit;
    } else {
        header("HTTP/1.1 404 Not Found");
        die("Archive file not found or expired.");
    }
}


function handlePostRequest(string $physicalPath, string $action, array $config): void {
    if ((isset($action) && ($action === 'zipAll'))) {
        // User clicked "Download all"
        handleZipRequest($physicalPath, [], true, $config, false);
    } elseif ((isset($action)) && ($action === 'zipSelected')) {
        // User clicked "Download selected"
        $selectedFiles = $_POST['selected'] ?? [];
        if (empty($selectedFiles)) {
            echo "data: " . json_encode(['type' => 'error', 'message' => 'Nie wybrano żadnych plików do pobrania.']) . "\n\n";
            exit;
        }

        // If exactly one file is selected and it is a regular file, download directly without ZIP
        if (count($selectedFiles) === 1) {
            $allowedFiles = getFileList($physicalPath, $config);
            $filtered = filterAllowedFiles($selectedFiles, $allowedFiles, $physicalPath, $map);
            if (!empty($filtered)) {
                $singleFile = reset($filtered);
                $fullPath = $physicalPath . DIRECTORY_SEPARATOR . $singleFile;
                if (is_file($fullPath)) {
                    if (!empty($config) && empty($config['allowDownload'])) {
                        header("HTTP/1.1 403 Forbidden");
                        die("Download not allowed.");
                    }
                    sendDirectFile($fullPath, $singleFile);
                    exit;
                }
            }
        }

        handleZipRequest($physicalPath, $selectedFiles, false, $config, false);
    }
}
