<?php

/**
 * Loads configuration settings from default, site-specific, and Apache autoindex files.
 * @param string $defaultConfig Path to the default configuration file.
 * @param string $siteConfig Path to the site-specific configuration file (optional).
 * @param string $autoindexConfig Path to the Apache autoindex.conf file (optional).
 * @return array The merged configuration array.
 */
function loadConfigs(string $defaultConfig, string $siteConfig, string $autoindexConfig): array {
    require_once $defaultConfig;
    if (file_exists($siteConfig)) {
        require_once $siteConfig;
    }

    // 1. defaults
    $config = $configDefaults;

    // 2. merge default config with apache mod autoindex.conf
    if (file_exists($autoindexConfig)) {
        $config = mergeConfigs($config, parseAutoindexConf($autoindexConfig));
    }

    // 3. overwrite values with config_site
    $config = mergeConfigs($config, $configSite ?? []);
    return $config;
};


/**
 * Merges system and local configurations with special handling for patterns and descriptions.
 * @param array $configA original configuration
 * @param array $configB newer configuration
 * @return array The resulting merged configuration.
 */
function mergeConfigs(array $configA, array $configB): array {
    $merged = array_replace_recursive($configA, $configB);

    $merged['ignorePatterns'] = array_unique(array_merge(
        $configA['ignorePatterns'] ?? [],
        $configB['ignorePatterns'] ?? []
    ));

    $merged['descriptions'] = array_merge(
        $configA['descriptions'] ?? [],
        $configB['descriptions'] ?? []
    );

    $merged['permissions'] = array_merge(
        $configA['permissions'] ?? [],
        $configB['permissions'] ?? []
    );

    return $merged;
}

/**
 * Parses Apache autoindex.conf to extract configuration rules.
 * * @param string $filePath Path to the .conf file
 * @return array Parsed configuration
 */
