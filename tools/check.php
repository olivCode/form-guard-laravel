<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$count = 0;
foreach (['src','config','tests','tools'] as $folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY,'-l',$file->getPathname()], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run PHP syntax check.');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($out . $err);
        } $count++;
    }
}
foreach (glob($root . '/*.md') as $path) {
    $base = str_replace('.fr.md', '.md', basename($path));
    $fr = substr($base, 0, -3) . '.fr.md';
    $text = file_get_contents($path);
    if (!is_file($root . '/' . $base) || !is_file($root . '/' . $fr) || !str_starts_with($text, '[English](' . $base . ') · [Français](' . $fr . ')')) {
        throw new RuntimeException('Missing bilingual document pair.');
    }
}
echo $count . " Laravel package PHP files linted; bilingual document pairs checked.\n";
