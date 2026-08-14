<?php
declare(strict_types=1);
namespace Packvium\Validation;
final readonly class ValidationIssue{public function __construct(public string $code,public string $detail){}}
