<?php
declare(strict_types=1);
namespace Packvium\Validation;

use Packvium\Support\ResultValidation\Validator;

/*
 * The reference validator, `packvium.validate`, ported to PHP. Loaded like
 * `Packvium\Commerce\functions.php`, and guarded the same way, so Composer's `files` and the
 * source autoloader can both require it in one process.
 */

if (!\function_exists(__NAMESPACE__ . '\\validate')) {
    /**
     * Every way `$result` fails to be a sound answer to `$request`, both as the decoded JSON
     * documents (`json_decode($text, true)`); an empty list means valid. It shares no code with
     * the engine, so it judges any engine's result, and returns the issues every Packvium
     * package returns for the same documents (docs/VALIDATION-CONTRACT.md).
     *
     * ```php
     * $issues = Packvium\Validation\validate($request, ArrayCodec::pack($request)); // []
     * ```
     *
     * @param  array<string,mixed> $request
     * @param  array<string,mixed> $result
     * @return list<ResultIssue>
     * @throws MalformedResult when the result does not have the documented shape at all
     */
    function validate(array $request, array $result): array
    {
        return Validator::validate($request, $result);
    }
}
