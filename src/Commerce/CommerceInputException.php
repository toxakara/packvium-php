<?php
declare(strict_types=1);
namespace Packvium\Commerce;

/**
 * The supplied document or request is not well formed -- a caller bug.
 *
 * Kept apart from a rejection on purpose (docs/COMMERCE-API.md): a well-formed request
 * the commercial model simply cannot answer is a successful call returning
 * status "rejected", not an exception.
 */
final class CommerceInputException extends \InvalidArgumentException {}
