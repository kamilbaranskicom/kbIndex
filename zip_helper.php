<?php
function createZip($dir,$files){
    $zipname = sys_get_temp_dir()."/download_".basename($dir)."_".time().".zip";
    $zip = new ZipArchive;
    $zip->open($zipname, ZipArchive::CREATE|ZipArchive::OVERWRITE);

    foreach($files as $item){
        $path = $dir.'/'.$item;
        if(is_dir($path)){
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $f){
                $zip->addFile($f, substr($f, strlen($dir)+1));
            }
        } elseif(is_file($path)){
            $zip->addFile($path, $item);
        }
    }
    $zip->close();

    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=\"".basename($zipname)."\"");
    readfile($zipname);
    unlink($zipname);
    exit;
}
