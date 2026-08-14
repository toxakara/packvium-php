<?php
declare(strict_types=1);

namespace Packvium\Tests;

use InvalidArgumentException;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Container;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Item;
use Packvium\Domain\Obstacle;
use Packvium\Domain\PackedContainer;
use Packvium\Domain\PackingRequest;
use Packvium\Domain\Placement;
use Packvium\Domain\Point;
use Packvium\Domain\RateTable;
use Packvium\Domain\Rotation;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;

/**
 * Domain invariants enforced at construction.
 *
 * An impossible item or container must be refused where it is built, not carried into
 * a solver to fail somewhere unrecognisable.
 */
final class DomainTest extends TestCase
{
    private static function placement(\Packvium\Domain\ItemInstance $instance, int $x = 0): Placement
    {
        $dims = $instance->item->dimensions;
        return new Placement($instance, new Point($x, 0, 0), Rotation::LWH, $dims, new Point($x, 0, 0), $dims);
    }

    // ------------------------------------------------------------------------ item

    public static function testAnItemNeedsAnId(): void
    {
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Item::create('', Dimensions::mm(1, 1, 1)));
    }

    public static function testQuantityMustBePositive(): void
    {
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Item::create('a', Dimensions::mm(1, 1, 1), quantity: 0));
    }

    public static function testSupportRatioIsAProportion(): void
    {
        foreach ([-0.1, 1.1] as $ratio) {
            self::assertThrows(InvalidArgumentException::class,
                static fn() => Item::create('a', Dimensions::mm(1, 1, 1), minimumSupportRatio: $ratio));
        }
    }

    public static function testStopIndexIsAbsentByDefault(): void
    {
        self::assertSame(null, Item::create('a', Dimensions::mm(1, 1, 1))->stopIndex);
    }

    public static function testStopIndexMustBeNonNegative(): void
    {
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Item::create('a', Dimensions::mm(1, 1, 1), stopIndex: -1));
    }

    public static function testAZeroStopIndexIsAccepted(): void
    {
        self::assertSame(0, Item::create('a', Dimensions::mm(1, 1, 1), stopIndex: 0)->stopIndex);
    }

    public static function testKeepUprightNarrowsTheAllowedRotations(): void
    {
        $item = Item::create('a', Dimensions::mm(1, 2, 3), keepUpright: true);
        self::assertSame(Rotation::upright(), $item->allowedRotations);
    }

    public static function testKeepUprightConflictingWithTheRotationListIsRefused(): void
    {
        // Silently allowing zero orientations would make the item unplaceable for a
        // reason no caller could see in the result.
        self::assertThrows(InvalidArgumentException::class, static fn() => Item::create(
            'a', Dimensions::mm(1, 2, 3), keepUpright: true, allowedRotations: [Rotation::LHW],
        ));
    }

    public static function testWeightsAreParsedFromEveryAcceptedNotation(): void
    {
        self::assertSame(Weight::of(1, 'kg')->ticks,
            Item::create('a', Dimensions::mm(1, 1, 1), '1 kg')->weight->ticks);
        $withLimit = Item::create('a', Dimensions::mm(1, 1, 1), maxTopLoad: '500 g');
        self::assertSame(Weight::of(500, 'g')->ticks, $withLimit->maxTopLoad?->ticks);
    }

    public static function testAnAbsentBearingLimitStaysAbsent(): void
    {
        self::assertNull(Item::create('a', Dimensions::mm(1, 1, 1))->maxTopLoad);
    }

    public static function testTagsAreDeduplicated(): void
    {
        $item = Item::create('a', Dimensions::mm(1, 1, 1), tags: ['fragile', 'fragile'], incompatibleTags: ['heavy']);
        self::assertSame(['fragile'], $item->tags);
        self::assertSame(['heavy'], $item->incompatibleTags);
    }

    public static function testInstancesAreNumberedFromOne(): void
    {
        $instances = Item::create('widget', Dimensions::mm(1, 1, 1), quantity: 3)->instances();
        self::assertSame(['widget#1', 'widget#2', 'widget#3'],
            array_map(static fn($i): string => $i->id(), $instances));
    }

    public static function testInstancesShareTheItemMeasurements(): void
    {
        [$instance] = Item::create('a', Dimensions::mm(1, 2, 3), '1 kg')->instances();
        self::assertSame(Length::mm(1)->ticks, $instance->dimensions()->length->ticks);
        self::assertSame(Weight::of(1, 'kg')->ticks, $instance->weight()->ticks);
    }

    // ------------------------------------------------------------------- container

    public static function testAContainerNeedsAnId(): void
    {
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Container::create('', Dimensions::mm(1, 1, 1)));
    }

    public static function testContainerQuantityAndCostAreSanityChecked(): void
    {
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Container::create('c', Dimensions::mm(1, 1, 1), quantity: 0));
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Container::create('c', Dimensions::mm(1, 1, 1), costMinor: -1));
    }

    public static function testOuterDimensionsCannotBeSmallerThanInner(): void
    {
        self::assertThrows(InvalidArgumentException::class, static fn() => Container::create(
            'c', Dimensions::mm(10, 10, 10), outerDimensions: Dimensions::mm(9, 10, 10),
        ));
    }

    public static function testAnObstacleMustLieInsideTheContainer(): void
    {
        $outside = new Obstacle('post', new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(20, 5, 5)));
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Container::create('c', Dimensions::mm(10, 10, 10), obstacles: [$outside]));
    }

    public static function testAnObstacleFlushWithTheFarWallIsAccepted(): void
    {
        $flush = new Obstacle('shelf', new AxisAlignedBox(new Point(Length::mm(5)->ticks, 0, 0), Dimensions::mm(5, 10, 10)));
        self::assertCount(1, Container::create('c', Dimensions::mm(10, 10, 10), obstacles: [$flush])->obstacles);
    }

    public static function testASingleBoxObstacleIsTheOneBoxCaseOfTheUnion(): void
    {
        // Nothing about existing single-box construction changes.
        $post = new Obstacle('post', new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(5, 5, 5)));
        self::assertSame([$post->box], $post->boxes());
    }

    public static function testAMultiBoxObstacleApproximatesANonRectangularShape(): void
    {
        // A wheel arch or tapered roof: a union of exact boxes, not a
        // single rectangle, expressed via additionalBoxes.
        $step1 = new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(10, 10, 5));
        $step2 = new AxisAlignedBox(new Point(0, 0, Length::mm(5)->ticks), Dimensions::mm(6, 10, 5));
        $arch = new Obstacle('wheel_arch', $step1, [$step2]);
        self::assertSame([$step1, $step2], $arch->boxes());
    }

    public static function testEveryBoxInAUnionMustLieInsideTheContainer(): void
    {
        $step1 = new AxisAlignedBox(new Point(0, 0, 0), Dimensions::mm(5, 5, 5));
        $outside = new AxisAlignedBox(new Point(Length::mm(50)->ticks, 0, 0), Dimensions::mm(5, 5, 5));
        $arch = new Obstacle('arch', $step1, [$outside]);
        self::assertThrows(InvalidArgumentException::class,
            static fn() => Container::create('c', Dimensions::mm(10, 10, 10), obstacles: [$arch]));
    }

    // --------------------------------------------------------------- packed result

    public static function testPackedContainerAggregatesWeightAndVolume(): void
    {
        $item = Item::create('a', Dimensions::mm(10, 10, 10), '500 g', quantity: 2);
        $box = Container::create('c', Dimensions::mm(20, 10, 10), tareWeight: '200 g');
        [$first, $second] = $item->instances();
        $packed = new PackedContainer($box, 1, [self::placement($first), self::placement($second, Length::mm(10)->ticks)]);

        self::assertSame('c#1', $packed->id());
        self::assertSame(Weight::of(1, 'kg')->ticks, $packed->payloadWeight()->ticks);
        self::assertSame(Weight::of('1.2', 'kg')->ticks, $packed->grossWeight()->ticks);
        self::assertSame($box->innerDimensions->volumeString(), $packed->usedVolumeString());
    }

    public static function testAPackedContainerCanBeRePackedAsAnItem(): void
    {
        // Nested packing feeds a full carton into the next level, so it must present
        // the outer dimensions and the gross weight, not the inner ones.
        $box = Container::create('c', Dimensions::mm(10, 10, 10), tareWeight: '100 g',
            outerDimensions: Dimensions::mm(12, 12, 12));
        [$instance] = Item::create('a', Dimensions::mm(10, 10, 10), '900 g')->instances();
        $asItem = (new PackedContainer($box, 1, [self::placement($instance)]))->asItem();

        self::assertSame(Length::mm(12)->ticks, $asItem->dimensions->length->ticks);
        self::assertSame(Weight::of(1, 'kg')->ticks, $asItem->weight->ticks);
        self::assertTrue($asItem->keepUpright);
        self::assertSame('c#1', $asItem->metadata['source_packed_container']);
    }

    // --------------------------------------------------------------------- request

    public static function testARequestNeedsAtLeastOneItemAndOneContainer(): void
    {
        $item = Item::create('a', Dimensions::mm(1, 1, 1));
        $box = Container::create('c', Dimensions::mm(1, 1, 1));
        self::assertThrows(InvalidArgumentException::class, static fn() => new PackingRequest([], [$box]));
        self::assertThrows(InvalidArgumentException::class, static fn() => new PackingRequest([$item], []));
    }

    public static function testDuplicateIdsAreRefused(): void
    {
        // Two items sharing an id would make every per-instance report ambiguous.
        $item = Item::create('a', Dimensions::mm(1, 1, 1));
        $box = Container::create('c', Dimensions::mm(1, 1, 1));
        self::assertThrows(InvalidArgumentException::class, static fn() => new PackingRequest([$item, $item], [$box]));
        self::assertThrows(InvalidArgumentException::class, static fn() => new PackingRequest([$item], [$box, $box]));
    }

    public static function testRequestExpandsQuantitiesIntoInstances(): void
    {
        $request = new PackingRequest(
            [Item::create('a', Dimensions::mm(1, 1, 1), quantity: 2), Item::create('b', Dimensions::mm(1, 1, 1))],
            [Container::create('c', Dimensions::mm(1, 1, 1))],
        );
        self::assertSame(['a#1', 'a#2', 'b#1'],
            array_map(static fn($i): string => $i->id(), $request->instances()));
    }

    // ------------------------------------------------------------ rate table

    /**
     * A malformed tariff misprices silently, which is why every one of these is refused
     * where the table is built rather than at the point a shipment is quoted. Each case
     * below is one of the constructor's guards; none of them had a PHP test before.
     */
    public static function testAMalformedTariffIsRefusedWhereItIsBuilt(): void
    {
        $cases = [
            'no bracket at all' => [[], []],
            'a length mismatch pairs a weight with another band\'s price' => [[100, 200], [10]],
            'a non-positive bound' => [[0], [10]],
            'a descending ladder hides an unreachable band' => [[200, 100], [10, 20]],
            'a negative price' => [[100], [-1]],
        ];
        foreach ($cases as $why => [$brackets, $prices]) {
            self::assertThrows(InvalidArgumentException::class,
                static fn() => new RateTable($brackets, $prices), $why);
        }
        self::assertThrows(InvalidArgumentException::class,
            static fn() => new RateTable([100], [10], minimumChargeMinor: -1), 'a negative minimum charge');
        self::assertThrows(InvalidArgumentException::class,
            static fn() => new RateTable([100], [10], fuelSurchargePermille: -1), 'a negative fuel surcharge');
    }

    public static function testEqualAdjacentBoundsAreRefusedNotMerelyDescendingOnes(): void
    {
        // `<=` rather than `<`: two bands with the same bound leaves the second one
        // permanently unreachable, which reads as priced but never prices anything.
        self::assertThrows(InvalidArgumentException::class,
            static fn() => new RateTable([100, 100], [10, 20]));
    }

    public static function testAPriceBandMayDipBecauseAPromotionalRateCardIsReal(): void
    {
        // Deliberately *not* rejected: the table is read by bracket, so a cheaper upper
        // band prices correctly. Asserting this keeps a future "prices must ascend"
        // guard from being added as an obvious-looking improvement.
        $table = new RateTable([100, 200], [900, 500]);
        self::assertSame(900, $table->chargeMinor(50));
        self::assertSame(500, $table->chargeMinor(150));
    }
}
