<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\unit\Storage;

use Horde\SessionHandler\Storage\PhpFilesSavePathSpec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpFilesSavePathSpec::class)]
class PhpFilesSavePathSpecTest extends TestCase
{
    // -----------------------------------------------------------------
    // parse(): happy paths
    // -----------------------------------------------------------------

    #[Test]
    public function testParsesPlainPath(): void
    {
        $spec = PhpFilesSavePathSpec::parse('/var/lib/php/sessions');

        self::assertSame(0, $spec->depth);
        self::assertSame(PhpFilesSavePathSpec::DEFAULT_MODE, $spec->mode);
        self::assertSame('/var/lib/php/sessions', $spec->basePath);
    }

    #[Test]
    public function testParsesDepthAndPath(): void
    {
        $spec = PhpFilesSavePathSpec::parse('2;/var/lib/php/sessions');

        self::assertSame(2, $spec->depth);
        self::assertSame(PhpFilesSavePathSpec::DEFAULT_MODE, $spec->mode);
        self::assertSame('/var/lib/php/sessions', $spec->basePath);
    }

    #[Test]
    public function testParsesDepthModeAndPath(): void
    {
        $spec = PhpFilesSavePathSpec::parse('2;0700;/var/lib/php/sessions');

        self::assertSame(2, $spec->depth);
        self::assertSame(0700, $spec->mode);
        self::assertSame('/var/lib/php/sessions', $spec->basePath);
    }

    #[Test]
    public function testZeroDepthIsValid(): void
    {
        $spec = PhpFilesSavePathSpec::parse('0;/tmp');

        self::assertSame(0, $spec->depth);
        self::assertSame('/tmp', $spec->basePath);
    }

    #[Test]
    public function testMaxDepthIsAccepted(): void
    {
        $input = PhpFilesSavePathSpec::MAX_DEPTH . ';/tmp';
        $spec = PhpFilesSavePathSpec::parse($input);

        self::assertSame(PhpFilesSavePathSpec::MAX_DEPTH, $spec->depth);
    }

    #[Test]
    public function testRelativePathIsAccepted(): void
    {
        // PHP itself does not require absolute paths; we don't either.
        $spec = PhpFilesSavePathSpec::parse('1;sessions');

        self::assertSame(1, $spec->depth);
        self::assertSame('sessions', $spec->basePath);
    }

    #[Test]
    public function testTrailingSlashOnPathIsPreserved(): void
    {
        // Match PHP: no normalisation.
        $spec = PhpFilesSavePathSpec::parse('/tmp/');

        self::assertSame('/tmp/', $spec->basePath);
    }

    // -----------------------------------------------------------------
    // parse(): rejections
    // -----------------------------------------------------------------

    #[Test]
    public function testRejectsEmptyInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty/');

        PhpFilesSavePathSpec::parse('');
    }

    #[Test]
    public function testRejectsTrailingSemicolon(): void
    {
        // "/tmp;" splits to ["/tmp", ""] — last field empty.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no path component/');

        PhpFilesSavePathSpec::parse('/tmp;');
    }

    #[Test]
    public function testRejectsFourFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/three semicolon-separated/');

        PhpFilesSavePathSpec::parse('1;0600;extra;/tmp');
    }

    #[Test]
    public function testRejectsNonNumericDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-numeric depth/');

        PhpFilesSavePathSpec::parse('two;/tmp');
    }

    #[Test]
    public function testRejectsNegativeDepth(): void
    {
        // ctype_digit rejects "-1", so the message is "non-numeric depth".
        $this->expectException(InvalidArgumentException::class);

        PhpFilesSavePathSpec::parse('-1;/tmp');
    }

    #[Test]
    public function testRejectsDepthAboveCap(): void
    {
        $input = (PhpFilesSavePathSpec::MAX_DEPTH + 1) . ';/tmp';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outside the supported range/');

        PhpFilesSavePathSpec::parse($input);
    }

    #[Test]
    public function testRejectsEmptyMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty MODE/');

        PhpFilesSavePathSpec::parse('1;;/tmp');
    }

    #[Test]
    public function testRejectsNonOctalMode(): void
    {
        // 0o9 is not a valid octal digit.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-octal MODE/');

        PhpFilesSavePathSpec::parse('1;0900;/tmp');
    }

    #[Test]
    public function testRejectsModeAboveOctalRange(): void
    {
        // 010000 octal = 4096 decimal, above 07777.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outside the valid range/');

        PhpFilesSavePathSpec::parse('1;010000;/tmp');
    }

    // -----------------------------------------------------------------
    // directoryFor()
    // -----------------------------------------------------------------

    #[Test]
    public function testDirectoryForFlatLayout(): void
    {
        $spec = PhpFilesSavePathSpec::parse('/tmp');

        self::assertSame('/tmp', $spec->directoryFor('abc123def456'));
    }

    #[Test]
    public function testDirectoryForOneLevel(): void
    {
        $spec = PhpFilesSavePathSpec::parse('1;/tmp');

        self::assertSame('/tmp/a', $spec->directoryFor('abc123'));
    }

    #[Test]
    public function testDirectoryForTwoLevels(): void
    {
        $spec = PhpFilesSavePathSpec::parse('2;/tmp');

        self::assertSame('/tmp/a/b', $spec->directoryFor('abc123'));
    }

    #[Test]
    public function testDirectoryForThreeLevels(): void
    {
        $spec = PhpFilesSavePathSpec::parse('3;/var/sess');

        self::assertSame('/var/sess/3/a/b', $spec->directoryFor('3ab9f0'));
    }

    #[Test]
    public function testDirectoryForRejectsTooShortSessionId(): void
    {
        $spec = PhpFilesSavePathSpec::parse('5;/tmp');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/shorter than the configured save_path depth/');

        $spec->directoryFor('abc');
    }
}
