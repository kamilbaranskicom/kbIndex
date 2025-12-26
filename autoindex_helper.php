<?php
// OK ... poprzednie funkcje: parseAutoindexConf, humanSize, isIgnored, breadcrumbs, pickIcon, readDirectory, sortDirectoryData, renderTableRows
// --- poprzednie funkcje pozostają bez zmian: parseAutoindexConf, humanSize, isIgnored, breadcrumbs, pickIcon ---



/**
 * Parses Apache autoindex.conf to extract configuration rules.
 * * @param string $filePath Path to the .conf file
 * @return array Parsed configuration
 */
function parseAutoindexConf(string $filePath): array {
    $config = [
        'icons' => [],           // Extension based
        'iconsByType' => [],     // MIME type based
        'iconsByEncoding' => [], // Encoding based
        'defaultIcon' => '',
        'ignorePatterns' => []
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
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
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

/** [old, tylko do porównania]
 * Generates breadcrumb navigation HTML for the current directory.
 * @param string $dir Current directory path
 * @return string HTML breadcrumb navigation
 */
function breadcrumbs($dir): string {
    $parts = array_filter(explode('/', str_replace('\\', '/', $dir)));
    $path = '';
    $html = '<a href="/">/</a>';
    foreach ($parts as $p) {
        $path .= "/$p";
        $html .= " / <a href=\"$path\">$p</a>";
    }
    return $html;
}

/**
 * Generates breadcrumbs based on the request URI.
 *
 * @return array Array of [name, path] pairs.
 */
function getBreadcrumbs(): array {
    $uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uriPath = rawurldecode($uriPath);

    // Clean path and split into segments
    $segments = array_values(array_filter(explode('/', $uriPath)));

    $breadcrumbs = [];
    $breadcrumbs[] = [
        'name' => '🏠',
        'url' => '/'
    ];

    $accumulatedSegments = [];
    foreach ($segments as $segment) {
        // Skip the tool's directory from the breadcrumb list
        if ($segment === 'kbIndex') continue;

        $accumulatedSegments[] = $segment;

        // Re-encode each segment for the URL to keep it valid
        $safeUrl = '/' . implode('/', array_map('rawurlencode', $accumulatedSegments)) . '/';

        $breadcrumbs[] = [
            'name' => $segment,
            'url'  => $safeUrl
        ];
    }

    debug($breadcrumbs);

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
 * Resolves the correct icon for a given file based on Apache configuration.
 * Priority: 1. Tokens, 2. Encoding, 3. MIME Type, 4. Filename, 5. Extension, 6. Suffix
 *
 * @param SplFileInfo $file
 * @param array $config Parsed apache configuration
 * @return string Path to the icon
 */
function resolveIcon(SplFileInfo $file, array $config, FileInfoAnalyzer $analyzer): string {
    $filename = $file->getFilename();
    $realPath = $file->getRealPath();

    // 1. Directory Token
    if ($file->isDir()) {
        return $config['iconsByToken']['^^DIRECTORY^^'] ?? $config['defaultIcon'];
    }

    // 2. Encoding (High priority in Apache)
    $encoding = $analyzer->getEncoding($realPath);
    if ($encoding && isset($config['iconsByEncoding'][$encoding])) {
        return $config['iconsByEncoding'][$encoding];
    }

    // 3. MIME Type
    $mimeType = $analyzer->getMimeType($realPath);
    if ($mimeType) {
        if (isset($config['iconsByType'][$mimeType])) {
            return $config['iconsByType'][$mimeType];
        }

        $mimeCategory = explode('/', $mimeType)[0] . '/*';
        if (isset($config['iconsByType'][$mimeCategory])) {
            return $config['iconsByType'][$mimeCategory];
        }
    }

    // 4. Filename
    if (isset($config['iconsByFilename'][$filename])) {
        return $config['iconsByFilename'][$filename];
    }

    // 5. Extension
    $ext = '.' . $file->getExtension();
    if (isset($config['iconsByExtension'][$ext])) {
        return $config['iconsByExtension'][$ext];
    }

    // 6. Suffix
    foreach ($config['iconsBySuffix'] as $suffix => $icon) {
        if (str_ends_with($filename, $suffix)) return $icon;
    }

    return $config['defaultIcon'];
}

/**
 * Helper to get MIME encoding (e.g., gzip)
 */
function getFileEncoding(string $path): ?string {
    if (!file_exists($path)) return null;
    $finfo = finfo_open(FILEINFO_MIME_ENCODING);
    $encoding = finfo_file($finfo, $path);
    finfo_close($finfo);

    // Apache often uses 'x-gzip' instead of just 'gzip'
    if ($encoding === 'gzip') return 'x-gzip';
    if ($encoding === 'compress') return 'x-compress';

    return ($encoding === 'binary') ? null : $encoding;
}

/**
 * Helper to get MIME type (e.g., image/png)
 */
function getFileMimeType(string $path): ?string {
    if (!file_exists($path)) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    return $mime;
}

/**
 * Handles file metadata extraction using modern PHP finfo objects.
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

/** [NIEPOTRZEBNE, tylko do porównania]
 * Reads the contents of a directory and gathers metadata.
 * @param string $dir Directory path
 * @param array $config Parsed apache configuration
 * @return array List of files with metadata
 */
function readDirectory($dir, $config): array {
    $items = array_diff(scandir($dir), ['.', '..']);
    $data = [];
    foreach ($items as $f) {
        if (isIgnored($f, $config['ignorePatterns'])) continue;
        $path = $dir . "/" . $f;
        $is_dir = is_dir($path);
        $size = $is_dir ? 0 : filesize($path);
        $mtime = filemtime($path);
        $desc = '';
        $descFile = $path . '.description';
        if (is_file($descFile)) $desc = trim(file_get_contents($descFile));
        $data[] = ['name' => $f, 'is_dir' => $is_dir, 'size' => $size, 'mtime' => $mtime, 'desc' => $desc];
    }
    return $data;
}

/**
 * Scans a directory and returns an array of file information objects.
 * * @param string $path The physical path to scan.
 * @param array $ignorePatterns Patterns from Apache's IndexIgnore.
 * @return array Collection of file data.
 * @throws UnexpectedValueException If the directory is not accessible.
 */
function getFileList(string $path, array $ignorePatterns, bool $showHidden = false): array {
    $fileList = [];

    // Check if path is a directory and is readable
    if (!is_dir($path) || !is_readable($path)) {
        throw new UnexpectedValueException("Directory is not accessible: " . $path);
    }

    $iterator = new DirectoryIterator($path);

    foreach ($iterator as $fileInfo) {
        $filename = $fileInfo->getFilename();

        // Use our earlier isIgnored() logic
        if (isIgnored($filename, $ignorePatterns) && !$showHidden) {
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
                'size'      => ($fileInfo->isDir() || ($isLink && !$exists)) ? 0 : $fileInfo->getSize(),
                'mtime'     => ($isLink && !$exists) ? time() : $fileInfo->getMTime(),
                'extension' => strtolower($fileInfo->getExtension())
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
                'extension' => ''
            ];
        }
    }
    return $fileList;
}

/**
 * Resolves icons and descriptions for a list of files.
 * @param array $fileList List of files with metadata
 * @param array $config Configuration settings
 * @return array Updated list with icons and descriptions
 */
function resolveIconsAndDescriptions(array $fileList, array $config): array {
    $analyzer = new FileInfoAnalyzer();
    $fileList = array_map(function ($file) use ($config, $analyzer) {
        $file['icon'] = resolveIcon($file['info'], $config, $analyzer);
        // Here we can also add $file['description'] later
        $file['description'] = '';
        return $file;
    }, $fileList);
    return $fileList;
}

/**
 * Sorts file list with directories always at the top.
 * * @param array $files The file list array.
 * @param string $sort Column to sort by: 'name', 'size', 'mtime'.
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
function renderTableRows($fileList, $config): string {
    $html = '';
    foreach ($fileList as $file) {
        $html .= '<tr data-isdir="' . ($file['is_dir'] ? 1 : 0) . '">' . "\n";
        $html .= '<td class="checkbox"><input type="checkbox" name="selected[]" value="' . htmlspecialchars($file['name']) . '"></td>' . "\n";
        $html .= '<td class="icon"><img src="' . $file['icon'] . '" alt=""></td>' . "\n";
        $html .= '<td><a href="' . rawurlencode($file['name']) . ($file['is_dir'] ? '/' : '') . '">' . htmlspecialchars($file['name']) . '</a></td>' . "\n";
        $html .= '<td class="size" title="' . $file['size'] . ' bytes">' . ($file['is_dir'] ? '-' : humanSize($file['size'])) . '</td>' . "\n";
        $html .= '<td>' . date("Y-m-d H:i", $file['mtime']) . '</td>' . "\n";
        $html .= '<td>' . htmlspecialchars($file['description']) . '</td>' . "\n";
        $html .= '</tr>' . "\n\n";
    }
    return $html;
}



/**
 * Renders the complete HTML page for the directory listing.
 * @param string $dir Current directory path
 * @param array $files List of files with metadata
 * @param array $config Configuration settings
 */
function renderHTML($dir, $fileList, $config, $breadcrumbs) {
?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="utf-8">
        <title>Index of /<?php echo htmlspecialchars(basename($dir)); ?></title>
        <link rel="stylesheet" href="<?= KB_INDEX_URI; ?>kbIndex.css">
        <script src="<?= KB_INDEX_URI; ?>kbIndex.js"></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
        <meta name="robots" content="noindex, nofollow">
    </head>

    <body>

        <h1><?php echo getBreadcrumbsHtml($breadcrumbs); ?></h1>

        <form method="post">
            <p>
                <button type="submit" name="zip_all">📦 Download all</button>
                <button type="submit" name="zip_selected">📁 Download selected</button>
            </p>

            <table class="file-table">
                <thead>
                    <tr>
                        <th></th>
                        <th></th>
                        <th class="sortable" data-sort="name" data-order="asc">Name</th>
                        <th class="sortable" data-sort="size" data-order="asc">Size</th>
                        <th class="sortable" data-sort="mtime" data-order="asc">Last modified</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>

                    <?php echo renderTableRows($fileList, $config); ?>

                </tbody>
            </table>
        </form>

        <footer><small>&copy; <?= date('Y'); ?> <a href="https://lukowastudio.com/">Łukowa Studio</a> &amp; <a href="https://kamilbaranski.com/">kamilbaranski.com</a></small></footer>

        <script src="include/js_sort.js"></script>
    </body>

    </html>
<?php
}
