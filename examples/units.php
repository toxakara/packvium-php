<?php
/**
 * Units: why there are no floats anywhere, and what you can type.
 *
 * Run it:
 *
 *     php examples/units.php
 *
 * Packing is arithmetic about physical space, and floating point is the wrong tool for
 * it: `0.1 + 0.2` is famously not `0.3`, and a box that "almost" fits either fits or
 * does not. So every length and weight in this library is an exact integer count of
 * ticks -- 1/16000 mm for length, 1/8 microgram for weight -- and nothing in the
 * request, the search or the result is ever a float.
 *
 * That choice is invisible until it saves you. This example shows where it does.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, Item};
use Packvium\Packer;
use Packvium\Unit\{Length, Weight};

// -----------------------------------------------------------------------------------
// What you can type. Integers, decimals, fractions and mixed fractions, in mm, cm, m,
// in and ft -- because a spec sheet says "12 3/8 in" and retyping that as 12.375 is a
// transcription step where mistakes live.
// -----------------------------------------------------------------------------------
foreach (['30', '30.5 mm', '3/16 in', '12 3/8 in', '2 ft', '1.5 m'] as $text) {
    $length = Length::parse($text);
    printf("%12s  ->  %12d ticks  =  %s mm\n", $text, $length->ticks, $length->decimal('mm'));
}

echo "\n";
foreach (['450 g', '12 oz', '1.5 kg', '2 3/4 lb'] as $text) {
    $weight = Weight::parse($text);
    printf("%12s  ->  %14d ticks  =  %s g\n", $text, $weight->ticks, $weight->decimal('g'));
}

// -----------------------------------------------------------------------------------
// Common fractional inches are exact, not rounded. 1/16000 mm was chosen so that every
// binary fraction of an inch down to 1/128 lands on a whole number of ticks -- which is
// what makes an imperial spec survive a conversion to millimetres and back.
// -----------------------------------------------------------------------------------
printf("\n1 inch is %d ticks, so 1/128 in is %d ticks exactly\n",
    Length::TICKS_PER_INCH, intdiv(Length::TICKS_PER_INCH, 128));
printf("128 x (1/128 in) == 1 in ?  %s\n",
    Length::parse('1/128 in')->ticks * 128 === Length::parse('1 in')->ticks ? 'true' : 'false');

// And the honest other half: a *third* of an inch is not a binary fraction, so it does
// not land on a whole tick. The library rounds it, deterministically and visibly, rather
// than carrying an error that only shows up as a box that "almost" fits.
printf("  3 x (1/3 in) == 1 in ?  %s -- 1/3 in is %d/3 ticks, which is not an integer\n",
    Length::parse('1/3 in')->ticks * 3 === Length::parse('1 in')->ticks ? 'true' : 'false',
    Length::TICKS_PER_INCH);

// -----------------------------------------------------------------------------------
// Where floats would actually bite.
// -----------------------------------------------------------------------------------
printf("\n0.1 + 0.2 == 0.3 in floats:  %s\n", 0.1 + 0.2 === 0.3 ? 'true' : 'false');
printf("the same three tenths as lengths:  %s\n",
    Length::parse('0.1 mm')->ticks + Length::parse('0.2 mm')->ticks === Length::parse('0.3 mm')->ticks
        ? 'true' : 'false');

// -----------------------------------------------------------------------------------
// The same exactness decides whether something fits. A 100mm cube into a 100mm cube is a
// fit, not a coin toss -- and one tick over is a refusal, with no tolerance to tune.
// -----------------------------------------------------------------------------------
$opening = Dimensions::mm('100', '100', '100');
$oneTickOver = new Dimensions(new Length($opening->length->ticks + 1), $opening->width, $opening->height);
printf("\nexactly 100mm fits:       %s\n", Dimensions::mm('100', '100', '100')->fitsInside($opening) ? 'true' : 'false');
printf("one tick over does not:   %s\n", $oneTickOver->fitsInside($opening) ? 'true' : 'false');

// -----------------------------------------------------------------------------------
// And it survives a round trip through JSON, because the wire format carries the decimal
// string rather than a float. What you typed is what the other language reads.
// -----------------------------------------------------------------------------------
printf("\non the wire: %s %s\n",
    json_encode(Length::parse('12 3/8 in')->toArray()),
    json_encode(Weight::parse('2 3/4 lb')->toArray('g')));

$result = (new Packer(PackingConfig::balanced()))->pack(
    [Item::create('shelf', Dimensions::inches('12 3/8', '9 1/2', '3/4'), '2 3/4 lb', quantity: 3)],
    [Container::create('carton', Dimensions::inches('13', '10', '4'), maxPayload: '20 lb')],
);
$packed = 0;
foreach ($result->containers as $container) {
    $packed += count($container->placements);
}
printf("packed %d shelves, %d left over\n", $packed, count($result->unpacked));
