<?php
declare(strict_types=1);
namespace Packvium\Nested;

use InvalidArgumentException;
use Packvium\Config\PackingConfig;
use Packvium\Domain\Container;
use Packvium\Domain\Item;
use Packvium\Domain\PackedContainer;
use Packvium\Packer;
use Packvium\Result\PackingResult;
use Packvium\Support\ExactInteger;
use Packvium\Support\StableSorter;

/**
 * Packs levels in order, each level's packed containers becoming the next level's items.
 *
 * `levelBeamWidth` K carries the K best chains from one level to the next. A chain
 * is a sequence of level results; it extends by packing its handoff against the level's whole
 * container list and, when K > 1 and the level offers more than one, against each container
 * alone. Chains rank by their outermost result's score, then each earlier level's outward in,
 * then generation order; two chains handing up the same items keep only the better. A level
 * with no complete candidate ends every chain, and the best incomplete one is the answer.
 * K = 1 is exactly the greedy chain. At most L * K * (T + 1) level packs for L levels offering
 * at most T containers each.
 */
final class NestedPacker
{
    public function __construct(public readonly int $levelBeamWidth = 1)
    {
        if ($levelBeamWidth < 1) {
            throw new InvalidArgumentException('level_beam_width must be a positive integer');
        }
    }

    /** @param list<Item> $items @param list<PackingLevel> $levels */
    public function pack(array $items, array $levels): NestedPackingResult
    {
        /** @var list<list<PackingResult>> $beam */
        $beam = [[]];
        /** @var list<list<Item>> $handoffs */
        $handoffs = [$items];
        foreach ($levels as $level) {
            $ranked = $this->rankedExtensions($level, $beam, $handoffs);
            $complete = self::completeChains($ranked);
            if ($complete === []) {
                return new NestedPackingResult($ranked[0]);
            }
            $beam = $this->distinctHandoffs($complete);
            $handoffs = \array_map(static fn(array $chain): array => self::handoff(self::last($chain)), $beam);
        }
        return new NestedPackingResult($beam[0]);
    }

    /**
     * Every extension of every chain, best first; the stable sort keeps generation order on ties.
     *
     * @param list<list<PackingResult>> $beam @param list<list<Item>> $handoffs
     * @return list<list<PackingResult>>
     */
    private function rankedExtensions(PackingLevel $level, array $beam, array $handoffs): array
    {
        $packer = new Packer($level->config ?? PackingConfig::balanced());
        $extensions = [];
        foreach ($beam as $index => $chain) {
            foreach ($this->candidates($packer, $handoffs[$index], $level->containers) as $result) {
                $extension = $chain;
                $extension[] = $result;
                $extensions[] = $extension;
            }
        }
        return StableSorter::sort(
            $extensions,
            static fn(array $left, array $right): int => self::compareChains($left, $right),
        );
    }

    /**
     * @param list<Item> $items @param list<Container> $containers
     * @return list<PackingResult>
     */
    private function candidates(Packer $packer, array $items, array $containers): array
    {
        $offers = [$containers];
        if ($this->levelBeamWidth > 1 && \count($containers) > 1) {
            foreach ($containers as $container) {
                $offers[] = [$container];
            }
        }
        return \array_map(static fn(array $offer): PackingResult => $packer->pack($items, $offer), $offers);
    }

    /**
     * The first K chains, best first, that hand distinct containers up to the next level.
     *
     * @param list<list<PackingResult>> $ranked @return list<list<PackingResult>>
     */
    private function distinctHandoffs(array $ranked): array
    {
        $kept = [];
        $seen = [];
        foreach ($ranked as $chain) {
            $handoff = self::handoffKey(self::last($chain));
            if (isset($seen[$handoff])) {
                continue;
            }
            $seen[$handoff] = true;
            $kept[] = $chain;
            if (\count($kept) === $this->levelBeamWidth) {
                break;
            }
        }
        return $kept;
    }

    /** @param list<list<PackingResult>> $ranked @return list<list<PackingResult>> */
    private static function completeChains(array $ranked): array
    {
        return \array_values(\array_filter($ranked, static fn(array $chain): bool => self::last($chain)->complete()));
    }

    /** Each packed container as one upright item of the next level. @return list<Item> */
    private static function handoff(PackingResult $result): array
    {
        return \array_map(static fn(PackedContainer $container): Item => $container->asItem(), $result->containers);
    }

    /**
     * The handed-up containers as a multiset: instance numbers and emission order are not part
     * of a packing's contract, so two engines must collapse the same chains.
     */
    private static function handoffKey(PackingResult $result): string
    {
        $containers = [];
        foreach ($result->containers as $container) {
            $billed = $container->billedDimensions();
            $containers[] = \serialize([$container->container->id, $billed->length->ticks, $billed->width->ticks,
                $billed->height->ticks, $container->grossWeight()->ticks]);
        }
        \sort($containers, \SORT_STRING);
        return \implode("\0", $containers);
    }

    /**
     * Outermost level first, then each earlier level outward in; chains compared here always
     * have the same length.
     *
     * @param list<PackingResult> $left @param list<PackingResult> $right
     */
    private static function compareChains(array $left, array $right): int
    {
        for ($index = \count($left) - 1; $index >= 0; --$index) {
            $order = self::compareScores($left[$index]->score, $right[$index]->score);
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }

    /**
     * Lexicographic, lower is better. A component past PHP's integer range is a decimal
     * string, so `<=>` on the raw arrays would compare it as text.
     *
     * @param list<int|float|string> $left @param list<int|float|string> $right
     */
    private static function compareScores(array $left, array $right): int
    {
        $shared = \min(\count($left), \count($right));
        for ($index = 0; $index < $shared; ++$index) {
            $order = self::compareComponents($left[$index], $right[$index]);
            if ($order !== 0) {
                return $order;
            }
        }
        return \count($left) <=> \count($right);
    }

    private static function compareComponents(int|float|string $left, int|float|string $right): int
    {
        if (\is_float($left) || \is_float($right)) {
            return (float)$left <=> (float)$right;
        }
        return ExactInteger::compare($left, $right);
    }

    /** @param list<PackingResult> $chain */
    private static function last(array $chain): PackingResult
    {
        return $chain[\count($chain) - 1];
    }
}
