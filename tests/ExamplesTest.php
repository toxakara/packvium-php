<?php
declare(strict_types=1);

namespace Packvium\Tests;

/**
 * Every shipped example actually runs.
 *
 * An example nobody executes is documentation that rots silently: the API moves, the
 * example keeps compiling in a reader's head, and the first person to paste it finds it
 * stopped working three releases ago. So the whole `examples/` directory is a test
 * target -- each file is run as a real subprocess, exactly the way the README tells a
 * reader to run it.
 *
 * The checks beyond "exit 0" are deliberate. An example that prints nothing has nothing
 * to teach, and one whose docblock does not say how to run it makes the reader guess.
 */
final class ExamplesTest extends TestCase
{
    /** @var array<string,array{code:int,out:string,err:string}> */
    private static array $runs = [];

    private static function package(): string
    {
        return dirname(__DIR__);
    }

    /** @return list<string> absolute paths, sorted */
    private static function examples(): array
    {
        $files = glob(self::package() . '/examples/*.php') ?: [];
        sort($files);
        return $files;
    }

    /** @return array{code:int,out:string,err:string} */
    private static function execute(string $path): array
    {
        $key = basename($path);
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, $path], $descriptors, $pipes, self::package());
        if (!is_resource($process)) {
            self::assertTrue(false, "could not start {$key}");
        }
        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return self::$runs[$key] = ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /**
     * Guards every test below: a glob that matched nothing would make them all pass
     * vacuously.
     */
    public static function testTheExamplesDirectoryIsNotEmpty(): void
    {
        self::assertTrue(self::examples() !== [], 'no examples found');
    }

    public static function testEveryExampleRunsToCompletion(): void
    {
        foreach (self::examples() as $path) {
            $run = self::execute($path);
            self::assertSame(0, $run['code'], basename($path) . " exited {$run['code']}:\n{$run['err']}");
        }
    }

    public static function testEveryExamplePrintsSomething(): void
    {
        foreach (self::examples() as $path) {
            self::assertTrue(trim(self::execute($path)['out']) !== '', basename($path) . ' produced no output');
        }
    }

    /**
     * Several examples deliberately catch and print a refusal -- that is the lesson. What
     * must not appear is an *uncaught* one.
     */
    public static function testNoExampleThrowsSomethingItDidNotMeanTo(): void
    {
        foreach (self::examples() as $path) {
            $run = self::execute($path);
            self::assertFalse(
                str_contains($run['err'] . $run['out'], 'Fatal error'),
                basename($path) . " raised a fatal error:\n{$run['err']}"
            );
        }
    }

    /**
     * This library promises the same answer for the same input, forever. An example whose
     * output moves between two runs is either demonstrating something it should not be,
     * or has found a determinism bug worth knowing about.
     *
     * Elapsed times are masked rather than asserted on -- they are the one part of a
     * result that is a fact about the machine instead of about the packing.
     */
    public static function testEveryExampleIsDeterministic(): void
    {
        foreach (self::examples() as $path) {
            $first = self::mask(self::execute($path)['out']);
            self::$runs = [];
            $second = self::mask(self::execute($path)['out']);
            self::assertSame($first, $second, basename($path) . ' printed different output on a second run');
        }
    }

    private static function mask(string $output): string
    {
        return preg_replace('/("duration_ms":\s*)\d+/', '$1<masked>', $output) ?? $output;
    }

    public static function testEveryExampleSaysHowToRunIt(): void
    {
        foreach (self::examples() as $path) {
            $source = file_get_contents($path) ?: '';
            $name = basename($path);
            self::assertTrue(str_contains($source, '/**'), "{$name} has no docblock");
            self::assertTrue(str_contains($source, 'Run it:'), "{$name} does not say how to run it");
            self::assertTrue(str_contains($source, "examples/{$name}"), "{$name}'s run instruction names another file");
        }
    }

    /**
     * A reader copies an example verbatim. If it reaches into an internal class, they
     * inherit a dependency on something free to change without notice.
     */
    public static function testNoExampleImportsAnInternalClass(): void
    {
        foreach (self::examples() as $path) {
            $source = file_get_contents($path) ?: '';
            preg_match_all('/^use\s+([^;]+);/m', $source, $matches);
            foreach ($matches[1] as $imported) {
                // `use function X` and `use const X` are the same import with a keyword
                // in front of them.
                $symbol = preg_replace('/^(function|const)\s+/', '', trim($imported)) ?? trim($imported);
                self::assertTrue(
                    str_starts_with($symbol, 'Packvium\\') || !str_contains($symbol, '\\'),
                    basename($path) . " imports {$symbol}"
                );
            }
        }
    }

    /** Examples that are not linked are examples nobody finds. */
    public static function testTheReadmeListsEveryExample(): void
    {
        $readme = file_get_contents(self::package() . '/README.md') ?: '';
        foreach (self::examples() as $path) {
            self::assertTrue(
                str_contains($readme, basename($path)),
                'README.md does not mention ' . basename($path)
            );
        }
    }
}
