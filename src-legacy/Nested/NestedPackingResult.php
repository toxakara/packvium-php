<?php
declare(strict_types=1);
namespace Packvium\Nested;
use Packvium\Result\PackingResult;
final class NestedPackingResult{/**
 * @var list<PackingResult>
 * @readonly
 */
public $levels;
/** @param list<PackingResult> $levels */public function __construct(array $levels)
{
    $this->levels = $levels;
}}
