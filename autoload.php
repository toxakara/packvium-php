<?php
declare(strict_types=1);

// One package, two trees. The canonical source under `src/` is typed PHP 8.2+; a
// published release also carries `src-legacy/`, generated from it by the pinned AST
// downgrade (docs/RUNTIME-COMPATIBILITY.md). PHP only parses the files it actually
// loads, so shipping both is safe -- what matters is that an old runtime never reaches
// the 8.2 tree. The generated tree is named for its role, not for a version: its floor
// moved from 7.4 to 7.3 in 0.1.3 and the name did not have to.
//
// Three shapes have to work, and this one rule covers all three:
//
//   - a canonical checkout or an install on 8.2+  -> `src/`
//   - a published package on 7.3-8.1              -> `src-legacy/`
//   - the standalone generated artifact, where `src/` is *already* downgraded and
//     no `src-legacy/` exists                      -> `src/`
//
// Below 8.2, prefer the downgraded tree when it is there; otherwise `src/` is either
// already downgraded or we are on a runtime that can read it.
$packviumLegacyTree = __DIR__ . '/src-legacy';
$packviumTree = (PHP_VERSION_ID < 80200 && is_dir($packviumLegacyTree))
    ? $packviumLegacyTree
    : __DIR__ . '/src';

// Prepended, not appended. Composer's own PSR-4 loader maps `Packvium\` to `src/`, and it
// is registered first; on 7.4 letting it answer would parse an 8.2 file and fatal. Going
// to the front of the queue keeps the PSR-4 mapping in composer.json -- which IDEs and
// static analysers read -- while making sure it is never the one consulted on old PHP.
spl_autoload_register(static function (string $class) use ($packviumTree): void {
    $prefix = 'Packvium\\';
    // `strncmp`, not `str_starts_with`: this file must parse and run on 7.3.
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $path = $packviumTree . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) { require_once $path; }
}, true, true);

// Namespaced functions cannot be autoloaded, so the file is required outright
// (Packvium\Commerce\quote and friends). `require_once` makes a second include -- through
// Composer's `files` and again by a consumer wiring this up by hand -- harmless.
require_once $packviumTree . '/Commerce/functions.php';
