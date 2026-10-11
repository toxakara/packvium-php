<?php
declare(strict_types=1);
namespace Packvium\Support;

/**
 * Writes `json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` to a stream
 * without ever holding the whole text.
 *
 * `bin/packvium` used to build the encoded result next to the array it came from. A result
 * that carries its alternatives is several hundred KB of text, and holding it while
 * `json_encode` grew it cost one more 2 MB Zend chunk -- the whole of the 1.6 peak-memory
 * regression on `fast` in the frontier's Linux container. Here the outer levels are
 * written member by member and only an array of at most {@see self::WHOLE_ELEMENTS}
 * elements, counted recursively, is encoded whole -- a placement, not a container -- so what
 * is held is one such value plus a {@see self::FLUSH_BYTES} buffer.
 *
 * The bytes are the same because pretty printing is local: a value at depth `d` is its own
 * pretty encoding with `4 * d` spaces after every newline, and an encoded string never
 * contains a raw newline. Arrays follow `json_encode`'s rule, the one in {@see JsonValue}:
 * a list is a JSON array, any other array a JSON object whose names are its keys as strings.
 * An empty array, and anything that is not an array, is encoded whole.
 *
 * One difference, on a path no answer takes: a value `json_encode` cannot encode throws
 * `JsonException` after the members before it were written, where the one-shot encoding
 * returned `false`. O(N * D) time for N bytes of output nested D arrays deep (each level
 * counts its members recursively), and O(F + L) memory for the largest value L encoded whole.
 */
final class PrettyJsonStream
{
    /** A placement counts 30 or so; a container with its placements counts thousands. */
    private const WHOLE_ELEMENTS = 64;
    private const FLUSH_BYTES = 65536;
    private const FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
    private const INDENT = '    ';

    /** @var resource */
    private $stream;
    /** @var string */
    private $buffer = '';

    /** @param resource $stream */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * @param mixed $value
     * @param resource $stream
     * @throws \JsonException
     */
    public static function write($value, $stream): void
    {
        $writer = new self($stream);
        $writer->value($value, 0);
        $writer->flush();
    }

    /** @param mixed $value */
    private function value($value, int $depth): void
    {
        if (!\is_array($value) || \count($value, \COUNT_RECURSIVE) <= self::WHOLE_ELEMENTS) {
            $this->append(self::encoded($value, $depth));
            return;
        }
        $list = JsonValue::isList($value);
        $separator = "\n" . \str_repeat(self::INDENT, $depth + 1);
        $this->append($list ? '[' : '{');
        $first = true;
        foreach ($value as $key => $member) {
            $this->append(($first ? '' : ',') . $separator);
            $first = false;
            if (!$list) {
                $this->append(\json_encode((string) $key, self::FLAGS) . ': ');
            }
            $this->value($member, $depth + 1);
        }
        $this->append("\n" . \str_repeat(self::INDENT, $depth) . ($list ? ']' : '}'));
    }

    /** @param mixed $value */
    private static function encoded($value, int $depth): string
    {
        $json = \json_encode($value, self::FLAGS);
        return $depth === 0 ? $json : \str_replace("\n", "\n" . \str_repeat(self::INDENT, $depth), $json);
    }

    private function append(string $text): void
    {
        $this->buffer .= $text;
        if (\strlen($this->buffer) >= self::FLUSH_BYTES) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (\fwrite($this->stream, $this->buffer) !== \strlen($this->buffer)) {
            throw new \RuntimeException('could not write the result to the output stream');
        }
        $this->buffer = '';
    }
}
