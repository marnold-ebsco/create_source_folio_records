<?php declare(strict_types=1);

namespace SourceFolioRecords\Mapping;

use RuntimeException;

/**
 * Resolves the value of a FOLIO field for one input row, per the rules
 * encoded in a legacy-column-to-FOLIO-field mapping file (the same
 * format `folio-migration-mapper`'s `create_map`/`verify_map` tools
 * produce and check):
 *
 *   {
 *       "data": [
 *           {
 *               "folio_field": "title",
 *               "legacy_field": "Title",
 *               "value": "",
 *               "description": "The primary title of the resource",
 *               "fallback_legacy_field": ""
 *           }
 *       ]
 *   }
 *
 * For a given `folio_field`:
 *   1. A non-empty "value" is used verbatim (hard-coded), regardless of
 *      the row's data.
 *   2. Otherwise, the row's `legacy_field` column is used, if present
 *      and non-empty.
 *   3. Otherwise, the row's `fallback_legacy_field` column is used, if
 *      present and non-empty.
 *   4. Otherwise, a non-empty "fallback_value" is used verbatim, if the
 *      entry has one — unlike "value", this only applies when neither
 *      column produced anything, rather than overriding them.
 *   5. Otherwise the field resolves to null (not present in this row).
 * "legacy_field"/"fallback_legacy_field" of "" or "Not mapped" are
 * treated as absent. Column name matching is case-insensitive.
 *
 * A nested group field's `folio_field` may name a specific instance with
 * `[N]` (1-based) before the dot, e.g. `identifiers[2].value`, to
 * support more than one identifier/contributor/publication entry per
 * record. Omitting the bracket (e.g. `identifiers.value`) is shorthand
 * for `identifiers[1].value`.
 */
final class FieldMapper {
    /** @var array<string, array<string, mixed>> Indexed by normalized, lowercased folio_field. */
    private array $index;

    /** @var array<string, ValueMap> Reference data maps, by normalized folio_field. */
    private array $valueMaps = [];

    /** @var array<string, string> Default legacy column per value-mapped field. */
    private array $mapColumns = [];

    /**
     * @param $index Mapping entries indexed by lowercased `folio_field`
     *               (bracket-less nested keys are normalized to `[1]`
     *               automatically). Prefer {@see fromFile()} unless
     *               constructing an index directly (e.g. in tests).
     */
    public function __construct(array $index) {
        $this->index = [];
        foreach ($index as $key => $entry) {
            $this->index[self::normalizeKey((string) $key)] = $entry;
        }
    }

    /**
     * Normalize a lowercased `folio_field` key: a bracket-less nested
     * group key (`identifiers.value`) is rewritten to explicit instance 1
     * (`identifiers[1].value`); anything else (top-level fields, or keys
     * that already have a `[N]`) passes through unchanged.
     */
    private static function normalizeKey(string $key): string {
        if (preg_match('/^([^.\[]+)\.(.+)$/', $key, $m) === 1) {
            return "{$m[1]}[1].{$m[2]}";
        }
        return $key;
    }

    /**
     * Load a mapping file and build a {@see FieldMapper} from it.
     *
     * @param $path Path to the mapping JSON file.
     * @throws RuntimeException If the file can't be read, or isn't a
     *                          JSON object with a top-level `data` array.
     */
    public static function fromFile(string $path): self {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read mapping file '$path'");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RuntimeException("Mapping file '$path' must be a JSON object with a top-level 'data' array");
        }

