<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/**
 * A reference gave neither an explicit version nor an as-of time while more than one
 * version exists, so which version is "the" answer is undefined.
 */
final class AmbiguousReferenceException extends CatalogException {}
