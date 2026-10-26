<?php

declare(strict_types=1);

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on. Run: php -d phar.readonly=0 scripts/build-phar.php\n");
    exit(1);
}

$root = dirname(__DIR__);
$buildDir = $root . '/build';
$pharPath = $buildDir . '/fpm-doctor.phar';

if (!is_dir($buildDir) && !mkdir($buildDir, 0775, true) && !is_dir($buildDir)) {
    fwrite(STDERR, "Cannot create build dir.\n");
    exit(1);
}
if (is_file($pharPath)) {
    unlink($pharPath);
}

$phar = new Phar($pharPath, 0, 'fpm-doctor.phar');
$phar->startBuffering();

$phar->buildFromDirectory($root, '#^' . preg_quote($root, '#') . '/src/.*\.php$#');

$stub = <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('fpm-doctor.phar');
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'FpmDoctor\\', 10) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 10));
    $file = 'phar://fpm-doctor.phar/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});
exit((new FpmDoctor\Application())->run($argv));
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

chmod($pharPath, 0755);

printf("Built %s (%d files, %s)\n", $pharPath, count($phar), number_format(filesize($pharPath)) . ' bytes');
