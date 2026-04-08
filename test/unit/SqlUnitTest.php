<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Horde_SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\SessionHandler\Test\Unit;

use Horde_Db_Adapter;
use Horde_Db_Adapter_Base_Column;
use Horde_Db_Exception;
use Horde_Db_Value_Binary;
use Horde_SessionHandler_Storage_Sql;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test-only interface extending Horde_Db_Adapter with the columns() method
 * which is normally provided via __call() on the concrete adapter base class.
 */
interface TestDbAdapter extends Horde_Db_Adapter
{
    public function columns(string $tableName): array;
}

#[CoversClass(Horde_SessionHandler_Storage_Sql::class)]
class SqlUnitTest extends TestCase
{
    /**
     * Create a mock of TestDbAdapter which includes columns().
     */
    private function createDbMock(): TestDbAdapter
    {
        return $this->createMock(TestDbAdapter::class);
    }

    private function createDbStub(): TestDbAdapter
    {
        return $this->createStub(TestDbAdapter::class);
    }

    private function createStorage(?TestDbAdapter $db = null, string $table = 'horde_sessionhandler'): Horde_SessionHandler_Storage_Sql
    {
        $db ??= $this->createDbMock();
        return new Horde_SessionHandler_Storage_Sql([
            'db' => $db,
            'table' => $table,
        ]);
    }

    private function stubColumnForRead(TestDbAdapter $db, string $binaryResult): void
    {
        $column = $this->createStub(Horde_Db_Adapter_Base_Column::class);
        $column->method('binaryToString')->willReturn($binaryResult);
        $db->expects($this->once())->method('columns')->willReturn(['session_data' => $column]);
    }

    public function testConstructorRequiresDb(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing db parameter');
        new Horde_SessionHandler_Storage_Sql([]);
    }

    public function testConstructorUsesDefaultTableName(): void
    {
        $db = $this->createDbMock();
        $storage = new Horde_SessionHandler_Storage_Sql(['db' => $db]);

        $db->expects($this->once())->method('transactionStarted')->willReturn(false);
        $db->expects($this->once())->method('beginDbTransaction');
        $this->stubColumnForRead($db, '');
        $db->expects($this->once())->method('selectValue')
            ->with(
                $this->stringContains('horde_sessionhandler'),
                $this->anything()
            )
            ->willReturn(null);

        $storage->read('test');
    }

    public function testConstructorAcceptsCustomTableName(): void
    {
        $db = $this->createDbMock();
        $storage = new Horde_SessionHandler_Storage_Sql([
            'db' => $db,
            'table' => 'custom_sessions',
        ]);

        $db->expects($this->once())->method('transactionStarted')->willReturn(false);
        $db->expects($this->once())->method('beginDbTransaction');
        $this->stubColumnForRead($db, '');
        $db->expects($this->once())->method('selectValue')
            ->with(
                $this->stringContains('custom_sessions'),
                $this->anything()
            )
            ->willReturn(null);

        $storage->read('test');
    }

    public function testOpenReturnsTrue(): void
    {
        $db = $this->createDbStub();
        $storage = $this->createStorage($db);
        $this->assertTrue($storage->open('/tmp', 'sess'));
    }

