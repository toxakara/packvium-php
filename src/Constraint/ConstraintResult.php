<?php
declare(strict_types=1);
namespace Packvium\Constraint;
final readonly class ConstraintResult{public function __construct(public bool $allowed,public string $code='allowed',public string $detail=''){}public static function allow():self{return new self(true);}public static function reject(string $code,string $detail=''):self{return new self(false,$code,$detail);}}
