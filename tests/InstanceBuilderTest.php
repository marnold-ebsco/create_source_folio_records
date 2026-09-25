<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Casting\ValueCaster;
use SourceFolioRecords\LiveReferenceResolver;
use SourceFolioRecords\Mapping\FieldMapper;
use SourceFolioRecords\RecordBuilder;
use SourceFolioRecords\ReferenceRegistry;
use SourceFolioRecords\Schema\InstanceSchema;
use SourceFolioRecords\StatisticalCodeResolver;
use phpFolioClient\FolioUtils;

/**
 * Exercises {@see RecordBuilder} configured for {@see InstanceSchema}
 * against the actual mapping/instance_field_mapping.json shipped at the
 * project root, using the same lowercased-header row shape
 * \SourceFolioRecords\Io\DelimitedFileReader produces from ezborrow.tsv —
 * so these tests double as a check that the bundled mapping file and the
 * builder still agree with each other.
 */
final class InstanceBuilderTest extends TestCase {
    /**
     * The mapping file bakes in a literal `statisticalCodeIds[0]` of
     * `EZBorrow` (see mapping/instance_field_mapping.json) — this
     * resolver stands in for the tenant's real statistical codes so
     * that literal resolves cleanly instead of logging a "not found"
     * warning on every test.
     */
    private const EZBORROW_STAT_CODE_ID = 'aaaaaaaa-1111-4111-8111-111111111111';

    /**
     * `instanceTypeId`/`identifiers[N].identifierTypeId`/
     * `contributors[N].contributorNameTypeId` are all `live:<namespace>`
     * fields now (see InstanceSchema) — these stand in for the
     * tenant's real reference data.
     */
    private const TEXT_INSTANCE_TYPE_ID = 'bbbbbbbb-2222-4222-9222-222222222222';
    private const ISBN_ID = 'cccccccc-3333-4333-a333-333333333333';
    private const ISSN_ID = 'dddddddd-4444-4444-8444-444444444444';
    private const PERSONAL_NAME_ID = 'eeeeeeee-5555-4555-8555-555555555555';

    private RecordBuilder $builder;
    private ReferenceRegistry $registry;
    private StatisticalCodeResolver $statisticalCodes;
    private array $liveReferences;

    protected function setUp(): void {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/mapping/instance_field_mapping.json');
        $this->registry = new ReferenceRegistry();
        $this->statisticalCodes = new StatisticalCodeResolver([self::EZBORROW_STAT_CODE_ID => 'EZBorrow']);
        $this->liveReferences = [
            'instanceType' => new LiveReferenceResolver([self::TEXT_INSTANCE_TYPE_ID => 'text']),
            'identifierType' => new LiveReferenceResolver([self::ISBN_ID => 'ISBN', self::ISSN_ID => 'ISSN']),
            'contributorNameType' => new LiveReferenceResolver([self::PERSONAL_NAME_ID => 'Personal name']),
        ];
        $this->builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), InstanceSchema::class, '|', $this->registry, $this->statisticalCodes, null, null, $this->liveReferences);
    }

    /** @param array<string, string> $row */
    private function build(array $row, int $rowNum = 2): array {
        $result = $this->builder->build($row, $rowNum);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testInstanceTypeIdIsRequiredAndUnmappedByDefault(): void {
        [$instance, $errors] = $this->build(['title' => 'Care ethics and poetry']);

        $this->assertSame(
            ['title' => 'Care ethics and poetry', 'source' => 'FOLIO', 'statisticalCodeIds' => [self::EZBORROW_STAT_CODE_ID]],
            $instance
        );
        $this->assertContains("Row 2: missing required field 'instanceTypeId'", $errors);
    }

    public function testBuildsFullInstanceOnceInstanceTypeIdIsMapped(): void {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/mapping/instance_field_mapping.json');
        $mapper->setLiteral('instanceTypeId', 'text');
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), InstanceSchema::class, '|', $this->registry, $this->statisticalCodes, null, null, $this->liveReferences);

        $result = $builder->build([
            'title' => 'Care ethics and poetry',
            'author_name' => 'Hamington, Maurice',
            'title_isbn' => '9780000000001',
            'publication_date' => '2020',
        ], 2);

        $this->assertSame([], $result->getErrors());
        $instance = $result->getRecord();

        $this->assertSame('Care ethics and poetry', $instance['title']);
        $this->assertSame(self::TEXT_INSTANCE_TYPE_ID, $instance['instanceTypeId']);
        $this->assertSame([['dateOfPublication' => '2020']], $instance['publication']);
        $this->assertSame(
            [['name' => 'Hamington, Maurice', 'contributorNameTypeId' => self::PERSONAL_NAME_ID]],
            $instance['contributors']
        );
        $this->assertSame(
            [['value' => '9780000000001', 'identifierTypeId' => self::ISBN_ID]],
            $instance['identifiers']
        );
        $this->assertSame([self::EZBORROW_STAT_CODE_ID], $instance['statisticalCodeIds']);
    }

    public function testIssnInstanceIsOmittedWhenNoIssnColumnIsMapped(): void {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/mapping/instance_field_mapping.json');
        $mapper->setLiteral('instanceTypeId', 'text');
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), InstanceSchema::class, '|', $this->registry, $this->statisticalCodes, null, null, $this->liveReferences);

        $result = $builder->build(['title' => 'Some title'], 2);

        $this->assertArrayNotHasKey('identifiers', $result->getRecord());
    }
}
