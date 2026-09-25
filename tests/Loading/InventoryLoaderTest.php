<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests\Loading;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\Io\ErrorLog;
use SourceFolioRecords\Loading\InventoryLoader;
use phpFolioClient\FolioClient;

final class InventoryLoaderTest extends TestCase {
    private string $logPath;

    protected function setUp(): void {
        $this->logPath = tempnam(sys_get_temp_dir(), 'inventory_loader_test_') . '.log';
    }

    protected function tearDown(): void {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    private function openLog(): ErrorLog {
        $log = new ErrorLog($this->logPath);
        $log->open();
        return $log;
    }

    private function logLines(ErrorLog $log): array {
        $log->close();
        $contents = trim((string) file_get_contents($this->logPath));
        return $contents === '' ? [] : explode("\n", $contents);
    }

    public function testUpsertsEveryRecordAndCountsSuccesses(): void {
        $client = $this->getMockBuilder(FolioClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upsert'])
            ->getMock();
        $client->expects($this->exactly(2))
            ->method('upsert')
            ->with('instance-storage/instances', $this->anything());

        $loader = new InventoryLoader($client);
        $log = $this->openLog();

        [$succeeded, $failed] = $loader->loadRecordType(
            'instances',
            'instance-storage/instances',
            [['id' => 'aaa'], ['id' => 'bbb']],
            $log
        );

        $this->assertSame(2, $succeeded);
        $this->assertSame(0, $failed);
        $this->assertSame([], $this->logLines($log));
    }

    public function testFailedUpsertIsLoggedAndCountedWithoutStoppingTheBatch(): void {
        $client = $this->getMockBuilder(FolioClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upsert'])
            ->getMock();
        $client->method('upsert')
            ->willReturnCallback(function (string $endpoint, array $record): void {
                if ($record['id'] === 'bad-id') {
                    throw new \Exception('boom');
                }
            });

        $loader = new InventoryLoader($client);
        $log = $this->openLog();

        [$succeeded, $failed] = $loader->loadRecordType(
            'items',
            'item-storage/items',
            [['id' => 'good-id'], ['id' => 'bad-id'], ['id' => 'also-good']],
            $log
        );

        $this->assertSame(2, $succeeded);
        $this->assertSame(1, $failed);
        $this->assertSame(
            ["[items] id=bad-id: boom"],
            $this->logLines($log)
        );
    }

    public function testMissingIdIsLoggedAsNoId(): void {
        $client = $this->getMockBuilder(FolioClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upsert'])
            ->getMock();
        $client->method('upsert')->willThrowException(new \Exception('missing id'));

        $loader = new InventoryLoader($client);
        $log = $this->openLog();

        [$succeeded, $failed] = $loader->loadRecordType(
            'holdings',
            'holdings-storage/holdings',
            [['permanentLocationId' => 'loc-1']],
            $log
        );

        $this->assertSame(0, $succeeded);
        $this->assertSame(1, $failed);
        $this->assertSame(
            ["[holdings] id=(no id): missing id"],
            $this->logLines($log)
        );
    }

    public function testLegacyIdentifierIsStrippedFromInstancesBeforeSending(): void {
        $sent = null;
        $client = $this->getMockBuilder(FolioClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upsert'])
            ->getMock();
        $client->method('upsert')
            ->willReturnCallback(function (string $endpoint, array $record) use (&$sent): void {
                $sent = $record;
            });

        $loader = new InventoryLoader($client);
        $log = $this->openLog();

        $loader->loadRecordType(
            'instances',
            'instance-storage/instances',
            [['id' => 'aaa', 'title' => 'Some title', 'legacyIdentifier' => 'PLEB-1249']],
            $log
        );

        $this->assertSame(['id' => 'aaa', 'title' => 'Some title'], $sent);
    }

    public function testLegacyIdentifierIsOnlyStrippedForInstancesNotOtherTypes(): void {
        $sent = null;
        $client = $this->getMockBuilder(FolioClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upsert'])
            ->getMock();
        $client->method('upsert')
            ->willReturnCallback(function (string $endpoint, array $record) use (&$sent): void {
                $sent = $record;
            });

        $loader = new InventoryLoader($client);
        $log = $this->openLog();

        $loader->loadRecordType(
            'items',
            'item-storage/items',
            [['id' => 'aaa', 'legacyIdentifier' => 'PLEB-1249']],
            $log
        );

        $this->assertSame(['id' => 'aaa', 'legacyIdentifier' => 'PLEB-1249'], $sent);
    }

    public function testEmptyRecordListSucceedsWithZeroCounts(): void {
        $client = $this->getMockBuilder(FolioClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['upsert'])
            ->getMock();
        $client->expects($this->never())->method('upsert');

        $loader = new InventoryLoader($client);
        $log = $this->openLog();

        [$succeeded, $failed] = $loader->loadRecordType('instances', 'instance-storage/instances', [], $log);

        $this->assertSame(0, $succeeded);
        $this->assertSame(0, $failed);
    }
}
