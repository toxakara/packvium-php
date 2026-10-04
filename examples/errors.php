<?php
/**
 * Turn a refused request into a useful answer, the way a web handler would.
 *
 * Run it:
 *
 *     php examples/errors.php
 *
 * A packing service takes JSON from a caller it does not control. Most failures are the
 * caller's: a quantity of zero, a negative width, a unit nobody has heard of. Those throw
 * `InvalidRequestException` before anything is solved -- an `InvalidArgumentException`,
 * so existing handlers still catch it -- with three things a program can use:
 *
 * - `reason()`: one of a closed set (`missing_field`, `wrong_type`, `below_minimum`, ...),
 *   the thing to branch on;
 * - `field()`: a JSON Pointer to the bad value in the request the caller sent, such as
 *   `/items/0/quantity`;
 * - `getMessage()`: `invalid_request: <field>: <detail>`, the same text in every Packvium
 *   engine, fit for a log line.
 *
 * `FixedPlacementException` is a subclass for items already in place that cannot be where
 * the request says. And a request that is valid but does not fit completely is not an
 * error at all: it is a result with `unpacked_items`.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\{ArrayCodec, InvalidRequestException};
use Packvium\Validation\FixedPlacementException;

$order = [
    'units' => ['length' => 'mm'],
    'configuration' => ['time_limit_ms' => 60000, 'effort_budget' => ['max_restarts' => 4]],
    'items' => [
        ['id' => 'mug', 'quantity' => 2, 'weight' => '380 g',
         'dimensions' => ['length' => '100', 'width' => '100', 'height' => '120']],
        ['id' => 'teapot', 'quantity' => 1, 'weight' => '1.2 kg',
         'dimensions' => ['length' => '220', 'width' => '160', 'height' => '180']],
    ],
    'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '300', 'width' => '250', 'height' => '200']]],
];

/**
 * Every value along a JSON Pointer, root first, so a message can name the item by id.
 *
 * @param mixed $document
 * @return list<mixed>
 */
function resolve($document, string $pointer): array
{
    $values = [$document];
    foreach (array_slice(explode('/', $pointer), 1) as $token) {
        $key = str_replace(['~1', '~0'], ['/', '~'], $token);
        $current = end($values);
        if (!is_array($current) || !array_key_exists($key, $current)) {
            break;
        }
        $values[] = $current[$key];
    }
    return $values;
}

/**
 * `/items/1/dimensions/width` becomes `item "teapot", dimensions/width`.
 *
 * The pointer is exact but written for programs. People know their items by id, so the
 * nearest object that has one names the place, and the rest of the pointer says which
 * value in it.
 */
function where(array $request, string $pointer): string
{
    $tokens = array_slice(explode('/', $pointer), 1);
    $values = resolve($request, $pointer);
    for ($depth = count($values) - 1; $depth > 0; $depth--) {
        $value = $values[$depth];
        if (is_array($value) && isset($value['id'])) {
            $kind = rtrim($tokens[$depth - 2], 's');
            $rest = implode('/', array_slice($tokens, $depth));
            return sprintf('%s "%s"', $kind, $value['id']) . ($rest === '' ? '' : ", {$rest}");
        }
    }
    return $pointer === '' ? 'the request' : $pointer;
}

/**
 * What a POST /pack endpoint would do: a status code and a JSON body.
 *
 * @return array{0:int,1:array<string,mixed>}
 */
function handle(string $body): array
{
    $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    try {
        $result = ArrayCodec::pack($request);
    } catch (FixedPlacementException $error) {
        // A subclass of InvalidRequestException, so it must be caught first. Its detail
        // names the physical problem (a collision, too much weight), which is what a dock
        // operator needs to hear.
        return [422, ['code' => $error->errorCode(), 'reason' => $error->reason(), 'field' => $error->field(),
            'message' => "The items already loaded cannot stay as recorded: {$error->detail()}."]];
    } catch (InvalidRequestException $error) {
        return [422, ['code' => $error->errorCode(), 'reason' => $error->reason(), 'field' => $error->field(),
            'message' => 'Please check ' . where($request, $error->field()) . ": {$error->detail()}."]];
    }
    // Not an error: the caller gets the plan, and a clear list of what is not in it.
    $leftOut = array_column($result['unpacked_items'], 'item_id');
    return [200, ['status' => $result['status'], 'boxes' => count($result['containers']), 'left_out' => $leftOut]];
}

/**
 * JSON with a space after each separator, the way the other Packvium examples print it.
 *
 * @param mixed $value
 */
function spaced($value): string
{
    if (!is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
        return '[' . implode(', ', array_map('spaced', $value)) . ']';
    }
    $pairs = [];
    foreach ($value as $key => $entry) {
        $pairs[] = spaced((string)$key) . ': ' . spaced($entry);
    }
    return '{' . implode(', ', $pairs) . '}';
}

