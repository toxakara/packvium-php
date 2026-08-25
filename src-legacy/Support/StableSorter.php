<?php
declare(strict_types=1);
namespace Packvium\Support;
final class StableSorter
{
    /** @template T @param list<T> $values @param callable(T,T):int $compare @return list<T> */
    public static function sort(array $values, callable $compare): array
    {
        $decorated=[]; foreach($values as $i=>$value){$decorated[]=['i'=>$i,'v'=>$value];}
        usort($decorated, static function (array $a, array $b) use ($compare): int {
            return $compare($a['v'],$b['v']) ?: ($a['i'] <=> $b['i']);
        });
        return array_values(array_map(static function (array $x) {
            return $x['v'];
        },$decorated));
    }

    /**
     * Stable sort by a precomputed key, mirroring Python's `sorted(key=...)`.
     *
     * The key is built once per element rather than twice per comparison, which matters
     * because the ordering keys involve big-integer volume chunking.
     *
     * @template T @param list<T> $values @param callable(T):array $key @return list<T>
     */
    public static function sortBy(array $values, callable $key): array
    {
        $decorated=[]; foreach($values as $i=>$value){$decorated[]=['k'=>$key($value),'i'=>$i,'v'=>$value];}
        usort($decorated, static function (array $a, array $b): int {
            return ($a['k'] <=> $b['k']) ?: ($a['i'] <=> $b['i']);
        });
        return array_values(array_map(static function (array $x) {
            return $x['v'];
        },$decorated));
    }
}
