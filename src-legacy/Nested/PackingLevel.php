<?php
declare(strict_types=1);
namespace Packvium\Nested;
use Packvium\Config\PackingConfig;
use Packvium\Domain\Container;
final class PackingLevel{/**
 * @readonly
 * @var string
 */
public $name;
/**
 * @var list<Container>
 * @readonly
 */
public $containers;
/**
 * @readonly
 * @var \Packvium\Config\PackingConfig|null
 */
public $config;
/** @param list<Container> $containers */public function __construct(string $name, array $containers, ?PackingConfig $config=null)
{
    $this->name = $name;
    $this->containers = $containers;
    $this->config = $config;
}}
