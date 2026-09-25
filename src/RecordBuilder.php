<?php declare(strict_types=1);

namespace SourceFolioRecords;

use SourceFolioRecords\Casting\ValueCaster;
use SourceFolioRecords\HoldingsSourceResolver;
use SourceFolioRecords\LiveReferenceResolver;
use SourceFolioRecords\LocationResolver;
use SourceFolioRecords\Mapping\FieldMapper;
use SourceFolioRecords\StatisticalCodeResolver;
use phpFolioClient\FolioUtils;

/**
 * Builds one FOLIO record — Instance, Holdings, or Item, per whichever
 * schema class is given (see {@see \SourceFolioRecords\Schema\InstanceSchema},
 * {@see \SourceFolioRecords\Schema\HoldingsSchema},
 * {@see \SourceFolioRecords\Schema\ItemSchema}) — from one input row.
 * Resolves each field's raw value via a {@see FieldMapper}, casts/
 * validates it via a {@see ValueCaster} and {@see FolioUtils}, and
 * collects any validation failures rather than throwing.
 *
 * A schema class is any class exposing these `public const` arrays:
 * `SCALAR_FIELDS` (field => cast type: `string`/`bool`/`int`/`number`,
 * `ref:<namespace>` to resolve a single name through the shared
 * {@see ReferenceRegistry} instead of casting it, or `live:<namespace>`
 * to resolve it against a live-tenant resolver instead — see
 * {@see resolveLiveReference()} for which namespaces are recognized,
 * and why every reference field this package builds eventually needs
 * to become one of these rather than `ref:` once records are actually
 * loaded), `LIST_FIELDS` (field => item type:
 * `string`/`uuid`/`ref:<namespace>`), `LIST_FIELD_ENUMS` (field =>
 * allowed values, checked against each item of a `string`-typed list
 * field), `NESTED_GROUPS` (schema array property => group spec:
 * `required` field names, `fields` (subfield => type, same types as
 * `SCALAR_FIELDS`), optional `enums`/`pattern` per subfield),
 * `SCALAR_LISTS` (schema array property =>
 * item type, currently only `statcode` — a *repeatable scalar* field,
 * one value per mapped `field[N]` instance rather than one delimited
 * cell; see {@see buildScalarListInstance()}), `NESTED_OBJECTS` (schema
 * object property => spec: `required` field names, `fields` (subfield
 * => type), optional `enums` per subfield — a *single* nested object,
 * unlike `NESTED_GROUPS`' array of objects; see
 * {@see buildNestedObject()}), `REQUIRED_FIELDS` (top-level field
 * names), `TOP_LEVEL_ENUMS` (field => allowed values), and
 * `TOP_LEVEL_PATTERNS` (field => regex).
 *
 * This class, and every schema it's used with, only ever produces the id
 * that comes from mapped/hard-coded data — the record's own `id` and any
 * cross-record linking field (an Item's `holdingsRecordId`, a Holdings'
 * `instanceId`) are deliberately outside its scope; see
 * `bin/build-inventory`, which sets those after calling {@see build()}.
 */
final class RecordBuilder {
    /**
     * Every `live:<namespace>` this class recognizes, and the
     * human-readable label used to phrase its error messages (e.g.
     * `'location'` produces "... references location name 'X', which
     * was not found in the tenant's locations"). `holdingsSource` and
     * `location` are resolved via their own dedicated constructor
     * params ({@see $holdingsSources}/{@see $locations}); every other
     * namespace here is looked up in {@see $liveReferences} instead —
     * see {@see resolveLiveReference()}.
     */
    private const LIVE_REFERENCE_LABELS = [
        'holdingsSource' => 'holdings source',
        'location' => 'location',
        'instanceType' => 'resource type',
        'materialType' => 'material type',
        'loanType' => 'loan type',
        'contributorNameType' => 'contributor name type',
        'identifierType' => 'identifier type',
    ];

