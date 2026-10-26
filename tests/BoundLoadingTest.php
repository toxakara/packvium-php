<?php
declare(strict_types=1);
namespace Packvium\Tests;

/** The pre-compute refusals must not compile the numeric bound implementation. */
final class BoundLoadingTest extends TestCase
{
    /** @return array{loaded:bool,bound:?array} */
    private static function probe(string $case): array
    {
        $script = <<<'PHP'
require 'autoload.php';
$item = Packvium\Domain\Item::create('i', Packvium\Domain\Dimensions::mm(500, 500, 500));
$container = Packvium\Domain\Container::create('c', Packvium\Domain\Dimensions::mm(100, 100, 100));
$case = $argv[1];
if ($case === 'machine') {
    $container = new Packvium\Domain\Container('m', Packvium\Domain\Dimensions::mm(100, 100, 100),
        madeToMeasure: new Packvium\Domain\MadeToMeasure([800000, 800000, 800000], [400000, 400000, 400000]));
}
$config = new Packvium\Config\PackingConfig(objective: $case === 'unsupported' ? 'maximum_volume' : 'default');
$scorer = $case === 'custom' ? new Packvium\Objective\DefaultSolutionScorer() : null;
$result = (new Packvium\Packer($config, solutionScorer: $scorer))->pack([$item], [$container])->toArray();
$loaded = false;
foreach (get_included_files() as $file) {
    if (basename($file) === 'ObjectiveBounds.php') { $loaded = true; }
}
echo json_encode(['loaded' => $loaded, 'bound' => $result['optimality']['lower_bound'] ?? null]);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        self::assertTrue(is_resource($process));
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);
        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function testAnUnsupportedObjectiveDoesNotLoadTheBound(): void
    {
        self::assertSame(['loaded' => false, 'bound' => null], self::probe('unsupported'));
    }

    public static function testAMachineDoesNotLoadTheBound(): void
    {
        self::assertSame(['loaded' => false, 'bound' => null], self::probe('machine'));
    }

    public static function testACustomScorerDoesNotLoadTheBound(): void
    {
        self::assertSame(['loaded' => false, 'bound' => null], self::probe('custom'));
    }

    public static function testAnUnpackableRequestStillStatesItsBound(): void
    {
        self::assertSame(['loaded' => true, 'bound' => [0]], self::probe('impossible'));
    }
}
