<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests\Loading;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Loading\InventoryFileReader;

final class InventoryFileReaderTest extends TestCase {
    private string $path;

    protected function setUp(): void {
        $this->path = tempnam(sys_get_temp_dir(), 'inventory_file_reader_test_');
    }

    protected function tearDown(): void {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testReturnsEmptyArrayForMissingFile(): void {
        $this->assertSame([], InventoryFileReader::read($this->path . '_does_not_exist'));
    }

    public function testReturnsEmptyArrayForEmptyFile(): void {
        file_put_contents($this->path, '   ');

        $this->assertSame([], InventoryFileReader::read($this->path));
    }

    public function testReadsNdjsonOneRecordPerLine(): void {
        file_put_contents($this->path, "{\"id\":\"1\"}\n{\"id\":\"2\"}\n");

        $this->assertSame([['id' => '1'], ['id' => '2']], InventoryFileReader::read($this->path));
    }

    public function testNdjsonSkipsBlankLinesAndMalformedLines(): void {
        file_put_contents($this->path, "{\"id\":\"1\"}\n\nnot json\n{\"id\":\"2\"}\n");

        $this->assertSame([['id' => '1'], ['id' => '2']], InventoryFileReader::read($this->path));
    }

    public function testReadsSingleJsonArray(): void {
        file_put_contents($this->path, json_encode([['id' => '1'], ['id' => '2']]));

        $this->assertSame([['id' => '1'], ['id' => '2']], InventoryFileReader::read($this->path));
    }

    public function testMalformedJsonArrayYieldsNoRecords(): void {
        file_put_contents($this->path, '[{"id":"1"}');

        $this->assertSame([], InventoryFileReader::read($this->path));
    }
}