function parseAutoindexConf(string $filePath): array {
    $config = [
        'iconsByToken' => [],
        'iconsBySuffix' => [],
        'iconsByExtension' => [],
        'iconsByFilename' => [],
        'iconsByType' => [],
        'iconsByEncoding' => [],
        'defaultIcon' => '',
        'ignorePatterns' => [],
        'descriptions' => [],
    ];

    if (!file_exists($filePath)) return $config;

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;

        /**
         * Parses AddIcon directive which supports extensions, filenames, and special tokens.
         */
        if (preg_match('/^AddIcon\s+(?:\((?:[^,]*),([^\)]+)\)|([^\s]+))\s+(.+)/i', $line, $matches)) {
            $icon = trim($matches[1] ?: $matches[2]);
            $targets = preg_split('/\s+/', trim($matches[3]));

            foreach ($targets as $target) {
                if (empty($target)) continue;

                // Handle special Apache tokens
                if (str_starts_with($target, '^^') && str_ends_with($target, '^^')) {
                    $config['iconsByToken'][$target] = $icon;
                }
                // Handle parent directory
                elseif ($target === '..') {
                    $config['iconsByToken']['PARENT_DIR'] = $icon;
                }
                // Handle suffix rules (e.g., /core)
                elseif (str_starts_with($target, '/')) {
                    $config['iconsBySuffix'][ltrim($target, '/')] = $icon;
                }
                // Handle extensions (e.g., .conf)
                elseif (str_starts_with($target, '.')) {
                    $config['iconsByExtension'][$target] = $icon;
                }
                // Handle exact filenames (e.g., README)
                else {
                    $config['iconsByFilename'][$target] = $icon;
                }
            }
        }


        // Extract AddIconByType
        // Supports both: AddIconByType (SND,/icons/sound.gif) audio/*
        // and: AddIconByType /icons/binary.gif application/octet-stream
        if (preg_match('/^AddIconByType\s+(?:\((?:[^,]*),([^\)]+)\)|([^\s]+))\s+(.+)/i', $line, $matches)) {
            // $matches[1] is the icon from the (alt,icon) format
            // $matches[2] is the icon from the simple format
            $icon = trim($matches[1] ?: $matches[2]);
            // The rest of the line contains one or more MIME types
            $mimeTypes = preg_split('/\s+/', trim($matches[3]));
            foreach ($mimeTypes as $mime) {
                if ($icon && $mime) {
                    $config['iconsByType'][$mime] = $icon;
                }
            }
        }

        // Extract AddIconByEncoding
        // Supports both: AddIconByEncoding (GZ,/icons/compressed.gif) x-gzip
        // and: AddIconByEncoding /icons/compressed2.gif x-gzip
        if (preg_match('/^AddIconByEncoding\s+(?:\((?:[^,]*),([^\)]+)\)|([^\s]+))\s+(.+)/i', $line, $matches)) {
            // $matches[1] is the icon from the (alt,icon) format
            // $matches[2] is the icon from the simple format
            // $matches[3] is the encodings
            $icon = trim($matches[1] ?: $matches[2]);
            $encodings = preg_split('/\s+/', trim($matches[3]));
            foreach ($encodings as $enc) {
                $config['iconsByEncoding'][$enc] = $icon;
            }
        }

        // Extract DefaultIcon
        // Supports: DefaultIcon /icons/unknown.png
        if (preg_match('/^DefaultIcon\s+([^\s]+)/i', $line, $matches)) {
            $config['defaultIcon'] = $matches[1];
        }

        // Extract IndexIgnore patterns
        // # IndexIgnore is a set of filenames which directory indexing should ignore
        // # and not include in the listing.  Shell-style wildcarding is permitted.
        // Supports: IndexIgnore .??* *~ *# RCS CVS *,v *,t
        if (preg_match('/^IndexIgnore\s+(.+)/i', $line, $matches)) {
            // Split by whitespace to get individual patterns
            $patterns = preg_split('/\s+/', trim($matches[1]));
            foreach ($patterns as $pattern) {
                if ($pattern !== '') {
                    $config['ignorePatterns'][] = $pattern;
                }
            }
        }

        // Extract AddDescription
        // Supports: AddDescription "My File Description" filename.ext
        // Multiple filenames can be specified separated by spaces
        // e.g., AddDescription "Compressed File" file1.zip file2.zip
        if (preg_match('/^AddDescription\s+"([^"]+)"\s+(.+)/i', $line, $matches)) {
            $description = $matches[1];
            $targets = preg_split('/\s+/', trim($matches[2]));

            foreach ($targets as $target) {
                $config['descriptions'][$target] = $description;
            }
        }
    }
    return $config;
}

/**
 * Converts bytes to a human-readable format.
 * @param int $bytes Number of bytes
 * @return string Human-readable size
 */
