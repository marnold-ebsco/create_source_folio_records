<?php declare(strict_types=1);

namespace SourceFolioRecords;

/**
 * Generic id<=>name resolver against a tenant's *real*, already-existing
 * reference data (instance types, material types, loan types,
 * contributor name types, identifier types, ...) — unlike
 * {@see ReferenceRegistry}, which fabricates a deterministic id for any
 * name it's given, this class only ever returns an id a live FOLIO
 * tenant actually has, since a made-up id would be rejected by the
 * server (a foreign-key constraint) once loaded.
 *
 * One instance covers a single reference type (e.g. just instance
 * types); `bin/build-inventory` builds one per `live:<namespace>`
 * field actually in use, keyed by namespace, and hands the whole set to
 * {@see RecordBuilder}. Kept separate from the earlier
 * {@see HoldingsSourceResolver}/{@see LocationResolver} (which have the
 * exact same shape) only because those already shipped with their own
 * names before this generic version existed — new `live:` reference
 * types should use this one instead of adding another dedicated class.
 *
 * Built from a snapshot of `id => name` pairs taken once per
 * `bin/build-inventory` run; see {@see empty()} for a build run with no
 * FOLIO connection to check against.
 */
final class LiveReferenceResolver {
    /** @param $namesById Snapshot of existing records: id => name. */
    public function __construct(
        private readonly array $namesById,
    ) {
    }

    /**
     * Build a resolver with no known records — every id/name lookup
     * will report "not found". Used when a build run has no `--config`
     * to check against (see `bin/build-inventory`), which is fine as
     * long as no mapping file actually maps the corresponding field.
     */
    public static function empty(): self {
        return new self([]);
    }

    /** Whether `$id` is a real id in this snapshot. */
    public function existsById(string $id): bool {
        return array_key_exists($id, $this->namesById);
    }

    /**
     * Look up a record's id by name (case-insensitive).
     *
     * @return The matching id, or null if no record has this name.
     */
    public function idForName(string $name): ?string {
        foreach ($this->namesById as $id => $existingName) {
            if (strcasecmp($existingName, $name) === 0) {
                return $id;
            }
        }
        return null;
    }
}