        $index = [];
        foreach ($decoded['data'] as $entry) {
            if (!is_array($entry) || empty($entry['folio_field'])) {
                continue;
            }
            $index[strtolower((string) $entry['folio_field'])] = $entry;
        }
        return new self($index);
    }

    /**
     * Resolve the raw (pre-cast) value of a FOLIO field for one row.
     *
     * @param $folioField Dot-notation for nested group fields (e.g.
     *                    `identifiers.value` or `identifiers[2].value`),
     *                    or a bare top-level field name otherwise.
     *                    Matched case-insensitively.
     * @param $row        The row, keyed by lowercased column header.
     * @return The resolved raw string value, or null if nothing in the
     *         mapping applies to this row.
     */
    public function resolve(string $folioField, array $row): ?string {
        $entry = $this->index[self::normalizeKey(strtolower($folioField))] ?? null;
        if ($entry === null) {
            return null;
        }

        $map = $this->valueMaps[self::normalizeKey(strtolower($folioField))] ?? null;
        if ($map !== null) {
            $mapped = $map->lookup($this->legacyValueFor($entry, $map, $row));
            if ($mapped !== null) {
                return $mapped;
            }
        }

        if (isset($entry['value']) && (string) $entry['value'] !== '') {
            return (string) $entry['value'];
        }

        foreach (['legacy_field', 'fallback_legacy_field'] as $key) {
            $columnName = trim((string) ($entry[$key] ?? ''));
            if ($columnName === '' || strcasecmp($columnName, 'Not mapped') === 0) {
                continue;
            }
            $raw = $row[strtolower($columnName)] ?? null;
            if ($raw !== null && trim((string) $raw) !== '') {
                return (string) $raw;
            }
        }

        if (isset($entry['fallback_value']) && (string) $entry['fallback_value'] !== '') {
            return (string) $entry['fallback_value'];
        }

        return null;
    }

    /**
     * Attach a reference data map to a field: the row's legacy value is
     * translated through it (see {@see ValueMap}) before the entry's own
     * fallbacks apply. The legacy value comes from the map's header column
     * if the row has it, else the entry's legacy_field/fallback_legacy_field.
     */
    public function setValueMap(string $folioField, ValueMap $map, string $defaultColumn = ''): void {
        $key = self::normalizeKey(strtolower($folioField));
        $this->valueMaps[$key] = $map;
        if ($defaultColumn !== '') {
            $this->mapColumns[$key] = strtolower($defaultColumn);
        }
    }

    /**
     * The legacy (pre-map) value this row supplies for a field — what a
     * reference data map would be keyed on — or '' if the row has none.
     * Meant for reporting (e.g. which source values fell through to a
     * default); {@see resolve()} is what actually builds the record.
     */
    public function sourceValue(string $folioField, array $row): string {
        $key = self::normalizeKey(strtolower($folioField));
        $entry = $this->index[$key] ?? [];
        $map = $this->valueMaps[$key] ?? null;
        $columns = $map !== null ? [$map->sourceColumn(), $this->mapColumns[$key] ?? ''] : [];
        foreach (['legacy_field', 'fallback_legacy_field'] as $k) {
            $columns[] = strtolower(trim((string) ($entry[$k] ?? '')));
        }
        foreach ($columns as $col) {
            if ($col !== '' && $col !== 'not mapped' && trim((string) ($row[$col] ?? '')) !== '') {
                return trim((string) $row[$col]);
            }
        }
        return '';
    }

    private function legacyValueFor(array $entry, ValueMap $map, array $row): ?string {
        $columns = [$map->sourceColumn()];
        foreach ($this->mapColumns as $k => $col) {
            if ($this->valueMaps[$k] === $map) {
                $columns[] = $col;
            }
        }
        foreach (['legacy_field', 'fallback_legacy_field'] as $key) {
            $columns[] = strtolower(trim((string) ($entry[$key] ?? '')));
        }
        $first = null;
        foreach ($columns as $col) {
            if ($col === '' || $col === 'not mapped' || !array_key_exists($col, $row)) {
                continue;
            }
            $first ??= (string) $row[$col];
            if (trim((string) $row[$col]) !== '') {
                return (string) $row[$col];
            }
        }
        return $first;
    }

    /**
     * Whether a field already has a usable mapping — a non-empty literal
     * `value`, a non-empty `fallback_value`, or a `legacy_field`/
     * `fallback_legacy_field` other than `""`/`"Not mapped"` —
     * independent of whether any particular row actually has data in
     * that column. Used to decide whether a required reference field
     * needs to be prompted for (see `bin/build-inventory`'s
     * `promptForLiteralIfUnmapped()`) rather than to resolve an actual
     * value.
     *
     * @param $folioField Same notation as {@see resolve()}.
     */
    public function isMapped(string $folioField): bool {
        $entry = $this->index[self::normalizeKey(strtolower($folioField))] ?? null;
        if ($entry === null) {
            return false;
        }
        if (isset($this->valueMaps[self::normalizeKey(strtolower($folioField))])) {
            return true;
        }
        if (isset($entry['value']) && (string) $entry['value'] !== '') {
            return true;
        }
        foreach (['legacy_field', 'fallback_legacy_field'] as $key) {
            $columnName = trim((string) ($entry[$key] ?? ''));
            if ($columnName !== '' && strcasecmp($columnName, 'Not mapped') !== 0) {
                return true;
            }
        }
        if (isset($entry['fallback_value']) && (string) $entry['fallback_value'] !== '') {
            return true;
        }
        return false;
    }

    /**
     * A field's `fallback_value` (see {@see resolve()}), if it has one —
     * used by `bin/build-inventory` to check a live reference field's
     * *actual configured* fallback literal against the tenant (e.g. "does
     * the tenant really have a material type named whatever
     * `materialTypeId`'s `fallback_value` says?") rather than hard-coding
     * that literal a second time in the script itself.
     *
     * @param $folioField Same notation as {@see resolve()}.
     * @return The fallback literal, or null if this field has none.
     */
    public function fallbackValueFor(string $folioField): ?string {
        $entry = $this->index[self::normalizeKey(strtolower($folioField))] ?? null;
        if ($entry === null || !isset($entry['fallback_value']) || (string) $entry['fallback_value'] === '') {
            return null;
        }
        return (string) $entry['fallback_value'];
    }

    /**
     * Whether this field resolves to a fixed literal (a non-empty
     * `value` in the mapping entry) — true means {@see resolve()} would
     * return the same thing for *every* row, regardless of data. Used by
     * {@see \SourceFolioRecords\RecordBuilder::buildNestedGroupInstance()}
     * to tell a nested group instance built entirely from literals (e.g.
     * a hard-coded `identifierTypeId` with no actual `value` data on
     * this row) apart from one genuinely populated by the row.
     *
     * @param $folioField Same notation as {@see resolve()}.
     */
    public function isLiteral(string $folioField): bool {
        $entry = $this->index[self::normalizeKey(strtolower($folioField))] ?? null;
        return $entry !== null && isset($entry['value']) && (string) $entry['value'] !== '';
    }

    /**
     * Drop every `<schemaKey>[N]` entry of a repeatable scalar field (see
     * {@see scalarListIndicesFor()}) so the field is left off every record.
     */
    public function removeScalarList(string $schemaKey): void {
        $prefix = strtolower($schemaKey);
        foreach (array_keys($this->index) as $key) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\[\d+\]$/', $key) === 1) {
                unset($this->index[$key], $this->valueMaps[$key], $this->mapColumns[$key]);
            }
        }
    }

    /**
     * Set (or overwrite) a field's literal `value`, so every subsequent
     * {@see resolve()} call for it returns `$value` regardless of any
     * row's actual data — used to inject a single answer collected
     * interactively (e.g. "what resource type should every instance
     * use?") as if it had been hard-coded in the mapping file all along.
     *
     * @param $folioField Same notation as {@see resolve()}.
     * @param $value      The literal value to use from now on.
     */
    public function setLiteral(string $folioField, string $value): void {
        $key = self::normalizeKey(strtolower($folioField));
        unset($this->valueMaps[$key], $this->mapColumns[$key]);
        $entry = $this->index[$key] ?? ['folio_field' => $folioField];
        $entry['value'] = $value;
        $this->index[$key] = $entry;
    }

    /**
     * Find which instance numbers a nested group has mapping entries
     * for, e.g. `[1, 2]` if the mapping defines `identifiers[1].*` and
     * `identifiers[2].*` fields but nothing for `identifiers[3]`.
     * Determines how many identifier/contributor/publication instances
     * {@see \SourceFolioRecords\RecordBuilder} will attempt to build for
     * that group — an instance whose fields all resolve empty for a
     * given row is simply omitted, so it's fine for the mapping to
     * define more instances than any single row uses.
     *
     * @param $schemaKey Schema array property name (e.g. `identifiers`).
     * @return Sorted, unique instance numbers (1-based); empty if the
     *         mapping has no entries at all for this group.
     */
    public function indicesFor(string $schemaKey): array {
        $prefix = strtolower($schemaKey);
        $indices = [];
        foreach (array_keys($this->index) as $key) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\[(\d+)\]\./', $key, $m) === 1) {
                $indices[(int) $m[1]] = true;
            }
        }
        $result = array_keys($indices);
        sort($result);
        return $result;
    }

    /**
     * Find which instance numbers a repeatable *scalar* field has
     * mapping entries for, e.g. `[0, 1]` if the mapping defines
     * `statisticalCodeIds[0]` and `statisticalCodeIds[1]` but nothing for
     * `statisticalCodeIds[2]`. Unlike {@see indicesFor()} (for nested
     * *group* fields, each instance having its own sub-fields after a
     * `.`), a repeatable scalar field's mapping entry is the bracketed
     * key on its own — one value per instance, no sub-fields — so
     * `folioField[N]` may add as many instances as needed, each carrying
     * exactly one value (e.g. one statistical code per entry). Numbering
     * starts wherever the mapping file starts it (`statisticalCodeIds`
     * starts at `[0]` in this package's own mapping files, unlike
     * {@see indicesFor()}'s 1-based nested groups) — this method just
     * reports whatever integers it finds, sorted, with no assumption
     * about where they start.
     *
     * @param $schemaKey Schema array property name (e.g. `statisticalCodeIds`).
     * @return Sorted, unique instance numbers, exactly as they appear in
     *         the mapping file; empty if the mapping has no entries at
     *         all for this field.
     */
    public function scalarListIndicesFor(string $schemaKey): array {
        $prefix = strtolower($schemaKey);
        $indices = [];
        foreach (array_keys($this->index) as $key) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\[(\d+)\]$/', $key, $m) === 1) {
                $indices[(int) $m[1]] = true;
            }
        }
        $result = array_keys($indices);
        sort($result);
        return $result;
    }
}