function humanSize($bytes): string {
    if ($bytes <= 0) return "-";
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Checks if a filename should be ignored based on Apache IndexIgnore patterns.
 * * @param string $filename The name of the file/directory to check
 * @param array $ignorePatterns List of glob patterns from config
 * @return bool True if the file should be hidden
 */
function isIgnored(string $filename, array $ignorePatterns): bool {
    // Always ignore current and parent directory pointers if you want to handle them manually
    if ($filename === '.' || $filename === '..') {
        return true;
    }

    foreach ($ignorePatterns as $pattern) {
        // fnmatch is the PHP equivalent of shell-style wildcarding
        // FNM_CASEFOLD makes it case-insensitive (standard for Apache on most systems)
        if (fnmatch($pattern, $filename, FNM_CASEFOLD)) {
            return true;
        }
    }

    return false;
}

/**
 * Generates breadcrumb array for the current request URI.
 * @return array Array of breadcrumb items
 */
function getBreadcrumbs(): array {
    $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uriPath = rawurldecode($uriPath);

    $segments = array_values(array_filter(explode('/', $uriPath)));
    $breadcrumbs = [];
    $breadcrumbs[] = ['name' => '🏠', 'url' => '/'];

    $accumulatedSegments = [];

    // Get the actual physical location of the script to compare
    $scriptUriPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $scriptUriPath = trim($scriptUriPath, '/');

    foreach ($segments as $segment) {
        $accumulatedSegments[] = $segment;
        $currentPath = '/' . implode('/', $accumulatedSegments) . '/';

        // OPTIONAL: Skip the tool folder ONLY if it's the ONLY segment 
        // and we are listing a different directory. 
        // But for consistency, it's often better to just show it.

        $breadcrumbs[] = [
            'name' => $segment,
            'url'  => $currentPath
        ];
    }

    return $breadcrumbs;
}

/**
 * Generates HTML for breadcrumb navigation.
 * @param array $breadcrumbs Array of breadcrumb items
 * @return string HTML string
 */
function getBreadcrumbsHtml(array $breadcrumbs): string {
    $html = '<nav class="breadcrumb-container">';
    $html .= '<span class="breadcrumb-prefix">Index of: </span>';

    $lastIndex = count($breadcrumbs) - 1;

    foreach ($breadcrumbs as $i => $crumb) {
        if ($i > 0) {
            $html .= '<span class="separator"> / </span>';
        }
        if ($i === $lastIndex) {
            $html .= '<span class="crumb-current">' . htmlspecialchars($crumb['name']) . '</span>';
        } else {
            $html .= '<a class="crumb-link" href="' . htmlspecialchars($crumb['url']) . '"';
            $html .= ' title="Go to ' . htmlspecialchars($crumb['name']) . '">' . htmlspecialchars($crumb['name']) . '</a>';
        }
    }
    $html .= '</nav>';
    return $html;
}

/**
 * Resolves the appropriate icon path for a file based on Apache's autoindex logic.
 *
 * @param SplFileInfo $file
 * @param array $config Parsed Apache configuration
 * @param FileInfoAnalyzer $analyzer Metadata extractor
 * @return string Path to the icon file
 */
function resolveIcon(SplFileInfo $file, array $config, FileInfoAnalyzer $analyzer): string {
    $filename = $file->getFilename();
    $realPath = $file->getRealPath(); // Returns false for broken symlinks

    // 1. Handle Broken Symlinks
    // If it's a link but the target doesn't exist, we can't analyze content.
    if ($file->isLink() && !$realPath) {
        return $config['iconsByToken']['^^BROKEN^^']
            ?? $config['iconsByExtension']['.broken']
            ?? $config['defaultIcon'];
    }

    // 2. Directory Token
    if ($file->isDir()) {
        return $config['iconsByToken']['^^DIRECTORY^^'] ?? $config['defaultIcon'];
    }

    // Only proceed with deep analysis if the file exists on disk
    if ($realPath) {
        // 3. Encoding (High priority in Apache, e.g., x-gzip)
        $encoding = $analyzer->getEncoding($realPath);
        if ($encoding && isset($config['iconsByEncoding'][$encoding])) {
            return $config['iconsByEncoding'][$encoding];
        }

        // 4. MIME Type (e.g., image/png or audio/*)
        $mimeType = $analyzer->getMimeType($realPath);
        if ($mimeType && $mimeType !== 'application/octet-stream') {
            if (isset($config['iconsByType'][$mimeType])) {
                return $config['iconsByType'][$mimeType];
            }

            // Check for wildcard matches like 'image/*'
            $mimeCategory = explode('/', $mimeType)[0] . '/*';
            if (isset($config['iconsByType'][$mimeCategory])) {
                return $config['iconsByType'][$mimeCategory];
            }
        }
    }

    // 5. Exact Filename Match (e.g., README)
    if (isset($config['iconsByFilename'][$filename])) {
        return $config['iconsByFilename'][$filename];
    }

    // 6. Extension Match (e.g., .php, .jpg)
    $ext = '.' . strtolower($file->getExtension());
    if (isset($config['iconsByExtension'][$ext])) {
        return $config['iconsByExtension'][$ext];
    }

    // 7. Suffix Match
    if (!empty($config['iconsBySuffix'])) {
        foreach ($config['iconsBySuffix'] as $suffix => $icon) {
            if (str_ends_with($filename, $suffix)) return $icon;
        }
    }

    // Fallback to the default system icon
    return $config['defaultIcon'];
}


/** 
 * Helper class to analyze file info (MIME type, encoding)
 */
class FileInfoAnalyzer {
    private finfo $mimeTypeDetector;
    private finfo $encodingDetector;

    public function __construct() {
        $this->mimeTypeDetector = new finfo(FILEINFO_MIME_TYPE);
        $this->encodingDetector = new finfo(FILEINFO_MIME_ENCODING);
    }

    public function getMimeType(string $path): ?string {
        if (!$path || !file_exists($path)) return null; // Safety first
        return $this->mimeTypeDetector->file($path) ?: null;
    }

    public function getEncoding(string $path): ?string {
        if (!file_exists($path)) return null;
        $encoding = $this->encodingDetector->file($path);

        if (!$encoding || $encoding === 'binary') return null;

        // Map standard encodings to Apache's expected x- prefix
        return match ($encoding) {
            'gzip'     => 'x-gzip',
            'compress' => 'x-compress',
            default    => $encoding,
        };
    }
}


/**
 * Scans a directory and returns an array of file information objects.
 * @param string $physicalPath The physical path to scan.
 * @param array $config Configuration settings.
 * @return array Collection of file data.
 * @throws UnexpectedValueException If the directory is not accessible.
 */
function getFileList(string $physicalPath, array $config): array {
    $fileList = [];

    // Check if path is a directory and is readable
    if (!is_dir($physicalPath) || !is_readable($physicalPath)) {
        throw new UnexpectedValueException("Directory is not accessible: " . $physicalPath);
    }

    // 1. Parse local BBS/ION descriptions first
    $localDescriptions = parseLocalDescriptions($physicalPath);
    $config['descriptions'] = array_merge($config['descriptions'], $localDescriptions);

    // 2. Merge with priority: Local files overwrite config patterns
    // array_merge ensures exact filename matches from BBS are found 
    // in resolveDescription's Priority #1 check.

    $iterator = new DirectoryIterator($physicalPath);

    foreach ($iterator as $fileInfo) {
        $filename = $fileInfo->getFilename();

        // Use our earlier isIgnored() logic
        if (isIgnored($filename, $config['ignorePatterns']) && !$config['showHiddenFiles']) {
            continue;
        }


        // Check if the link is broken
        $isLink = $fileInfo->isLink();
        $exists = file_exists($fileInfo->getPathname());

        try {
            $fileList[] = [
                'info'      => clone $fileInfo,
                'name'      => $filename,
                'is_dir'    => $fileInfo->isDir(),
                'is_link'   => $isLink,
                'is_broken' => $isLink && !$exists,
                'size'      => ($fileInfo->isDir() || ($isLink && !$exists)) ? getDirectorySize($fileInfo->getRealPath()) : $fileInfo->getSize(),
                'mtime'     => ($isLink && !$exists) ? time() : $fileInfo->getMTime(),
                'extension' => strtolower($fileInfo->getExtension()),
                'description' => resolveDescription($fileInfo, $config['descriptions']),
            ];
        } catch (Exception $e) {
            // If something still fails (e.g. permission denied during getSize), 
            // add as a broken/inaccessible entry
            $fileList[] = [
                'info'      => clone $fileInfo,
                'name'      => $filename . ' [Inaccessible]',
                'is_dir'    => false,
                'is_link'   => $isLink,
                'is_broken' => true,
                'size'      => 0,
                'mtime'     => time(),
                'extension' => '',
                'description' => resolveDescription($fileInfo, $config['descriptions']),
            ];
        }
    }

    $fileList = resolveIcons($fileList, $config);
    return $fileList;
}

/**
 * Calculates the total size of a directory including all subdirectories and files.
 * Uses RecursiveIterator for maximum performance and memory efficiency.
 * 
 * TODO: Now it doesn't check if files are allowed or ignored; just raw size.
 * 
 * @param string $path Path to the directory.
 * @return int Total size in bytes.
 */
function getDirectorySize(string $path): int {
    $totalSize = 0;

    // Check if path is valid and readable
    if (!is_dir($path) || !is_readable($path)) {
        return 0;
    }

    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $totalSize += $file->getSize();
        }
    } catch (Exception $e) {
        // Fallback to 0 if something goes wrong with permissions
        return 0;
    }

    return $totalSize;
}

