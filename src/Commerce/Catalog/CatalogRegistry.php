<?php
declare(strict_types=1);
namespace Packvium\Commerce\Catalog;

/**
 * Per-catalog append-only publication history: the one place catalog state can change,
 * and it can only ever append. Complexity: publish O(1), resolve O(h) over the history.
 */
final class CatalogRegistry
{
    /** @var list<Version> */
    private array $versions = [];

    public function __construct(public readonly string $catalogId)
    {
        if ($catalogId === '') { throw new \InvalidArgumentException('catalog_id is required'); }
    }

    /** @return list<Version> The full append-only history, oldest first. */
    public function versions(): array
    {
        return $this->versions;
    }

    public function publish(Snapshot $snapshot, int $effectiveAt, int $publishedAt, string $note = ''): Version
    {
        $version = new Version(count($this->versions) + 1, $snapshot, $effectiveAt, $publishedAt, null, $note);
        $this->versions[] = $version;
        return $version;
    }

    /** Publish a new version whose snapshot equals a prior version's. History is never rewritten. */
    public function rollback(int $toVersion, int $publishedAt, ?int $effectiveAt = null, string $note = ''): Version
    {
        $target = $this->version($toVersion);
        $version = new Version(
            count($this->versions) + 1, $target->snapshot, $effectiveAt ?? $publishedAt, $publishedAt,
            $toVersion, $note !== '' ? $note : "rollback to version {$toVersion}",
        );
        $this->versions[] = $version;
        return $version;
    }

    /** Resolve one concrete version, pinned by number or by the version effective as of a time. */
    public function resolve(int $resolvedAt, ?int $version = null, ?int $asOf = null): Version
    {
        if ($version !== null) { return $this->version($version); }
        if ($asOf === null) {
            if (count($this->versions) > 1) {
                throw new AmbiguousReferenceException(
                    "catalog '{$this->catalogId}' has " . count($this->versions)
                    . " versions; resolving requires an explicit version or as_of",
                );
            }
            if ($this->versions === []) {
                throw new VersionNotFoundException("catalog '{$this->catalogId}' has no published versions");
            }
            return $this->versions[0];
        }
        $winner = null;
        foreach ($this->versions as $candidate) {
            if ($candidate->effectiveAt > $asOf) { continue; }
            // Ties in effective_at break on the higher (later-published) number, so a
            // same-instant correction or rollback wins deterministically.
            if ($winner === null
                || $candidate->effectiveAt > $winner->effectiveAt
                || ($candidate->effectiveAt === $winner->effectiveAt && $candidate->number > $winner->number)) {
                $winner = $candidate;
            }
        }
        if ($winner === null) {
            throw new NoEffectiveVersionException("catalog '{$this->catalogId}' has no version effective as of {$asOf}");
        }
        return $winner;
    }

    public function version(int $number): Version
    {
        foreach ($this->versions as $version) {
            if ($version->number === $number) { return $version; }
        }
        throw new VersionNotFoundException("catalog '{$this->catalogId}' has no version {$number}");
    }
}
