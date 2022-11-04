<?php
/**
 * Prepare the test setup.
 */
namespace Horde\SessionHandler\Storage\Sql;
use Horde\SessionHandler\Storage\BaseTestCase;
use \Horde_Log_Logger;
use \Horde_Log_Handler_Cli;
use \Horde_Db_Migration_Migrator;
use \Horde_SessionHandler_Storage_Sql;

/**
 * Copyright 2012-2017 Horde LLC (http://www.horde.org/)
 *
 * @author     Jan Schneider <jan@horde.org>
 * @category   Horde
 * @package    Horde_SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SqlBaseTestCase extends BaseTestCase
{
    protected static $db;

    protected static $migrator;

    protected static $reason;

    public function testWrite()
    {
        $this->_write();
    }

    /**
     * @depends testWrite
     */
    public function testRead()
    {
        $this->_read();
    }

    /**
     * @depends testWrite
     */
    public function testReopen()
    {
        $this->_reopen();
    }

    /**
     * @depends testWrite
     */
    public function testList()
    {
        $this->_list();
    }

    /**
     * @depends testList
     */
    public function testDestroy()
    {
        $this->_destroy();
    }

    /**
     * @depends testDestroy
     */
    public function testGc()
    {
        $this->_gc();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $logger = new Horde_Log_Logger(new Horde_Log_Handler_Cli());
        //self::$db->setLogger($logger);
        $dir = dirname(__FILE__) . '/../../../../../migration/Horde/SessionHandler';
        if (!is_dir($dir)) {
            error_reporting(E_ALL & ~E_DEPRECATED);
            $dir = PEAR_Config::singleton()
                ->get('data_dir', null, 'pear.horde.org')
                . '/Horde_SessionHandler/migration';
            error_reporting(E_ALL | E_STRICT);
        }
        self::$migrator = new Horde_Db_Migration_Migrator(
            self::$db,
            null,//$logger,
            array('migrationsPath' => $dir,
                  'schemaTableName' => 'horde_sh_schema_info'));
        self::$migrator->up();

        self::$handler = new Horde_SessionHandler_Storage_Sql(array('db' => self::$db));
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
