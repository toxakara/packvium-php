<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/** A first-party rule forbidding one master-data entry from being packed with another. */
final class ExclusionRule
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @readonly
     * @var \Packvium\Commerce\Catalog\ExclusionScope
     */
    public $scope;
    /**
     * @readonly
     * @var string
     */
    public $subjectId;
    /**
     * @readonly
     * @var string
     */
    public $excludedId;
    /**
     * @readonly
     * @var string
     */
    public $reason = '';
    /**
     * @param mixed $scope
     */
    public function __construct(
        string $id,
        $scope,
        string $subjectId,
        string $excludedId,
        string $reason = ''
    ) {
        $this->id = $id;
        $this->scope = $scope;
        $this->subjectId = $subjectId;
        $this->excludedId = $excludedId;
        $this->reason = $reason;
        if ($id === '') { throw new \InvalidArgumentException('exclusion id is required'); }
        if ($subjectId === '' || $excludedId === '') {
            throw new \InvalidArgumentException('an exclusion rule must reference both a subject and an excluded id');
        }
    }
}
