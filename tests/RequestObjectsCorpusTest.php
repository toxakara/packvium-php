<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\FixedPlacement;
use Packvium\Domain\Point;
use Packvium\Domain\Rotation;
use Packvium\Packer;
use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;
use ReflectionMethod;
use Throwable;

/**
 * Every malformed request of the request-errors corpus is refused on the object path
 * with the refusal the JSON path gives. Mirrors
 * `packvium-python/tests/test_request_objects_corpus.py`, over the same
 * `conformance/request_errors/corpus.json`.
 *
 * Each case is built as objects with `ArrayCodec`'s own builders, but without the JSON rules,
 * and passed to `Packer::pack`. A refusal while an object is built must carry the JSON path's
 * exception class, code and reason with an empty field; a refusal from `Packer::pack` must
 * carry its field and message too. A case with no object form, or whose difference is kept on
 * purpose, is listed below with why; a listed case that starts matching fails, so it has to
 * leave the list.
 */
final class RequestObjectsCorpusTest extends TestCase
{
    private const UNKNOWN_KEY = 'an object has no place for a key its class does not declare';
    private const COERCED = 'the codec casts it, so the objects it builds are a different, valid request';
    private const TYPED = 'a typed parameter cannot hold it';
    private const OUTPUT = 'the output units are arguments of the result\'s serializer, not part of the objects packed';
    private const UNITS = 'the request\'s length unit is an argument of each measure, not an object packed';
    private const CONSTRUCTOR_FIRST = 'two faults: a constructor refuses its own value as the object is built, '
        . 'before Packer::pack walks the request in the JSON order';
    private const CEILING_SECOND = 'a length past the admission ceiling also breaks its constructor\'s own rule, '
        . 'which refuses it as the object is built, before Packer::pack reaches the ceiling';
    private const NEGATIVE_POSITION = 'a Point cannot hold a negative coordinate; the measure it is read with '
        . 'refuses it as negative_measure, where the JSON path names the placement';

