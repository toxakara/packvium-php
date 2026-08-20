<?php
declare(strict_types=1);
namespace Packvium\Commerce\Rating;

/**
 * What is being rated: a shipment's real weight and volume, the zone it is moving to,
 * and whichever accessorial services this specific shipment needs.
 */
final readonly class RatingRequest
{
    /** @param list<string> $requestedAccessorials */
    public function __construct(
        public string $zone,
        public int $actualWeightG,
        public int $volumeMm3,
        public array $requestedAccessorials = [],
    ) {
        if ($zone === '') { throw new \InvalidArgumentException('zone is required'); }
        if ($actualWeightG < 0) { throw new \InvalidArgumentException('actual_weight_g cannot be negative'); }
        if ($volumeMm3 < 0) { throw new \InvalidArgumentException('volume_mm3 cannot be negative'); }
        foreach ($requestedAccessorials as $accessorial) {
            if ($accessorial === '') { throw new \InvalidArgumentException('requested accessorial ids must be non-empty'); }
        }
        if (count(array_unique($requestedAccessorials)) !== count($requestedAccessorials)) {
            throw new \InvalidArgumentException('requested accessorial ids must be unique');
        }
    }
}