/**
 * Resolves icons for a list of files.
 * @param array $fileList List of files with metadata
 * @param array $config Configuration settings
 * @return array Updated list with icons
 */
function resolveIcons(array $fileList, array $config): array {
    $analyzer = new FileInfoAnalyzer();

    $fileList = array_map(function ($file) use ($config, $analyzer) {

        $file['icon'] = resolveIcon($file['info'], $config, $analyzer);
        return $file;
    }, $fileList);

    return $fileList;
}

/**
 * Resolves the description for a given file based on $config['descriptions'].
 * @param SplFileInfo $file
 * @param array $descriptions List of descriptions from config ['target' => 'description']
 * @return string The resolved description or an empty string if no match found.
 */
function resolveDescription(SplFileInfo $file, array $descriptions): string {
    if (empty($descriptions)) {
        return '';
    }

    // Check for broken links as per original logic
    if ($file->isLink() && !$file->getRealPath()) {
        return $descriptions['.broken'] ?? 'Broken link';
    }

    $filename = $file->getFilename();
    $extension = '.' . strtolower($file->getExtension());

    // 1. Priority: Exact filename match (e.g., README.txt or files.bbs entry)
    // Since we merged $localDescriptions last, if 'README.txt' exists 
    // in files.bbs, it will be picked here first.
    if (isset($descriptions[$filename])) {
        return $descriptions[$filename];
    }

    // 2. Priority: Exact extension match (e.g., .zip)
    if (isset($descriptions[$extension])) {
        return $descriptions[$extension];
    }

    // 3. Priority: Shell-style wildcard matching (e.g., *.txt, data_*)
    foreach ($descriptions as $pattern => $description) {
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            if (fnmatch($pattern, $filename, FNM_CASEFOLD)) {
                return $description;
            }
        }
    }

    return '';
}