    /**
     * @param $mapper        Resolves legacy column data to record fields.
     * @param $caster        Casts/splits raw cell values.
     * @param $folioUtils    Used for UUID validation.
     * @param $schemaClass   Fully-qualified name of a schema class (see
     *                       class docblock for the shape it must expose).
     * @param $listDelimiter Delimiter used *within* a cell for
     *                       multi-value fields (default `|`).
     * @param $registry      Shared reference-data resolver for
     *                       `ref:<namespace>` fields; required if the
     *                       schema uses any, otherwise unused.
     * @param $statisticalCodes Resolver for `SCALAR_LISTS` fields of item
     *                       type `statcode` against a tenant's real,
     *                       already-existing statistical codes; required
     *                       if the schema uses any (every schema in this
     *                       package does), otherwise unused. Defaults to
     *                       {@see StatisticalCodeResolver::empty()} (every
     *                       id/name lookup reports "not found") for a
     *                       build run with no live tenant to check against.
     * @param $holdingsSources Resolver for `SCALAR_FIELDS` fields of type
     *                       `live:holdingsSource` (currently only
     *                       Holdings' `sourceId`) against a tenant's
     *                       real, already-existing holdings sources;
     *                       required if the schema uses one, otherwise
     *                       unused. Defaults to
     *                       {@see HoldingsSourceResolver::empty()} (every
     *                       id/name lookup reports "not found") for a
     *                       build run with no live tenant to check against.
     * @param $locations     Resolver for `SCALAR_FIELDS` fields of type
     *                       `live:location` (Holdings' `permanentLocationId`/
     *                       `temporaryLocationId`) against a tenant's
     *                       real, already-existing locations; required
     *                       if the schema uses one, otherwise unused.
     *                       Defaults to {@see LocationResolver::empty()}
     *                       (every id/name lookup reports "not found")
     *                       for a build run with no live tenant to
     *                       check against.
     * @param $liveReferences Every other `live:<namespace>` resolver
     *                       this schema's fields use, keyed by
     *                       namespace (e.g. `instanceType`,
     *                       `materialType`, `loanType`,
     *                       `contributorNameType`, `identifierType`) —
     *                       see {@see resolveLiveReference()}. A
     *                       namespace with no entry here behaves as
     *                       {@see LiveReferenceResolver::empty()}
     *                       (every id/name lookup reports "not found").
     */
    public function __construct(
        private readonly FieldMapper $mapper,
        private readonly ValueCaster $caster,
        private readonly FolioUtils $folioUtils,
        private readonly string $schemaClass,
        private readonly string $listDelimiter = '|',
        private readonly ?ReferenceRegistry $registry = null,
        private readonly ?StatisticalCodeResolver $statisticalCodes = null,
        private readonly ?HoldingsSourceResolver $holdingsSources = null,
        private readonly ?LocationResolver $locations = null,
        /** @var array<string, LiveReferenceResolver> */
        private readonly array $liveReferences = [],
    ) {
    }

