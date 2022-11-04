<?php
/**
 * Prepare the test setup.
 */
namespace Horde\SessionHandler\Storage;
use \Horde_SessionHandler_Storage_File;

/**
 * Copyright 2012-2017 Horde LLC (http://www.horde.org/)
 *
 * @author     Jan Schneider <jan@horde.org>
 * @category   Horde
 * @package    Horde_SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class FileTest extends BaseTestCase
{
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
        self::$handler = new Horde_SessionHandler_Storage_File(array('path' => self::$dir));
    }
}
