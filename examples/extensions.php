<?php
/**
 * Extension points: rules the request has no field for.
 *
 * Run it:
 *
 *     php examples/extensions.php
 *
 * Most packing rules are already fields on `Item` and `Container` -- see constraints.php.
 * This example is about the rules that are not, and it comes with an honest warning about
 * what you give up by writing one.
 *
 * **An extension point is an in-process, one-language interface.** A custom constraint or
 * scorer has no representation in the JSON request, so an engine driven over JSON cannot
 * see it, and nothing can check that the Python, Rust and JavaScript engines agree about
 * it. Use one to specialise a single PHP application. A rule that must hold for every
 * caller of every engine belongs in the request as data -- a `policy` rule, a tag, a field.
 *
 * Three extension points are shown, each registered without touching the solver:
 *
 * - a `PlacementConstraint`, through `ExtensionRegistry`, refuses candidate positions;
 * - a `SolutionScorer`, passed to `Packer`, ranks complete solutions;
 * - a `ContainerSelector`, through `ExtensionRegistry`, decides which container type a
 *   multi-container search opens next.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Algorithm\{EffortBudget, RawSolution, SingleContainerSolution};
use Packvium\Config\{PackingConfig, SolverProfile};
use Packvium\Constraint\{ConstraintContext, ConstraintResult, PlacementConstraint};
use Packvium\Domain\{Container, Dimensions, Item, Rotation};
use Packvium\Extension\{ContainerSelector, DefaultContainerSelector, ExtensionRegistry};
use Packvium\Objective\{DefaultSolutionScorer, ObjectiveScore, SolutionScorer};
use Packvium\Packer;
use Packvium\Unit\Length;

// The interfaces take a few types that live in `Packvium\Algorithm` (`RawSolution`,
// `SingleContainerSolution`, `EffortBudget`). Implementing an interface means naming them;
// read only what is shown here from them, since that namespace is not frozen like the rest.

/**
 * Counted work, not a clock, bounds every solve here, so the example prints the same on
 * every machine. The time limit is only a fuse, far above what these solves need.
 */
function config(): PackingConfig
{
    return new PackingConfig(SolverProfile::Balanced, timeLimitMs: 60000, effortBudget: new EffortBudget(null, null, 20000));
}

$anyOrientation = [Rotation::LWH, Rotation::LHW, Rotation::WLH, Rotation::WHL, Rotation::HLW, Rotation::HWL];

// -----------------------------------------------------------------------------------
// A custom placement constraint. `maxTopLoad` caps what may rest on an item and
// `mustBeOnFloor` pins one to the bottom -- but neither says "nothing fragile above waist
// height, because that is where it gets knocked off a trolley". That is a real warehouse
// rule with no field, so it is a constraint.
//
// A constraint answers about *one candidate position*. It never searches; it is asked many
// thousands of times per solve, so keep it O(1) in the number of placements where you can.
// This one is: it looks at the candidate's z coordinate and nothing else.
// -----------------------------------------------------------------------------------
final class FragileHeightLimit implements PlacementConstraint
{
    public function __construct(private Length $limit, private string $tag = 'fragile')
    {
    }

    public function evaluate(ConstraintContext $context): ConstraintResult
    {
        if (!in_array($this->tag, $context->item->item->tags, true)) {
            return ConstraintResult::allow();
        }
        // `Point` carries raw tick counts, not `Length` objects: it is built once per
        // candidate and this is the hot path.
        if ($context->point->z <= $this->limit->ticks) {
            return ConstraintResult::allow();
        }
        // The code is yours. It travels into the unpacked reason, so make it something a
        // person reading a failed order will understand.
        return ConstraintResult::reject(
            'fragile_too_high',
            sprintf('base at %smm is above the %smm limit for %s items',
                (new Length($context->point->z))->decimal('mm'), $this->limit->decimal('mm'), $this->tag)
        );
    }
}

// The footprint is only as wide as one crate, so the column has to grow upwards and the
// rule has something to refuse. A rule that never fires teaches nothing.
$items = [
    Item::create('crate', Dimensions::mm('400', '400', '300'), '12 kg', quantity: 3),
    Item::create('vase', Dimensions::mm('400', '400', '200'), '2 kg', 2, $anyOrientation, tags: ['fragile']),
];
$column = [Container::create('column', Dimensions::mm('400', '400', '1300'), maxPayload: '200 kg', quantity: 1)];

$vaseHeights = static function (array $containers): array {
    $heights = [];
    foreach ($containers as $container) {
        foreach ($container->placements as $placement) {
            if ($placement->instance->item->id === 'vase') {
                $heights[] = (new Length($placement->position->z))->decimal('mm');
            }
        }
    }
    sort($heights, SORT_NUMERIC);
    return $heights;
};

$unrestricted = (new Packer(config()))->pack($items, $column);
$heights = $vaseHeights($unrestricted->containers);
echo 'without the rule, the highest vase sits at ', end($heights), " mm\n";

