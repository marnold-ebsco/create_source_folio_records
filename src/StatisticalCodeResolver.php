<?php declare(strict_types=1);

namespace SourceFolioRecords;

use phpFolioClient\FolioReferenceDataManager;

/**
 * Resolves `statisticalCodeIds` entries against a tenant's *real*,
 * already-existing statistical codes — unlike {@see ReferenceRegistry},
 * which fabricates a deterministic id for any name it's given, this
 * class only ever returns an id that a live FOLIO tenant actually has,
 * since a made-up statistical code id would silently fail to mean
 * anything once loaded.
 *
 * Built from a snapshot of `id => name` pairs (see {@see fromReferenceDataManager()}
 * for the live source, {@see empty()} for a build run with no FOLIO
 * connection to check against) taken once per `bin/build-inventory` run.
 */
final class StatisticalCodeResolver {
    /** @param $namesById Snapshot of existing statistical codes: id => name. */
    public function __construct(
        private readonly array $namesById,
    ) {
    }

    /**
     * Build a resolver from a live tenant's statistical codes.
     *
     * @param $manager   Used to fetch every statistical code's id/name.
     * @param $tenant_id Tenant id to query against, for ECS (consortial)
     *                   environments; null uses the client's default tenant.
     */
    public static function fromReferenceDataManager(FolioReferenceDataManager $manager, ?string $tenant_id = null): self {
        return new self($manager->getStatisticalCodeNames($tenant_id));
    }

    /**
     * Build a resolver with no known statistical codes — every id/name
     * lookup will report "not found". Used when a build run has no
     * `--config` to check against (see `bin/build-inventory`), which is
     * fine as long as no mapping file actually maps `statisticalCodeIds`.
     */
    public static function empty(): self {
        return new self([]);
    }

    /** Whether `$id` is a real statistical code id in this snapshot. */
    public function existsById(string $id): bool {
        return array_key_exists($id, $this->namesById);
    }

    /**
     * Look up a statistical code's id by name (case-insensitive).
     *
     * @return The matching id, or null if no statistical code has this name.
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
