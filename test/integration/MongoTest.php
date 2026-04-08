<?php

declare(strict_types=1);

/**
 * Copyright 2016-2026 Horde LLC (http://www.horde.org/)
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
use Horde_SessionHandler_Storage_Mongo;
use Horde_Test_Factory_Mongo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

#[CoversClass(Horde_SessionHandler_Storage_Mongo::class)]
class MongoTest extends BaseTestCase
{
    protected static string $reason = '';
    protected static $mongo;

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
        if (($config = self::getConfig('SESSIONHANDLER_MONGO_TEST_CONFIG', __DIR__ . '/..'))
            && isset($config['sessionhandler']['mongo'])) {
            $factory = new Horde_Test_Factory_Mongo();
            self::$mongo = $factory->create([
                'config' => $config['sessionhandler']['mongo'],
                'dbname' => 'horde_sessionhandler_test',
            ]);
        }
        if (empty(self::$mongo)) {
            self::$reason = 'MongoDB not available.';
            return;
        }
        self::$handler = new Horde_SessionHandler_Storage_Mongo([
            'mongo_db' => self::$mongo,
        ]);
        parent::setUpBeforeClass();
    }

    public function setUp(): void
    {
        if (!self::$handler) {
            $this->markTestSkipped(self::$reason);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$mongo) {
            self::$mongo->selectDB(null)->drop();
        }
        parent::tearDownAfterClass();
    }
}
