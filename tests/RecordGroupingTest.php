<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\RecordGrouping;

final class RecordGroupingTest extends TestCase {
    public function testInstanceKeyIsSameForMatchingTitleAndAuthorRegardlessOfCaseOrWhitespace(): void {
        $a = ['title' => 'Economic Principles and Problems', 'author_name' => ''];
        $b = ['title' => '  economic   principles and problems ', 'author_name' => ''];

        $this->assertSame(RecordGrouping::instanceKey($a), RecordGrouping::instanceKey($b));
    }

    public function testInstanceKeyDiffersForDifferentTitle(): void {
        $a = ['title' => 'Economic Principles and Problems', 'author_name' => ''];
        $b = ['title' => 'Care ethics and poetry', 'author_name' => ''];

        $this->assertNotSame(RecordGrouping::instanceKey($a), RecordGrouping::instanceKey($b));
    }

    public function testInstanceKeyDiffersForDifferentAuthorOfTheSameTitle(): void {
        $a = ['title' => 'Some Title', 'author_name' => 'Smith, Jane'];
        $b = ['title' => 'Some Title', 'author_name' => 'Doe, John'];

        $this->assertNotSame(RecordGrouping::instanceKey($a), RecordGrouping::instanceKey($b));
    }

    public function testHoldingsKeyIsSameForMatchingTitleAuthorCallNumberAndLocation(): void {
        $a = [
            'title' => 'Economic Principles and Problems',
            'author_name' => '',
            'item_call_number' => '',
            'item_permanent_shelving_location' => 'Reserves Overnight',
            'item_holding_location' => 'PBUB',
        ];
        $b = [
            'title' => 'economic principles and problems',
            'author_name' => '',
            'item_call_number' => '',
            'item_permanent_shelving_location' => 'reserves overnight',
            'item_holding_location' => 'PBUB',
        ];

        $this->assertSame(RecordGrouping::holdingsKey($a), RecordGrouping::holdingsKey($b));
    }

    public function testHoldingsKeyFallsBackToHoldingLocationWhenPermanentShelvingLocationIsBlank(): void {
        $row = [
            'title' => 'Some Title',
            'author_name' => '',
            'item_call_number' => 'PZ7.H1234',
            'item_permanent_shelving_location' => '',
            'item_holding_location' => 'Circulation Desk - EZBorrow',
        ];

        $this->assertStringContainsString('circulation desk - ezborrow', RecordGrouping::holdingsKey($row));
    }

    public function testHoldingsKeyDiffersForDifferentCallNumberOfTheSameInstance(): void {
        $a = ['title' => 'Some Title', 'author_name' => '', 'item_call_number' => 'A100', 'item_permanent_shelving_location' => 'Main'];
        $b = ['title' => 'Some Title', 'author_name' => '', 'item_call_number' => 'B200', 'item_permanent_shelving_location' => 'Main'];

        $this->assertNotSame(RecordGrouping::holdingsKey($a), RecordGrouping::holdingsKey($b));
    }

    public function testHoldingsKeyDiffersForDifferentLocationOfTheSameInstance(): void {
        $a = ['title' => 'Some Title', 'author_name' => '', 'item_call_number' => 'A100', 'item_permanent_shelving_location' => 'Main Stacks'];
        $b = ['title' => 'Some Title', 'author_name' => '', 'item_call_number' => 'A100', 'item_permanent_shelving_location' => 'Basement'];

        $this->assertNotSame(RecordGrouping::holdingsKey($a), RecordGrouping::holdingsKey($b));
    }

    public function testHoldingsKeyStartsWithItsInstanceKey(): void {
        $row = ['title' => 'Some Title', 'author_name' => 'Doe, John', 'item_call_number' => 'A100', 'item_permanent_shelving_location' => 'Main'];

        $this->assertStringStartsWith(RecordGrouping::instanceKey($row) . '|', RecordGrouping::holdingsKey($row));
    }
}
