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

namespace Horde\SessionHandler\Test\Integration;

use Horde\SessionHandler\Test\Unnamespaced\BaseTestCase;
use Horde_SessionHandler_Storage_Memcache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use Horde_Memcache;

#[CoversClass(Horde_SessionHandler_Storage_Memcache::class)]
class MemcacheTest extends BaseTestCase
{
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

    public static function setUpBeforeClass(): void
    {
        if (!(extension_loaded('memcache') || extension_loaded('memcached'))) {
            self::$reason = 'No memcache extension.';
            return;
        }
        $config = self::getConfig(
            'SESSIONHANDLER_MEMCACHE_TEST_CONFIG',
            dirname(__FILE__) . '/..'
        );
        if (!$config || empty($config['sessionhandler']['memcache'])) {
            self::$reason = 'No memcache configuration.';
            return;
        }
        $memcache = new Horde_Memcache($config['sessionhandler']['memcache']);
        $memcache->delete('sessionid');
        $memcache->delete('sessionid2');
        self::$handler = new Horde_SessionHandler_Storage_Memcache(
            ['memcache' => $memcache, 'track' => true]
        );
        parent::setUpBeforeClass();
    }

    public function setUp(): void
    {
        if (!self::$handler) {
            $this->markTestSkipped(self::$reason);
        }
    }
}
