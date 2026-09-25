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

/**
 * Exercises `statisticalCodeIds[N]` resolution (shared logic in
 * {@see RecordBuilder::resolveStatisticalCode()}) via {@see ItemSchema}
 * — the same code path applies unchanged to InstanceSchema and
 * HoldingsSchema, which both declare the same `statcode` SCALAR_LISTS
 * entry. Each instance (`[0]`, `[1]`, ...) carries exactly one value, so
 * more than one statistical code is added by mapping more instances,
 * not by delimiting several values into one cell.
 */
final class StatisticalCodeFieldTest extends TestCase {
    private const DAMAGED_ID = 'aaaaaaaa-1111-4111-8111-111111111111';
    private const WITHDRAWN_ID = 'bbbbbbbb-2222-4222-9222-222222222222';
    private const UNKNOWN_ID = 'cccccccc-3333-4333-a333-333333333333';

    private ReferenceRegistry $registry;
    private StatisticalCodeResolver $resolver;
    private array $liveReferences;

    protected function setUp(): void {
        $this->registry = new ReferenceRegistry();
        $this->resolver = new StatisticalCodeResolver([
            self::DAMAGED_ID => 'Damaged',
            self::WITHDRAWN_ID => 'Withdrawn',
        ]);
        $this->liveReferences = [
            'materialType' => new LiveReferenceResolver(['ffffffff-6666-4666-8666-666666666666' => 'book']),
            'loanType' => new LiveReferenceResolver(['99999999-7777-4777-8777-777777777777' => 'Can circulate']),
        ];
    }

    /** @param $statisticalCodeIdLiterals Each instance's literal `value`, e.g. `['Withdrawn', self::DAMAGED_ID]`. */
    private function builder(array $statisticalCodeIdLiterals, ?StatisticalCodeResolver $resolver = null): RecordBuilder {
        $index = [
            'materialtypeid' => ['folio_field' => 'materialTypeId', 'value' => 'book'],
            'permanentloantypeid' => ['folio_field' => 'permanentLoanTypeId', 'value' => 'Can circulate'],
            'status.name' => ['folio_field' => 'status.name', 'value' => 'Available'],
        ];
        foreach ($statisticalCodeIdLiterals as $i => $literal) {
            $index["statisticalcodeids[$i]"] = ['folio_field' => "statisticalCodeIds[$i]", 'value' => $literal];
        }
        $mapper = new FieldMapper($index);
        return new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), ItemSchema::class, '|', $this->registry, $resolver ?? $this->resolver, null, null, $this->liveReferences);
    }

    private function build(RecordBuilder $builder): array {
        $result = $builder->build(['item_barcode' => 'PLEB-1249'], 2);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testUnmappedStatisticalCodeIdsIsOmitted(): void {
        [$item, $errors] = $this->build($this->builder([]));

        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey('statisticalCodeIds', $item);
    }

    public function testExistingUuidIsPassedThroughUnchanged(): void {
        [$item, $errors] = $this->build($this->builder([self::DAMAGED_ID]));

        $this->assertSame([], $errors);
        $this->assertSame([self::DAMAGED_ID], $item['statisticalCodeIds']);
    }

    public function testUnknownUuidIsDroppedAndLoggedAsAWarning(): void {
        [$item, $errors] = $this->build($this->builder([self::UNKNOWN_ID]));

        $this->assertArrayNotHasKey('statisticalCodeIds', $item);
        $this->assertContains(
            "Row 2: Warning: 'statisticalCodeIds[0]' references statistical code id '" . self::UNKNOWN_ID . "', which was not found in the tenant's statistical codes",
            $errors
        );
    }

    public function testNameIsResolvedToItsId(): void {
        [$item, $errors] = $this->build($this->builder(['Withdrawn']));

        $this->assertSame([], $errors);
        $this->assertSame([self::WITHDRAWN_ID], $item['statisticalCodeIds']);
    }

    public function testNameMatchIsCaseInsensitive(): void {
        [$item, $errors] = $this->build($this->builder(['withdrawn']));

        $this->assertSame([], $errors);
        $this->assertSame([self::WITHDRAWN_ID], $item['statisticalCodeIds']);
    }

    public function testUnknownNameIsDroppedAndLogged(): void {
        [$item, $errors] = $this->build($this->builder(['Not A Real Code']));

        $this->assertArrayNotHasKey('statisticalCodeIds', $item);
        $this->assertContains(
            "Row 2: Warning: 'statisticalCodeIds[0]' references statistical code name 'Not A Real Code', which was not found in the tenant's statistical codes",
            $errors
        );
    }

    public function testMultipleInstancesAreResolvedIndependently(): void {
        [$item, $errors] = $this->build($this->builder([self::DAMAGED_ID, 'Withdrawn', 'Not A Real Code']));

        $this->assertSame([self::DAMAGED_ID, self::WITHDRAWN_ID], $item['statisticalCodeIds']);
        $this->assertContains(
            "Row 2: Warning: 'statisticalCodeIds[2]' references statistical code name 'Not A Real Code', which was not found in the tenant's statistical codes",
            $errors
        );
    }

    public function testNoResolverGivenTreatsEverythingAsNotFound(): void {
        $index = [
            'materialtypeid' => ['folio_field' => 'materialTypeId', 'value' => 'book'],
            'permanentloantypeid' => ['folio_field' => 'permanentLoanTypeId', 'value' => 'Can circulate'],
            'status.name' => ['folio_field' => 'status.name', 'value' => 'Available'],
            'statisticalcodeids[0]' => ['folio_field' => 'statisticalCodeIds[0]', 'value' => self::DAMAGED_ID],
        ];
        $mapper = new FieldMapper($index);
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), ItemSchema::class, '|', $this->registry);

        [$item, $errors] = $this->build($builder);

        $this->assertArrayNotHasKey('statisticalCodeIds', $item);
        $this->assertContains(
            "Row 2: Warning: 'statisticalCodeIds[0]' references statistical code id '" . self::DAMAGED_ID . "', which was not found in the tenant's statistical codes",
            $errors
        );
    }
}
