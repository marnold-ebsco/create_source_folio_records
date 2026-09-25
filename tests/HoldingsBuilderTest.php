<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Casting\ValueCaster;
use SourceFolioRecords\HoldingsSourceResolver;
use SourceFolioRecords\LocationResolver;
use SourceFolioRecords\Mapping\FieldMapper;
use SourceFolioRecords\RecordBuilder;
use SourceFolioRecords\ReferenceRegistry;
use SourceFolioRecords\Schema\HoldingsSchema;
use SourceFolioRecords\StatisticalCodeResolver;
use phpFolioClient\FolioUtils;

final class HoldingsBuilderTest extends TestCase {
    /**
     * The mapping file bakes in a literal `statisticalCodeIds[0]` of
     * `EZBorrow` (see mapping/ezborrow/holdings_field_mapping.json) — this
     * resolver stands in for the tenant's real statistical codes so
     * that literal resolves cleanly instead of logging a "not found"
     * warning on every test.
     */
    private const EZBORROW_STAT_CODE_ID = 'aaaaaaaa-1111-4111-8111-111111111111';

    /**
     * The mapping file also bakes in a literal `sourceId` of `FOLIO`
     * (see mapping/ezborrow/holdings_field_mapping.json) — this resolver stands
     * in for the tenant's real holdings sources so that literal
     * resolves cleanly instead of failing every test with a missing
     * required field.
     */
    private const FOLIO_SOURCE_ID = 'bbbbbbbb-2222-4222-9222-222222222222';

    /**
     * The mapping file's `permanentLocationId` prefers the row's own
     * `Item_Permanent_Shelving_Location`/`Item_Holding_Location` columns,
     * falling back to the literal `Migration` only when both are blank
     * (see mapping/ezborrow/holdings_field_mapping.json) — this resolver
     * stands in for the tenant's real locations, covering all three
     * names, so each of those paths resolves cleanly instead of failing
     * with a missing required field.
     */
    private const MIGRATION_LOCATION_ID = 'cccccccc-3333-4333-a333-333333333333';
    private const MAIN_STACKS_LOCATION_ID = 'dddddddd-4444-4444-8444-444444444444';
    private const CIRC_DESK_LOCATION_ID = 'eeeeeeee-5555-4555-9555-555555555555';

    private RecordBuilder $builder;
    private ReferenceRegistry $registry;

    protected function setUp(): void {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/mapping/ezborrow/holdings_field_mapping.json');
        $this->registry = new ReferenceRegistry();
        $statisticalCodes = new StatisticalCodeResolver([self::EZBORROW_STAT_CODE_ID => 'EZBorrow']);
        $holdingsSources = new HoldingsSourceResolver([self::FOLIO_SOURCE_ID => 'FOLIO']);
        $locations = new LocationResolver([
            self::MIGRATION_LOCATION_ID => 'Migration',
            self::MAIN_STACKS_LOCATION_ID => 'Main Stacks',
            self::CIRC_DESK_LOCATION_ID => 'Circulation Desk - EZBorrow',
        ]);
        $this->builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), HoldingsSchema::class, '|', $this->registry, $statisticalCodes, $holdingsSources, $locations);
    }

    /** @param array<string, string> $row */
    private function build(array $row, int $rowNum = 2): array {
        $result = $this->builder->build($row, $rowNum);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testPermanentLocationIdUsesRowsOwnShelvingLocationWhenPresent(): void {
        [$holdings, $errors] = $this->build([
            'item_permanent_shelving_location' => 'Main Stacks',
            'item_holding_location' => 'Circulation Desk - EZBorrow',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(self::MAIN_STACKS_LOCATION_ID, $holdings['permanentLocationId']);
        $this->assertSame([self::EZBORROW_STAT_CODE_ID], $holdings['statisticalCodeIds']);
        $this->assertSame(self::FOLIO_SOURCE_ID, $holdings['sourceId']);
    }

    public function testPermanentLocationIdFallsBackToHoldingLocationWhenShelvingLocationBlank(): void {
        [$holdings, $errors] = $this->build([
            'item_holding_location' => 'Circulation Desk - EZBorrow',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(self::CIRC_DESK_LOCATION_ID, $holdings['permanentLocationId']);
    }

    public function testPermanentLocationIdFallsBackToMigrationWhenBothLocationColumnsBlank(): void {
        [$holdings, $errors] = $this->build([]);

        $this->assertSame([], $errors);
        $this->assertSame(self::MIGRATION_LOCATION_ID, $holdings['permanentLocationId']);
    }

    public function testCallNumberIsMappedDirectlyWithNoPrefixOrSuffix(): void {
        [$holdings, $errors] = $this->build([
            'item_holding_location' => 'Circulation Desk - EZBorrow',
            'item_call_number' => 'PZ7.H1234',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('PZ7.H1234', $holdings['callNumber']);
        $this->assertArrayNotHasKey('callNumberPrefix', $holdings);
        $this->assertArrayNotHasKey('callNumberSuffix', $holdings);
    }
}