/**
 * Parses local description files (BBS style files.bbs or descript.ion).
 *
 * @param string $directory Physical path to the directory being scanned.
 * @return array Associative array of [filename => description].
 */
function parseLocalDescriptions(string $directory): array {
    $localDesc = [];
    $targets = ['files.bbs', 'descript.ion', 'FILES.BBS', 'DESCRIPT.ION'];

    foreach ($targets as $target) {
        $path = $directory . DIRECTORY_SEPARATOR . $target;
        if (file_exists($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                // Standard: filename description (supports quotes for filenames with spaces)
                if (
                    preg_match('/^"([^"]+)"\s+(.+)$/', $line, $matches) ||
                    preg_match('/^(\S+)\s+(.+)$/', $line, $matches)
                ) {
                    $localDesc[$matches[1]] = $matches[2];
                }
            }
            // Standard BBS: once a description file is found, we stop looking for others
            break;
        }
    }
    return $localDesc;
}

/**
 * Sorts file list with directories always at the top.
 * @param array $fileList The file list array.
 * @param string $sortBy Column to sort by: 'name', 'size', 'mtime'.
 * @param string $order Direction: 'asc' or 'desc'.
 * @return array Sorted array.
 */
function sortFileList(array $fileList, string $sortBy = 'name', string $order = 'asc'): array {
    usort($fileList, function ($a, $b) use ($sortBy, $order) {
        // 1. Always keep directories at the top (Folders First)
        if ($a['is_dir'] !== $b['is_dir']) {
            return $b['is_dir'] <=> $a['is_dir'];
        }

        // 2. Determine comparison logic based on the column
        switch ($sortBy) {
            case 'size':
                $cmp = $a['size'] <=> $b['size'];
                break;
            case 'mtime':
                $cmp = $a['mtime'] <=> $b['mtime'];
                break;
            case 'name':
            default:
                // Natural sort handles numbers in strings better (e.g. 2.jpg before 10.jpg)
                $cmp = strnatcasecmp($a['name'], $b['name']);
                break;
        }

        // 3. Apply order
        return ($order === 'desc') ? -$cmp : $cmp;
    });

    return $fileList;
}

