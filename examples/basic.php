<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container,Dimensions,Item};
use Packvium\Packer;
$result=(new Packer(PackingConfig::balanced()))->pack([Item::create('sku',Dimensions::mm('100','80','40'),'500 g',quantity:4)],[Container::create('box',Dimensions::mm('250','200','100'),maxPayload:'10 kg')]);
echo json_encode($result->toArray(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
