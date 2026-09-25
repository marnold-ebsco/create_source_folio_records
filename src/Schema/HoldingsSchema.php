<?php declare(strict_types=1);

namespace SourceFolioRecords\Schema;

/**
 * Static description of the slice of the FOLIO `mod-inventory-storage`
 * `holdingsRecord` schema this package builds: permanent/temporary
 * location and call number type/prefix/number/suffix, per the user's
 * requested field list, plus `sourceId` (see below). `instanceId` is
 * deliberately not modeled here — it's a cross-record link set
 * programmatically by `bin/build-inventory` after this row's Instance
 * has been built, not something any column ever maps to.
 *
 * Holds no behavior, only data — {@see \SourceFolioRecords\RecordBuilder}
 * reads these constants to know which fields exist and how to cast/
 * validate each one.
 */
final class HoldingsSchema {
    /**
     * Top-level scalar fields: schema property name => cast type.
     * `sourceId` (`live:holdingsSource`) is required by the real
     * `mod-inventory-storage` holdings schema even though it wasn't
     * spelled out in the user's field list — kept here deliberately,
     * same as `permanentLocationId` (see `REQUIRED_FIELDS` below).
     * Both are resolved by {@see \SourceFolioRecords\RecordBuilder}
     * against {@see \SourceFolioRecords\HoldingsSourceResolver}/
     * {@see \SourceFolioRecords\LocationResolver}, since (unlike `ref:`
     * fields) they must be a real, already-existing record, not a
     * fabricated id — a fabricated location id is exactly what broke
     * the first real load attempt against a live tenant (see
     * `mapping/holdings_field_mapping.json`'s own note on why
     * `permanentLocationId` is hard-coded to `Migration`).
     * `temporaryLocationId` stays `ref:` (fabricated): it's optional,
     * and neither `ezborrow.tsv` nor `source_folio.tsv` ever actually
     * populates it, so there's no real data to get this wrong for yet —
     * revisit if a source file ever does carry temporary location data.
     */
    public const SCALAR_FIELDS = [
        'permanentLocationId' => 'live:location',
        'temporaryLocationId' => 'ref:location',
        'callNumberTypeId' => 'ref:callNumberType',
        'callNumberPrefix' => 'string',
        'callNumber' => 'string',
        'callNumberSuffix' => 'string',
        'sourceId' => 'live:holdingsSource',
    ];

    /** Top-level list fields. (None for this narrowed schema.) */
    public const LIST_FIELDS = [];

    /** Nested groups. (None for this narrowed schema.) */
    public const NESTED_GROUPS = [];

    /**
     * Repeatable scalar fields keyed by their schema array property name
     * => item type. `statcode` is resolved by
     * {@see \SourceFolioRecords\RecordBuilder} against a
     * {@see \SourceFolioRecords\StatisticalCodeResolver}. Each value is
     * one mapping entry (`statisticalCodeIds[0]`, `statisticalCodeIds[1]`,
     * ...) rather than a single delimited cell, so the mapping file can
     * add as many as needed — see
     * {@see \SourceFolioRecords\Mapping\FieldMapper::scalarListIndicesFor()}.
     */
    public const SCALAR_LISTS = [
        'statisticalCodeIds' => 'statcode',
    ];

    /** Single nested objects. (None for this narrowed schema.) */
    public const NESTED_OBJECTS = [];

    /**
     * Top-level fields the schema marks as required. `permanentLocationId`
     * and `sourceId` are both required by the real `mod-inventory-storage`
     * holdings schema even though only `permanentLocationId` was spelled
     * out in the user's field list — kept here deliberately (see
     * `HoldingsSchema`'s own class docblock).
     */
    public const REQUIRED_FIELDS = ['permanentLocationId', 'sourceId'];

    /** Allowed values for top-level enum fields. (None for this narrowed schema.) */
    public const TOP_LEVEL_ENUMS = [];

    /** Regex patterns top-level fields must match, if present. (None for this narrowed schema.) */
    public const TOP_LEVEL_PATTERNS = [];

    /** Allowed values for each item of an enum-constrained list field. (None for this narrowed schema.) */
    public const LIST_FIELD_ENUMS = [];

    private function __construct() {
        // Static data holder; never instantiated.
    }
}
