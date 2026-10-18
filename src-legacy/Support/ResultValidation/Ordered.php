<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/**
 * Insertion-ordered map keyed the way a Python dict is: by `Json::hashKey`, so `1` and `True`
 * are one key, and assigning an existing key replaces its value in place. Keys keep the JSON
 * value they came from, for printing.
 *
 * @template T
 */
final class Ordered
{
    /** @var list<array{0:mixed,1:T}> */
    private $entries = [];
    /** @var array<string,int> */
    private $index = [];

    /** @param mixed $key @param T $value */
    public function set($key, $value): void
    {
        $hashed = Json::hashKey($key);
        if (isset($this->index[$hashed])) {
            $this->entries[$this->index[$hashed]][1] = $value;
            return;
        }
        $this->index[$hashed] = \count($this->entries);
        $this->entries[] = [$key, $value];
    }

    /** @param mixed $key @param T|null $default @return T|null */
    public function get($key, $default = null)
    {
        $position = $this->position($key);
        return $position === null ? $default : $this->entries[$position][1];
    }

    /** @param mixed $key */
    public function has($key): bool
    {
        return $this->position($key) !== null;
    }

    /** The entry's place in insertion order. @param mixed $key */
    public function position($key): ?int
    {
        return $this->index[Json::hashKey($key)] ?? null;
    }

    /** @return list<array{0:mixed,1:T}> key and value, in insertion order */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<T> */
    public function values(): array
    {
        return \array_map(static function (array $entry) { return $entry[1]; }, $this->entries);
    }

    /** @return list<mixed> */
    public function keys(): array
    {
        return \array_map(static function (array $entry) { return $entry[0]; }, $this->entries);
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
