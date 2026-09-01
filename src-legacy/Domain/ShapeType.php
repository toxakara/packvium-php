<?php
declare(strict_types=1);
namespace Packvium\Domain;

class ShapeType
{
    public const RIGID_CUBOID = 'rigid_cuboid';
    public const CONVEX_HULL = 'convex_hull';
    public const COMPRESSIBLE = 'compressible';
}