/**
 * Renders table rows for the file listing.
 * @param array $files List of files with metadata
 * @param array $config Configuration settings
 * @return string HTML table rows
 */
function renderTableRows($fileList): string {
    $html = '';
    foreach ($fileList as $file) {
        $html .= '<tr data-isdir="' . ($file['is_dir'] ? 1 : 0) . '">' . "\n";
        $html .= ' <td class="checkbox"><label><input type="checkbox" name="selected[]" value="' . htmlspecialchars($file['name']) . '"></label></td>' . "\n";
        $html .= ' <td class="icon"><img src="' . $file['icon'] . '" alt=""></td>' . "\n";
        $html .= ' <td><a href="' . rawurlencode($file['name']) . ($file['is_dir'] ? '/' : '') . '">' . htmlspecialchars($file['name']) . '</a></td>' . "\n";
        $html .= ' <td data-value="' . (isset(pathinfo($file['name'])['extension']) ? pathinfo($file['name'])['extension'] : '') . '"></td>' . "\n";
        // [old]
        // $html .= ' <td data-value="' . $file['size'] . '" class="size" title="' . $file['size'] . ' bytes">' . ($file['is_dir'] ? '-' : humanSize($file['size'])) . '</td>' . "\n";
        $html .= ' <td data-value="' . $file['size'] . '" class="size" title="' . $file['size'] . ' bytes">' . humanSize($file['size']) . '</td>' . "\n";
        $html .= ' <td data-value="' . $file['mtime'] . '">' . date("Y-m-d H:i", $file['mtime']) . '</td>' . "\n";
        $html .= ' <td>' . htmlspecialchars($file['description']) . '</td>' . "\n";
        $html .= '</tr>' . "\n\n";
    }
    return $html;
}



/**
 * Renders the complete HTML page for the file listing.
 * @param string $path Current directory path
 * @param array $fileList List of files with metadata
 * @param array $config Configuration settings
 * @param array $breadcrumbs Breadcrumb navigation data
 */
