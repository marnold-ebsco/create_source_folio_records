<?php declare(strict_types=1);

namespace SourceFolioRecords;

/**
 * Deduplicates named reference-data values (e.g. resource type,
 * material type names, and each row's own legacy identifier) into
 * deterministic UUIDs, shared across a whole build run so the same name
 * always resolves to the same UUID no matter which record mentions it
 * first — and, since the id is deterministic (see {@see FOLIO_NAMESPACE}),
 * the *same* UUID again on a completely separate run, too.
 */
final class ReferenceRegistry {
    /**
     * FOLIO's own well-known namespace for deterministic (UUID v5) ids —
     * see {@see resolve()}. Matches the convention FOLIO's own migration
     * tooling uses (the `folio_uuid` Python library:
     * https://github.com/FOLIO-FSE/folio_uuid), so an id computed here is
     * byte-identical to one any other tool following that same
     * convention would compute for the same name.
     */
    public const FOLIO_NAMESPACE = '8405ae4d-b315-42e1-918a-d1919900cf3f';

    /** @var array<string, array<string, string>> namespace => (lowercased name => uuid) */
    private array $uuidsByName = [];

    /** @var array<string, array<string, string>> namespace => (uuid => original-case name), insertion order preserved. */
    private array $namesByUuid = [];

    /**
     * @param $tenant Tenant id mixed into every deterministic id this
     *                registry generates, so the same name gets a
     *                different id in a different target tenant —
     *                matching the real `folio_uuid` convention exactly.
     *                Defaults to the fixed placeholder `'offline'` when
     *                there's no real tenant to use — still fully
     *                deterministic run-to-run, just not tenant-scoped.
     */
    public function __construct(
        private readonly string $tenant = 'offline',
    ) {
    }

    /**
     * Resolve a name to its UUID within a namespace: generates one the
     * first time this (namespace, name) pair is seen, and caches it for
     * every later call — so within a single run, this always returns the
     * same UUID for the same name, and (since it's a `uuid5` of
     * {@see FOLIO_NAMESPACE} plus `"{tenant}:{namespace}:{key}"`) the
     * same UUID again on a separate run.
     *
     * @param $namespace Logical grouping (e.g. `instanceType`, `instance`).
     * @param $name      The raw name/key to resolve; matching is
     *                   case-insensitive and ignores surrounding whitespace.
     * @param $rowNum    Unused; accepted for parity with callers that
     *                   pass a row number for logging purposes elsewhere.
     * @return The resolved UUID.
     */
    public function resolve(string $namespace, string $name, ?int $rowNum = null): string {
        $name = trim($name);
        $key = strtolower($name);

        if (!isset($this->uuidsByName[$namespace][$key])) {
            $combined = implode(':', [$this->tenant, $namespace, $key]);
            $uuid = self::generateUuidV5(self::FOLIO_NAMESPACE, $combined);
            $this->uuidsByName[$namespace][$key] = $uuid;
            $this->namesByUuid[$namespace][$uuid] = $name;
        }

        return $this->uuidsByName[$namespace][$key];
    }

    /**
     * Generate a deterministic UUID v5 (version/variant nibbles set per
     * RFC 4122): the same `$namespace`/`$name` pair always produces the
     * same UUID. Computed exactly per RFC 4122 §4.3 — SHA-1 of the
     * namespace's 16 raw bytes followed by `$name`, truncated to 16
     * bytes with version/variant bits overwritten — the same algorithm
     * Python's `uuid.uuid5()` (and the FOLIO ecosystem's own `folio_uuid`
     * library) use, so this produces identical output for identical
     * input regardless of which implementation computed it.
     *
     * @param $namespace A UUID string — e.g. {@see FOLIO_NAMESPACE}.
     * @param $name      The value to hash within that namespace.
     */
    public static function generateUuidV5(string $namespace, string $name): string {
        $namespaceBytes = hex2bin(str_replace('-', '', $namespace));
        $hash = sha1((string) $namespaceBytes . $name, true);
        $data = substr($hash, 0, 16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x50);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