function show(string $label, array $request): void
{
    [$status, $body] = handle(json_encode($request, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    echo "  {$label}\n";
    echo "    HTTP {$status}  ", spaced($body), "\n";
}

/** A copy of `$request` with `$change` applied to it. */
function broken(array $request, callable $change): array
{
    $change($request);
    return $request;
}

function section(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

// --------------------------------------------------------------------------------------
section('1. Refused before solving: one reason, one pointer, one message');

show('a valid order', $order);
show('quantity 0',
    broken($order, static function (array &$r): void { $r['items'][0]['quantity'] = 0; }));
show('a negative width',
    broken($order, static function (array &$r): void { $r['items'][1]['dimensions']['width'] = '-160'; }));
show('a missing height',
    broken($order, static function (array &$r): void { unset($r['items'][1]['dimensions']['height']); }));
show('a float where a measure belongs',
    broken($order, static function (array &$r): void { $r['items'][1]['dimensions']['width'] = 160.5; }));
show('the same container id twice',
    broken($order, static function (array &$r): void { $r['containers'][] = $r['containers'][0]; }));
show('an unknown solver profile',
    broken($order, static function (array &$r): void { $r['configuration']['solver_profile'] = 'turbo'; }));
echo '
  A float is refused as a measure on purpose: binary floating point cannot hold most
  decimal lengths exactly, and every length here is exact. Send "160.5" as a string.
  When several values are wrong, the first is reported, in a fixed order every
  engine shares: units, configuration, items, containers, fixed placements.
';

// --------------------------------------------------------------------------------------
section('2. Items already in place that cannot be there');

// Turned (`WLH`: its width along the box's length) so the mugs still fit beside it.
$onBoard = ['item_type' => 'teapot', 'container_type' => 'box', 'orientation' => 'WLH',
    'position' => ['x' => '0', 'y' => '0', 'z' => '0']];
show('a teapot already in the box, packed around',
    broken($order, static function (array &$r) use ($onBoard): void { $r['fixed_placements'] = [$onBoard]; }));
show('a teapot recorded at x = 200 mm, hanging out of a 300 mm box',
    broken($order, static function (array &$r) use ($onBoard): void {
        $r['fixed_placements'] = [array_merge($onBoard, ['position' => ['x' => '200']])];
    }));
show('a mug and the teapot recorded in the same place',
    broken($order, static function (array &$r) use ($onBoard): void {
        $r['fixed_placements'] = [$onBoard, ['item_type' => 'mug', 'container_type' => 'box', 'orientation' => 'LWH']];
    }));
show('an orientation code that does not exist',
    broken($order, static function (array &$r) use ($onBoard): void {
        $r['fixed_placements'] = [array_merge($onBoard, ['orientation' => 'XYZ'])];
    }));
echo '
  `cannot_hold` means the fixed set is well formed but is not a valid packing on its
  own, and `field` is the whole list. `malformed` points at the one bad value.
';

// --------------------------------------------------------------------------------------
section('3. Not an error: a valid order that does not fit');

show('a teapot too tall for any box',
    broken($order, static function (array &$r): void { $r['items'][1]['dimensions']['height'] = '450'; }));
echo '
  The caller asked a fair question and got an answer: the rest of the order is
  packed, and `unpacked_items` says what is not and why. Retrying it will not help,
  and neither will treating it as a 4xx.
';

// --------------------------------------------------------------------------------------
section('4. Two refusals that do not carry a pointer');

foreach ([
    'an unknown objective' => static function (array &$r): void { $r['configuration']['objective'] = 'cheapest'; },
    'an unknown door' => static function (array &$r): void { $r['containers'][0]['access_directions'] = ['rear']; },
] as $label => $change) {
    try {
        ArrayCodec::pack(broken($order, $change));
    } catch (InvalidRequestException $error) {
        printf("  %s: InvalidRequestException, reason %s, field \"%s\": %s\n",
            $label, $error->reason(), $error->field(), substr($error->getMessage(), 0, 50));
    } catch (InvalidArgumentException $error) {
        $class = substr(strrchr(get_class($error), '\\') ?: get_class($error), 1);
        printf("  %s: %s: %s\n", $label, $class, substr($error->getMessage(), 0, 70));
    }
}
echo '
  Both are `InvalidArgumentException`s, so a handler that ends with
  `catch (InvalidArgumentException $e)` still turns them into a 422 -- but neither
  has a field to highlight. The objective is refused by the objective registry with
  a message only; the door reaches the request error with reason `invalid_value` and
  an empty pointer, which means the request as a whole.
';
