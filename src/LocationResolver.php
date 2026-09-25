<?php declare(strict_types=1);

namespace SourceFolioRecords;

use phpFolioClient\FolioReferenceDataManager;

/**
 * Resolves a Holdings record's `permanentLocationId`/`temporaryLocationId`
 * against a tenant's *real*, already-existing locations — unlike
 * {@see ReferenceRegistry}, which fabricates a deterministic id for any
 * name it's given, this class only ever returns an id a live FOLIO
 * tenant actually has, since a made-up location id would be rejected by
 * the server (a foreign-key constraint) once loaded.
 *
 * Built from a snapshot of `id => name` pairs (see
 * {@see fromReferenceDataManager()} for the live source, {@see empty()}
 * for a build run with no FOLIO connection to check against) taken once
 * per `bin/build-inventory` run.
 */
final class LocationResolver {
    /** @param $namesById Snapshot of existing locations: id => name. */
    public function __construct(
        private readonly array $namesById,
    ) {
    }

    /**
     * Build a resolver from a live tenant's locations.
     *
     * @param $tenant_id Tenant id to query against, for ECS (consortial)
     *                   environments; null uses the client's default tenant.
     */
    public static function fromReferenceDataManager(FolioReferenceDataManager $manager, ?string $tenant_id = null): self {
        return new self($manager->getLocations($tenant_id));
    }

    /**
     * Build a resolver with no known locations — every id/name lookup
     * will report "not found". Used when a build run has no `--config`
     * to check against (see `bin/build-inventory`), which is fine as
     * long as no mapping file actually maps `permanentLocationId`/
     * `temporaryLocationId`.
     */
    public static function empty(): self {
        return new self([]);
    }

    /** Whether `$id` is a real location id in this snapshot. */
    public function existsById(string $id): bool {
        return array_key_exists($id, $this->namesById);
    }

    /**
     * Look up a location's id by name (case-insensitive).
     *
     * @return The matching id, or null if no location has this name.
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
