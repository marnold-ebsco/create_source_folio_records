<?php declare(strict_types=1);

namespace SourceFolioRecords\Cli;

/**
 * Finds/prompts for a FOLIO connection config `.ini` file (the format
 * `\phpFolioClient\FolioConfig` reads) at a given directory — shared by
 * `bin/build-inventory` and `bin/test-connection` so `--config` can be
 * picked interactively instead of typed out in full.
 */
final class ConfigFile {
    /**
     * List every `*.ini` file directly under `$dir`, sorted by name. A
     * `*.ini.example` template (see `tenant.ini.example`) never matches,
     * since its filename doesn't end in `.ini`.
     */
    public static function listIn(string $dir): array {
        $files = glob(rtrim($dir, '/\\') . '/*.ini') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Prompt for which `.ini` file under `$dir` to use, when `--config`
     * wasn't given — lets a `tenant.ini`-style connection file be picked
     * interactively instead of typed out in full.
     *
     * @return The chosen file's path, or `''` if `$dir` has no `.ini`
     *         files, or the user declines every option offered.
     */
    public static function promptFor(string $dir): string {
        $files = self::listIn($dir);
        if ($files === []) {
            return '';
        }
        if (count($files) === 1) {
            fwrite(STDERR, "Use '" . basename($files[0]) . "' as the FOLIO connection config? [Y/n]: ");
            $answer = trim((string) fgets(STDIN));
            return ($answer === '' || strcasecmp($answer, 'y') === 0) ? $files[0] : '';
        }

        fwrite(STDERR, "Which FOLIO connection config file should be used?\n");
        foreach ($files as $i => $file) {
            fwrite(STDERR, '  ' . ($i + 1) . ') ' . basename($file) . "\n");
        }
        fwrite(STDERR, '  ' . (count($files) + 1) . ") None\n");
        fwrite(STDERR, 'Enter a number: ');
        $answer = trim((string) fgets(STDIN));

        if (ctype_digit($answer) && isset($files[((int) $answer) - 1])) {
            return $files[((int) $answer) - 1];
        }
        return '';
    }
}
