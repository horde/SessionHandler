<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\Unit;

use Horde\SessionHandler\SessionId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionId::class)]
class SessionIdTest extends TestCase
{
    #[Test]
    public function testAcceptsAlphanumericId(): void
    {
        $id = new SessionId('abc123DEF');
        self::assertSame('abc123DEF', $id->id);
    }

    #[Test]
    public function testAcceptsCommasAndDashes(): void
    {
        $id = new SessionId('abc-123,def');
        self::assertSame('abc-123,def', $id->id);
    }

    #[Test]
    public function testAcceptsSingleCharId(): void
    {
        $id = new SessionId('a');
        self::assertSame('a', $id->id);
    }

    #[Test]
    public function testAcceptsMaxLengthId(): void
    {
        $longId = str_repeat('a', 256);
        $id = new SessionId($longId);
        self::assertSame($longId, $id->id);
    }

    #[Test]
    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionId('');
    }

    #[Test]
    public function testRejectsSlashes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionId('abc/def');
    }

    #[Test]
    public function testRejectsDots(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionId('abc.def');
    }

    #[Test]
    public function testRejectsSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionId('abc def');
    }

    #[Test]
    public function testRejectsOverMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionId(str_repeat('a', 257));
    }

    #[Test]
    public function testToStringReturnsId(): void
    {
        $id = new SessionId('session42');
        self::assertSame('session42', (string) $id);
    }

    #[Test]
    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = new SessionId('same-id');
        $b = new SessionId('same-id');
        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function testEqualsReturnsFalseForDifferentId(): void
    {
        $a = new SessionId('id-one');
        $b = new SessionId('id-two');
        self::assertFalse($a->equals($b));
    }
}
