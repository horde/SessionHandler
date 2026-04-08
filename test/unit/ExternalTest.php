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

use Horde\SessionHandler\Test\Unnamespaced\BaseTestCase;
use Horde_SessionHandler_Storage_External;
use Horde_SessionHandler_Storage_File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

#[CoversClass(Horde_SessionHandler_Storage_External::class)]
class ExternalTest extends BaseTestCase
{
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

    /**
     * The external driver doesn't support listing, so test for existing
     * sessions manually.
     */
    #[Depends('testWrite')]
    public function testList(): void
    {
        self::$handler->close();
        self::$handler->open(self::$dir, 'sessionname');
        self::$handler->read('sessionid2');
        self::$handler->write('sessionid2', 'sessiondata2');
        /* List while session is active. */
        $this->assertNotEmpty(self::$handler->read('sessionid'));
        $this->assertNotEmpty(self::$handler->read('sessionid2'));
        self::$handler->close();

        /* List while session is inactive. */
        self::$handler->open(self::$dir, 'sessionname');
        $this->assertNotEmpty(self::$handler->read('sessionid'));
        $this->assertNotEmpty(self::$handler->read('sessionid2'));
        self::$handler->close();
    }

    #[Depends('testList')]
    public function testDestroy(): void
    {
        self::$handler->open(self::$dir, 'sessionname');
        self::$handler->read('sessionid2');
        self::$handler->destroy('sessionid2');
        $this->assertSame('', self::$handler->read('sessionid2'));
    }

    #[Depends('testDestroy')]
    public function testGc(): void
    {
        self::$handler->open(self::$dir, 'sessionname');
        self::$handler->gc(-1);
        $this->assertSame('', self::$handler->read('sessionid'));
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $external = new Horde_SessionHandler_Storage_File(['path' => self::$dir]);
        self::$handler = new Horde_SessionHandler_Storage_External(
            ['open' => [$external, 'open'],
                'close' => [$external, 'close'],
                'read' => [$external, 'read'],
                'write' => [$external, 'write'],
                'destroy' => [$external, 'destroy'],
                'gc' => [$external, 'gc']]
        );
    }
}
