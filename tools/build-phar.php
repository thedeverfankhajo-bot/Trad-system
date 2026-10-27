<?php

declare(strict_types=1);

/**
 * Build a distributable PocketMine-MP plugin PHAR.
 *
 * Only runtime files are packaged. Tests, CI, development dependencies,
 * repository metadata and documentation are intentionally excluded.
 */

if(PHP_SAPI !== "cli"){
    fwrite(STDERR, "Run this builder from the CLI.\n");
    exit(1);
}
if(!extension_loaded("phar")){
    fwrite(STDERR, "The PHP Phar extension is required.\n");
    exit(1);
}
if(ini_get("phar.readonly") === "1"){
    fwrite(STDERR, "Run with -d phar.readonly=0.\n");
    exit(1);
}

$root = dirname(__DIR__);
$manifest = $root . "/plugin.yml";
$source = $root . "/src";
$resources = $root . "/resources";
$outputDir = $root . "/build";
$output = $outputDir . "/TradeSystem.phar";

foreach([$manifest, $source, $resources] as $required){
    if(!file_exists($required)){
        fwrite(STDERR, "Required runtime path is missing: {$required}\n");
        exit(1);
    }
}

if(!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)){
    fwrite(STDERR, "Could not create build directory.\n");
    exit(1);
}
if(file_exists($output)){
    if(!unlink($output)){
        fwrite(STDERR, "Could not replace existing PHAR.\n");
        exit(1);
    }
}

try{
    $phar = new Phar($output);
    $phar->startBuffering();
    $phar->setStub("<?php __HALT_COMPILER();");
    $phar->addFile($manifest, "plugin.yml");

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
    );
    foreach($iterator as $file){
        if(!$file->isFile() || $file->isLink()){
            continue;
        }
        $absolute = $file->getPathname();
        $relative = substr($absolute, strlen($root) + 1);
        $phar->addFile($absolute, str_replace(DIRECTORY_SEPARATOR, "/", $relative));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS)
    );
    foreach($iterator as $file){
        if(!$file->isFile() || $file->isLink()){
            continue;
        }
        $absolute = $file->getPathname();
        $relative = substr($absolute, strlen($root) + 1);
        $phar->addFile($absolute, str_replace(DIRECTORY_SEPARATOR, "/", $relative));
    }
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->stopBuffering();
    unset($phar);

    $check = new Phar($output);
    foreach(["plugin.yml", "src/TradSystem/Main.php", "resources/config.yml", "resources/messages.yml"] as $entry){
        if(!$check->offsetExists($entry)){
            throw new RuntimeException("Required entry missing from PHAR: " . $entry);
        }
    }
    if($check->offsetExists("tests/run.php") || $check->offsetExists("README.md")){
        throw new RuntimeException("Development files must not be included in the runtime PHAR.");
    }

    $hash = hash_file("sha256", $output);
    printf("Built %s (%d bytes)\nSHA-256: %s\n", $output, filesize($output), $hash);
}catch(Throwable $e){
    @unlink($output);
    fwrite(STDERR, "PHAR build failed: " . $e->getMessage() . "\n");
    exit(1);
}
