<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Cli\ConfigFile;

final class ConfigFileTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/source_folio_records_config_file_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testListInReturnsNoFilesWhenNoneExist(): void {
        $this->assertSame([], ConfigFile::listIn($this->dir));
    }

    public function testListInFindsIniFilesSortedByName(): void {
        touch("{$this->dir}/tenant.ini");
        touch("{$this->dir}/bucknell.ini");

        $this->assertSame(
            ["{$this->dir}/bucknell.ini", "{$this->dir}/tenant.ini"],
            ConfigFile::listIn($this->dir)
        );
    }

    public function testListInIgnoresIniExampleTemplates(): void {
        touch("{$this->dir}/tenant.ini.example");

        $this->assertSame([], ConfigFile::listIn($this->dir));
    }
}