function renderHTML($path, $fileList, $config, $breadcrumbs, $sort = 'name', $order = 'asc') {
?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="utf-8">
        <title>Index of <?php echo htmlspecialchars($path);
                        echo htmlspecialchars($config['titleSuffix']); ?></title>
        <link rel="stylesheet" href="<?= KB_INDEX_URI; ?>kbIndex.css">
        <?php if (file_exists('kbIndex_site.css')) { ?>
            <link rel="stylesheet" href="<?= KB_INDEX_URI; ?>kbIndex_site.css"><?php }; ?>
        <script src="<?= KB_INDEX_URI; ?>kbIndex.js" defer></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
        <meta name="robots" content="noindex, nofollow">
    </head>

    <body>

        <h1><?php echo getBreadcrumbsHtml($breadcrumbs); ?></h1>

        <form id="kbIndexForm" action="?" method="post">
            <p class="download-buttons">
                <button type="submit" name="action" value="zipAll" id="zipAll">📦 Download all</button>
                <button type="submit" name="action" value="zipSelected" id="zipSelected">📁 Download selected</button>
                <?php if (isActionAllowed('allowDelete', $path, $config)) { ?>
                    <button type="button" name="action" value="delete" id="deleteSelected" class="hidden btn-danger">🗑️ Delete selected</button>
                <?php }; ?>
                <span id="selectedMessage"></span>
            </p>

            <table class="file-table">
                <thead>
                    <tr>
                        <th class="checkbox"><label><input type="checkbox" id="selectAll"></label></th>
                        <th></th>
                        <?php
                        echo renderOneTH('name', 'Name', $sort, $order);
                        echo renderOneTH('ext', '.ext', $sort, $order);
                        echo renderOneTH('size', 'Size', $sort, $order);
                        echo renderOneTH('mtime', 'Last modified', $sort, $order);
                        echo renderOneTH('description', 'Description', $sort, $order);
                        ?>
                    </tr>
                </thead>
                <tbody>

                    <?php echo renderTableRows($fileList); ?>

                </tbody>
            </table>
        </form>

        <footer>
            <small>
                <?php

                $counts = array_reduce($fileList, function ($carry, $item) {
                    // Treat 'is_dir' as boolean
                    if (!empty($item['is_dir'])) {
                        $carry['dirs']++;
                    } else {
                        $carry['files']++;
                    }
                    return $carry;
                }, ['dirs' => 0, 'files' => 0]);

                $dirCount  = $counts['dirs'] ?? 0; // true counts as 1
                $fileCount = $counts['files'] ?? 0; // false counts as 0

                echo '&copy; ' . date('Y') . ' ' . $config['footerUser'] . ' &bullet; ';
                echo 'Total ' . count($fileList) . ' items ';
                echo '(directories: ' . $dirCount . ', ';
                echo 'files: ' . $fileCount . '), ';
                echo 'size: ' . humanSize(array_sum(array_column($fileList, 'size'))) . '. ';
                if ($config['displayNewestItem']) {
                    $newest = array_reduce($fileList, function ($carry, $item) {
                        return ($carry === null || $item['mtime'] > $carry['mtime']) ? $item : $carry;
                    });

                    echo 'Newest item: ' . $newest['name'] . ' (' . date("Y-m-d H:i", $newest['mtime']) . ').';
                }
                ?>
            </small>
        </footer>

        <div id="status-box" class="hidden">
            <div id="status-content">
                <h2>Preparing your download...</h2>
                <div id="status-message">Initializing...</div>
                <div class="progress-container">
                    <div id="status-progress" class="progress-bar-fill" style="width: 0%;"></div>
                </div>
                <div id="status-details"></div>
            </div>
        </div>

    </body>

    </html>
<?php
}

/**
 * Renders a sortable table header cell.
 * @param string $id Column identifier
 * @param string $longName Display name
 * @param string $sort Current sort column
 * @param string $order Current sort order
 * @return string HTML for the table header cell
 */
function renderOneTH($id, $longName, $sort, $order) {
    $html = '<th class="sortable';
    if ($sort == $id) {
        $html .= ' ' . $order;
    }
    $html .= '" data-sort="' . $id . '" data-order="asc" onclick="sortTable(\'' . $id . '\')"';
    $html .= '>' . $longName . '</th>' . "\n";
    return $html;
}

/**
 * Debugging helper to print variable contents.
 * @param mixed $var Variable to debug
 */
function debug($var) {
    echo '<hr><pre>';
    //var_dump($var);
    print_r($var);
    echo '</pre></hr>';
}

/**
 * Calculates total weight and counts for a provided list of file names.
 * * @param array $fileNames List of names from the POST/GET request.
 * @param string $currentDir The directory context.
 * @return array Stats including total size, file count, and folder count.
 */
function calculateProcessingStats(array $fileNames, string $currentDir): array {
    $stats = ['size' => 0, 'files' => 0, 'dirs' => 0, 'validPaths' => []];

    foreach ($fileNames as $name) {
        $fullPath = $currentDir . DIRECTORY_SEPARATOR . $name;

        // Security check: ensure path is within allowed bounds (your existing logic)
        if (!isPathAllowed($fullPath)) continue;

        if (is_dir($fullPath)) {
            $stats['dirs']++;
            $stats['size'] += getDirectorySize($fullPath);
        } else if (file_exists($fullPath)) {
            $stats['files']++;
            $stats['size'] += filesize($fullPath);
        }
        $stats['validPaths'][] = $fullPath;
    }

    return $stats;
}

