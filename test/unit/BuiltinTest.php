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

namespace Horde\SessionHandler\Test\Unit;

use Horde_SessionHandler_Storage_Builtin;
use Horde_Util;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Horde\Util\Util;

#[CoversClass(Horde_SessionHandler_Storage_Builtin::class)]
class BuiltinTest extends TestCase
{
    protected static Horde_SessionHandler_Storage_Builtin $handler;
    protected static string $dir;

    #[RunInSeparateProcess]
    public function testWrite(): void
    {
        $this->_write();
    }

    #[RunInSeparateProcess]
    public function testRead(): void
    {
        $this->_write();
        $this->assertEquals('sessiondata|s:3:"foo";', self::$handler->read('sessionid'));
    }

    #[RunInSeparateProcess]
    public function testReopen(): void
    {
        $this->_write();
        session_write_close();
        session_name('sessionname');
        session_id('sessionid');
        session_start();
        $this->assertEquals('foo', $_SESSION['sessiondata']);
        session_write_close();
    }

    #[RunInSeparateProcess]
    public function testList(): void
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
        $this->assertEquals(['sessionid', 'sessionid2'], $ids);
        session_write_close();

        /* List while session is inactive. */
        $ids = self::$handler->getSessionIDs();
        sort($ids);
        $this->assertEquals(['sessionid', 'sessionid2'], $ids);
    }

    #[RunInSeparateProcess]
    public function testDestroy(): void
    {
        $this->testList();
        session_name('sessionname');
        session_id('sessionid2');
        session_start();
        $sessionIds = self::$handler->getSessionIDs();
        sort($sessionIds);
        $this->assertEquals(
            ['sessionid', 'sessionid2'],
            $sessionIds
        );
        session_destroy();
        $this->assertEquals(
            ['sessionid'],
            self::$handler->getSessionIDs()
        );
    }

    #[RunInSeparateProcess]
    public function testGc(): void
    {
        $this->testDestroy();
        ini_set('session.gc_probability', '100');
        ini_set('session.gc_divisor', '1');
        ini_set('session.gc_maxlifetime', '-1');
        session_name('sessionname');
        session_start();
        $this->assertEquals(
            [],
            self::$handler->getSessionIDs()
        );
    }

    protected function _write(): void
    {
        session_name('sessionname');
        session_id('sessionid');
        session_start();
        $this->assertEmpty($_SESSION);
        $_SESSION['sessiondata'] = 'foo';
        session_write_close();
    }

    public static function setUpBeforeClass(): void
    {
        self::$dir = Util::createTempDir();
        if (!headers_sent()) {
            session_cache_limiter('');
            ini_set('session.use_cookies', '0');
            ini_set('session.save_path', self::$dir);
        }
        self::$handler = new Horde_SessionHandler_Storage_Builtin(['path' => self::$dir]);
    }

    public static function tearDownAfterClass(): void
    {
        unset($_SESSION);
        if (session_status() == PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent()) {
            session_name(ini_get('session.name'));
        }
    }
}
