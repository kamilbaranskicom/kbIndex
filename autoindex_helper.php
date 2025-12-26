<?php
// OK ... poprzednie funkcje: parseAutoindexConf, humanSize, isIgnored, breadcrumbs, pickIcon, readDirectory, sortDirectoryData, renderTableRows
// --- poprzednie funkcje pozostają bez zmian: parseAutoindexConf, humanSize, isIgnored, breadcrumbs, pickIcon ---

function parseAutoindexConf($confFile){
    $iconMap = [];
    $mimeMap = [];
    $encodingMap = [];
    $altMap = [];
    $ignorePatterns = [];

    if (!file_exists($confFile)) return [$iconMap,$mimeMap,$encodingMap,$altMap,$ignorePatterns];

    foreach(file($confFile) as $line){
        $line = trim($line);
        if(!$line || str_starts_with($line,'#')) continue;

        // AddIcon
        if(preg_match('/^AddIcon\s+(\S+)\s+(.+)$/', $line, $m)){
            $icon = $m[1];
            $exts = preg_split('/\s+/',$m[2]);
            foreach($exts as $ext) $iconMap[$ext] = $icon;
        }
        // AddIconByType
        elseif(str_starts_with($line,'AddIconByType')){
            // "(SND,/icons/sound2.gif) audio/*"
            if(preg_match('/AddIconByType\s+(?:\([^\)]*\)\s*)?(\S+)/', $line, $m)){
                $mime = $m[1]; // audio/*
                if(preg_match('/\(([^,]+)?,?([^)]+)?\)/', $line, $iconm)){
                    $icon = trim($iconm[2] ?? $iconm[1] ?? '');
                    if($icon) $mimeMap[$mime] = $icon;
                }
            }
        }
        // IndexIgnore
        elseif(str_starts_with($line,'IndexIgnore')){
            $parts = preg_split('/\s+/',$line);
            array_shift($parts);
            $ignorePatterns = array_merge($ignorePatterns,$parts);
        }
    }
    return [$iconMap,$mimeMap,$encodingMap,$altMap,$ignorePatterns];
}

function humanSize($bytes){
    if($bytes<=0) return "-";
    $units=['B','KB','MB','GB','TB'];
    $i=floor(log($bytes,1024));
    return round($bytes/pow(1024,$i),2).' '.$units[$i];
}

function isIgnored($filename,$patterns){
    foreach($patterns as $p){
        $regex = '#^'.str_replace(['*','?'],['.*','.'],preg_quote($p,'#')).'$#i';
        if(preg_match($regex,$filename)) return true;
    }
    return false;
}

function breadcrumbs($dir){
    $parts = array_filter(explode('/', str_replace('\\','/',$dir)));
    $path='';
    $html='<a href="/">/</a>';
    foreach($parts as $p){
        $path.="/$p";
        $html.=" / <a href=\"$path\">$p</a>";
    }
    return $html;
}

function pickIcon($item,$iconMap,$mimeMap,$encodingMap){
    if($item['is_dir'] && isset($iconMap['^^DIRECTORY^^'])) return $iconMap['^^DIRECTORY^^'];

    $ext = strtolower(strrchr($item['name'],'.'));

    // AddIconByExtension
    if($ext && isset($iconMap[$ext])) return $iconMap[$ext];

    // AddIconByType (MIME)
    if(function_exists('mime_content_type')){
        $mime = mime_content_type($item['name']);
        if($mime){
            foreach($mimeMap as $pattern=>$icon){
                if(substr($pattern,-1)==='*'){
                    $prefix = rtrim($pattern,'*');
                    if(str_starts_with($mime,$prefix)) return $icon;
                } elseif($pattern===$mime){
                    return $icon;
                }
            }
        }
    }

    return "/pliki/icons/unknown.png";
}


/**
 * Wczytuje katalog i zwraca dane do tabeli
 * @param string $dir katalog do wczytania
 * @param array $ignorePatterns wzorce do IndexIgnore
 * @return array lista elementów ['name','is_dir','size','mtime','desc']
 */
