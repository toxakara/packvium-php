<?php
declare(strict_types=1);

namespace Packvium\Result;

use InvalidArgumentException;

final class TerminationSummary
{
    /** @param list<StartRecord> $starts */
    public static function aggregate(array $starts, bool $error = false): ResultFact
    {
        if ($starts === []) {
            throw new InvalidArgumentException('termination aggregation requires at least one start record');
        }
        $selected = array_values(array_filter($starts, static function (StartRecord $start): bool {
            return $start->selected;
        }));
        if (count($selected) !== 1) {
            throw new InvalidArgumentException('termination aggregation requires exactly one selected start');
        }
        $anyTruncated = false;
        $allCompleted = true;
        $globalDeadline = false;
        foreach ($starts as $start) {
            $anyTruncated = $anyTruncated || $start->truncated;
            $allCompleted = $allCompleted && $start->completed;
            $globalDeadline = $globalDeadline || $start->globalDeadlineReached;
        }
        $winningTruncated = $selected[0]->truncated;
        $code = $error ? 'error' : (($winningTruncated || $globalDeadline) ? 'time_limit' : 'complete');
        return new ResultFact($code, [
            'any_start_truncated' => $anyTruncated,
            'all_required_starts_completed' => $allCompleted,
            'winning_start_truncated' => $winningTruncated,
            'global_deadline_reached' => $globalDeadline,
            'starts' => array_map(
                static function (StartRecord $start): array {
                    return $start->toArray();
                },
                $starts,
            ),
        ]);
    }
}
