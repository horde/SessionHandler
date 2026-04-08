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

namespace Horde\SessionHandler\Test\Integration\Sql;

use Horde\SessionHandler\Test\Unnamespaced\SqlBaseTestCase;
use Horde_Db_Adapter_Mysqli;

/**
 * @coversNothing
 */
class MysqliTest extends SqlBaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('mysqli')) {
            self::$reason = 'No mysqli extension';
            return;
        }
        $config = self::getConfig(
            'SESSIONHANDLER_SQL_MYSQLI_TEST_CONFIG',
            dirname(__FILE__) . '/../..'
        );
        if (!$config || empty($config['sessionhandler']['sql']['mysqli'])) {
            self::$reason = 'No mysqli configuration';
            return;
        }
        self::$db = new Horde_Db_Adapter_Mysqli($config['sessionhandler']['sql']['mysqli']);
        parent::setUpBeforeClass();
    }
}
