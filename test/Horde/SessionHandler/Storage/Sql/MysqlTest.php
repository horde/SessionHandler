<?php
/**
 * Prepare the test setup.
 */
namespace Horde\SessionHandler\Storage\Sql;

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
        if (!extension_loaded('mysql')) {
            self::$reason = 'No mysql extension';
            return;
        }
        $config = self::getConfig('SESSIONHANDLER_SQL_MYSQL_TEST_CONFIG',
                                  dirname(__FILE__) . '/../..');
        if (!$config || empty($config['sessionhandler']['sql']['mysql'])) {
            self::$reason = 'No mysql configuration';
            return;
        }
        self::$db = new Horde_Db_Adapter_Mysql($config['sessionhandler']['sql']['mysql']);
        parent::setUpBeforeClass();
    }
}
