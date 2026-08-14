<?php
declare(strict_types=1);
namespace Packvium\Tests;

use Packvium\Support\BigInt;

/**
 * The objective vector divides container volumes in cubic ticks, which overflow a
 * 64-bit integer, so these helpers carry the exactness the score depends on.
 */
final class BigIntTest extends TestCase
{
    public static function run(): void
    {
        self::assertSame(0, BigInt::compare('42', '42'));
        self::assertSame(-1, BigInt::compare('999', '1000'));
        self::assertSame(1, BigInt::compare('1000', '999'));
        self::assertSame(0, BigInt::compare('007', '7'));

        self::assertSame('93', BigInt::subtract('100', '7'));
        self::assertSame('0', BigInt::subtract('42', '42'));
        // A one-metre cube is 4.096e21 cubic ticks — beyond PHP_INT_MAX.
        self::assertSame('4095999999999999999999', BigInt::subtract('4096000000000000000000', '1'));

        self::assertSame('14', BigInt::divide('100', '7'));
        self::assertSame('0', BigInt::divide('7', '100'));
        self::assertSame('1', BigInt::divide('999', '999'));
        self::assertSame('4096000000000000', BigInt::divide('4096000000000000000000', '1000000'));
        self::assertSame('333333333333333333333333', BigInt::divide('1000000000000000000000000', '3'));

        // The exact shape the scorer relies on: floor(free * 1e6 / volume).
        $volume = BigInt::multiply(BigInt::multiply('16000000', '16000000'), '16000000');
        self::assertSame('4096000000000000000000', $volume);
        $free = BigInt::divide($volume, '4');
        self::assertSame('250000', BigInt::divide(BigInt::multiply($free, '1000000'), $volume));

        $negative = false;
        try {
            BigInt::subtract('1', '2');
        } catch (\InvalidArgumentException) {
            $negative = true;
        }
        self::assertTrue($negative, 'subtract must reject a negative result');

        $divideByZero = false;
        try {
            BigInt::divide('1', '0');
        } catch (\DivisionByZeroError) {
            $divideByZero = true;
        }
        self::assertTrue($divideByZero, 'divide must reject a zero divisor');
    }
}
