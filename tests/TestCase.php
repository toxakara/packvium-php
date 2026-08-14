<?php
declare(strict_types=1);

namespace Packvium\Tests;

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Thrown by {@see TestCase::skip()} when a test cannot run here for a reason that is not
 * a defect — a fixture the surrounding project owns and a published copy of this package
 * does not carry. Counted separately from a failure, and never silently.
 */
final class SkippedTest extends RuntimeException
{
}

/**
 * The suite's own test harness.
 *
 * The library has no runtime dependencies and the tests deliberately have none either,
 * so this stands in for PHPUnit. It discovers every `test*` method on a suite, runs
 * each one independently and records the first failed assertion rather than aborting
 * the whole run — a single regression should not hide the twenty tests behind it.
 */
abstract class TestCase
{
    private static int $assertions = 0;
    private static int $tests = 0;

    /** @var array<string,int> suite short name => number of tests run */
    private static array $suites = [];

    /** @var list<array{name:string,message:string,where:string}> */
    private static array $failures = [];

    /** @var list<array{name:string,reason:string}> */
    private static array $skipped = [];

    public static function run(): void
    {
        $reflection = new ReflectionClass(static::class);
        $suite = $reflection->getShortName();
        self::$suites[$suite] = 0;

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }
            self::$tests++;
            self::$suites[$suite]++;
            try {
                $method->invoke(null);
            } catch (SkippedTest $skip) {
                self::$tests--;
                self::$suites[$suite]--;
                self::$skipped[] = [
                    'name' => $suite . '::' . $method->getName(),
                    'reason' => $skip->getMessage(),
                ];
            } catch (Throwable $error) {
                self::$failures[] = [
                    'name' => $suite . '::' . $method->getName(),
                    'message' => $error->getMessage(),
                    'where' => self::origin($error),
                ];
            }
        }
    }

    /** Prints the run summary and returns the process exit code. */
    public static function report(): int
    {
        foreach (self::$suites as $suite => $count) {
            printf("  %-22s %2d tests\n", $suite, $count);
        }
        foreach (self::$skipped as $skip) {
            printf("  SKIP %s — %s\n", $skip['name'], $skip['reason']);
        }
        if (self::$failures === []) {
            printf("OK — %d suites, %d tests, %d assertions%s\n",
                count(self::$suites), self::$tests, self::$assertions,
                self::$skipped === [] ? '' : sprintf(', %d skipped', count(self::$skipped)));
            return 0;
        }
        fwrite(STDERR, sprintf("\n%d FAILED:\n", count(self::$failures)));
        foreach (self::$failures as $failure) {
            fwrite(STDERR, sprintf("  %s\n    %s\n    at %s\n", $failure['name'], $failure['message'], $failure['where']));
        }
        return 1;
    }

    public static function assertions(): int
    {
        return self::$assertions;
    }

    /**
     * Abandon this test without failing it. Use only when the test cannot run *here* --
     * a fixture owned by the surrounding project and absent from a published copy of
     * this package -- never to duck a real failure.
     */
    protected static function skip(string $reason): never
    {
        throw new SkippedTest($reason);
    }

    // ------------------------------------------------------------------ assertions

    protected static function assertTrue(bool $value, string $message = 'Expected true'): void
    {
        self::$assertions++;
        if (!$value) {
            throw new RuntimeException($message);
        }
    }

    protected static function assertFalse(bool $value, string $message = 'Expected false'): void
    {
        self::assertTrue(!$value, $message);
    }

    /** Identity comparison: same type, same value, same order for arrays. */
    protected static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(self::describe($message, $expected, $actual));
        }
    }

    protected static function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($unexpected === $actual) {
            throw new RuntimeException($message !== '' ? $message : 'Expected a value other than ' . self::render($unexpected));
        }
    }

    protected static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected != $actual) {
            throw new RuntimeException(self::describe($message, $expected, $actual));
        }
    }

    protected static function assertCount(int $expected, array|\Countable $value, string $message = ''): void
    {
        self::$assertions++;
        if (count($value) !== $expected) {
            throw new RuntimeException(self::describe($message, $expected, count($value)));
        }
    }

    protected static function assertNull(mixed $value, string $message = 'Expected null'): void
    {
        self::assertTrue($value === null, $message);
    }

    protected static function assertNotNull(mixed $value, string $message = 'Expected a value, got null'): void
    {
        self::assertTrue($value !== null, $message);
    }

    protected static function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        self::$assertions++;
        if (!in_array($needle, $haystack, true)) {
            throw new RuntimeException($message !== ''
                ? $message
                : self::render($needle) . ' is not in ' . self::render($haystack));
        }
    }

    protected static function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        self::$assertions++;
        if (in_array($needle, $haystack, true)) {
            throw new RuntimeException($message !== ''
                ? $message
                : self::render($needle) . ' should not be in ' . self::render($haystack));
        }
    }

    protected static function assertGreaterThan(int|float $floor, int|float $actual, string $message = ''): void
    {
        self::assertTrue($actual > $floor, $message !== '' ? $message : "Expected more than {$floor}, got {$actual}");
    }

    protected static function assertLessThanOrEqual(int|float $ceiling, int|float $actual, string $message = ''): void
    {
        self::assertTrue($actual <= $ceiling, $message !== '' ? $message : "Expected at most {$ceiling}, got {$actual}");
    }

    /**
     * Asserts that $callable throws $expected.
     *
     * @param class-string<Throwable> $expected
     */
    protected static function assertThrows(string $expected, callable $callable, string $message = ''): void
    {
        self::$assertions++;
        try {
            $callable();
        } catch (Throwable $error) {
            if ($error instanceof $expected) {
                return;
            }
            throw new RuntimeException(self::describe($message, $expected, $error::class));
        }
        throw new RuntimeException($message !== '' ? $message : "Expected {$expected}, nothing was thrown");
    }

    // --------------------------------------------------------------------- helpers

    private static function describe(string $message, mixed $expected, mixed $actual): string
    {
        $detail = 'expected ' . self::render($expected) . ', got ' . self::render($actual);
        return $message !== '' ? $message . ' — ' . $detail : ucfirst($detail);
    }

    private static function render(mixed $value): string
    {
        $text = is_object($value) ? $value::class : var_export($value, true);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
    }

    /** The line in the test file, rather than the line inside the assertion helper. */
    private static function origin(Throwable $error): string
    {
        foreach ($error->getTrace() as $frame) {
            $file = $frame['file'] ?? '';
            if (str_ends_with($file, 'Test.php')) {
                return basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }
        return basename($error->getFile()) . ':' . $error->getLine();
    }
}