$restricted = (new Packer(config(), new ExtensionRegistry([new FragileHeightLimit(Length::mm('400'))])))->pack($items, $column);
printf("with a 400mm limit, vases sit at %s and %d were left behind\n",
    json_encode($vaseHeights($restricted->containers)), count($restricted->unpacked));

// -----------------------------------------------------------------------------------
// A custom solution scorer. The six built-in objectives rank by space, packaging cost,
// billed weight, landed money, stack height or value. None of them cares whether the
// weight is spread *evenly across the containers* -- which is what a two-person lift or a
// van's balance depends on, and is nowhere in the request.
//
// Return exact integers, lower is better, compared left to right. Keep the unpacked count
// first unless you really mean "leave items behind to score better", and fall back to the
// default vector for everything your rule does not care about -- otherwise two equally
// balanced solutions are ranked by luck.
// -----------------------------------------------------------------------------------
final class EvenlyLoadedContainers implements SolutionScorer
{
    public function score(RawSolution $solution): ObjectiveScore
    {
        $loads = array_map(static fn($container): int => $container->payloadWeight()->ticks, $solution->containers);
        $spread = $loads === [] ? 0 : max($loads) - min($loads);
        $default = (new DefaultSolutionScorer())->score($solution)->components;
        return new ObjectiveScore(array_merge([count($solution->unpacked), $spread], array_slice($default, 1)));
    }
}

// Two heavy items and two light ones, two boxes, two slots each. Every arrangement uses the
// same volume, so the built-in objectives are indifferent -- and put both anvils in one box.
$lopsided = [
    Item::create('anvil', Dimensions::mm('200', '200', '200'), '10 kg', quantity: 2),
    Item::create('pillow', Dimensions::mm('200', '200', '200'), '1 kg', quantity: 2),
];
$twoBoxes = [Container::create('box', Dimensions::mm('400', '200', '200'), maxPayload: '50 kg', quantity: 2)];

echo "\n";
foreach (['default' => null, 'evenly loaded' => new EvenlyLoadedContainers()] as $label => $scorer) {
    $result = (new Packer(config(), new ExtensionRegistry(), $scorer))->pack($lopsided, $twoBoxes);
    $contents = [];
    $weights = [];
    foreach ($result->containers as $container) {
        $ids = array_map(static fn($placement): string => $placement->instance->item->id, $container->placements);
        sort($ids);
        $contents[] = $ids;
        $weights[] = $container->payloadWeight()->decimal('kg') . ' kg';
    }
    printf("%15s: %s  ->  %s\n", $label, json_encode($contents), json_encode($weights));
}

// -----------------------------------------------------------------------------------
// A custom container selector. When several container types are offered, the search asks
// the selector which one to open next, comparing each type's best single-container fill.
// The default opens whichever holds the most items, then the cheapest. This one keeps
// "most items" first -- drop it and the search happily opens box after box that each
// hold one item -- and then prefers the *smallest* box, however much it costs, because
// shipping air costs more than cardboard.
// -----------------------------------------------------------------------------------
final class SmallestBoxFirst implements ContainerSelector
{
    public function score(Container $container, SingleContainerSolution $solution): array
    {
        $default = (new DefaultContainerSelector())->score($container, $solution);
        // Whole cubic millimetres are plenty to rank boxes by and stay inside an int;
        // the exact volume in ticks does not.
        $size = $container->innerDimensions;
        $cubicMm = intdiv($size->length->ticks, Length::TICKS_PER_MM)
            * intdiv($size->width->ticks, Length::TICKS_PER_MM)
            * intdiv($size->height->ticks, Length::TICKS_PER_MM);
        return [$default[0], $cubicMm, $container->id];
    }
}

$books = [Item::create('book', Dimensions::mm('210', '140', '30'), '450 g', quantity: 4)];
$cartons = [
    Container::create('mailer', Dimensions::mm('220', '150', '130'), costMinor: 190),
    Container::create('carton', Dimensions::mm('400', '300', '250'), costMinor: 120),
];

echo "\n";
foreach (['default' => null, 'smallest box first' => new SmallestBoxFirst()] as $label => $selector) {
    $registry = new ExtensionRegistry([], [], [], null, $selector);
    $result = (new Packer(config(), $registry))->pack($books, $cartons);
    $opened = array_map(static fn($container): string => $container->container->id, $result->containers);
    printf("%18s: opens %s, packaging cost %d\n", $label, json_encode($opened),
        array_sum(array_map(static fn($container): int => $container->container->costMinor, $result->containers)));
}

echo "\n";
echo "None of these rules exists in the JSON request. Hand the same request to another\n";
echo "Packvium engine and you get the unrestricted answer -- which is exactly why a rule\n";
echo "everyone must obey belongs in the request as data, not in a class.\n";
