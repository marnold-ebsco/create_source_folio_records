<?php declare(strict_types=1);

namespace SourceFolioRecords\Io;

use RuntimeException;

/**
 * A single run's error/validation log file. Every {@see open()} call
 * creates a brand-new file, creating its parent directory if necessary.
 */
final class ErrorLog {
    /** @var resource|null */
    private $handle = null;

    /**
     * @param $path Path of the log file to create.
     */
    public function __construct(private readonly string $path) {
    }

    /**
     * A fresh, collision-resistant token identifying one run, named after
     * the input file so multiple inputs' logs/output directories are easy
     * to tell apart — e.g. `ezborrow_20260925_154212_9c3ada`. Shared by
     * {@see defaultPathFor()} and `bin/build-inventory`'s default output
     * directory naming, so a run's log file and output directory carry
     * the same token when neither `--error-log` nor `--output-dir` was
     * given explicitly.
     *
     * @param $inputPath Path of the file being imported.
     */
    public static function runToken(string $inputPath): string {
        $base = pathinfo($inputPath, PATHINFO_FILENAME);
        $timestamp = date('Ymd_His');
        $unique = bin2hex(random_bytes(3));
        return "{$base}_{$timestamp}_{$unique}";
    }

    /**
     * A log file path for a given run token (see {@see runToken()}) under
     * `$logsDir`.
     */
    public static function pathFor(string $logsDir, string $token): string {
        return rtrim($logsDir, '/\\') . "/$token.log";
    }

    /**
     * Build a fresh, collision-resistant default log path for one run —
     * see {@see runToken()}. Generates its own token; use {@see runToken()}
     * plus {@see pathFor()} directly instead when the same token also
     * needs to name something else (e.g. `bin/build-inventory`'s default
     * output directory).
     *
     * @param $inputPath Path of the file being imported.
     * @param $logsDir   Directory the log file should live in.
     */
    public static function defaultPathFor(string $inputPath, string $logsDir): string {
        return self::pathFor($logsDir, self::runToken($inputPath));
    }

    /**
     * Create the log file (and its parent directory, if needed).
     *
     * @throws RuntimeException If the directory can't be created, or
     *                          the file can't be opened for writing.
     */
    public function open(): void {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory '$dir'");
        }
        $handle = fopen($this->path, 'w');
        if ($handle === false) {
            throw new RuntimeException("Cannot write to error log '{$this->path}'");
        }
        $this->handle = $handle;
    }

    /** Append one line (a trailing newline is added). */
    public function write(string $line): void {
        fwrite($this->handle, $line . "\n");
    }

    /** @return The path this log was opened at. */
    public function getPath(): string {
        return $this->path;
    }

    /** Close the underlying file handle, if open. */
    public function close(): void {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