    private const JSON_NUMBER = 'a JSON number past 2^53 - 1 is a rule of the JSON text; an object holds the exact integer';
    /** Cases with no object form: the value cannot be held, or the codec changes it. */
    private const NOT_OBJECTS = [
        'item.weight=2^63 ticks' => 'a Weight cannot hold more than PHP_INT_MAX ticks',
        'item.max_top_load=2^63 ticks' => 'a Weight cannot hold more than PHP_INT_MAX ticks',
        'container.tare_weight=2^63 ticks' => 'a Weight cannot hold more than PHP_INT_MAX ticks',
        'container.max_payload=2^63 ticks' => 'a Weight cannot hold more than PHP_INT_MAX ticks',
        'container.max_stack_density=2^63 ticks' => 'a Weight cannot hold more than PHP_INT_MAX ticks',
        'container.made_to_measure.tare_per_square_metre=2^63 ticks' => 'a Weight cannot hold more than PHP_INT_MAX ticks',
        'obstacle-origin-false' => 'ArrayCodec::point takes an array, not a boolean',
        'obstacle-origin-zero' => 'ArrayCodec::point takes an array, not a number',
        'obstacle-origin-nonempty-list' => 'the private codec reads a coordinate list as a different, valid Point at the origin',
        'obstacle-origin-empty-string' => 'ArrayCodec::point takes an array, not text',
        'obstacle-additional-origin-false' => 'ArrayCodec::point takes an array, not a boolean',
        'obstacle-additional-origin-zero' => 'ArrayCodec::point takes an array, not a number',
        'obstacle-additional-origin-nonempty-list' => 'the private codec reads a coordinate list as a different, valid Point at the origin',
        'obstacle-additional-origin-empty-string' => 'ArrayCodec::point takes an array, not text',
        'request-not-object' => 'a request that is not an object has no objects',
        'items-missing' => 'Packer::pack takes the items as an argument',
        'items-not-list' => 'Packer::pack takes the items as a list of Item',
        'item-not-object' => 'an item that is not an object is not an Item',
        'containers-missing' => 'Packer::pack takes the containers as an argument',
        'configuration-not-object' => 'a configuration that is not an object is not a PackingConfig',
        'effort-not-object' => 'an effort budget that is not an object is not an EffortBudget',
        'container-made-to-measure-not-object' => 'a machine that is not an object is not a MadeToMeasure',
        'container-deformable-boundary-not-object' => 'the object holds the bag\'s kind, not a block',
        'container-deformable-boundary-kind-missing' => 'the object holds the bag\'s kind, not a block',
        'item-id-missing' => 'Item requires an id',
        'item-dimensions-missing' => 'Item requires dimensions',
        'item-side-missing' => 'Dimensions requires all three sides',
        'container-made-to-measure-minimum-missing' => 'MadeToMeasure requires a minimum',
        'container-made-to-measure-increment-missing' => 'MadeToMeasure requires an increment',
        'container-pallet-overhang-limit-not-object' => 'a limit that is not an object is not an OverhangLimit',
        'container-pallet-overhang-limit-length-missing' => 'OverhangLimit requires a length',
        'container-pallet-overhang-limit-width-missing' => 'OverhangLimit requires a width',
        'container-pallet-overhang-limit-key-unknown' => self::UNKNOWN_KEY,
        'rate-brackets-not-list' => 'RateTable holds its brackets as a list',
        'solver-profile-unknown' => 'SolverProfile has no such case',
        'configuration-key-unknown-before-bad-value' => 'SolverProfile has no such case, and ' . self::UNKNOWN_KEY,
        'configuration-key-unknown' => self::UNKNOWN_KEY,
        'configuration-key-unknown-null' => self::UNKNOWN_KEY,
        'configuration-key-unknown-escaped' => self::UNKNOWN_KEY,
        'configuration-keys-unknown-first-in-code-point-order' => self::UNKNOWN_KEY,
        'effort-key-unknown' => self::UNKNOWN_KEY,
        'container-deformable-boundary-key-unknown' => self::UNKNOWN_KEY,
        'container-gusseted-bag-key-unknown' => self::UNKNOWN_KEY,
        'an-unknown-boundary-key-before-a-missing-gusset' => self::UNKNOWN_KEY,
        'container-made-to-measure-key-unknown' => self::UNKNOWN_KEY,
        'unknown-configuration-key-before-its-values' => self::UNKNOWN_KEY,
        'unsupported-before-units' => 'an unsupported field has no object form',
        'unsupported-before-an-item' => 'an unsupported field has no object form',
        'container-flat-bag-with-access-directions' => 'an empty list is how an object leaves the field out',
        'item-id-number' => self::COERCED,
        'item-quantity-text' => self::COERCED,
        'container-access-directions-not-list' => self::COERCED,
        'effort-limit-text' => self::TYPED,
        'item-accepts-stacking-groups-not-list' => self::TYPED,
        'item-side-boolean' => self::TYPED,
        'container-gusseted-bag-side-gusset-depth-boolean' => self::TYPED,
        'item-side-fraction' => self::TYPED,
        'item-stacking-group-number' => self::TYPED,
        'fixed-position-unparsable' => 'a Point holds integer ticks, not text',
        'fixed-placement-id-not-a-string' => self::TYPED,
        // A length past a native int has no `Length` to hold it.
        'item-side-whole-beyond-exact' => self::TYPED,
        'item-side-negative-beyond-exact' => self::TYPED,
        'item-side-beyond-native-integers' => self::TYPED,
        'item-side-vast-mixed-fraction' => self::TYPED,
        'item-side-vast-with-a-unit-suffix' => self::TYPED,
        'obstacle-additional-boxes-not-list' => 'an Obstacle holds its additional boxes as a list',
        'fixed_placements.position.x="-1"' => self::NEGATIVE_POSITION,
        'fixed_placements.position.x=-1' => self::NEGATIVE_POSITION,
        'fixed_placements.position.y="-1"' => self::NEGATIVE_POSITION,
        'fixed_placements.position.y=-1' => self::NEGATIVE_POSITION,
        'fixed_placements.position.z="-1"' => self::NEGATIVE_POSITION,
        'fixed_placements.position.z=-1' => self::NEGATIVE_POSITION,
        // A closed vocabulary the object holds as an enum case cannot hold a stranger.
        'item-allowed-rotations-not-list' => self::TYPED,
        'item-allowed-rotation-unknown' => 'Rotation has no such case',
        'item-allowed-rotation-lowercase' => 'Rotation has no such case',
        'item-shape-type-unknown' => 'ShapeType has no such case',
        'fixed-orientation-unknown' => 'Rotation has no such case',
        'item-allowed-rotation-reflection-on-a-hull' => 'Rotation has no such case',
        'item-allowed-rotation-legacy-spelled-signed-on-a-hull' => 'Rotation has no such case',
        'item-allowed-rotation-signed-beside-an-unknown-shape' => 'ShapeType has no such case',
        'fixed-orientation-reflection-on-a-hull' => 'Rotation has no such case',
        'configuration-solvers-not-list' => self::TYPED,
        'output-not-object' => self::OUTPUT,
        'output-length-unit-unknown' => self::OUTPUT,
        'output-weight-unit-unknown' => self::OUTPUT,
        'output-before-configuration' => self::OUTPUT,
        'output-before-currency' => self::OUTPUT,
        // A currency is held as `?string`; a number or a list has no object form.
        'currency-number' => self::TYPED,
        'currency-list' => self::TYPED,
        'rate-table-currency-number' => self::TYPED,
        // The request's length unit is an argument of each measure, not an object of its own.
        'units-not-object' => self::UNITS,
        'units-list' => self::UNITS,
        'units-weight' => self::UNITS,
        'units-unknown-key' => self::UNITS,
        'units-unknown-keys-first-in-code-point-order' => self::UNITS,
        'units-unknown-key-null' => self::UNITS,
        'unknown-units-key-before-its-length' => self::UNITS,
        'units-length-object' => self::TYPED,
        'units-length-list' => self::TYPED,
        // A bool property cannot hold anything else; the codec casts what it reads.
        'item-keep-upright-text' => self::COERCED,
        'item-keep-upright-text-true' => self::COERCED,
        'item-keep-upright-zero' => self::COERCED,
        'item-keep-upright-one' => self::COERCED,
        'item-keep-upright-list' => self::COERCED,
        'item-stackable-text' => self::COERCED,
        'item-stackable-text-true' => self::COERCED,
        'item-stackable-zero' => self::COERCED,
        'item-stackable-one' => self::COERCED,
        'item-stackable-list' => self::COERCED,
        'item-must-be-on-floor-text' => self::COERCED,
        'item-must-be-on-floor-text-true' => self::COERCED,
        'item-must-be-on-floor-zero' => self::COERCED,
        'item-must-be-on-floor-one' => self::COERCED,
        'item-must-be-on-floor-list' => self::COERCED,
        'configuration-require-placement-coordinates-text' => self::COERCED,
        'configuration-require-placement-coordinates-text-true' => self::COERCED,
        'configuration-require-placement-coordinates-zero' => self::COERCED,
        'configuration-require-placement-coordinates-one' => self::COERCED,
        'configuration-require-placement-coordinates-list' => self::COERCED,
        'item-booleans-in-schema-order' => self::COERCED,
        'configuration-boolean-before-items' => self::COERCED,
    ];

