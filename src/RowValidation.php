<?php declare(strict_types=1);

namespace SourceFolioRecords;

/**
 * Distinguishes a fatal validation error (missing required field,
 * invalid UUID, disallowed enum value, ...) from a mere warning (e.g. a
 * `statisticalCodeIds` entry that didn't resolve to a real code — see
 * {@see RecordBuilder::resolveStatisticalCode()}) among the string
 * messages a {@see RecordBuildResult} collects. Only a fatal error means
 * the record it came from is unfit to ship; a warning is already fully
 * handled at the point it's logged (the one bad value was dropped, the
 * rest of the record still built) and shouldn't stop the row.
 */
final class RowValidation {
    /**
     * @param $errors Error/warning messages, typically the combined
     *                {@see RecordBuildResult::getErrors()} of every
     *                record built from one row.
     * @return True if at least one message is a fatal error (i.e. not a
     *         `Warning:`-labeled one).
     */
    public static function hasFatalErrors(array $errors): bool {
        foreach ($errors as $error) {
            if (!self::isWarning($error)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether one error message is a warning rather than a fatal error —
     * see {@see RecordBuilder::resolveStatisticalCode()}, the only
     * source of `Warning:`-labeled messages today.
     */
    public static function isWarning(string $error): bool {
        return str_contains($error, ': Warning:');
    }

    private function __construct() {
        // Static helper; never instantiated.
    }
}
