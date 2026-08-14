<?php
declare(strict_types=1);
namespace Packvium\Validation;
final readonly class ValidationReport{/** @param list<ValidationIssue> $issues */public function __construct(public bool $valid,public array $issues){}}
