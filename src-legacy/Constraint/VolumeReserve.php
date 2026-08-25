<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use Packvium\Domain\Container;
use Packvium\Support\BigInt;
/**
 * Volume set aside for packing material, exact even for a metre-scale container.
 *
 * Not floating-point `ratio * volume`: a one-metre container's volume
 * (4.096 * 10^21 cubic ticks) is far beyond the range where a double is exact, and
 * this is a feasibility number, not a display one. See docs/UNITS-AND-NUMERICS.md.
 */
final class VolumeReserve
{
    public static function reserved(Container $container):string
    {
        $scaled=(string)SupportConstraint::scaled($container->voidFillReserveRatio);
        return BigInt::divide(BigInt::multiply($container->innerDimensions->volumeString(),$scaled),'1000000');
    }

    public static function usable(Container $container):string
    {
        return BigInt::subtract($container->innerDimensions->volumeString(),self::reserved($container));
    }
}
