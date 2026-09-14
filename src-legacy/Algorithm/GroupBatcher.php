<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
use Packvium\Domain\ItemInstance;
final class GroupBatcher
{
    /**
     * Items in a group share a container, so they are offered to a solver atomically.
     *
     * A batch that does not fit is rejected as a whole and leaves the rest of the order
     * untouched — an impossible group must never strand unrelated items.
     *
     * @param list<ItemInstance> $items @return list<list<ItemInstance>>
     */
    public static function batches(array $items):array
    {
        $batches=[];$positions=[];
        foreach($items as $item){
            $group=$item->item->group;
            if($group===null){$batches[]=[$item];continue;}
            if(!isset($positions[$group])){
                $positions[$group]=count($batches);
                $batches[]=[$item];
            }else $batches[$positions[$group]][]=$item;
        }
        return $batches;
    }

    /** @param list<list<ItemInstance>> $batches @return list<ItemInstance> */
    public static function flatten(array $batches):array
    {
        $out=[];
        foreach($batches as $batch)foreach($batch as $item)$out[]=$item;
        return $out;
    }
}
