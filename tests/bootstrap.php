<?php

/** @var $loader \Composer\Autoload\ClassLoader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$_ENV['TMP_DIR'] =  __DIR__ . '/tmp';
// cleanup
$clear = function ($dir) {
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        /* @var $file \SplFileInfo */
        if ($file->getFilename()[0] !== '.') {
            @unlink($file);
        }
    }
};
$clear(__DIR__ . '/tmp');
$clear(__DIR__ . '/tmp-aop');
