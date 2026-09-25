<?php declare(strict_types=1);

namespace SourceFolioRecords;

use phpFolioClient\FolioClient;

/**
 * Resolves a Holdings record's required `sourceId` against a tenant's
 * *real*, already-existing holdings-sources (e.g. `FOLIO`, `MARC`) —
 * unlike {@see ReferenceRegistry}, which fabricates a deterministic id
 * for any name it's given, this class only ever returns an id a live
 * FOLIO tenant actually has, since a made-up source id would be
 * rejected by the server (a foreign-key constraint) once loaded.
 *
 * Built from a snapshot of `id => name` pairs (see
 * {@see fromClient()} for the live source, {@see empty()} for a build
 * run with no FOLIO connection to check against) taken once per
 * `bin/build-inventory` run.
 */
final class HoldingsSourceResolver {
    /** @param $namesById Snapshot of existing holdings sources: id => name. */
    public function __construct(
        private readonly array $namesById,
    ) {
    }

    /**
     * Build a resolver from a live tenant's holdings sources.
     *
     * `phpFolioClient\FolioReferenceDataManager` has no dedicated
     * holdings-sources helper (unlike locations/material types/etc.), so
     * this reads the `holdings-sources` endpoint directly via the
     * client's generic {@see FolioClient::get()}.
     *
     * @param $tenant_id Tenant id to query against, for ECS (consortial)
     *                   environments; null uses the client's default tenant.
     */
    public static function fromClient(FolioClient $client, ?string $tenant_id = null): self {
        $namesById = [];
        foreach ($client->get('holdings-sources', null, null, null, $tenant_id) as $source) {
            $namesById[$source->id] = $source->name;
        }
        return new self($namesById);
    }

    /**
     * Build a resolver with no known holdings sources — every id/name
     * lookup will report "not found". Used when a build run has no
     * `--config` to check against (see `bin/build-inventory`), which is
     * fine as long as no mapping file actually maps `sourceId`.
     */
    public static function empty(): self {
        return new self([]);
    }

    /** Whether `$id` is a real holdings source id in this snapshot. */
    public function existsById(string $id): bool {
        return array_key_exists($id, $this->namesById);
    }

    /**
     * Look up a holdings source's id by name (case-insensitive).
     *
     * @return The matching id, or null if no holdings source has this name.
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
