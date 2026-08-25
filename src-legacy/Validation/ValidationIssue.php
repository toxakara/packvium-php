<?php
declare(strict_types=1);
namespace Packvium\Validation;
final class ValidationIssue{/**
 * @readonly
 * @var string
 */
public $code;
/**
 * @readonly
 * @var string
 */
public $detail;
public function __construct(string $code, string $detail)
{
    $this->code = $code;
    $this->detail = $detail;
}}
