<?php
declare(strict_types=1);

namespace Packvium\Result;

final class StartRecord
{
    /**
     * @readonly
     * @var string
     */
    public $id;
    /**
     * @readonly
     * @var bool
     */
    public $started;
    /**
     * @readonly
     * @var bool
     */
    public $completed;
    /**
     * @readonly
     * @var bool
     */
    public $truncated;
    /**
     * @readonly
     * @var bool
     */
    public $selected = false;
    /**
     * @readonly
     * @var bool
     */
    public $globalDeadlineReached = false;
    public function __construct(string $id, bool $started, bool $completed, bool $truncated, bool $selected = false, bool $globalDeadlineReached = false)
    {
        $this->id = $id;
        $this->started = $started;
        $this->completed = $completed;
        $this->truncated = $truncated;
        $this->selected = $selected;
        $this->globalDeadlineReached = $globalDeadlineReached;
    }

    public function withSelected(bool $selected): self
    {
        return new self(
            $this->id,
            $this->started,
            $this->completed,
            $this->truncated,
            $selected,
            $this->globalDeadlineReached,
        );
    }

    public function withGlobalDeadline(bool $reached): self
    {
        return new self(
            $this->id,
            $this->started,
            $this->completed,
            $this->truncated,
            $this->selected,
            $reached,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'started' => $this->started,
            'completed' => $this->completed,
            'truncated' => $this->truncated,
            'selected' => $this->selected,
            'global_deadline_reached' => $this->globalDeadlineReached,
        ];
    }
}