    /** Cases the object path answers differently on purpose (docs/ERRORS.md). */
    private const DIFFERENT_ON_PURPOSE = [
        'configuration-objective-unknown' => 'an unknown objective keeps UnknownObjectiveException',
        'configuration-solver-unknown' => 'an unknown solver keeps UnknownSolverException',
        'container-access-direction-unknown' => 'an unknown direction keeps InvalidDirectionError',
        'item-accepts-stacking-groups-entry-empty' => 'a set\'s index counts in code-point order',
        'an-item-id-before-its-quantity' => self::CONSTRUCTOR_FIRST,
        'item-ids-before-containers' => self::CONSTRUCTOR_FIRST,
        'container-ids-before-fixed-placements' => self::CONSTRUCTOR_FIRST,
        'currency-before-configuration' => self::CONSTRUCTOR_FIRST,
        'item-boolean-before-empty-rotations' => self::CONSTRUCTOR_FIRST,
        'item.nesting_height=2^53 ticks' => self::CEILING_SECOND,
        'container.made_to_measure.minimum.length=2^53 ticks' => self::CEILING_SECOND,
        'container.made_to_measure.minimum.width=2^53 ticks' => self::CEILING_SECOND,
        'container.made_to_measure.minimum.height=2^53 ticks' => self::CEILING_SECOND,
        'container.made_to_measure.increment.length=2^53 ticks' => self::CEILING_SECOND,
        'container.made_to_measure.increment.height=2^53 ticks' => self::CEILING_SECOND,
        'container.obstacles.origin.x=2^53 ticks' => self::CEILING_SECOND,
        'container.obstacles.origin.y=2^53 ticks' => self::CEILING_SECOND,
        'container.obstacles.origin.z=2^53 ticks' => self::CEILING_SECOND,
        'container.obstacles.dimensions.length=2^53 ticks' => self::CEILING_SECOND,
        'container-gusseted-bag-side-gusset-depth-past-the-ceiling' => self::CEILING_SECOND,
        'configuration-boolean-before-effort-budget' => 'two faults: the EffortBudget is built, and refuses its own '
            . 'value, before the PackingConfig that holds it',
        'item.weight=2^53 as a number' => self::JSON_NUMBER,
        'item.max_top_load=2^53 as a number' => self::JSON_NUMBER,
        'container.tare_weight=2^53 as a number' => self::JSON_NUMBER,
        'container.max_payload=2^53 as a number' => self::JSON_NUMBER,
        'container.max_stack_density=2^53 as a number' => self::JSON_NUMBER,
        'container.made_to_measure.tare_per_square_metre=2^53 as a number' => self::JSON_NUMBER,
    ];

