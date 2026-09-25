<?php declare(strict_types=1);

namespace SourceFolioRecords\Loading;

/**
 * Reads a record file written by `bin/build-inventory`: either a single
 * JSON array, or ndjson (one JSON object per line). Format is detected
 * per file, by its first non-whitespace character, so instances/holdings/
 * items don't all have to share the same `--format`.
 */
final class InventoryFileReader {
    /**
     * @param $path Path to the record file.
     * @return The decoded records, in file order; empty if the file
     *         doesn't exist, is empty, or contains no valid JSON. A
     *         malformed ndjson line is skipped rather than failing the
     *         whole read; a malformed JSON array file yields no records.
     */
    public static function read(string $path): array {
        if (!is_file($path)) {
            return [];
        }
        $contents = trim((string) file_get_contents($path));
        if ($contents === '') {
            return [];
        }
        if ($contents[0] === '[') {
            $decoded = json_decode($contents, true);
            return is_array($decoded) ? $decoded : [];
        }

        $records = [];
        foreach (preg_split('/\r?\n/', $contents) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
        return $records;
    }
}
