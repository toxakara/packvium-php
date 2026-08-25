<?php
declare(strict_types=1);
namespace Packvium\Validation;
final class ValidationReport{/**
 * @readonly
 * @var bool
 */
public $valid;
/**
 * @var list<ValidationIssue>
 * @readonly
 */
public $issues;
/** @param list<ValidationIssue> $issues */public function __construct(bool $valid, array $issues)
{
    $this->valid = $valid;
    $this->issues = $issues;
}}
