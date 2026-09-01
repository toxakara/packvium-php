<?php
declare(strict_types=1);
namespace Packvium\Domain;

/**
 * How much of an item's declared box the item actually occupies.
 *
 * `RIGID_CUBOID` is the default and the whole of the contract before this epic: the item is
 * its box. The other two narrow that in one dimension each -- `CONVEX_HULL` in space,
 * `COMPRESSIBLE` in height under load -- and neither may be inferred. An engine that packed
 * a hull as its bounding box would return a plan that validates and does not physically fit.
 */
enum ShapeType: string
{
    case RIGID_CUBOID = 'rigid_cuboid';
    case CONVEX_HULL = 'convex_hull';
    case COMPRESSIBLE = 'compressible';
}
