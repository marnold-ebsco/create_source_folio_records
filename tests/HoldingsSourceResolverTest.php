<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\HoldingsSourceResolver;

final class HoldingsSourceResolverTest extends TestCase {
    private HoldingsSourceResolver $resolver;

    protected function setUp(): void {
        $this->resolver = new HoldingsSourceResolver([
            'aaaaaaaa-1111-4111-8111-111111111111' => 'FOLIO',
            'bbbbbbbb-2222-4222-9222-222222222222' => 'MARC',
        ]);
    }

    public function testExistsByIdIsTrueForAKnownId(): void {
        $this->assertTrue($this->resolver->existsById('aaaaaaaa-1111-4111-8111-111111111111'));
    }

    public function testExistsByIdIsFalseForAnUnknownId(): void {
        $this->assertFalse($this->resolver->existsById('cccccccc-3333-4333-a333-333333333333'));
    }

    public function testIdForNameFindsAKnownNameCaseInsensitively(): void {
        $this->assertSame('aaaaaaaa-1111-4111-8111-111111111111', $this->resolver->idForName('folio'));
    }

    public function testIdForNameReturnsNullForAnUnknownName(): void {
        $this->assertNull($this->resolver->idForName('CONSORTIUM'));
    }

    public function testEmptyResolverFindsNothing(): void {
        $empty = HoldingsSourceResolver::empty();

        $this->assertFalse($empty->existsById('aaaaaaaa-1111-4111-8111-111111111111'));
        $this->assertNull($empty->idForName('FOLIO'));
    }
}
