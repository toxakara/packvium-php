<?php
/**
 * The smallest useful call: some items, some boxes, one answer.
 *
 * Run it:
 *
 *     php examples/basic.php
 *
 * Three things are worth noticing in a handful of lines.
 *
 * `Dimensions::mm` and `Dimensions::inches` are both exact -- "4 in" is not converted to
 * a rounded number of millimetres, it is stored as an exact tick count, so an imperial
 * spec sheet and a metric container agree without a tolerance to tune (see units.php).
 *
 * `keepUpright` is a rule, not a hint. The mug will never be laid on its side, and if
 * that makes it not fit you are told which item failed and why, rather than getting a
 * plausible-looking arrangement that spills coffee.
 *
 * `costMinor` is what the box costs *you*, in minor currency units. The default
 * objective ignores it -- it opens as few containers as possible and packs them tightly.
 * Ranking by packaging cost, by carrier-billed weight or by actual money is one setting
 * away; that is what objectives.php is for.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Packer;

$result = (new Packer(PackingConfig::balanced()))->pack(
    [
        Item::create('book', Dimensions::mm('210', '140', '30'), '450 g', quantity: 4),
        Item::create('mug', Dimensions::inches('4', '4', '5'), '12 oz', quantity: 2, keepUpright: true),
    ],
    [
        Container::create('box-m', Dimensions::mm('400', '300', '250'), maxPayload: '20 kg', costMinor: 180),
        Container::create('box-l', Dimensions::mm('500', '400', '350'), maxPayload: '30 kg', costMinor: 250),
    ],
);

$placed = 0;
$chosen = [];
foreach ($result->containers as $container) {
    $placed += count($container->placements);
    $chosen[] = $container->container->id;
}

printf("status      %s\n", $result->status->value);
printf("containers  %s\n", json_encode($chosen));
printf("packed      %d of 6\n", $placed);
printf("unpacked    %d\n", count($result->unpacked));
printf("score       %s  <- lexicographic, exact integers, lower is better\n", json_encode($result->score));

echo "\nthe full result as a plain array is what serialization.php explores:\n";
$keys = array_keys($result->toArray());
sort($keys);
echo json_encode($keys), "\n";
