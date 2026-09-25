<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Casting\ValueCaster;
use SourceFolioRecords\LiveReferenceResolver;
use SourceFolioRecords\Mapping\FieldMapper;
use SourceFolioRecords\RecordBuilder;
use SourceFolioRecords\ReferenceRegistry;
use SourceFolioRecords\Schema\ItemSchema;
use phpFolioClient\FolioUtils;

/**
 * Exercises the `status` `NESTED_OBJECTS` mechanism
 * (`RecordBuilder::buildNestedObject()`) in isolation from the other
 * required fields exercised by {@see ItemBuilderTest} against the real
 * mapping file. `materialTypeId`/`permanentLoanTypeId` are given fixed,
 * already-resolvable literals here (not under test) purely so they
 * don't also fail as missing.
 */
final class ItemStatusFieldTest extends TestCase {
    private ReferenceRegistry $registry;
    private array $liveReferences;

    protected function setUp(): void {
        $this->registry = new ReferenceRegistry();
        $this->liveReferences = [
            'materialType' => new LiveReferenceResolver(['bbbbbbbb-2222-4222-9222-222222222222' => 'book']),
            'loanType' => new LiveReferenceResolver(['cccccccc-3333-4333-a333-333333333333' => 'Can circulate']),
        ];
    }

    private function builder(?string $statusNameLiteral): RecordBuilder {
        $index = [
            'materialtypeid' => ['folio_field' => 'materialTypeId', 'value' => 'book'],
            'permanentloantypeid' => ['folio_field' => 'permanentLoanTypeId', 'value' => 'Can circulate'],
        ];
        if ($statusNameLiteral !== null) {
            $index['status.name'] = ['folio_field' => 'status.name', 'value' => $statusNameLiteral];
        }
        $mapper = new FieldMapper($index);
        return new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), ItemSchema::class, '|', $this->registry, null, null, null, $this->liveReferences);
    }

    private function build(RecordBuilder $builder): array {
        $result = $builder->build([], 2);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testStatusNameIsBuiltAsANestedObjectNotAnArray(): void {
        [$item, $errors] = $this->build($this->builder('Available'));

        $this->assertSame([], $errors);
        $this->assertSame(['name' => 'Available'], $item['status']);
    }

    public function testUnmappedStatusIsReportedAsAMissingRequiredField(): void {
        [$item, $errors] = $this->build($this->builder(null));

        $this->assertArrayNotHasKey('status', $item);
        $this->assertContains("Row 2: missing required field 'status'", $errors);
    }

    public function testStatusNameOutsideTheEnumIsReportedAsAnError(): void {
        [$item, $errors] = $this->build($this->builder('Not A Real Status'));

        $this->assertContains(
            "Row 2: 'status.name' value 'Not A Real Status' is not one of: Aged to lost, Available, Awaiting pickup, Awaiting delivery, Checked out, Claimed returned, Declared lost, In process, In process (non-requestable), In transit, Intellectual item, Long missing, Lost and paid, Missing, On order, Paged, Restricted, Order closed, Unavailable, Unknown, Withdrawn",
            $errors
        );
        $this->assertSame(['name' => 'Not A Real Status'], $item['status']);
    }
}
