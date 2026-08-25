<?php
declare(strict_types=1);
namespace Packvium\Constraint;
final class ConstraintResult{/**
 * @readonly
 * @var bool
 */
public $allowed;
/**
 * @readonly
 * @var string
 */
public $code = 'allowed';
/**
 * @readonly
 * @var string
 */
public $detail = '';
public function __construct(bool $allowed, string $code='allowed', string $detail='')
{
    $this->allowed = $allowed;
    $this->code = $code;
    $this->detail = $detail;
}public static function allow():self{return new self(true);}public static function reject(string $code,string $detail=''):self{return new self(false,$code,$detail);}}
