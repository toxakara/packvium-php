<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\Container;
use Packvium\Domain\ItemInstance;
/**
 * A constraint that can rule an item out of *every* offered container without searching
 * for a placement first.
 *
 * `evaluate` answers about one candidate, which is never enough to say why an item is
 * unpacked: a rejected candidate only means *this* position failed. A constraint that
 * implements this can say more -- that no position in any offered container could have
 * worked -- and that is what lets the unpacked reason be reported as `proven` and name
 * the rule responsible, rather than falling through to the generic observed reason
 *.
 *
 * Returns a `[reasonCode, detail]` pair, or `null` when nothing can be proven about this
 * item. Proving nothing is the correct answer for most rules: a segregation rule or a
 * per-container cap depends on what else was packed, so whether it leaves an item behind
 * is a property of the search, not of the request.
 */
interface ProvenRejection
{
    /**
     * @param list<Container> $containers
     * @return array{0:string,1:string}|null
     */
    public function provesUnplaceable(ItemInstance $item, array $containers): ?array;
}
