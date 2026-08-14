<?php
declare(strict_types=1);
namespace Packvium\Nested;
use Packvium\Config\PackingConfig;
use Packvium\Domain\Container;
final readonly class PackingLevel{/** @param list<Container> $containers */public function __construct(public string $name,public array $containers,public ?PackingConfig $config=null){}}
