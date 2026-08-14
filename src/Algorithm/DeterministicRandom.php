<?php
declare(strict_types=1);
namespace Packvium\Algorithm;
/**
 * Seeded linear congruential generator.
 *
 * Only the high bits are published: the low bits of a power-of-two modulus have tiny
 * periods, and nextInt is called with very small bounds by the shuffle. Draws outside
 * the largest whole multiple of $upper are rejected so the result carries no modulo bias.
 */
final class DeterministicRandom
{
    private const MODULUS=2147483648;
    private const MULTIPLIER=1103515245;
    private const INCREMENT=12345;
    private const DRAW_LIMIT=4294967296;

    private int $state;

    public function __construct(int $seed){$this->state=$seed%self::MODULUS;}

    private function bits():int
    {
        $this->state=(int)((self::MULTIPLIER*$this->state+self::INCREMENT)%self::MODULUS);
        return $this->state>>15;
    }

    public function nextInt(int $upper):int
    {
        if($upper<=1)return 0;
        $bound=self::DRAW_LIMIT-(self::DRAW_LIMIT%$upper);
        while(true){
            $draw=$this->bits()*65536+$this->bits();
            if($draw<$bound)return $draw%$upper;
        }
    }

    public function shuffled(array $values):array
    {
        $out=array_values($values);
        for($i=count($out)-1;$i>0;$i--){$j=$this->nextInt($i+1);[$out[$i],$out[$j]]=[$out[$j],$out[$i]];}
        return $out;
    }
}