/**
 * Checks if a given path is allowed based on security constraints.
 * @param string $path The full path to check.
 * @return bool True if the path is allowed, false otherwise.
 */
function isPathAllowed(string $path): bool {
    // Implement your security checks here
    // For example, ensure the path is within a specific base directory
    $baseDir = realpath(__DIR__); // Adjust as needed
    $realPath = realpath($path);

    return $realPath !== false && str_starts_with($realPath, $baseDir);
}

function debug2mime($mime = 'cokolwiek/cokolwiek') {
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile('kbIndex.js');
    die();
}


/**
 * Checks if a specific action is allowed for a given path based on the configuration.
 *
 * @param string $action The action to check (e.g., 'allowDelete').
 * @param string $currentRelativePath The relative path to the directory being checked.
 * @param array $config The configuration array containing permissions.
 * @return bool True if the action is allowed, false otherwise.
 */
function isActionAllowed($action, $currentRelativePath, $config) {
    // Normalizujemy ścieżkę (zawsze zaczynamy od /)
    $path = '/' . ltrim($currentRelativePath, '/');
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';

    $allowed = false;

    // Szukamy w configu
    foreach ($config['permissions'] as $prefix => $perms) {
        $prefix = rtrim($prefix, '/');
        if ($prefix === '') $prefix = '/';

        // Jeśli ścieżka zaczyna się od prefixu z configa
        if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
            if (isset($perms[$action])) {
                $allowed = $perms[$action];
            }
        }
    }

    return $allowed;
}

function handleDeleteRequest($physicalPath, $relativeRequestPath, $config) {
    $filesToDelete = $_POST['selected'] ?? [];

    // 1. Sprawdzenie uprawnień dla aktualnego folderu
    if (!isActionAllowed('allowDelete', $relativeRequestPath, $config)) {
        logActivity("NIEAUTORYZOWANA PRÓBA USUNIĘCIA w: $relativeRequestPath", $config);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Brak uprawnień do usuwania w tym miejscu.']);
        exit;
    }

    $allowedFiles = getFileList($physicalPath, $config);
    // check if we have any files from ignorePatterns -> we can't delete the file as it might be .htaccess or kbIndexConfigLocal.
    $filesToDelete = filterAllowedFiles($filesToDelete, $allowedFiles, $physicalPath, $map);

    $deletedCount = 0;
    $errors = [];

    foreach ($filesToDelete as $file) {
        // 2. Bezpieczeństwo: basename() chroni przed atakami typu ../../../
        $safeFile = basename($file);
        $fullPath = $physicalPath . DIRECTORY_SEPARATOR . $safeFile;

        if (file_exists($fullPath)) {
            // 3. Wywołanie nowej funkcji rekurencyjnej
            if (deleteRecursive($fullPath)) {
                $deletedCount++;
                logActivity("USUNIĘTO: $relativeRequestPath/$safeFile", $config);
            } else {
                $errors[] = "Nie udało się usunąć: " . $safeFile;
            }
        }
    }

    header('Content-Type: application/json');
    if ($deletedCount > 0 && empty($errors)) {
        echo json_encode(['status' => 'success', 'deleted' => $deletedCount]);
    } else {
        echo json_encode([
            'status' => count($errors) > 0 ? 'partial' : 'success',
            'deleted' => $deletedCount,
            'message' => implode(', ', $errors)
        ]);
    }
    exit;
}

function deleteRecursive($path) {
    if (!file_exists($path)) {
        return true;
    }

    if (!is_dir($path)) {
        return unlink($path);
    }

    foreach (scandir($path) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteRecursive($path . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($path);
}

/**
 * Loguje zdarzenia do pliku activity.log
 */
function logActivity($message, $config) {
    if (!$config['logActivity']) {
        return;
    }
    $timestamp = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $logEntry = "[$timestamp] [IP: $ip] $message" . PHP_EOL;

    // Używamy FILE_APPEND, żeby nie nadpisywać pliku
    file_put_contents($config['logFile'] ?? __DIR__ . '/activity.log', $logEntry, FILE_APPEND);
}
