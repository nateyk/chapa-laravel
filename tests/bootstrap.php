<?php

declare(strict_types=1);

spl_autoload_register(static function (string $name): void {
    $prefix = 'Nateyk\\Chapa\\';
    if (!str_starts_with($name, $prefix)) {
        return;
    }
    $relative = substr($name, strlen($prefix));
    $filename = __DIR__.'/../src/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($filename)) {
        require $filename;
    }
});