    /** Pinned, so the skip set cannot grow without this line changing too. */
    private const SKIPPED = 148;

    public static function testTheSkipSetIsPinnedAndNamesOnlyCorpusCases(): void
    {
        $names = \array_keys(self::corpus());
        self::assertSame([], \array_intersect_key(self::NOT_OBJECTS, self::DIFFERENT_ON_PURPOSE));
        self::assertSame([], \array_diff(\array_keys(self::skips()), $names), 'a skip names no corpus case');
        self::assertCount(self::SKIPPED, self::skips());
        self::assertGreaterThan(100, \count($names) - self::SKIPPED, 'the corpus quietly emptied itself');
    }

    public static function testTheObjectPathRefusesAsTheJsonPathDoes(): void
    {
        $differences = [];
        foreach (self::corpus() as $name => $request) {
            if (isset(self::skips()[$name])) {
                continue;
            }
            [$json, $objects, $stage] = self::outcomes($request);
            if (!self::sameRefusal($json, $objects, $stage)) {
                $differences[] = "{$name}: json " . self::describe($json) . "; objects at {$stage} " . self::describe($objects);
            }
        }
        self::assertTrue($differences === [], \implode("\n", $differences));
    }

    public static function testASkippedCaseStillDiffers(): void
    {
        $matching = [];
        foreach (self::corpus() as $name => $request) {
            if (!isset(self::skips()[$name])) {
                continue;
            }
            [$json, $objects, $stage] = self::outcomes($request);
            if (self::sameRefusal($json, $objects, $stage)) {
                $matching[] = $name;
            }
        }
        self::assertTrue($matching === [], 'a skip that matches has been fixed, and must leave its list: '
            . \implode(', ', $matching));
    }

