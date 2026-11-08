<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Artifacts\OperationalArtifact;
use Packvium\Config\PackingConfig;
use Packvium\Domain\{Container, Dimensions, FixedPlacement, Item, Point, Rotation};
use Packvium\Execution\ExecutionPlanException;
use Packvium\Execution\Plan;
use Packvium\Packer;
use Packvium\Revisions\PlanRevision;
use Packvium\Revisions\PlanRevisionException;
use Packvium\Serialization\ArrayCodec;
use Packvium\Unit\Length;
use Packvium\Validation\FixedPlacementException;
use Packvium\Validation\ResultIssue;

use function Packvium\Validation\validate;

/**
 * `placement_id` (1.10): the canonical JSON of `[container id, x, y, z, item type, orientation]`,
 * a pure function of a placement. Held to packvium-python's tests/test_placement_id.py.
 */
final class PlacementIdTest extends TestCase
{
    private const MM = Length::TICKS_PER_MM;

    public static function testEveryPlacementOfTheAnswerAndItsAlternativesCarriesItsId(): void
    {
        foreach (['box', 'a@b#c', 'böx-箱', "q\"uo\\te\n\u{2028}"] as $containerId) {
            $request = self::request($containerId);
            $result = ArrayCodec::pack($request);
            $placements = self::everyPlacement($result);
            self::assertGreaterThan(3, \count($placements));
            foreach ($placements as [$container, $placement]) {
                self::assertSame(self::expectedId($container, $placement), $placement['placement_id']);
            }
            self::assertSame([], validate($request, $result));
        }
    }

    public static function testATamperedIdIsAMismatchNamingBoth(): void
    {
        $result = ArrayCodec::pack(self::request());
        $placement = &$result['containers'][0]['placements'][0];
        $placement['placement_id'] = self::ident('box#1', 1, 2, 3);
        $expected = self::expectedId($result['containers'][0], $placement);
        self::assertSame(
            ["placement_id_mismatch: {$placement['item_id']} in box#1: reported " . self::ident('box#1', 1, 2, 3) . ", expected {$expected}"],
            self::issues(self::request(), $result)
        );
    }

    public static function testAMissingIdIsAMismatch(): void
    {
        $result = ArrayCodec::pack(self::request());
        unset($result['containers'][0]['placements'][0]['placement_id']);
        $issues = validate(self::request(), $result);
        self::assertSame(['placement_id_mismatch'], self::codes($issues));
        self::assertTrue(\str_contains($issues[0]->detail, 'no placement_id, expected ["box#1",'));
    }

    public static function testARepeatedIdIsADuplicate(): void
    {
        $result = ArrayCodec::pack(self::request());
        $result['containers'][0]['placements'][1]['placement_id'] = $result['containers'][0]['placements'][0]['placement_id'];
        self::assertSame(['placement_id_mismatch', 'duplicate_placement_id'], self::codes(validate(self::request(), $result)));
    }

    public static function testALatticeSummaryHasNoPlacementsAndSoNoIds(): void
    {
        $request = self::request('box', 2);
        $request['configuration'] = ['solver_profile' => 'fast', 'require_placement_coordinates' => false];
        $result = ArrayCodec::pack($request);
        foreach ($result['containers'] as $container) {
            self::assertSame([], $container['placements']);
        }
        self::assertSame([], validate($request, $result));
    }

    // ------------------------------------------------------------------ fixed placements

    public static function testAFixedPlacementMayQuoteTheIdItsResultPlacementCarries(): void
    {
        $result = ArrayCodec::pack(self::fixedRequest(['placement_id' => self::ident('box#1', 100 * self::MM, 0, 0)]));
        $fixed = \array_values(\array_filter($result['containers'][0]['placements'], static fn(array $p): bool => ($p['fixed'] ?? false) === true));
        self::assertSame([self::ident('box#1', 100 * self::MM, 0, 0)], \array_column($fixed, 'placement_id'));
        self::assertSame('feasible', ArrayCodec::pack(self::fixedRequest(['placement_id' => null]))['status']);
    }

    public static function testAFixedIdNamingAnotherPlaceIsRefused(): void
    {
        $error = self::refusal(self::fixedRequest(['placement_id' => self::ident('box#1', 0, 0, 0)]));
        self::assertSame(['invalid_fixed_placement', 'invalid_value', '/fixed_placements/0/placement_id'], [$error->errorCode(), $error->reason(), $error->field()]);
        self::assertSame('invalid_fixed_placement: fixed_placements[0].placement_id must be ' . \json_encode(self::ident('box#1', 100 * self::MM, 0, 0)), $error->getMessage());
    }

