<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\MalformedResult;
use Packvium\Validation\ResultIssue;

/** The result's own claims about the search: counters, counted-work limits, start records, facts. */
final class Facts
{
    private const METRICS = [
        'candidate_points_considered', 'orientations_considered', 'feasible_candidates', 'collision_checks',
        'support_checks', 'space_partitions', 'search_nodes_expanded',
    ];

    /** @param mixed $result @return mixed */
    private static function algorithm($result)
    {
        $algorithm = Json::at($result, 'algorithm');
        if (!Json::isDict($algorithm)) {
            throw MalformedResult::contract('algorithm must be an object');
        }
        return $algorithm;
    }

    /** @param mixed $result @param list<ResultIssue> $issues */
    public static function checkAlgorithmMetrics($result, array &$issues): void
    {
        $algorithm = self::algorithm($result);
        $metrics = Json::get($algorithm, 'metrics');
        $wellFormed = Json::isDict($metrics);
        foreach (self::METRICS as $field) {
            $count = $wellFormed ? Json::strictInt(Json::get($metrics, $field)) : null;
            $wellFormed = $wellFormed && $count !== null && $count >= 0;
        }
        if (!$wellFormed) {
            $issues[] = new ResultIssue('solver_metrics_mismatch', 'algorithm.metrics must contain every non-negative integer counter');
            return;
        }
        $candidates = Json::strictInt(Json::get($algorithm, 'candidates_evaluated'));
        $attempts = Json::strictInt(Json::get($algorithm, 'placements_attempted'));
        if ($candidates === null || $attempts === null || $candidates !== $metrics['feasible_candidates'] || $attempts !== $metrics['orientations_considered']) {
            $issues[] = new ResultIssue('solver_metrics_mismatch', 'legacy candidate counters must mirror algorithm.metrics');
        }
    }

    /** Counted-work limits are respected, and the stop flag is evidenced by one. @param mixed $request @param mixed $result @param list<ResultIssue> $issues */
    public static function checkEffortBudget($request, $result, array &$issues): void
    {
        $algorithm = Json::at($result, 'algorithm');
        $effortLimited = Json::get($algorithm, 'effort_limit_reached');
        if (!\is_bool($effortLimited)) {
            throw MalformedResult::contract('algorithm.effort_limit_reached must be a boolean');
        }
        $budget = Json::get(Json::get($request, 'configuration', []), 'effort_budget');
        if ($budget === null) {
            if ($effortLimited) {
                $issues[] = new ResultIssue('effort_budget_mismatch', 'effort_limit_reached is true but the request has no effort_budget');
            }
            return;
        }
        if (!Json::isDict($budget)) {
            throw MalformedResult::contract('config.effort_budget must be an object');
        }
        $metrics = Json::get($algorithm, 'metrics');
        if (!Json::isDict($metrics)) {
            return;
        }
        $reached = false;
        foreach (['max_candidates_evaluated' => 'feasible_candidates', 'max_placement_attempts' => 'orientations_considered', 'max_search_nodes' => 'search_nodes_expanded'] as $budgetName => $metricName) {
            $limit = Json::get($budget, $budgetName);
            $value = Json::strictInt(Json::get($metrics, $metricName));
            if ($limit === null || $value === null) {
                continue;
            }
            $order = Json::compare($value, $limit);
            if ($order > 0) {
                $issues[] = new ResultIssue('effort_budget_exceeded', "{$metricName} is {$value}, above {$budgetName}=" . PyFormat::str($limit));
            }
            $reached = $reached || $order >= 0;
        }
        $restartLimit = Json::get($budget, 'max_restarts');
        $starts = Json::get(Json::get($result, 'termination', []), 'starts', []);
        $limit = Json::intInstance($restartLimit);
        if ($limit !== null && Json::isList($starts)) {
            $started = 0;
            foreach ($starts as $start) {
                if (Json::isDict($start) && Json::get($start, 'started') === true) {
                    ++$started;
                }
            }
            if ($started > $limit) {
                $issues[] = new ResultIssue('effort_budget_exceeded', "started {$started} solver runs, above max_restarts=" . PyFormat::str($restartLimit));
            }
            $reached = $reached || $started >= $limit;
        }
        if ($effortLimited && !$reached) {
            $issues[] = new ResultIssue('effort_budget_mismatch', 'effort_limit_reached is true but no configured counted-work boundary was reached');
        }
    }

    /** @param mixed $algorithm */
    private static function flag($algorithm, string $name): bool
    {
        $value = Json::get($algorithm, $name);
        if (!\is_bool($value)) {
            throw MalformedResult::contract("algorithm.{$name} must be a boolean");
        }
        return $value;
    }

    /** @param mixed $result */
    private static function factCode($result, string $name): string
    {
        $fact = Json::at($result, $name);
        if (!Json::isDict($fact)) {
            throw MalformedResult::contract("{$name} must be an object");
        }
        $code = Json::get($fact, 'code');
        if (!\is_string($code) || $code === '') {
            throw MalformedResult::contract("{$name}.code must be a non-empty string");
        }
        return $code;
    }

