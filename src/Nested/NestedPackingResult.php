<?php
declare(strict_types=1);
namespace Packvium\Nested;
use Packvium\Result\PackingResult;
final readonly class NestedPackingResult{/** @param list<PackingResult> $levels */public function __construct(public array $levels){}}
