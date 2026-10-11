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

        self::agreesWithDigitByDigitArithmetic();
    }

    /**
     * The native, limb and float-estimate paths against the digit-by-digit arithmetic they
     * replace, on every length pair across each path's boundary (18 digits native, 9 a
     * limb, a 2^50 quotient for the estimate) and on carries, borrows and zero limbs.
     */
    private static function agreesWithDigitByDigitArithmetic(): void
    {
        mt_srand(1606);
        $values = ['0', '1', '9', '10', '999999999', '1000000000', '999999999999999999', '1000000000000000000',
            '4096000000000000000000', '1000000000000000000000000000', str_repeat('9', 40)];
        for ($digits = 1; $digits <= 40; $digits++) {
            for ($sample = 0; $sample < 2; $sample++) { $values[] = self::randomDigits($digits); }
        }
        foreach ($values as $a) {
            foreach ($values as $b) {
                self::assertSame(self::slowAdd($a, $b), BigInt::add($a, $b), "{$a} + {$b}");
                self::assertSame(self::slowMultiply($a, $b), BigInt::multiply($a, $b), "{$a} * {$b}");
                if (BigInt::compare($a, $b) >= 0) { self::assertSame(self::slowSubtract($a, $b), BigInt::subtract($a, $b), "{$a} - {$b}"); }
                if ($b !== '0') { self::assertSame(self::slowDivide($a, $b), BigInt::divide($a, $b), "{$a} / {$b}"); }
            }
        }
        self::assertSame('7', BigInt::add('0007', '000'));
        self::assertSame('0', BigInt::multiply('000', '12345678901234567890'));
        self::assertSame('12', BigInt::divide('0000000000000000000144', '000012'));
    }

    private static function randomDigits(int $digits): string
    {
        $out = (string)mt_rand(1, 9);
        for ($i = 1; $i < $digits; $i++) { $out .= mt_rand(0, 9) < 3 ? (mt_rand(0, 1) ? '0' : '9') : (string)mt_rand(0, 9); }
        return $out;
    }

    private static function slowAdd(string $a, string $b): string
    {
        $i = strlen($a) - 1; $j = strlen($b) - 1; $carry = 0; $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = ($i >= 0 ? ord($a[$i--]) - 48 : 0) + ($j >= 0 ? ord($b[$j--]) - 48 : 0) + $carry;
            $out = (string)($sum % 10) . $out; $carry = intdiv($sum, 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    private static function slowMultiply(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0'; $b = ltrim($b, '0') ?: '0';
        if ($a === '0' || $b === '0') { return '0'; }
        $digits = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; --$i) {
            for ($j = strlen($b) - 1; $j >= 0; --$j) {
                $p = (ord($a[$i]) - 48) * (ord($b[$j]) - 48) + $digits[$i + $j + 1];
                $digits[$i + $j + 1] = $p % 10; $digits[$i + $j] += intdiv($p, 10);
            }
        }
        return ltrim(implode('', $digits), '0') ?: '0';
    }

    private static function slowSubtract(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0'; $b = ltrim($b, '0') ?: '0';
        $i = strlen($a) - 1; $j = strlen($b) - 1; $borrow = 0; $out = '';
        while ($i >= 0) {
            $digit = (ord($a[$i--]) - 48) - $borrow - ($j >= 0 ? ord($b[$j--]) - 48 : 0);
            if ($digit < 0) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
            $out = (string)$digit . $out;
        }
        return ltrim($out, '0') ?: '0';
    }

    private static function slowDivide(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0'; $b = ltrim($b, '0') ?: '0';
        $quotient = ''; $remainder = '0';
        for ($i = 0, $n = strlen($a); $i < $n; ++$i) {
            $remainder = ltrim($remainder . $a[$i], '0') ?: '0';
            $digit = 0;
            while (BigInt::compare($remainder, $b) >= 0) { $remainder = self::slowSubtract($remainder, $b); ++$digit; }
            $quotient .= (string)$digit;
        }
        return ltrim($quotient, '0') ?: '0';
    }
}
