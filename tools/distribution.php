<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$archive = $root . '/build/distribution/form-guard-laravel.zip';
$allowed = ['LICENSE','README.md','README.fr.md','SECURITY.md','SECURITY.fr.md','CHANGELOG.md','CHANGELOG.fr.md','composer.json'];
foreach (['src','config','resources'] as $folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile() && !$entry->isLink()) {
            $allowed[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        }
    }
}
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open adapter distribution.');
}
$seen = [];
for ($i = 0;$i < $zip->numFiles;$i++) {
    $name = $zip->getNameIndex($i);
    if (str_ends_with($name, '/')) {
        continue;
    }if (!in_array($name, $allowed, true) || $zip->getFromIndex($i) !== file_get_contents($root . '/' . $name)) {
        throw new RuntimeException('Unexpected adapter distribution member: ' . $name);
    }$seen[] = $name;
}
$zip->close();
if (array_diff($allowed, $seen)) {
    throw new RuntimeException('Incomplete adapter distribution.');
}
$packages = [];
foreach ([[json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR),$archive]] as [$metadata,$path]) {
    $package = array_intersect_key($metadata, array_flip(['name','description','type','license','require','autoload','bin','extra']));
    $package['version'] = '1.0.0';
    $path = str_replace('\\', '/', realpath($path));
    $package['dist'] = ['type' => 'zip','url' => 'file://' . (str_starts_with($path, '/') ? '' : '/') . $path,'reference' => hash_file('sha256', $path),'shasum' => sha1_file($path)];
    $packages[] = $package;
}
$app = $root . '/build/consumer-' . bin2hex(random_bytes(5));
mkdir($app . '/bootstrap/cache', 0700, true);
file_put_contents($app . '/composer.json', json_encode(['name' => 'form-guard-tests/laravel-consumer','license' => 'proprietary','repositories' => [['type' => 'package','package' => $packages]],'require' => ['olivcode/form-guard-laravel' => '1.0.0','laravel/framework' => '^13.0'],'config' => ['allow-plugins' => false]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$composer = getenv('COMPOSER_BINARY') ?: $root . '/composer.phar';
if (!is_file($composer)) {
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        foreach (['composer.phar','composer'] as $name) {
            if (is_file($directory . '/' . $name)) {
                $composer = $directory . '/' . $name;
                break 2;
            }
        }
    }
}
if (!is_file($composer)) {
    throw new RuntimeException('Set COMPOSER_BINARY to the Composer PHAR.');
}
$run = static function (array $arguments) use ($app): void {
    $environment = getenv();
    unset($environment['COMPOSER']);
    $pipes = [];
    $log = $app . '/process-' . bin2hex(random_bytes(4)) . '.log';
    $process = proc_open([PHP_BINARY,...$arguments], [0 => ['pipe','r'],1 => ['file',$log,'a'],2 => ['redirect',1]], $pipes, $app, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run adapter consumer.');
    }fclose($pipes[0]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(file_get_contents($log));
    }
};
$run([$composer,'install','--no-interaction','--prefer-dist','--no-plugins','--no-scripts','--no-progress']);
$probe = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
$library=realpath(__DIR__ . '/vendor/olivcode/form-guard-laravel');
$loaded=realpath((new ReflectionClass(FormGuard\Laravel\FormManager::class))->getFileName());
if(!$library||!$loaded||!str_starts_with($loaded,$library . DIRECTORY_SEPARATOR)){throw new RuntimeException('Consumer did not load installed adapter.');}
$manifest=new Illuminate\Foundation\PackageManifest(new Illuminate\Filesystem\Filesystem(),__DIR__,__DIR__ . '/bootstrap/cache/packages.php');
if(!in_array(FormGuard\Laravel\FormGuardServiceProvider::class,$manifest->providers(),true)){throw new RuntimeException('Laravel auto-discovery metadata failed.');}
foreach(['/config/form-guard.php','/resources/views/fields.blade.php'] as $file){if(!is_file($library . $file)){throw new RuntimeException('Installed adapter is incomplete.');}}
$store=new FormGuard\Laravel\ClaimStore(__DIR__ . '/runtime/claims');
if(!$store->claim('installed',str_repeat('a',32),time()+7200)||$store->claim('installed',str_repeat('a',32),time()+7200)){throw new RuntimeException('Installed reservation failed.');}
if(!is_file(__DIR__ . '/vendor/olivcode/form-guard/assets/form-guard.js')){throw new RuntimeException('Core asset missing.');}
PHP;
file_put_contents($app . '/probe.php', $probe);
$run([$app . '/probe.php']);
$claim = glob($app . '/runtime/claims/*.claim')[0];
$hash = hash_file('sha256', $claim);
$run([$composer,'remove','olivcode/form-guard-laravel','--no-interaction','--no-plugins','--no-scripts','--no-progress']);
if (is_dir($app . '/vendor/olivcode/form-guard-laravel') || hash_file('sha256', $claim) !== $hash) {
    throw new RuntimeException('Adapter removal changed application reservations.');
}
file_put_contents($root . '/build/distribution-manifest.json', json_encode(['files' => $seen,'sha256' => hash_file('sha256', $archive),'installed_consumer' => true,'discovery' => true,'remove_preserves_application_data' => true], JSON_PRETTY_PRINT));
echo count($seen) . " adapter distribution files audited; fresh Composer installation, Laravel discovery, runtime reservation and safe removal passed.\n";
