<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Config\PackingConfig;
use Packvium\Domain\ItemInstance;
final class ItemOrdering
{
    /**
     * The keys every built-in item ordering starts with.
     *
     * Priority is a preference, not a guarantee: it leads so a caller can bias the
     * search, but ties (the default, priority 0 for all items) fall through to the
     * strategy's own key unchanged. Under `maximum_value` the objective's second key is
     * the value left behind (docs/OBJECTIVE.md), so the most valuable item takes first
     * refusal on the space -- behind an explicit priority bias, ahead of the strategy's
     * own key. An undeclared value is zero, so a request that never sets `value` orders
     * exactly as it did before this key existed.
     *
     * A route is unloaded last-in-first-out, so the later a stop, the earlier its items
     * must be loaded to end up underneath. Without this, `RouteOrderConstraint` refuses
     * the second item of a two-stop column and the answer costs a container it did not
     * need. Items with no stop ride the whole route and go to the bottom with the last
     * stop. Feasibility leads the objective's own key: an item that cannot be placed at
     * all loses key 0, which dominates every objective's key 1.
     *
     * @return list<int|float>
     */
    public static function lead(ItemInstance $instance,PackingConfig $config):array
    {
        $item=$instance->item;
        $stop=$item->stopIndex===null?-INF:-(float)$item->stopIndex;
        if($config->objective!=='maximum_value')return [-$item->priority,$stop];
        return [-$item->priority,$stop,-($item->value??0)];
    }
}