    public function testCloseCommitsOpenTransaction(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('transactionStarted')->willReturn(true);
        $db->expects($this->once())->method('commitDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->close());
    }

    public function testCloseReturnsTrueWithNoTransaction(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('transactionStarted')->willReturn(false);
        $db->expects($this->never())->method('commitDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->close());
    }

    public function testCloseReturnsFalseOnCommitError(): void
    {
        $db = $this->createDbStub();
        $db->method('transactionStarted')->willReturn(true);
        $db->method('commitDbTransaction')
            ->willThrowException(new Horde_Db_Exception('commit failed'));

        $storage = $this->createStorage($db);
        $this->assertFalse($storage->close());
    }

    public function testReadBeginsTransactionAndReturnsData(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('transactionStarted')->willReturn(false);
        $db->expects($this->once())->method('beginDbTransaction');
        $this->stubColumnForRead($db, 'session-data');
        $db->expects($this->once())->method('selectValue')->willReturn('binary-blob');

        $storage = $this->createStorage($db);
        $this->assertEquals('session-data', $storage->read('sid'));
    }

    public function testReadDoesNotRestartExistingTransaction(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('transactionStarted')->willReturn(true);
        $db->expects($this->never())->method('beginDbTransaction');
        $this->stubColumnForRead($db, 'data');
        $db->expects($this->once())->method('selectValue')->willReturn('blob');

        $storage = $this->createStorage($db);
        $storage->read('sid');
    }

    public function testReadReturnsEmptyStringOnDbError(): void
    {
        $db = $this->createDbStub();
        $db->method('transactionStarted')->willReturn(false);
        $db->method('beginDbTransaction');
        $db->method('columns')
            ->willThrowException(new Horde_Db_Exception('db error'));

        $storage = $this->createStorage($db);
        $this->assertEquals('', $storage->read('sid'));
    }

    public function testWriteInsertsNewSession(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('isActive')->willReturn(true);
        $db->expects($this->once())->method('selectValue')->willReturn(null);
        $db->expects($this->once())->method('insertBlob')
            ->with(
                'horde_sessionhandler',
                $this->callback(function ($fields) {
                    return $fields['session_id'] === 'sid'
                        && $fields['session_data'] instanceof Horde_Db_Value_Binary
                        && isset($fields['session_lastmodified']);
                }),
                null,
                'sid'
            );
        $db->expects($this->once())->method('commitDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->write('sid', 'data'));
    }

    public function testWriteUpdatesExistingSession(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('isActive')->willReturn(true);
        $db->expects($this->once())->method('selectValue')->willReturn(1);
        $db->expects($this->once())->method('updateBlob')
            ->with(
                'horde_sessionhandler',
                $this->callback(function ($fields) {
                    return $fields['session_data'] instanceof Horde_Db_Value_Binary
                        && isset($fields['session_lastmodified']);
                }),
                $this->callback(function ($where) {
                    return $where[0] === 'session_id = ?' && $where[1] === ['sid'];
                })
            );
        $db->expects($this->once())->method('commitDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->write('sid', 'updated-data'));
    }

    public function testWriteReconnectsIfNotActive(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('isActive')->willReturn(false);
        $db->expects($this->once())->method('reconnect');
        $db->expects($this->once())->method('beginDbTransaction');
        $db->expects($this->once())->method('selectValue')->willReturn(null);
        $db->expects($this->once())->method('insertBlob');
        $db->expects($this->once())->method('commitDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->write('sid', 'data'));
    }

    public function testWriteReturnsFalseOnExistenceCheckError(): void
    {
        $db = $this->createDbStub();
        $db->method('isActive')->willReturn(true);
        $db->method('selectValue')
            ->willThrowException(new Horde_Db_Exception('query failed'));

        $storage = $this->createStorage($db);
        $this->assertFalse($storage->write('sid', 'data'));
    }

    public function testWriteRollsBackOnInsertError(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('isActive')->willReturn(true);
        $db->expects($this->once())->method('selectValue')->willReturn(null);
        $db->expects($this->once())->method('insertBlob')
            ->willThrowException(new Horde_Db_Exception('insert failed'));
        $db->expects($this->once())->method('rollbackDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertFalse($storage->write('sid', 'data'));
    }

    public function testWriteHandlesRollbackFailureGracefully(): void
    {
        $db = $this->createDbStub();
        $db->method('isActive')->willReturn(true);
        $db->method('selectValue')->willReturn(null);
        $db->method('insertBlob')
            ->willThrowException(new Horde_Db_Exception('insert failed'));
        $db->method('rollbackDbTransaction')
            ->willThrowException(new Horde_Db_Exception('rollback failed'));

        $storage = $this->createStorage($db);
        $this->assertFalse($storage->write('sid', 'data'));
    }

    public function testDestroyDeletesAndCommits(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('delete')
            ->with(
                $this->stringContains('DELETE FROM horde_sessionhandler WHERE session_id = ?'),
                ['sid']
            );
        $db->expects($this->once())->method('commitDbTransaction');

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->destroy('sid'));
    }

    public function testDestroyReturnsFalseOnError(): void
    {
        $db = $this->createDbStub();
        $db->method('delete')
            ->willThrowException(new Horde_Db_Exception('delete failed'));

        $storage = $this->createStorage($db);
        $this->assertFalse($storage->destroy('sid'));
    }

    public function testGcDeletesExpiredSessions(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('delete')
            ->with(
                $this->stringContains('session_lastmodified'),
                $this->callback(function ($values) {
                    return is_array($values) && count($values) === 1 && is_int($values[0]);
                })
            );

        $storage = $this->createStorage($db);
        $this->assertTrue($storage->gc(300));
    }

    public function testGcReturnsFalseOnError(): void
    {
        $db = $this->createDbStub();
        $db->method('delete')
            ->willThrowException(new Horde_Db_Exception('gc failed'));

        $storage = $this->createStorage($db);
        $this->assertFalse($storage->gc(300));
    }

    public function testGetSessionIDsReturnsActiveSessionIds(): void
    {
        $db = $this->createDbMock();
        $db->expects($this->once())->method('selectValues')
            ->with(
                $this->stringContains('session_id'),
                $this->callback(function ($values) {
                    return is_array($values) && count($values) === 1;
                })
            )
            ->willReturn(['id1', 'id2', 'id3']);

        $storage = $this->createStorage($db);
        $this->assertEquals(['id1', 'id2', 'id3'], $storage->getSessionIDs());
    }

    public function testGetSessionIDsReturnsEmptyOnError(): void
    {
        $db = $this->createDbStub();
        $db->method('selectValues')
            ->willThrowException(new Horde_Db_Exception('query failed'));

        $storage = $this->createStorage($db);
        $this->assertEquals([], $storage->getSessionIDs());
    }
}
