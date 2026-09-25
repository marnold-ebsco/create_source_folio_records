<?php declare(strict_types=1);

namespace SourceFolioRecords;

/**
 * Computes the one stable identifier a source row is keyed by — used
 * both as the `legacyIdentifier` written onto the built Instance, and as
 * the {@see ReferenceRegistry} key for that row's deterministic Instance/
 * Holdings/Item ids, so the same row always produces the same three ids
 * on a re-run.
 */
final class LegacyIdentifier {
    /**
     * Prefers the row's barcode (unique per physical item in practice);
     * falls back to a hash of title/author/publication date/ISBN when
     * there's no barcode, so a row with no barcode still gets a stable,
     * reasonably-unique identifier instead of colliding with every other
     * barcode-less row.
     *
     * @param $row The row, keyed by lowercased column header (as yielded
     *             by {@see \SourceFolioRecords\Io\DelimitedFileReader}).
     */
    public static function compute(array $row): string {
        $barcode = trim((string) ($row['item_barcode'] ?? ''));
        if ($barcode !== '') {
            return $barcode;
        }

        $parts = [
            trim((string) ($row['title'] ?? '')),
            trim((string) ($row['author_name'] ?? '')),
            trim((string) ($row['publication_date'] ?? '')),
            trim((string) ($row['title_isbn'] ?? '')),
        ];
        return sha1(implode('|', $parts));
    }
}
