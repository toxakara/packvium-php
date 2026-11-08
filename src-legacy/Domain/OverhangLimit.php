<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Length;
/**
 * How far a load may pass a pallet deck's edge (`PalletOverhang`): `$length` past each
 * end along the length axis, `$width` past each side along the width axis. Zero forbids overhang
 * on that axis; it is not the same as the container stating no limit.
 */
final class OverhangLimit
{
    /**
     * @readonly
     * @var \Packvium\Unit\Length
     */
    public $length;
    /**
     * @readonly
     * @var \Packvium\Unit\Length
     */
    public $width;
    public function __construct(Length $length, Length $width)
    {
        $this->length = $length;
        $this->width = $width;
    }
}