    /** @return array<string,mixed> case name => request */
    private static function corpus(): array
    {
        // A cross-language fixture kept one level above this package; a published copy does
        // not carry it.
        $path = \dirname(__DIR__, 2) . '/conformance/request_errors/corpus.json';
        if (!\is_file($path)) {
            self::skip('the shared request-error corpus is not part of this package');
        }
        $decoded = \json_decode((string)\file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('packvium-request-error-corpus/v1', $decoded['format']);
        $cases = [];
        foreach ($decoded['cases'] as $case) {
            $cases[$case['name']] = $case['request'];
        }
        return $cases;
    }

    /** @return array<string,string> */
    private static function skips(): array
    {
        return self::NOT_OBJECTS + self::DIFFERENT_ON_PURPOSE;
    }

    /**
     * The JSON path's refusal, the object path's outcome, and where the object path ended.
     *
     * @param mixed $request
     * @return array{0:Throwable,1:?Throwable,2:string}
     */
    private static function outcomes($request): array
    {
        $json = null;
        try {
            ArrayCodec::pack($request);
        } catch (Throwable $error) {
            $json = $error;
        }
        self::assertNotNull($json, 'the JSON path answered a malformed request');
        // A key the codec reads and the request lacks is a warning to PHP; here it means the
        // objects cannot be built, so it is thrown like every other failure to build them.
        \set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            [$config, $items, $containers, $fixed] = self::objects($request);
        } catch (Throwable $error) {
            // What the objects could not be built from is the finding.
            return [$json, $error, 'constructor'];
        } finally {
            \restore_error_handler();
        }
        try {
            (new Packer($config))->pack($items, $containers, $fixed, $request['currency'] ?? null);
        } catch (Throwable $error) {
            return [$json, $error, 'pack'];
        }
        return [$json, null, 'pack'];
    }

    /**
     * `ArrayCodec`'s model without the JSON rules: its builders, and its fixed placements
     * without `FixedPlacementShape` in front of them.
     *
     * @param mixed $request
     * @return array{0:mixed,1:mixed,2:mixed,3:list<FixedPlacement>}
     */
    private static function objects($request): array
    {
        $data = $request;
        unset($data['fixed_placements']);
        [$config, , , $items, $containers] = self::codec('model', $data);
        $unit = (string)($request['units']['length'] ?? 'mm');
        $fixed = [];
        foreach ($request['fixed_placements'] ?? [] as $raw) {
            $position = self::codec('point', $raw['position'] ?? [], $unit);
            $fixed[] = new FixedPlacement($raw['item_type'], $raw['container_type'], $position,
                Rotation::from($raw['orientation']), $raw['container_instance'] ?? 1, $raw['placement_id'] ?? null);
        }
        return [$config, $items, $containers, $fixed];
    }

    /** @return mixed */
    private static function codec(string $method, ...$arguments)
    {
        $builder = new ReflectionMethod(ArrayCodec::class, $method);
        $builder->setAccessible(true);
        return $builder->invoke(null, ...$arguments);
    }

    private static function sameRefusal(Throwable $json, ?Throwable $objects, string $stage): bool
    {
        if (!$objects instanceof InvalidRequestException || !$json instanceof InvalidRequestException
            || !\is_a($objects, \get_class($json))) {
            return false;
        }
        if ([$objects->errorCode(), $objects->reason()] !== [$json->errorCode(), $json->reason()]) {
            return false;
        }
        if ($stage === 'constructor') {
            return $objects->field() === '';
        }
        return [$objects->field(), $objects->getMessage()] === [$json->field(), $json->getMessage()];
    }

    private static function describe(?Throwable $error): string
    {
        if ($error === null) {
            return 'accepted';
        }
        if ($error instanceof InvalidRequestException) {
            return \sprintf('%s(%s, %s, %s, %s)', \get_class($error), $error->errorCode(), $error->reason(),
                $error->field(), $error->getMessage());
        }
        return \get_class($error) . ': ' . $error->getMessage();
    }
}