    public static function testAFixedIdCountsTheInstanceAndDefaultsItToOne(): void
    {
        $error = self::refusal(self::fixedRequest(['container_instance' => 2, 'position' => [], 'placement_id' => self::ident('box#1', 0, 0, 0)]));
        self::assertTrue(\str_ends_with($error->detail(), \json_encode(self::ident('box#2', 0, 0, 0))));
    }

    public static function testAFixedIdPastEveryExactIntegerIsLeftToAdmission(): void
    {
        $error = self::refusal(self::fixedRequest([
            'position' => ['x' => ['value' => '9007199254740992', 'unit' => 'ticks']],
            'placement_id' => self::ident('box#1', 0, 0, 0),
        ]));
        self::assertSame('cannot_hold', $error->reason());
        self::assertTrue(\str_contains($error->detail(), 'outside_container'));
    }

    public static function testAFixedIdThatIsNotAStringIsMalformed(): void
    {
        $error = self::refusal(self::fixedRequest(['placement_id' => 7]));
        self::assertSame(['malformed', '/fixed_placements/0/placement_id', 'fixed_placements[0].placement_id is a string'], [$error->reason(), $error->field(), $error->detail()]);
    }

    public static function testTheObjectApiHoldsAFixedIdToTheSameRule(): void
    {
        self::assertTrue(self::objectPack(self::ident('box#1', 100 * self::MM, 0, 0)));
        try {
            self::objectPack(self::ident('box#1', 100 * self::MM, 0, 0, 'cube', 'WLH'));
            self::assertTrue(false, 'a wrong id was accepted');
        } catch (FixedPlacementException $error) {
            self::assertSame(['invalid_value', '/fixed_placements/0/placement_id'], [$error->reason(), $error->field()]);
        }
    }

    // ------------------------------------------------------------------ plan, artifact, revisions

    public static function testThePlanCopiesEachIdAndAProjectionWithoutIdsKeepsItsBytes(): void
    {
        $result = ArrayCodec::pack(self::request());
        $references = self::references(Plan::build(self::request(), $result));
        $ids = [];
        foreach ($result['containers'] as $container) {
            foreach ($container['placements'] as $placement) {
                $ids[] = $placement['placement_id'];
            }
        }
        self::assertSame($ids, \array_column($references, 'placement_id'));
        foreach (self::references(Plan::build(self::request(), self::withoutIds($result))) as $reference) {
            self::assertFalse(\array_key_exists('placement_id', $reference));
        }
        $keys = \array_keys(Plan::placementReference(0, $result['containers'][0]['placements'][0]));
        self::assertSame(['container_index', 'item_type', 'orientation', 'position_ticks'], $keys);
    }

    public static function testAPlanWithANonStringIdIsRefused(): void
    {
        $result = ArrayCodec::pack(self::request());
        $result['containers'][0]['placements'][0]['placement_id'] = 3;
        self::assertThrows(ExecutionPlanException::class, static fn() => Plan::build(self::request(), $result));
    }

    public static function testTheArtifactV1CarriesNoId(): void
    {
        $result = ArrayCodec::pack(self::request());
        $artifact = OperationalArtifact::build(self::request(), $result);
        self::assertFalse(\str_contains(OperationalArtifact::canonicalJson($artifact), 'placement_id'));
        self::assertSame($artifact, OperationalArtifact::build(self::request(), self::withoutIds($result)));
        $decoded = \json_decode((string)\json_encode($result));
        self::assertSame(
            OperationalArtifact::canonicalJson($artifact),
            OperationalArtifact::canonicalJson(OperationalArtifact::build(\json_decode((string)\json_encode(self::request())), $decoded))
        );
    }

