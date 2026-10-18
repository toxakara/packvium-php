<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

/** An item type as the request declares it, read independently of the engine's `Item`. */
final class ItemSpec
{
    /** @var string */
    public $id;
    /** @var array{0:int|string,1:int|string,2:int|string} */
    public $dimensions;
    /** @var int|string */
    public $weight;
    /** @var mixed the request's value, compared and printed as the reference does */
    public $quantity;
    /** @var list<mixed> */
    public $allowedRotations;
    /** @var bool */
    public $stackable;
    /** @var bool */
    public $mustBeOnFloor;
    /** @var int|string|null */
    public $maxTopLoad;
    /** @var Ratio */
    public $minimumSupportRatio;
    /** @var array<string,true> */
    public $tags;
    /** @var array<string,true> */
    public $eligibleContainerTags;
    /** @var int|null */
    public $maxStackedItems;
    /** @var mixed */
    public $groundContactRule;
    /** @var int|string|null */
    public $nestingHeight;
    /** @var array<string,true> */
    public $incompatibleTags;
    /** @var int|null */
    public $stopIndex;
    /** @var mixed */
    public $value;
    /** @var mixed */
    public $shapeType;
    /** @var list<array{0:int|string,1:int|string,2:int|string}>|null */
    public $hullVertices;
    /** @var int|string|null */
    public $compressionRatioPpm;
    /** @var int|null */
    public $maxCompressionPressureKpa;
    /** @var mixed */
    public $stackingGroup;
    /** @var array<string,true> */
    public $acceptsStackingGroups;

    /** `orientation in allowed_rotations`. @param mixed $orientation */
    public function allows($orientation): bool
    {
        foreach ($this->allowedRotations as $allowed) {
            if (Json::equals($allowed, $orientation)) {
                return true;
            }
        }
        return false;
    }

    public function isConvexHull(): bool
    {
        return Json::isText($this->shapeType, 'convex_hull');
    }

    /** @return array{0:int|string,1:int|string,2:int|string} */
    public function rotated(string $orientation): array
    {
        $axes = Geometry::axes($orientation);
        return [$this->dimensions[$axes[0]], $this->dimensions[$axes[1]], $this->dimensions[$axes[2]]];
    }
}
