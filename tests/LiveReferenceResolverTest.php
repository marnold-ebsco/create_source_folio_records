<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\LiveReferenceResolver;

final class LiveReferenceResolverTest extends TestCase {
    private LiveReferenceResolver $resolver;

    protected function setUp(): void {
        $this->resolver = new LiveReferenceResolver([
            'aaaaaaaa-1111-4111-8111-111111111111' => 'text',
            'bbbbbbbb-2222-4222-9222-222222222222' => 'still image',
        ]);
    }

    public function testExistsByIdIsTrueForAKnownId(): void {
        $this->assertTrue($this->resolver->existsById('aaaaaaaa-1111-4111-8111-111111111111'));
    }

    public function testExistsByIdIsFalseForAnUnknownId(): void {
        $this->assertFalse($this->resolver->existsById('cccccccc-3333-4333-a333-333333333333'));
    }

    public function testIdForNameFindsAKnownNameCaseInsensitively(): void {
        $this->assertSame('bbbbbbbb-2222-4222-9222-222222222222', $this->resolver->idForName('STILL IMAGE'));
    }

    public function testIdForNameReturnsNullForAnUnknownName(): void {
        $this->assertNull($this->resolver->idForName('unspecified'));
    }

    public function testEmptyResolverFindsNothing(): void {
        $empty = LiveReferenceResolver::empty();

        $this->assertFalse($empty->existsById('aaaaaaaa-1111-4111-8111-111111111111'));
        $this->assertNull($empty->idForName('text'));
    }
}
