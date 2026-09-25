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
use phpFolioClient\FolioUtils;

/**
 * Exercises Holdings' `sourceId` resolution (`RecordBuilder::resolveLiveReference()`)
 * in isolation from the other required fields exercised by
 * {@see HoldingsBuilderTest} against the real mapping file.
 * `permanentLocationId` is given a fixed, already-resolvable literal
 * here (not under test) purely so it doesn't also fail as missing.
 */
final class HoldingsSourceFieldTest extends TestCase {
    private const FOLIO_ID = 'aaaaaaaa-1111-4111-8111-111111111111';
    private const UNKNOWN_ID = 'cccccccc-3333-4333-a333-333333333333';
    private const MAIN_STACKS_ID = 'dddddddd-4444-4444-8444-444444444444';

    private ReferenceRegistry $registry;
    private HoldingsSourceResolver $resolver;
    private LocationResolver $locations;

    protected function setUp(): void {
        $this->registry = new ReferenceRegistry();
        $this->resolver = new HoldingsSourceResolver([self::FOLIO_ID => 'FOLIO']);
        $this->locations = new LocationResolver([self::MAIN_STACKS_ID => 'Main Stacks']);
    }

    private function builder(string $sourceIdLiteral, ?HoldingsSourceResolver $resolver = null): RecordBuilder {
        $index = [
            'permanentlocationid' => ['folio_field' => 'permanentLocationId', 'value' => 'Main Stacks'],
            'sourceid' => ['folio_field' => 'sourceId', 'value' => $sourceIdLiteral],
        ];
        $mapper = new FieldMapper($index);
        return new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), HoldingsSchema::class, '|', $this->registry, null, $resolver ?? $this->resolver, $this->locations);
    }

    private function build(RecordBuilder $builder): array {
        $result = $builder->build([], 2);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testExistingUuidIsPassedThroughUnchanged(): void {
        [$holdings, $errors] = $this->build($this->builder(self::FOLIO_ID));

        $this->assertSame([], $errors);
        $this->assertSame(self::FOLIO_ID, $holdings['sourceId']);
    }

    public function testExistingNameIsResolvedToItsId(): void {
        [$holdings, $errors] = $this->build($this->builder('FOLIO'));

        $this->assertSame([], $errors);
        $this->assertSame(self::FOLIO_ID, $holdings['sourceId']);
    }

    public function testNameMatchIsCaseInsensitive(): void {
        [$holdings, $errors] = $this->build($this->builder('folio'));

        $this->assertSame([], $errors);
        $this->assertSame(self::FOLIO_ID, $holdings['sourceId']);
    }

    public function testUnknownUuidIsFatalAndOmitted(): void {
        [$holdings, $errors] = $this->build($this->builder(self::UNKNOWN_ID));

        $this->assertArrayNotHasKey('sourceId', $holdings);
        $this->assertContains(
            "Row 2: 'sourceId' references holdings source id '" . self::UNKNOWN_ID . "', which was not found in the tenant's holdings sources",
            $errors
        );
        $this->assertContains("Row 2: missing required field 'sourceId'", $errors);
    }

    public function testUnknownNameIsFatalAndOmitted(): void {
        [$holdings, $errors] = $this->build($this->builder('CONSORTIUM'));

        $this->assertArrayNotHasKey('sourceId', $holdings);
        $this->assertContains(
            "Row 2: 'sourceId' references holdings source name 'CONSORTIUM', which was not found in the tenant's holdings sources",
            $errors
        );
    }

    public function testNoResolverGivenTreatsEverythingAsNotFound(): void {
        [$holdings, $errors] = $this->build($this->builder('FOLIO', HoldingsSourceResolver::empty()));

        $this->assertArrayNotHasKey('sourceId', $holdings);
        $this->assertContains(
            "Row 2: 'sourceId' references holdings source name 'FOLIO', which was not found in the tenant's holdings sources",
            $errors
        );
    }
}
