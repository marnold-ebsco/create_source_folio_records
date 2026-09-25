<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\LegacyIdentifier;

final class LegacyIdentifierTest extends TestCase {
    public function testUsesBarcodeWhenPresent(): void {
        $row = ['item_barcode' => 'PLEB-1249', 'title' => 'Care ethics and poetry'];
        $this->assertSame('PLEB-1249', LegacyIdentifier::compute($row));
    }

    public function testFallsBackToHashOfTitleAuthorPublicationDateIsbnWhenNoBarcode(): void {
        $row = [
            'item_barcode' => '',
            'title' => 'Uma história não contada',
            'author_name' => 'Domingues, Petrônio',
            'publication_date' => '',
            'title_isbn' => '',
        ];
        $expected = sha1('Uma história não contada|Domingues, Petrônio||');
        $this->assertSame($expected, LegacyIdentifier::compute($row));
    }

    public function testHashFallbackIsDeterministic(): void {
        $row = ['title' => 'Same title', 'author_name' => 'Same author'];
        $this->assertSame(LegacyIdentifier::compute($row), LegacyIdentifier::compute($row));
    }

    public function testDifferentRowsWithNoBarcodeGetDifferentIdentifiers(): void {
        $rowA = ['title' => 'Title A', 'author_name' => 'Author A'];
        $rowB = ['title' => 'Title B', 'author_name' => 'Author B'];
        $this->assertNotSame(LegacyIdentifier::compute($rowA), LegacyIdentifier::compute($rowB));
    }

    public function testMissingColumnsAreTreatedAsEmptyRatherThanErroring(): void {
        $this->assertIsString(LegacyIdentifier::compute([]));
    }
}
