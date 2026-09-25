<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Casting\ValueCaster;
use SourceFolioRecords\LiveReferenceResolver;
use SourceFolioRecords\Mapping\FieldMapper;
use SourceFolioRecords\RecordBuilder;
use SourceFolioRecords\ReferenceRegistry;
use SourceFolioRecords\Schema\ItemSchema;
use SourceFolioRecords\StatisticalCodeResolver;
use phpFolioClient\FolioUtils;

final class ItemBuilderTest extends TestCase {
    /**
     * The mapping file bakes in a literal `statisticalCodeIds[0]` of
     * `EZBorrow` (see mapping/ezborrow/item_field_mapping.json) — this resolver
     * stands in for the tenant's real statistical codes so that literal
     * resolves cleanly instead of logging a "not found" warning on
     * every test.
     */
    private const EZBORROW_STAT_CODE_ID = 'aaaaaaaa-1111-4111-8111-111111111111';

    /**
     * `materialTypeId`/`permanentLoanTypeId` are `live:<namespace>`
     * fields now (see ItemSchema) — these stand in for the tenant's
     * real reference data.
     */
    private const BOOK_MATERIAL_TYPE_ID = 'bbbbbbbb-2222-4222-9222-222222222222';
    private const CAN_CIRCULATE_LOAN_TYPE_ID = 'cccccccc-3333-4333-a333-333333333333';

    private ReferenceRegistry $registry;
    private StatisticalCodeResolver $statisticalCodes;
    private array $liveReferences;

    private function builderWithLiterals(): RecordBuilder {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/mapping/ezborrow/item_field_mapping.json');
        $mapper->setLiteral('materialTypeId', 'book');
        $mapper->setLiteral('permanentLoanTypeId', 'Can circulate');
        return new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), ItemSchema::class, '|', $this->registry, $this->statisticalCodes, null, null, $this->liveReferences);
    }

    protected function setUp(): void {
        $this->registry = new ReferenceRegistry();
        $this->statisticalCodes = new StatisticalCodeResolver([self::EZBORROW_STAT_CODE_ID => 'EZBorrow']);
        $this->liveReferences = [
            'materialType' => new LiveReferenceResolver([self::BOOK_MATERIAL_TYPE_ID => 'book']),
            'loanType' => new LiveReferenceResolver([self::CAN_CIRCULATE_LOAN_TYPE_ID => 'Can circulate']),
        ];
    }

    /** @param array<string, string> $row */
    private function build(RecordBuilder $builder, array $row, int $rowNum = 2): array {
        $result = $builder->build($row, $rowNum);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testMaterialTypeAndLoanTypeAreRequired(): void {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/mapping/ezborrow/item_field_mapping.json');
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), ItemSchema::class, '|', $this->registry, $this->statisticalCodes);

        [, $errors] = $this->build($builder, ['item_barcode' => 'PLEB-1249']);

        $this->assertContains("Row 2: missing required field 'materialTypeId'", $errors);
        $this->assertContains("Row 2: missing required field 'permanentLoanTypeId'", $errors);
    }

    public function testBuildsBarcodeAndBothCirculationNotesWithHardCodedNoteType(): void {
        [$item, $errors] = $this->build($this->builderWithLiterals(), [
            'item_barcode' => 'PLEB-1249',
            'lhr_item_public_note' => 'On display',
            'lhr_item_nonpublic_note' => 'Damaged spine',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('PLEB-1249', $item['barcode']);
        $this->assertSame(self::BOOK_MATERIAL_TYPE_ID, $item['materialTypeId']);
        $this->assertSame(self::CAN_CIRCULATE_LOAN_TYPE_ID, $item['permanentLoanTypeId']);
        $this->assertSame([
            ['note' => 'On display', 'noteType' => 'Check out', 'staffOnly' => false],
            ['note' => 'Damaged spine', 'noteType' => 'Check out', 'staffOnly' => true],
        ], $item['circulationNotes']);
        $this->assertSame([self::EZBORROW_STAT_CODE_ID], $item['statisticalCodeIds']);
    }

    public function testElectronicAccessIsOmittedWhenNoUrlColumnIsMapped(): void {
        [$item, $errors] = $this->build($this->builderWithLiterals(), ['item_barcode' => 'PLEB-1249']);

        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey('electronicAccess', $item);
    }
}
