<?php

declare(strict_types=1);

/**
 * Copyright 2012-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author     Jan Schneider <jan@horde.org>
 * @category   Horde
 * @package    Horde_SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\SessionHandler\Test\Unnamespaced;

use Horde_Db_Adapter;
use Horde_Db_Migration_Migrator;
use Horde_Log_Handler_Cli;
use Horde_Log_Logger;
use Horde_SessionHandler_Storage_Sql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

#[CoversClass(Horde_SessionHandler_Storage_Sql::class)]
abstract class SqlBaseTestCase extends BaseTestCase
{
    protected static ?Horde_Db_Adapter $db = null;
    protected static ?Horde_Db_Migration_Migrator $migrator = null;
    protected static string $reason = '';

    public function testWrite(): void
    {
        $this->_write();
    }

    #[Depends('testWrite')]
    public function testRead(): void
    {
        $this->_read();
    }

    #[Depends('testWrite')]
    public function testReopen(): void
    {
        $this->_reopen();
    }

    #[Depends('testWrite')]
    public function testList(): void
    {
        $this->_list();
    }

    #[Depends('testList')]
    public function testDestroy(): void
    {
        $this->_destroy();
    }

    #[Depends('testDestroy')]
    public function testGc(): void
    {
        $this->_gc();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $dir = dirname(__FILE__) . '/../../migration/Horde/SessionHandler';
        if (!is_dir($dir)) {
            error_reporting(E_ALL & ~E_DEPRECATED);
            $dir = PEAR_Config::singleton()
                ->get('data_dir', null, 'pear.horde.org')
                . '/Horde_SessionHandler/migration';
            error_reporting(E_ALL | E_STRICT);
        }
        self::$migrator = new Horde_Db_Migration_Migrator(
            self::$db,
            null,
            ['migrationsPath' => $dir,
                'schemaTableName' => 'horde_sh_schema_info']
        );
        self::$migrator->up();

        self::$handler = new Horde_SessionHandler_Storage_Sql(['db' => self::$db]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$migrator) {
            self::$migrator->down();
        }
        if (self::$db) {
            self::$db->disconnect();
        }
        self::$db = self::$migrator = null;
        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        if (!self::$db) {
            $this->markTestSkipped(self::$reason);
        }
    }
}
