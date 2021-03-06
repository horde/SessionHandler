<?php
/**
 * Prepare the test setup.
 */
require_once dirname(__FILE__) . '/Base.php';

/**
 * Copyright 2012-2017 Horde LLC (http://www.horde.org/)
 *
 * @author     Jan Schneider <jan@horde.org>
 * @category   Horde
 * @package    Horde_SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Horde_SessionHandler_Storage_BuiltinTest extends Horde_SessionHandler_Storage_Base
{
    /**
     * @runInSeparateProcess
     */
    public function testWrite()
    {
        $this->_write();
    }

    /**
     * @runInSeparateProcess
     */
    public function testRead()
    {
        $this->_write();
        $this->assertEquals('sessiondata|s:3:"foo";', self::$handler->read('sessionid'));
    }

    /**
     * @runInSeparateProcess
     */
    public function testReopen()
    {
        $this->_write();
        session_write_close();
        session_name('sessionname');
        session_id('sessionid');
        session_start();
        $this->assertEquals('foo', $_SESSION['sessiondata']);
        session_write_close();
    }

    /**
     * @runInSeparateProcess
     */
    public function testList()
    {
        $this->_write();
        session_write_close();
        session_name('sessionname');
        session_id('sessionid2');
        session_start();
        $_SESSION['sessiondata2'] = 'foo';
        /* List while session is active. */
        $ids = self::$handler->getSessionIDs();
        sort($ids);
        $this->assertEquals(array('sessionid', 'sessionid2'), $ids);
        session_write_close();

        /* List while session is inactive. */
        $ids = self::$handler->getSessionIDs();
        sort($ids);
        $this->assertEquals(array('sessionid', 'sessionid2'), $ids);
    }

    /**
     * @runInSeparateProcess
     */
    public function testDestroy()
    {
        $this->testList();
        session_name('sessionname');
        session_id('sessionid2');
        session_start();
        $sessionIds = self::$handler->getSessionIDs();
        sort($sessionIds);
        $this->assertEquals(array('sessionid', 'sessionid2'),
                            $sessionIds);
        session_destroy();
        $this->assertEquals(array('sessionid'),
                            self::$handler->getSessionIDs());
    }

    /**
     * @runInSeparateProcess
     */
    public function testGc()
    {
        $this->testDestroy();
        $this->probability = ini_get('session.gc_probability');
        $this->divisor     = ini_get('session.gc_divisor');
        $this->maxlifetime = ini_get('session.gc_maxlifetime');
        ini_set('session.gc_probability', 100);
        ini_set('session.gc_divisor', 1);
        ini_set('session.gc_maxlifetime', -1);
        session_name('sessionname');
        session_start();
        $this->assertEquals(array(),
                            self::$handler->getSessionIDs());
    }

    protected function _write()
    {
        session_name('sessionname');
        session_id('sessionid');
        session_start();
        $this->assertEmpty($_SESSION);
        $_SESSION['sessiondata'] = 'foo';
        session_write_close();
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$handler = new Horde_SessionHandler_Storage_Builtin(array('path' => self::$dir));
    }

    /**
     * @todo Rely on session_status() in H6.
     */
    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        unset($_SESSION);
        if ((function_exists('session_status') &&
             session_status() == PHP_SESSION_ACTIVE) ||
            (!function_exists('session_status') &&
             session_id())) {
            session_destroy();
        }
    }

}