    /** @param mixed $result @param list<ResultIssue> $issues */
    public static function checkResultFacts($result, array &$issues): void
    {
        $algorithm = self::algorithm($result);
        $timedOut = self::flag($algorithm, 'time_limit_reached');
        $effortLimited = self::flag($algorithm, 'effort_limit_reached');
        if ($timedOut && $effortLimited) {
            $issues[] = new ResultIssue('result_fact_mismatch', 'time_limit_reached and effort_limit_reached cannot both be true');
        }
        $termination = Json::at($result, 'termination');
        if (!Json::isDict($termination)) {
            throw MalformedResult::contract('termination must be an object');
        }
        self::checkStarts($termination, $algorithm, $timedOut, $effortLimited, $issues);

        $status = Json::at($result, 'status');
        $complete = Json::truthy(Json::at($result, 'complete'));
        $invalid = Json::isText($status, 'invalid_result');
        $expected = [
            'feasibility' => Json::isText($status, 'infeasible') ? 'infeasible' : (($complete && !$invalid) ? 'feasible' : 'unknown'),
            'termination' => $invalid ? 'error' : ($timedOut ? 'time_limit' : ($effortLimited ? 'effort_limit' : 'complete')),
            'optimality' => Json::isText($status, 'optimal') ? 'proven_optimal'
                : (Json::isText($status, 'infeasible') ? 'proven_infeasible' : ((!$complete && !$invalid) ? 'best_found' : 'not_proven')),
        ];
        foreach ($expected as $name => $code) {
            $actual = self::factCode($result, $name);
            if ($actual !== $code) {
                $issues[] = new ResultIssue('result_fact_mismatch', "{$name}.code is " . PyFormat::stringRepr($actual) . ', expected ' . PyFormat::stringRepr($code));
            }
        }
    }

    /** @param mixed $termination @param mixed $algorithm @param list<ResultIssue> $issues */
    private static function checkStarts($termination, $algorithm, bool $timedOut, bool $effortLimited, array &$issues): void
    {
        $starts = Json::get($termination, 'starts');
        if (!Json::isList($starts) || $starts === []) {
            $issues[] = new ResultIssue('termination_aggregate_mismatch', 'termination.starts must contain at least one start');
            return;
        }
        $malformed = false;
        foreach ($starts as $start) {
            if (!Json::isDict($start) || !\is_string(Json::get($start, 'id'))) {
                $malformed = true;
                continue;
            }
            $flags = [];
            foreach (['started', 'completed', 'truncated', 'selected', 'global_deadline_reached'] as $name) {
                $flags[] = Json::get($start, $name);
            }
            if (\count(\array_filter($flags, 'is_bool')) !== 5) {
                $malformed = true;
                continue;
            }
            [$started, $completed, $truncated] = $flags;
            if (($completed && (!$started || $truncated)) || ($truncated && (!$started || $completed)) || (!$started && ($completed || $truncated))) {
                $issues[] = new ResultIssue('start_record_mismatch', Json::at($start, 'id') . ': inconsistent lifecycle flags');
            }
        }
        if ($malformed) {
            $issues[] = new ResultIssue('start_record_mismatch', 'a start record is missing its id or boolean lifecycle fields');
        }
        $isTrue = static function ($start, string $name): bool {
            return Json::isDict($start) && Json::get($start, $name) === true;
        };
        $selected = \array_values(\array_filter($starts, static function ($start) use ($isTrue): bool { return $isTrue($start, 'selected'); }));
        if (\count($selected) !== 1) {
            $issues[] = new ResultIssue('termination_aggregate_mismatch', 'expected one selected start, found ' . \count($selected));
            return;
        }
        foreach ($starts as $start) {
            if (!Json::isDict($start)) {
                return;
            }
        }
        $winner = $selected[0];
        $any = static function (string $name) use ($starts, $isTrue): bool {
            foreach ($starts as $start) {
                if ($isTrue($start, $name)) {
                    return true;
                }
            }
            return false;
        };
        $all = static function (string $name) use ($starts, $isTrue): bool {
            foreach ($starts as $start) {
                if (!$isTrue($start, $name)) {
                    return false;
                }
            }
            return true;
        };
        $aggregate = [
            'any_start_truncated' => $any('truncated'),
            'all_required_starts_completed' => $all('completed'),
            'winning_start_truncated' => $isTrue($winner, 'truncated'),
            'global_deadline_reached' => $any('global_deadline_reached'),
        ];
        foreach ($aggregate as $name => $expected) {
            $reported = Json::get($termination, $name);
            if ($reported !== $expected) {
                $issues[] = new ResultIssue('termination_aggregate_mismatch', "{$name} is " . PyFormat::repr($reported) . ', expected ' . PyFormat::boolRepr($expected));
            }
        }
        $solver = Json::get($algorithm, 'solver');
        $selectedId = Json::get($winner, 'id', '');
        if (\is_string($solver) && !Json::isText($selectedId, $solver)
            && \strncmp(Json::text($selectedId, 'a start id'), "{$solver}#", \strlen($solver) + 1) !== 0) {
            $issues[] = new ResultIssue('winning_start_mismatch', 'selected start ' . PyFormat::repr($selectedId) . ' does not identify solver ' . PyFormat::stringRepr($solver));
        }
        if ($aggregate['global_deadline_reached'] && !$timedOut) {
            $issues[] = new ResultIssue('legacy_time_limit_mismatch', 'global_deadline_reached requires time_limit_reached');
        }
        if (($aggregate['global_deadline_reached'] || $aggregate['winning_start_truncated']) !== ($timedOut || $effortLimited)) {
            $issues[] = new ResultIssue('termination_limit_mismatch', 'selected-start truncation must identify exactly one reached limit');
        }
    }
}
