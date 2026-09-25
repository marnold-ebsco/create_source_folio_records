<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Mapping\FieldMapper;

final class FieldMapperTest extends TestCase {
    private function mapper(array $entries): FieldMapper {
        $index = [];
        foreach ($entries as $entry) {
            $index[strtolower($entry['folio_field'])] = $entry;
        }
        return new FieldMapper($index);
    }

    public function testResolvesLiteralValueRegardlessOfRowData(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'instanceTypeId', 'legacy_field' => 'Not mapped', 'value' => 'text'],
        ]);
        $this->assertSame('text', $mapper->resolve('instanceTypeId', ['instancetypeid' => 'ignored']));
    }

    public function testResolvesFromLegacyColumn(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'title', 'legacy_field' => 'Title', 'value' => ''],
        ]);
        $this->assertSame('Care ethics and poetry', $mapper->resolve('title', ['title' => 'Care ethics and poetry']));
    }

    public function testFallsBackToFallbackColumnWhenPrimaryColumnEmpty(): void {
        $mapper = $this->mapper([
            [
                'folio_field' => 'permanentLocationId',
                'legacy_field' => 'Item_Permanent_Shelving_Location',
                'value' => '',
                'fallback_legacy_field' => 'Item_Holding_Location',
            ],
        ]);
        $row = ['item_permanent_shelving_location' => '', 'item_holding_location' => 'Circulation Desk - EZBorrow'];
        $this->assertSame('Circulation Desk - EZBorrow', $mapper->resolve('permanentLocationId', $row));
    }

    public function testResolvesNullWhenNothingApplies(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'callNumberTypeId', 'legacy_field' => 'Not mapped', 'value' => ''],
        ]);
        $this->assertNull($mapper->resolve('callNumberTypeId', ['anything' => 'x']));
        $this->assertNull($mapper->resolve('neverDefined', ['anything' => 'x']));
    }

    public function testBracketlessNestedKeyIsShorthandForInstanceOne(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'identifiers[1].value', 'legacy_field' => 'Title_ISBN', 'value' => ''],
        ]);
        $this->assertSame('9780000000001', $mapper->resolve('identifiers.value', ['title_isbn' => '9780000000001']));
    }

    public function testIndicesForFindsEveryMappedInstance(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'identifiers[1].value', 'legacy_field' => 'Title_ISBN', 'value' => ''],
            ['folio_field' => 'identifiers[2].value', 'legacy_field' => 'Not mapped', 'value' => ''],
        ]);
        $this->assertSame([1, 2], $mapper->indicesFor('identifiers'));
        $this->assertSame([], $mapper->indicesFor('contributors'));
    }

    public function testScalarListIndicesForFindsEveryMappedInstance(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'statisticalCodeIds[0]', 'legacy_field' => 'Not mapped', 'value' => 'Damaged'],
            ['folio_field' => 'statisticalCodeIds[1]', 'legacy_field' => 'Not mapped', 'value' => 'Withdrawn'],
        ]);
        $this->assertSame([0, 1], $mapper->scalarListIndicesFor('statisticalCodeIds'));
        $this->assertSame([], $mapper->scalarListIndicesFor('somethingElse'));
    }

    public function testScalarListIndicesForIgnoresNestedGroupKeysWithTheSamePrefix(): void {
        $mapper = $this->mapper([
            // A nested-group key (has a `.subfield` after the bracket) must not
            // be mistaken for a repeatable scalar field's own bracketed key.
            ['folio_field' => 'identifiers[1].value', 'legacy_field' => 'Title_ISBN', 'value' => ''],
        ]);
        $this->assertSame([], $mapper->scalarListIndicesFor('identifiers'));
    }

    public function testIsMappedTrueForLiteralOrColumnFalseOtherwise(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'instanceTypeId', 'legacy_field' => 'Not mapped', 'value' => ''],
            ['folio_field' => 'title', 'legacy_field' => 'Title', 'value' => ''],
        ]);
        $this->assertFalse($mapper->isMapped('instanceTypeId'));
        $this->assertTrue($mapper->isMapped('title'));
        $this->assertFalse($mapper->isMapped('neverDefined'));
    }

    public function testSetLiteralMakesAnUnmappedFieldResolveForEveryRow(): void {
        $mapper = $this->mapper([
            ['folio_field' => 'instanceTypeId', 'legacy_field' => 'Not mapped', 'value' => ''],
        ]);
        $this->assertFalse($mapper->isMapped('instanceTypeId'));

        $mapper->setLiteral('instanceTypeId', 'text');

        $this->assertTrue($mapper->isMapped('instanceTypeId'));
        $this->assertSame('text', $mapper->resolve('instanceTypeId', []));
        $this->assertSame('text', $mapper->resolve('instanceTypeId', ['instancetypeid' => 'something else']));
    }

    public function testSetLiteralWorksEvenForAFieldWithNoExistingEntry(): void {
        $mapper = $this->mapper([]);
        $mapper->setLiteral('materialTypeId', 'book');
        $this->assertSame('book', $mapper->resolve('materialTypeId', []));
    }
}
