<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Validation\MalformedResult;
use Packvium\Serialization\ArrayCodec;
use function Packvium\Validation\validate;

final class EffectiveRotationValidationTest extends TestCase
{
    public static function testTheIndependentValidatorRefusesAnEmptyEffectiveRotationList(): void
    {
        $request = [
            'items' => [['id' => 'cube', 'dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]],
            'containers' => [['id' => 'box', 'inner_dimensions' => ['length' => '10', 'width' => '10', 'height' => '10']]],
        ];
        $result = ArrayCodec::pack($request);
        foreach ([[['+L-W-H'], true], [['LHW'], true], [[], true], [[], false]] as [$codes, $upright]) {
            $invalid = $request;
            $invalid['items'][0]['shape_type'] = 'convex_hull';
            $invalid['items'][0]['allowed_rotations'] = $codes;
            $invalid['items'][0]['keep_upright'] = $upright;
            try {
                validate($invalid, $result);
                self::assertTrue(false, 'the independent validator must refuse the invalid request');
            } catch (MalformedResult $error) {
                self::assertSame(MalformedResult::CONTRACT, $error->kind);
                self::assertSame('items[0].allowed_rotations must allow at least one rotation after keep_upright', $error->getMessage());
            }
        }
    }
}
