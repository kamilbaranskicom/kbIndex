<?php
// echo (file_exists('/tmp/kbindex_69570dc4d983e.zip') ? 'tak' : 'nie');
    //$cmd = "(zip -1 -r " . escapeshellarg($tmpZip) . " " . $zipTarget . " && touch " . escapeshellarg($doneMarker) . ") >> /tmp/mojlog.txt 2>&1 &";

    echo sys_get_temp_dir().'<br>';

    chdir(sys_get_temp_dir());
    $cmd = 'ls -l /tmp/';

    exec($cmd, $output, $returnCode);

    echo '<pre>';
print_r($output);

echo '<hr>';

print_r(file_exists('/tmp/mojlog.txt'));