    /**
     * Build and validate one record from one row.
     *
     * @param $row    The row, keyed by lowercased column header.
     * @param $rowNum Row number, used only to phrase error messages.
     */
    public function build(array $row, int $rowNum): RecordBuildResult {
        $errors = [];
        $record = [];
        $schemaClass = $this->schemaClass;

        foreach ($schemaClass::SCALAR_FIELDS as $field => $type) {
            $raw = $this->mapper->resolve($field, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            if (str_starts_with($type, 'ref:')) {
                $record[$field] = $this->registry->resolve(substr($type, strlen('ref:')), $raw, $rowNum);
                continue;
            }
            if (str_starts_with($type, 'live:')) {
                $resolved = $this->resolveLiveReference(substr($type, strlen('live:')), $field, trim($raw), $errors, $rowNum);
                if ($resolved !== null) {
                    $record[$field] = $resolved;
                }
                continue;
            }
            $value = $this->caster->cast($raw, $type, $field, $errors, $rowNum);
            if ($value !== null) {
                $record[$field] = $value;
            }
        }

        foreach ($schemaClass::LIST_FIELDS as $field => $itemType) {
            $raw = $this->mapper->resolve($field, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            $namespace = str_starts_with($itemType, 'ref:') ? substr($itemType, strlen('ref:')) : null;
            $items = $this->caster->splitList($raw, $this->listDelimiter);
            if ($itemType === 'uuid') {
                foreach ($items as $item) {
                    if (!$this->folioUtils->isValidUuid($item)) {
                        $errors[] = "Row $rowNum: '$field' contains invalid UUID '$item'";
                    }
                }
            } elseif ($namespace !== null) {
                $items = array_map(fn($item) => $this->registry->resolve($namespace, $item, $rowNum), $items);
            } elseif (isset($schemaClass::LIST_FIELD_ENUMS[$field])) {
                $allowedValues = $schemaClass::LIST_FIELD_ENUMS[$field];
                foreach ($items as $item) {
                    if (!in_array($item, $allowedValues, true)) {
                        $errors[] = "Row $rowNum: '$field' contains '$item', which is not one of: " . implode(', ', $allowedValues);
                    }
                }
            }
            if ($items) {
                $record[$field] = $items;
            }
        }

        foreach ($schemaClass::NESTED_GROUPS as $schemaKey => $spec) {
            $instances = [];
            foreach ($this->mapper->indicesFor($schemaKey) as $index) {
                $sub = $this->buildNestedGroupInstance($schemaKey, $index, $spec, $row, $errors, $rowNum);
                if ($sub !== null) {
                    $instances[] = $sub;
                }
            }
            if ($instances !== []) {
                $record[$schemaKey] = $this->applyDefaultPrimary($instances, $spec);
            }
        }

        foreach ($schemaClass::SCALAR_LISTS as $schemaKey => $itemType) {
            $items = [];
            foreach ($this->mapper->scalarListIndicesFor($schemaKey) as $index) {
                $resolved = $this->buildScalarListInstance($schemaKey, $index, $itemType, $row, $errors, $rowNum);
                if ($resolved !== null) {
                    $items[] = $resolved;
                }
            }
            if ($items !== []) {
                $record[$schemaKey] = $items;
            }
        }

        foreach ($schemaClass::NESTED_OBJECTS as $schemaKey => $spec) {
            $obj = $this->buildNestedObject($schemaKey, $spec, $row, $errors, $rowNum);
            if ($obj !== null) {
                $record[$schemaKey] = $obj;
            }
        }

        $this->validateTopLevel($record, $errors, $rowNum);

        return new RecordBuildResult($record, $errors);
    }

    /**
     * Resolve one instance (e.g. the statistical code mapped at index 0)
     * of a repeatable scalar field — see `SCALAR_LISTS` in the class
     * docblock, and {@see FieldMapper::scalarListIndicesFor()} for how
     * instances are discovered from the mapping file.
     *
     * @param $schemaKey Schema array property name (e.g. `statisticalCodeIds`).
     * @param $index     Instance number exactly as it appears in the
     *                   mapping file's `field[N]` key (e.g.
     *                   `statisticalCodeIds` starts at `[0]`; other
     *                   repeatable fields in this package start at `[1]`
     *                   — the mapping file's own numbering, whatever it
     *                   is, is preserved verbatim in log messages), per
     *                   a {@see FieldMapper::scalarListIndicesFor()} result.
     * @param $itemType  This field's item type (currently only `statcode`).
     * @param $row       The row, keyed by lowercased column header.
     * @param $errors    Error/warning list to append to.
     * @param $rowNum    Row number, used only to phrase log messages.
     * @return The resolved value, or null if this instance's raw value
     *         was empty, or didn't resolve to anything real.
     */
    private function buildScalarListInstance(string $schemaKey, int $index, string $itemType, array $row, array &$errors, int $rowNum): ?string {
        $fullFieldName = "{$schemaKey}[{$index}]";
        $raw = $this->mapper->resolve($fullFieldName, $row);
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);

        if ($itemType === 'statcode') {
            return $this->resolveStatisticalCode($fullFieldName, $raw, $errors, $rowNum);
        }

        return $raw;
    }

    /**
     * Resolve one statistical code value (either a real statistical code
     * UUID or a statistical code name — see the mapping file's
     * `statisticalCodeIds[N]` entries) against {@see StatisticalCodeResolver}:
     * a UUID is only kept if it's a real, already-existing statistical
     * code (otherwise it's dropped and a warning is logged, rather than
     * passing through an id that would silently fail to mean anything
     * once loaded); a non-UUID value is treated as a code name and
     * replaced with its real id, if found (dropped and logged if not).
     *
     * @param $field  Field name (including its `[N]`), used only to
     *                phrase log messages.
     * @param $item   The trimmed raw value to resolve.
     * @param $errors Error/warning list to append to.
     * @param $rowNum Row number, used only to phrase log messages.
     * @return The resolved, verified statistical code id, or null if it
     *         didn't resolve to anything real.
     */
    private function resolveStatisticalCode(string $field, string $item, array &$errors, int $rowNum): ?string {
        $resolver = $this->statisticalCodes ?? StatisticalCodeResolver::empty();

        if ($this->folioUtils->isValidUuid($item)) {
            if ($resolver->existsById($item)) {
                return $item;
            }
            $errors[] = "Row $rowNum: Warning: '$field' references statistical code id '$item', which was not found in the tenant's statistical codes";
            return null;
        }

        $id = $resolver->idForName($item);
        if ($id !== null) {
            return $id;
        }
        $errors[] = "Row $rowNum: Warning: '$field' references statistical code name '$item', which was not found in the tenant's statistical codes";
        return null;
    }

    /**
     * Resolve a `live:<namespace>` scalar field's raw value (either a
     * real id or a name) against the matching live-tenant resolver.
     * Unlike a `statcode` {@see SCALAR_LISTS} entry, a failed lookup
     * here is logged as a fatal error, not a warning — every schema
     * that declares a `live:` field marks it required (see
     * {@see \SourceFolioRecords\Schema\HoldingsSchema}), so a value that
     * doesn't resolve leaves the field genuinely missing rather than
     * silently dropping one item out of a list.
     *
     * @param $namespace Which live resolver to use (currently only
     *                   `holdingsSource`).
     * @param $field     Field name, used only to phrase the error message.
     * @param $item      The trimmed raw value to resolve.
     * @param $errors    Error list to append to.
     * @param $rowNum    Row number, used only to phrase the error message.
     * @return The resolved, verified id, or null if it didn't resolve
     *         to anything real (or the namespace is unrecognized).
     */
    private function resolveLiveReference(string $namespace, string $field, string $item, array &$errors, int $rowNum): ?string {
        $label = self::LIVE_REFERENCE_LABELS[$namespace] ?? null;
        if ($label === null) {
            return null;
        }
        $resolver = match ($namespace) {
            'holdingsSource' => $this->holdingsSources ?? HoldingsSourceResolver::empty(),
            'location' => $this->locations ?? LocationResolver::empty(),
            default => $this->liveReferences[$namespace] ?? LiveReferenceResolver::empty(),
        };

        if ($this->folioUtils->isValidUuid($item)) {
            if ($resolver->existsById($item)) {
                return $item;
            }
            $errors[] = "Row $rowNum: '$field' references $label id '$item', which was not found in the tenant's {$label}s";
            return null;
        }

        $id = $resolver->idForName($item);
        if ($id !== null) {
            return $id;
        }
        $errors[] = "Row $rowNum: '$field' references $label name '$item', which was not found in the tenant's {$label}s";
        return null;
    }

    /**
     * Build a single nested object (e.g. an Item's `status`) from its
     * mapped sub-fields — see `NESTED_OBJECTS` in the class docblock.
     * Unlike {@see buildNestedGroupInstance()}, there is exactly one
     * instance of this field (no `[N]` — bracket-less dotted keys like
     * `status.name` resolve to instance 1 via
     * {@see FieldMapper}'s own bracket-less shorthand), and the result
     * is the object itself, not a single-element array of one.
     *
     * @param $schemaKey Schema object property name (e.g. `status`).
     * @param $spec      This field's `NESTED_OBJECTS` entry.
     * @param $row       The row, keyed by lowercased column header.
     * @param $errors    Error list to append to.
     * @param $rowNum    Row number, used only to phrase error messages.
     * @return The built object, or null if every field was empty (i.e.
     *         it doesn't apply to this row).
     */
    private function buildNestedObject(string $schemaKey, array $spec, array $row, array &$errors, int $rowNum): ?array {
        $obj = [];
        foreach ($spec['fields'] as $subField => $subType) {
            $fullFieldName = "{$schemaKey}.{$subField}";
            $raw = $this->mapper->resolve($fullFieldName, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }

            $value = $this->caster->cast($raw, $subType, $fullFieldName, $errors, $rowNum);
            if ($value === null) {
                continue;
            }
            if (isset($spec['enums'][$subField]) && !in_array($value, $spec['enums'][$subField], true)) {
                $errors[] = "Row $rowNum: '$fullFieldName' value '$value' is not one of: " . implode(', ', $spec['enums'][$subField]);
            }
            $obj[$subField] = $value;
        }

        if ($obj === []) {
            return null;
        }

        foreach ($spec['required'] as $requiredField) {
            if (!isset($obj[$requiredField]) || $obj[$requiredField] === '') {
                $errors[] = "Row $rowNum: '$schemaKey' object is missing required field '$schemaKey.$requiredField'";
            }
        }

        return $obj;
    }

    /**
     * Build one instance of a nested group (e.g. the 2nd identifier)
     * from its mapped sub-fields.
     *
     * @param $schemaKey Schema array property name (e.g. `identifiers`).
     * @param $index     1-based instance number within this group,
     *                   matching a {@see FieldMapper::indicesFor()} result.
     * @param $spec      This group's `NESTED_GROUPS` entry.
     * @param $row       The row, keyed by lowercased column header.
     * @param $errors    Error list to append to.
     * @param $rowNum    Row number, used only to phrase error messages.
     * @return The built sub-object, or null if every field in this
     *         instance was empty (i.e. it doesn't apply to this row).
     */
    private function buildNestedGroupInstance(string $schemaKey, int $index, array $spec, array $row, array &$errors, int $rowNum): ?array {
        $groupLabel = "{$schemaKey}[{$index}]";
        $sub = [];
        $hasNonLiteralData = false;
        foreach ($spec['fields'] as $subField => $subType) {
            $fullFieldName = "{$groupLabel}.{$subField}";
            $raw = $this->mapper->resolve($fullFieldName, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            if (!$this->mapper->isLiteral($fullFieldName)) {
                $hasNonLiteralData = true;
            }

            if ($subType === 'uuid_list') {
                $items = $this->caster->splitList($raw, $this->listDelimiter);
                foreach ($items as $item) {
                    if (!$this->folioUtils->isValidUuid($item)) {
                        $errors[] = "Row $rowNum: '$fullFieldName' contains invalid UUID '$item'";
                    }
                }
                if ($items) {
                    $sub[$subField] = $items;
                }
                continue;
            }

            if (str_starts_with($subType, 'ref:')) {
                $sub[$subField] = $this->registry->resolve(substr($subType, strlen('ref:')), $raw, $rowNum);
                continue;
            }

            if (str_starts_with($subType, 'live:')) {
                $resolved = $this->resolveLiveReference(substr($subType, strlen('live:')), $fullFieldName, trim($raw), $errors, $rowNum);
                if ($resolved !== null) {
                    $sub[$subField] = $resolved;
                }
                continue;
            }

            $value = $this->caster->cast($raw, $subType, $fullFieldName, $errors, $rowNum);
            if ($value === null) {
                continue;
            }
            if (isset($spec['enums'][$subField]) && !in_array($value, $spec['enums'][$subField], true)) {
                $errors[] = "Row $rowNum: '$fullFieldName' value '$value' is not one of: " . implode(', ', $spec['enums'][$subField]);
            }
            if (isset($spec['pattern'][$subField]) && !preg_match($spec['pattern'][$subField], (string) $value)) {
                $errors[] = "Row $rowNum: '$fullFieldName' value '$value' does not match the expected format";
            }
            $sub[$subField] = $value;
        }

        if (!$hasNonLiteralData) {
            // Every populated field in this instance came from a hard-coded
            // literal (e.g. a fixed identifierTypeId with no actual value
            // data on this row) - nothing on this row actually asked for
            // this instance to exist, so it doesn't apply, rather than
            // being "built" and then flagged for a missing required field.
            return null;
        }

        foreach ($spec['required'] as $requiredField) {
            if (!isset($sub[$requiredField]) || $sub[$requiredField] === '') {
                $errors[] = "Row $rowNum: '$groupLabel' group is missing required field '$groupLabel.$requiredField'";
            }
        }

        return $sub;
    }

    /**
     * If a group supports `isPrimary` and no instance is explicitly
     * `true`, pick one to default to `true` (none of this package's
     * schemas currently declare `primaryFlag`, but the mechanism is kept
     * for parity with the pattern this class is modeled on).
     *
     * @param $instances Non-empty list of built sub-objects for one group.
     * @param $spec      This group's `NESTED_GROUPS` entry.
     */
    private function applyDefaultPrimary(array $instances, array $spec): array {
        if (empty($spec['primaryFlag'])) {
            return $instances;
        }
        foreach ($instances as $sub) {
            if (($sub['isPrimary'] ?? null) === true) {
                return $instances;
            }
        }
        foreach ($instances as $index => $sub) {
            if (!array_key_exists('isPrimary', $sub)) {
                $instances[$index]['isPrimary'] = true;
                return $instances;
            }
        }
        return $instances;
    }

    /**
     * Validate the fully-assembled record's top-level required fields
     * and cross-field constraints, appending to `$errors`. `id`, if
     * present, is always checked as a UUID regardless of schema — every
     * FOLIO record shares that constraint.
     */
    private function validateTopLevel(array $record, array &$errors, int $rowNum): void {
        $schemaClass = $this->schemaClass;

        foreach ($schemaClass::REQUIRED_FIELDS as $requiredField) {
            if (empty($record[$requiredField])) {
                $errors[] = "Row $rowNum: missing required field '$requiredField'";
            }
        }
        foreach ($schemaClass::TOP_LEVEL_PATTERNS as $field => $pattern) {
            if (isset($record[$field]) && !preg_match($pattern, (string) $record[$field])) {
                $errors[] = "Row $rowNum: '$field' value '{$record[$field]}' does not match the expected format";
            }
        }
        foreach ($schemaClass::TOP_LEVEL_ENUMS as $field => $allowedValues) {
            if (isset($record[$field]) && !in_array($record[$field], $allowedValues, true)) {
                $errors[] = "Row $rowNum: '$field' value '{$record[$field]}' is not one of: " . implode(', ', $allowedValues);
            }
        }
        if (isset($record['id']) && !$this->folioUtils->isValidUuid((string) $record['id'])) {
            $errors[] = "Row $rowNum: 'id' value '{$record['id']}' is not a valid UUID";
        }
    }
}
