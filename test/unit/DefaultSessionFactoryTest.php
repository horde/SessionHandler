<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\Unit;

use Horde\SessionHandler\DefaultSessionFactory;
use Horde\SessionHandler\SessionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultSessionFactory::class)]
class DefaultSessionFactoryTest extends TestCase
{
    private DefaultSessionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DefaultSessionFactory();
    }

    #[Test]
    public function testCreateNewReturnsSessionWithGivenIdAndNoData(): void
    {
        $id = new SessionId('new-session');
        $session = $this->factory->createNew($id);

        self::assertTrue($id->equals($session->getId()));
        self::assertSame([], $session->keys());
        self::assertFalse($session->isDirty());
    }

    #[Test]
    public function testRestoreReturnsSessionWithGivenIdAndData(): void
    {
        $id = new SessionId('restored-session');
        $data = ['user' => 'bob', 'theme' => 'dark'];
        $session = $this->factory->restore($id, $data);

        self::assertTrue($id->equals($session->getId()));
        self::assertSame('bob', $session->get('user'));
        self::assertSame('dark', $session->get('theme'));
        self::assertFalse($session->isDirty());
    }
}
