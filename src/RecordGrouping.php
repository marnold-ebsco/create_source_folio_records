<?php declare(strict_types=1);

namespace SourceFolioRecords;

/**
 * Computes deduplication keys `bin/build-inventory` uses to collapse
 * repeated rows into a single shared Instance (same title + author)
 * and, within that, a single shared Holdings record (same title +
 * author + call number + location) — e.g. the same title appearing on
 * several circulation rows (one row per physical copy/barcode) becomes
 * one Instance, with one Holdings per distinct call number/location
 * combination, and one Item per row attached to whichever Holdings its
 * own call number/location matches. Items are never deduplicated —
 * every row still produces its own Item.
 *
 * Two rows are only treated as "the same" if their title/author/call
 * number/location text matches exactly after normalizing (trim,
 * collapse internal whitespace, lowercase) — a typo or a genuinely
 * different value in one row's data will not be merged.
 */
final class RecordGrouping {
    /**
     * @param $row The row, keyed by lowercased column header.
     * @return A key identifying this row's Instance: identical for
     *         every row sharing the same (normalized) title and author.
     */
    public static function instanceKey(array $row): string {
        return self::normalize((string) ($row['title'] ?? ''))
            . '|' . self::normalize((string) ($row['author_name'] ?? ''));
    }

    /**
     * @param $row The row, keyed by lowercased column header.
     * @return A key identifying this row's Holdings: identical for
     *         every row sharing the same Instance key plus the same
     *         (normalized) call number and location. Location mirrors
     *         `permanentLocationId`'s own mapping fallback (permanent
     *         shelving location, else holding location — see
     *         `mapping/holdings_field_mapping.json`) so the grouping
     *         matches what actually ends up in the built Holdings record.
     */
    public static function holdingsKey(array $row): string {
        $location = trim((string) ($row['item_permanent_shelving_location'] ?? ''));
        if ($location === '') {
            $location = (string) ($row['item_holding_location'] ?? '');
        }
        return self::instanceKey($row)
            . '|' . self::normalize((string) ($row['item_call_number'] ?? ''))
            . '|' . self::normalize($location);
    }

    private static function normalize(string $value): string {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function __construct() {
        // Static helper; never instantiated.
    }
}
