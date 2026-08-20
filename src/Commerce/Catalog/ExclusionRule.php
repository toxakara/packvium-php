<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party rule forbidding one master-data entry from being packed with another. */
final readonly class ExclusionRule
{
    public function __construct(
        public string $id,
        public ExclusionScope $scope,
        public string $subjectId,
        public string $excludedId,
        public string $reason = '',
    ) {
        if ($id === '') { throw new \InvalidArgumentException('exclusion id is required'); }
        if ($subjectId === '' || $excludedId === '') {
            throw new \InvalidArgumentException('an exclusion rule must reference both a subject and an excluded id');
        }
    }
}
