<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** An as-of time was resolved before any catalog version had become effective. */
final class NoEffectiveVersionException extends CatalogException {}
