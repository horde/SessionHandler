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
use Horde_SessionHandler_Storage_File;
use Horde_SessionHandler_Storage_Stack;
use Horde_Util;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use Horde\Util\Util;

#[CoversClass(Horde_SessionHandler_Storage_Stack::class)]
class StackTest extends BaseTestCase
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

    #[Depends('testDestroy')]
    public function testGc(): void
    {
        $this->_gc();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $storage1 = new Horde_SessionHandler_Storage_File([
            'path' => self::$dir,
        ]);
        $storage2 = new Horde_SessionHandler_Storage_File([
            'path' => Util::createTempDir(),
        ]);

        self::$handler = new Horde_SessionHandler_Storage_Stack([
            'stack' => [
                $storage1,
                $storage2,
            ],
        ]);
    }
}
