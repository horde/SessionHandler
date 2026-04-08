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

namespace Horde\SessionHandler\Test\Integration\Sql\Pdo;

use Horde\SessionHandler\Test\Unnamespaced\SqlBaseTestCase;
use Horde_Db_Adapter_Pdo_Sqlite;
use Exception;

/**
 * @coversNothing
 */
class SqliteTest extends SqlBaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::$reason = 'PDO SQLite extension not available';
            return;
        }

        try {
            self::$db = new Horde_Db_Adapter_Pdo_Sqlite(['dbname' => ':memory:']);
            parent::setUpBeforeClass();
        } catch (Exception $e) {
            self::$reason = 'SQLite not available: ' . $e->getMessage();
        }
    }
}
