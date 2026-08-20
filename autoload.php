<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'Packvium\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) { require_once $path; }
});
// Namespaced functions cannot be autoloaded; composer loads this through autoload.files
// and a consumer without composer gets it here (Packvium\Commerce\quote and friends).
require_once __DIR__ . '/src/Commerce/functions.php';
