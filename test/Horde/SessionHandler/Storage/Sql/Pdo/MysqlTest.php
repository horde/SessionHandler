<?php
/**
 * Prepare the test setup.
 */
namespace Horde\SessionHandler\Storage\Sql\Pdo;
use Horde\SessionHandler\Storage\Sql\SqlBaseTestCase;
use \PDO;

/**
 * Copyright 2012-2017 Horde LLC (http://www.horde.org/)
 *
 * @author     Jan Schneider <jan@horde.org>
 * @category   Horde
 * @package    SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class MysqlTest extends SqlBaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo') ||
            !in_array('mysql', PDO::getAvailableDrivers())) {
            self::$reason = 'No mysql extension or no mysql PDO driver';
            return;
        }
        $config = self::getConfig('SESSIONHANDLER_SQL_PDO_MYSQL_TEST_CONFIG',
                                  dirname(__FILE__) . '/../../..');
        if ($config && !empty($config['sessionhandler']['sql']['pdo_mysql'])) {
            self::$db = new Horde_Db_Adapter_Pdo_Mysql($config['sessionhandler']['sql']['pdo_mysql']);
            parent::setUpBeforeClass();
        } else {
            self::$reason = 'No pdo_mysql configuration';
        }
    }
}
