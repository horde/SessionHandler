<?php
/**
 * Prepare the test setup.
 */
namespace Horde\SessionHandler\Storage\Sql;

/**
 * Copyright 2014-2017 Horde LLC (http://www.horde.org/)
 *
 * @author     Jan Schneider <jan@horde.org>
 * @category   Horde
 * @package    SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Oci8Test extends SqlBaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('oci8')) {
            self::$reason = 'No oci8 extension';
            return;
        }
        $config = self::getConfig('SESSIONHANDLER_SQL_OCI8_TEST_CONFIG',
                                  dirname(__FILE__) . '/../..');
        if (!$config || empty($config['sessionhandler']['sql']['oci8'])) {
            self::$reason = 'No oci8 configuration';
            return;
        }
        self::$db = new Horde_Db_Adapter_Oci8($config['sessionhandler']['sql']['oci8']);
        parent::setUpBeforeClass();
    }

    public function testLargeWrite()
    {
        $this->assertTrue(self::$handler->open(self::$dir, 'sessiondata'));
        $this->assertSame('', self::$handler->read('largedata'));
        // Write twice to test both INSERT and UPDATE.
        $this->assertTrue(self::$handler->write('largedata', str_repeat('x', 4001)));
        $this->assertTrue(self::$handler->write('largedata', str_repeat('x', 4001)));
        $this->assertTrue(self::$handler->destroy('largedata'));
    }
}
