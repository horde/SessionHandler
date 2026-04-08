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
use Horde_Db_Adapter_Pdo_Pgsql;
use PDO;

/**
 * @coversNothing
 */
class PgsqlTest extends SqlBaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo')
            || !in_array('pgsql', PDO::getAvailableDrivers())) {
            self::$reason = 'No pgsql extension or no pgsql PDO driver';
            return;
        }
        $config = self::getConfig(
            'SESSIONHANDLER_SQL_PDO_PGSQL_TEST_CONFIG',
            dirname(__FILE__) . '/../../..'
        );
        if ($config && !empty($config['sessionhandler']['sql']['pdo_pgsql'])) {
            self::$db = new Horde_Db_Adapter_Pdo_Pgsql($config['sessionhandler']['sql']['pdo_pgsql']);
            parent::setUpBeforeClass();
        } else {
            self::$reason = 'No pdo_pgsql configuration';
        }
    }
}