function readDirectory($dir,$ignorePatterns=[]){
    $items = array_diff(scandir($dir), ['.','..']);
    $data = [];
    foreach($items as $f){
        if(isIgnored($f,$ignorePatterns)) continue;
        $path = $dir."/".$f;
        $is_dir = is_dir($path);
        $size = $is_dir?0:filesize($path);
        $mtime = filemtime($path);
        $desc='';
        $descFile = $path.'.description';
        if(is_file($descFile)) $desc = trim(file_get_contents($descFile));
        $data[] = ['name'=>$f,'is_dir'=>$is_dir,'size'=>$size,'mtime'=>$mtime,'desc'=>$desc];
    }
    return $data;
}

/**
 * Sortuje dane katalogu
 * @param array $data lista elementów jak wyżej
 * @param string $sort kolumna ('name','size','mtime')
 * @param string $order 'asc'|'desc'
 * @return array posortowane
 */
function sortDirectoryData($data,$sort='name',$order='asc'){
    usort($data,function($a,$b) use($sort,$order){
        if($a['is_dir'] && !$b['is_dir']) return -1;
        if(!$a['is_dir'] && $b['is_dir']) return 1;

        switch($sort){
            case 'size': $cmp = $a['size'] <=> $b['size']; break;
            case 'mtime': $cmp = $a['mtime'] <=> $b['mtime']; break;
            default: $cmp = strcasecmp($a['name'],$b['name']); break;
        }
        return $order==='asc'?$cmp:-$cmp;
    });
    return $data;
}

/**
 * Generuje HTML wierszy tabeli
 * @param array $data lista elementów
 * @param array $iconMap
 * @param array $mimeMap
 * @return string HTML wierszy <tr>
 */
function renderTableRows($data,$iconMap,$mimeMap) {
    $html='';
    foreach($data as $item){
        $html.='<tr data-isdir="'.($item['is_dir']?1:0).'">'."\n";
        $html.='<td class="checkbox"><input type="checkbox" name="selected[]" value="'.htmlspecialchars($item['name']).'"></td>'."\n";
        $html.='<td class="icon"><img src="'.pickIcon($item,$iconMap,$mimeMap,[]).'" alt=""></td>'."\n";
        $html.='<td><a href="'.rawurlencode($item['name']).($item['is_dir']?'/':'').'">'.htmlspecialchars($item['name']).'</a></td>'."\n";
        $html.='<td class="size" title="'.$item['size'].' bytes">'.($item['is_dir']?'-':humanSize($item['size'])).'</td>'."\n";
        $html.='<td>'.date("Y-m-d H:i",$item['mtime']).'</td>'."\n";
        $html.='<td>'.htmlspecialchars($item['desc']).'</td>'."\n";
        $html.='</tr>'."\n\n";
    }
    return $html;
}




/**
 * Renderuje całą stronę indexu katalogu
 * @param string $dir katalog do wyświetlenia
 * @param array $data wczytane i posortowane dane katalogu
 * @param array $iconMap mapowanie ikon
 * @param array $mimeMap mapowanie MIME na ikony
 */
function renderHTML($dir,$data,$iconMap,$mimeMap){
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Index of /<?php echo htmlspecialchars(basename($dir)); ?></title>
        <link rel="stylesheet" href="style.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body>

    <h1>Index of <?php echo breadcrumbs($dir); ?></h1>

    <form method="post">
    <p>
        <button type="submit" name="zip_all">📦 Pobierz wszystkie</button>
        <button type="submit" name="zip_selected">📁 Pobierz zaznaczone</button>
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

    <?php echo renderTableRows($data,$iconMap,$mimeMap); ?>

    </tbody>
    </table>
    </form>

    <footer><small>&copy; <?=date('Y'); ?> <a href="https://lukowastudio.com/">Łukowa Studio</a> &amp; <a href="https://kamilbaranski.com/">kamilbaranski.com</a></small></footer>

    <script src="include/js_sort.js"></script>
    </body>
    </html>
    <?php
}
