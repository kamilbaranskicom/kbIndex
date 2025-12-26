<?php

/**
 * Handles download requests for zipping files.
 * @param string $physicalPath The physical directory path.
 * @param array $config Configuration array including ignore patterns.
 * @return void
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

    // Get legitimate files in current directory to compare against
    $allowedFiles = getFileList($physicalPath, $config['ignorePatterns']);
    $allowedNames = array_column($allowedFiles, 'name');

    if ($action === 'all') {
        foreach ($allowedNames as $name) {
            $toZip[] = $physicalPath . DIRECTORY_SEPARATOR . $name;
        }
    } else {
        foreach ($_POST['selected'] as $name) {
            // 1. Basic cleaning
            $name = basename($name); 
            
            // 2. Strict check: only allow if the file was found by our scanner
            if (in_array($name, $allowedNames)) {
                $toZip[] = $physicalPath . DIRECTORY_SEPARATOR . $name;
            }
        }
    }

    if (!empty($toZip)) {
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
    set_time_limit(900);

    $timestamp = date('Ymd_His');
    $safeBaseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
    $finalFileName = "{$safeBaseName}_download_{$timestamp}.zip";

    // tempnam creates a file, but 'zip' command expects to create it itself
    // so we get a unique name and then delete the empty file tempnam created
    $tmpZip = tempnam(sys_get_temp_dir(), 'kb_');
    unlink($tmpZip);
    $tmpZip .= '.zip';

    $oldDir = getcwd();
    if (!chdir($currentPath)) {
        throw new Exception("Cannot access directory: $currentPath");
    }

    // Prepare relative paths (just filenames in current folder)
    $relativeFiles = array_map(function ($path) {
        return escapeshellarg(basename($path));
    }, $files);

    // Build command with error redirection
    $cmd = "zip -1 -r " . escapeshellarg($tmpZip) . " " . implode(' ', $relativeFiles) . " 2>&1";

    exec($cmd, $output, $returnCode);
    chdir($oldDir);

    if ($returnCode !== 0) {
        // This will tell us EXACTLY why it failed (e.g., "zip: command not found")
        throw new Exception("ZIP Error (Code $returnCode): " . implode("\n", $output));
    }

    if (file_exists($tmpZip) && filesize($tmpZip) > 0) {
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

    throw new Exception("ZIP file is empty or was not created.");
}
