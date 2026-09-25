<?php declare(strict_types=1);

namespace SourceFolioRecords\Schema;

/**
 * Static description of the slice of the FOLIO `mod-inventory-storage`
 * `instance` schema this package builds:
 * https://s3.amazonaws.com/foliodocs/api/mod-inventory/r/inventory.html#inventory_instances_post
 *
 * Deliberately narrower than the full schema — only the fields actually
 * requested (discovery suppress, instance status term, title,
 * publication date, ISBN, ISSN, resource type, contributors,
 * statistical codes) are modeled; everything else the real schema
 * supports (alternative titles, classifications, series, subjects,
 * electronic access, notes, ...) is left out rather than guessed at from
 * data that doesn't support it.
 *
 * Holds no behavior, only data — {@see \SourceFolioRecords\RecordBuilder}
 * reads these constants to know which fields exist, how to cast/validate
 * each one, and how the nested groups (identifiers/contributors/
 * publication) are shaped.
 */
final class InstanceSchema {
    /**
     * Top-level scalar fields: schema property name => cast type.
     * `source` is required by the real `mod-inventory-storage` instance
     * schema even though it wasn't spelled out in the user's field
     * list — kept here deliberately (see `REQUIRED_FIELDS` below). It's
     * a plain string (not a UUID reference), so unlike
     * `permanentLocationId`/`sourceId`/`statisticalCodeIds` it needs no
     * live-tenant verification; `mapping/instance_field_mapping.json`
     * hard-codes it to `FOLIO`, the standard value for a locally
     * created (not MARC-derived) instance.
     */
    public const SCALAR_FIELDS = [
        'title' => 'string',
        'discoverySuppress' => 'bool',
        'statusId' => 'ref:instanceStatus',
        'instanceTypeId' => 'live:instanceType',
        'source' => 'string',
    ];

    /** Top-level list fields: schema property name => list item type. (None for this narrowed schema.) */
    public const LIST_FIELDS = [];

    /**
     * Nested groups keyed by their schema array property name. Each
     * `identifierTypeId`/`contributorNameTypeId` resolves against a
     * live-tenant {@see \SourceFolioRecords\LiveReferenceResolver}
     * (`live:...`), not a literal string — see
     * {@see \SourceFolioRecords\RecordBuilder::buildNestedGroupInstance()}.
     * More than one identifier per instance is supported via
     * `identifiers[1].*`/`identifiers[2].*` in the mapping file — see
     * {@see \SourceFolioRecords\Mapping\FieldMapper::indicesFor()}.
     */
    public const NESTED_GROUPS = [
        'identifiers' => [
            'required' => ['value', 'identifierTypeId'],
            'fields' => [
                'value' => 'string',
                'identifierTypeId' => 'live:identifierType',
            ],
        ],
        'contributors' => [
            'required' => ['name', 'contributorNameTypeId'],
            'fields' => [
                'name' => 'string',
                'contributorNameTypeId' => 'live:contributorNameType',
            ],
        ],
        'publication' => [
            'required' => [],
            'fields' => [
                'dateOfPublication' => 'string',
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

    /** Single nested objects. (None for this narrowed schema.) */
    public const NESTED_OBJECTS = [];

    /**
     * Top-level fields the schema marks as required. `source` is
     * required by the real `mod-inventory-storage` instance schema
     * even though it wasn't spelled out in the user's field list — kept
     * here deliberately (see `SCALAR_FIELDS` above).
     */
    public const REQUIRED_FIELDS = ['title', 'instanceTypeId', 'source'];

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