    public static function testALockEventMayCarryTheIdAndIsOneBoxWithTheSameEntryWithoutIt(): void
    {
        $base = self::request();
        $root = PlanRevision::root($base);
        $artifact = OperationalArtifact::build($base, ArrayCodec::pack($base));
        $entry = ['item_type' => 'cube', 'container_type' => 'box', 'position' => ['x' => '0', 'y' => '0', 'z' => '0'], 'orientation' => 'LWH'];
        $quoted = $entry + ['placement_id' => self::ident('box#1', 0, 0, 0)];
        $revision = PlanRevision::derive($root, $artifact, [
            ['sequence' => 1, 'type' => 'placement_locked', 'placement' => $quoted],
            ['sequence' => 2, 'type' => 'placement_verified', 'placement' => $entry],
        ]);
        self::assertSame([$quoted], \json_decode((string)\json_encode($revision->request->fixed_placements), true));
        try {
            PlanRevision::derive($root, $artifact, [['sequence' => 1, 'type' => 'placement_locked', 'placement' => $entry + ['placement_id' => 1]]]);
            self::assertTrue(false, 'a non-string id was accepted');
        } catch (PlanRevisionException $error) {
            self::assertSame(['invalid_event', 'placement.placement_id is a string'], [$error->errorCode(), $error->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private static function request(string $containerId = 'box', int $quantity = 4): array
    {
        $dims = static fn(int $l, int $w, int $h): array => ['length' => (string)$l, 'width' => (string)$w, 'height' => (string)$h];
        return [
            'configuration' => ['solver_profile' => 'balanced', 'time_limit_ms' => 300000, 'alternatives' => 3],
            'items' => [['id' => 'cube', 'quantity' => $quantity, 'dimensions' => $dims(100, 100, 100)]],
            'containers' => [['id' => $containerId, 'quantity' => 3, 'inner_dimensions' => $dims(200, 100, 100)]],
        ];
    }

    /** @param array<string,mixed> $placement @return array<string,mixed> */
    private static function fixedRequest(array $placement): array
    {
        $request = self::request();
        $request['fixed_placements'] = [\array_replace(['item_type' => 'cube', 'container_type' => 'box', 'position' => ['x' => '100'], 'orientation' => 'LWH'], $placement)];
        return $request;
    }

    private static function ident(string $containerId, int $x, int $y, int $z, string $itemType = 'cube', string $orientation = 'LWH'): string
    {
        return (string)\json_encode([$containerId, $x, $y, $z, $itemType, $orientation], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /** @param array<string,mixed> $container @param array<string,mixed> $placement */
    private static function expectedId(array $container, array $placement): string
    {
        $ticks = \array_map(static fn(string $axis): int => $placement['position'][$axis]['ticks'], ['x', 'y', 'z']);
        return self::ident($container['id'], ...$ticks, ...[$placement['item_type'], $placement['orientation']]);
    }

    /** @param array<string,mixed> $result @return list<array{0:array<string,mixed>,1:array<string,mixed>}> */
    private static function everyPlacement(array $result): array
    {
        $out = [];
        foreach ([$result, ...$result['alternatives']] as $document) {
            foreach ($document['containers'] as $container) {
                foreach ($container['placements'] as $placement) {
                    $out[] = [$container, $placement];
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function withoutIds(array $result): array
    {
        foreach ($result['containers'] as $c => $container) {
            foreach ($container['placements'] as $p => $placement) {
                unset($result['containers'][$c]['placements'][$p]['placement_id']);
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    private static function references(array $plan): array
    {
        $out = [];
        foreach ($plan['containers'] as $container) {
            foreach ($container['steps'] as $step) {
                $out[] = $step['placement'];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $request */
    private static function refusal(array $request): FixedPlacementException
    {
        try {
            ArrayCodec::pack($request);
        } catch (FixedPlacementException $error) {
            return $error;
        }
        throw new \RuntimeException('the request was accepted');
    }

    private static function objectPack(string $placementId): bool
    {
        $side = Length::parse('100');
        $item = new Item('cube', new Dimensions($side, $side, $side), quantity: 2);
        $container = new Container('box', new Dimensions(Length::parse('200'), $side, $side));
        $fixed = new FixedPlacement('cube', 'box', new Point(100 * self::MM, 0, 0), Rotation::LWH, 1, $placementId);
        return (new Packer(new PackingConfig()))->pack([$item], [$container], [$fixed])->complete();
    }

    /** @param list<ResultIssue> $issues @return list<string> */
    private static function codes(array $issues): array
    {
        return \array_map(static fn(ResultIssue $issue): string => $issue->code, $issues);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $result @return list<string> */
    private static function issues(array $request, array $result): array
    {
        return \array_map('strval', validate($request, $result));
    }
}
