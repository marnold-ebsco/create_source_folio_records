<?php declare(strict_types=1);

namespace SourceFolioRecords\Schema;

/**
 * Static description of the slice of the FOLIO `mod-inventory-storage`
 * `item` schema this package builds: barcode, material type, permanent
 * loan type, circulation notes, and electronic access, per the user's
 * requested field list, plus `status.name` (see `NESTED_OBJECTS` below).
 * `holdingsRecordId` is deliberately not modeled here — it's a
 * cross-record link set programmatically by `bin/build-inventory` after
 * this row's Holdings has been built, not something any column ever
 * maps to.
 *
 * Holds no behavior, only data — {@see \SourceFolioRecords\RecordBuilder}
 * reads these constants to know which fields exist and how to cast/
 * validate each one.
 */
final class ItemSchema {
    /**
     * Top-level scalar fields: schema property name => cast type.
     * `materialTypeId`/`permanentLoanTypeId` resolve against a
     * live-tenant {@see \SourceFolioRecords\LiveReferenceResolver}
     * (`live:...`), not a fabricated id — a made-up material/loan type
     * id is exactly what broke the first real load attempt against a
     * live tenant (a foreign-key constraint failure), same as Holdings'
     * `permanentLocationId`.
     */
    public const SCALAR_FIELDS = [
        'barcode' => 'string',
        'materialTypeId' => 'live:materialType',
        'permanentLoanTypeId' => 'live:loanType',
    ];

    /** Top-level list fields. (None for this narrowed schema.) */
    public const LIST_FIELDS = [];

    /**
     * Nested groups keyed by their schema array property name.
     * `circulationNotes` covers both check-in and check-out notes (see
     * `item_field_mapping.json`'s two mapped instances); `electronicAccess`
     * is fully modeled but has no source columns in `ezborrow.tsv`, so it
     * never actually populates for this dataset — the infrastructure is
     * still here for a source file that does carry URL data.
     */
    public const NESTED_GROUPS = [
        'circulationNotes' => [
            'required' => ['note', 'noteType'],
            'fields' => [
                'note' => 'string',
                'noteType' => 'string',
                'staffOnly' => 'bool',
            ],
            'enums' => ['noteType' => ['Check in', 'Check out']],
        ],
        'electronicAccess' => [
            'required' => ['uri'],
            'fields' => [
                'uri' => 'string',
                'linkText' => 'string',
                'materialsSpecification' => 'string',
                'publicNote' => 'string',
                'relationshipId' => 'string',
            ],
        ],
    ];

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

    /**
     * Single nested objects keyed by their schema object property name.
     * `status` is required by the real `mod-inventory-storage` item
     * schema even though it wasn't spelled out in the user's field
     * list — kept here deliberately (see `ItemSchema`'s own class
     * docblock). `status.name` is hard-coded to a literal `Available`
     * in `item_field_mapping.json` rather than mapped from
     * `Item_Status_Current_Status`: that column's values (`AVAILABLE`/
     * `ON_HOLD`/`ON_LOAN`) are the source system's own circulation
     * codes, not FOLIO's item status enum, and translating them isn't
     * something this project's data actually needs yet.
     */
    public const NESTED_OBJECTS = [
        'status' => [
            'required' => ['name'],
            'fields' => [
                'name' => 'string',
            ],
            'enums' => [
                'name' => [
                    'Aged to lost', 'Available', 'Awaiting pickup', 'Awaiting delivery',
                    'Checked out', 'Claimed returned', 'Declared lost', 'In process',
                    'In process (non-requestable)', 'In transit', 'Intellectual item',
                    'Long missing', 'Lost and paid', 'Missing', 'On order', 'Paged',
                    'Restricted', 'Order closed', 'Unavailable', 'Unknown', 'Withdrawn',
                ],
            ],
        ],
    ];

    /** Top-level fields the schema marks as required. */
    public const REQUIRED_FIELDS = ['materialTypeId', 'permanentLoanTypeId', 'status'];

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
