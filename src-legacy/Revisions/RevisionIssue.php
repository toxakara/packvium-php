<?php
declare(strict_types=1);
namespace Packvium\Revisions;

/** One way a chain fails its audit, anchored on the revision where it shows. */
final class RevisionIssue
{
    /** @var string */
    public $code;
    /** @var int */
    public $revision;
    /** @var string */
    public $detail;

    public function __construct(string $code, int $revision, string $detail)
    {
        $this->code = $code;
        $this->revision = $revision;
        $this->detail = $detail;
    }
}
