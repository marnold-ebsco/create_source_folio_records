<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\LocationResolver;

final class LocationResolverTest extends TestCase {
    private LocationResolver $resolver;

    protected function setUp(): void {
        $this->resolver = new LocationResolver([
            'aaaaaaaa-1111-4111-8111-111111111111' => 'Migration',
            'bbbbbbbb-2222-4222-9222-222222222222' => 'Main Stacks',
        ]);
    }

    public function testExistsByIdIsTrueForAKnownId(): void {
        $this->assertTrue($this->resolver->existsById('aaaaaaaa-1111-4111-8111-111111111111'));
    }

    public function testExistsByIdIsFalseForAnUnknownId(): void {
        $this->assertFalse($this->resolver->existsById('cccccccc-3333-4333-a333-333333333333'));
    }

    public function testIdForNameFindsAKnownNameCaseInsensitively(): void {
        $this->assertSame('aaaaaaaa-1111-4111-8111-111111111111', $this->resolver->idForName('migration'));
    }

    public function testIdForNameReturnsNullForAnUnknownName(): void {
        $this->assertNull($this->resolver->idForName('Reserves Overnight'));
    }

    public function testEmptyResolverFindsNothing(): void {
        $empty = LocationResolver::empty();

        $this->assertFalse($empty->existsById('aaaaaaaa-1111-4111-8111-111111111111'));
        $this->assertNull($empty->idForName('Migration'));
    }
}
