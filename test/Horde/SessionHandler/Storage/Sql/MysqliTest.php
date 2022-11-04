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
class MysqliTest extends SqlBaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('mysqli')) {
            self::$reason = 'No mysqli extension';
            return;
        }
        $config = self::getConfig('SESSIONHANDLER_SQL_MYSQLI_TEST_CONFIG',
                                  dirname(__FILE__) . '/../..');
        if (!$config || empty($config['sessionhandler']['sql']['mysqli'])) {
            self::$reason = 'No mysqli configuration';
            return;
        }
        self::$db = new Horde_Db_Adapter_Mysqli($config['sessionhandler']['sql']['mysqli']);
        parent::setUpBeforeClass();
    }
}
