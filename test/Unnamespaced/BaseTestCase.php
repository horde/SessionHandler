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

use Horde_SessionHandler_Storage;
use Horde_Util;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    protected static ?Horde_SessionHandler_Storage $handler = null;
    protected static string $dir = '';

    protected function _write(): void
    {
        $this->assertTrue(self::$handler->open(self::$dir, 'sessionname'));
        $this->assertSame('', self::$handler->read('sessionid'));
        $this->assertTrue(self::$handler->write('sessionid', 'sessiondata'));
    }

    protected function _read(): void
    {
        $this->assertEquals('sessiondata', self::$handler->read('sessionid'));
    }

    protected function _reopen(): void
    {
        $this->assertTrue(self::$handler->close());
        $this->assertTrue(self::$handler->open(self::$dir, 'sessionname'));
        $this->assertEquals('sessiondata', self::$handler->read('sessionid'));
        $this->assertTrue(self::$handler->close());
    }

    protected function _list(): void
    {
        $this->assertTrue(self::$handler->close());
        $this->assertTrue(self::$handler->open(self::$dir, 'sessionname'));
        self::$handler->read('sessionid2');
        $this->assertTrue(self::$handler->write('sessionid2', 'sessiondata2'));
        /* List while session is active. */
        $ids = self::$handler->getSessionIDs();
        sort($ids);
        $this->assertEquals(['sessionid', 'sessionid2'], $ids);
        $this->assertTrue(self::$handler->close());

        /* List while session is inactive. */
        $this->assertTrue(self::$handler->open(self::$dir, 'sessionname'));
        $ids = self::$handler->getSessionIDs();
        sort($ids);
        $this->assertEquals(['sessionid', 'sessionid2'], $ids);
        $this->assertTrue(self::$handler->close());
    }

    protected function _destroy(): void
    {
        $this->assertTrue(self::$handler->open(self::$dir, 'sessionname'));
        self::$handler->read('sessionid2');
        $this->assertTrue(self::$handler->destroy('sessionid2'));
        $this->assertEquals(
            ['sessionid'],
            self::$handler->getSessionIDs()
        );
    }

    protected function _gc(): void
    {
        $this->assertTrue(self::$handler->open(self::$dir, 'sessionname'));
        $this->assertTrue(self::$handler->gc(-1));
        $this->assertEquals(
            [],
            self::$handler->getSessionIDs()
        );
    }

    /**
     * Load test configuration from environment variable or conf.php file.
     *
     * Replaces Horde_Test_Case::getConfig() to avoid horde/test dependency.
     */
    public static function getConfig(string $env, ?string $path = null): ?array
    {
        $config = getenv($env);
        if ($config) {
            $json = json_decode($config, true);
            if ($json) {
                return $json;
            }
        }

        if ($path) {
            $configFile = $path . '/conf.php';
            if (file_exists($configFile)) {
                $conf = [];
                require $configFile;
                return $conf;
            }
        }

        return null;
    }

    public static function setUpBeforeClass(): void
    {
        self::$dir = Horde_Util::createTempDir();
    }

    public static function tearDownAfterClass(): void
    {
        self::$handler = null;
    }
}
