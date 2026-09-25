<?php declare(strict_types=1);

namespace SourceFolioRecords\Loading;

use SourceFolioRecords\Io\ErrorLog;
use phpFolioClient\FolioClient;

/**
 * Loads one record type's already-built records into a live FOLIO tenant
 * via {@see FolioClient::upsert()} (POST if the record's id doesn't exist
 * yet, PUT if it does). A record that fails to load is logged and
 * skipped rather than aborting the rest of the batch — see
 * `bin/load-inventory`, which runs this once per record type
 * (instances, then holdings, then items) and gives each its own
 * timestamped {@see ErrorLog}.
 *
 * Fields that exist only for this package's own build-time bookkeeping
 * (currently just an Instance's `legacyIdentifier` — see
 * \SourceFolioRecords\LegacyIdentifier) aren't part of FOLIO's real
 * schema, and FOLIO's strict JSON deserialization rejects any field it
 * doesn't recognize. They stay in the build output (`instances.json`,
 * etc.) for traceability, but are stripped from the payload here, right
 * before it's sent — the one and only place a record actually leaves
 * this tool.
 */
final class InventoryLoader {
    /**
     * Record fields to omit from the outgoing payload, keyed by record
     * type label (matching `bin/load-inventory`'s `$recordType` argument
     * to {@see loadRecordType()}) — build-time-only bookkeeping fields
     * that FOLIO's real schema doesn't recognize.
     */
    private const BOOKKEEPING_FIELDS = [
        'instances' => ['legacyIdentifier'],
    ];

    public function __construct(
        private readonly FolioClient $client,
    ) {
    }

    /**
     * Upsert every record of one type, logging any failure rather than
     * throwing.
     *
     * @param $recordType Human-readable label for this batch (e.g.
     *                     `instances`), used both to prefix log lines
     *                     and to look up which bookkeeping fields (if
     *                     any) to strip before sending — see
     *                     {@see BOOKKEEPING_FIELDS}.
     * @param $endpoint   FOLIO storage endpoint to upsert against (e.g.
     *                    `instance-storage/instances`).
     * @param $records    The records to load, as decoded arrays.
     * @param $errorLog   Where to log failures (already open; not
     *                    opened or closed here).
     * @return [$succeeded, $failed] counts.
     */
    public function loadRecordType(string $recordType, string $endpoint, array $records, ErrorLog $errorLog): array {
        $succeeded = 0;
        $failed = 0;
        $bookkeepingFields = self::BOOKKEEPING_FIELDS[$recordType] ?? [];
        foreach ($records as $record) {
            $id = is_array($record) && isset($record['id']) ? (string) $record['id'] : '(no id)';
            $payload = $bookkeepingFields !== [] ? array_diff_key($record, array_flip($bookkeepingFields)) : $record;
            try {
                $this->client->upsert($endpoint, $payload);
                $succeeded++;
            } catch (\Throwable $e) {
                $failed++;
                $errorLog->write("[$recordType] id=$id: " . $e->getMessage());
            }
        }
        return [$succeeded, $failed];
    }
}
