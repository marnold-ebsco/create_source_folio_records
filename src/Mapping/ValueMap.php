<?php declare(strict_types=1);

namespace SourceFolioRecords\Mapping;

use RuntimeException;

/**
 * A two-column reference data map (legacy value -> FOLIO name/code), read
 * from a TSV whose first row is a header. The header's first cell names
 * the legacy source column. Special legacy values:
 *   `*`        default, used when a value has no entry of its own
 *   `(blank)`  used when the row's value is empty
 * Matching is case-insensitive and trimmed.
 */
final class ValueMap {
    /** @param array<string, string> $entries lowercased legacy value => FOLIO value */
    public function __construct(
        private readonly string $sourceColumn,
        private readonly array $entries,
    ) {
    }

    public static function fromFile(string $path): self {
        $fh = is_readable($path) ? fopen($path, 'rb') : false;
        if ($fh === false) {
            throw new RuntimeException("Cannot read reference data map '$path'");
        }
        $header = fgetcsv($fh, null, "\t", '"', '');
        $sourceColumn = trim((string) ($header[0] ?? ''));
        $entries = [];
        while (($cells = fgetcsv($fh, null, "\t", '"', '')) !== false) {
            if ($cells === [null] || count($cells) < 2) {
                continue;
            }
            $folio = trim((string) $cells[1]);
            if ($folio === '') {
                continue;
            }
            $entries[strtolower(trim((string) $cells[0]))] = $folio;
        }
        fclose($fh);
        return new self($sourceColumn, $entries);
    }

    /** Lowercased name of the legacy column this map is keyed on. */
    public function sourceColumn(): string {
        return strtolower($this->sourceColumn);
    }

    /** @return The mapped FOLIO value, or null if neither an entry nor a `*` default applies. */
    public function lookup(?string $legacy): ?string {
        $key = strtolower(trim((string) $legacy));
        if ($key === '') {
            return $this->entries['(blank)'] ?? $this->entries['*'] ?? null;
        }
        return $this->entries[$key] ?? $this->entries['*'] ?? null;
    }
}
