<?php

declare(strict_types=1);

namespace anchovy\securebackups\tests\unit;

use anchovy\securebackups\helpers\RestoreCommand;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shell command spliced into Craft's restore.
 *
 * A defect here does not throw. It hands malformed input to the database client
 * mid-restore, so these assertions are deliberately exact about the resulting string.
 */
final class RestoreCommandTest extends TestCase
{
    /**
     * Craft's real default for MySQL, as returned by the schema.
     */
    private const MYSQL_COMMAND = 'mysql --defaults-extra-file="/tmp/my.cnf" {database} < "{file}"';

    public function testRecognisesAPipeableCommand(): void
    {
        self::assertTrue(RestoreCommand::isPipeable(self::MYSQL_COMMAND));
    }

    public function testToleratesTrailingWhitespace(): void
    {
        self::assertTrue(RestoreCommand::isPipeable(self::MYSQL_COMMAND . "  \n"));
    }

    public function testRejectsAFileArgumentCommand(): void
    {
        // pg_restore takes the backup as an argument, so there is nothing to pipe into.
        $pgRestore = 'pg_restore --dbname={database} "{file}"';

        self::assertFalse(RestoreCommand::isPipeable($pgRestore));
    }

    public function testBuildsTheFullPipeline(): void
    {
        $command = RestoreCommand::build(self::MYSQL_COMMAND, 'aes-256-cbc', 'SB_KEY_ABC', true);

        self::assertSame(
            'openssl enc -d -aes-256-cbc -pbkdf2 -pass env:SB_KEY_ABC -in "{file}"'
            . ' | gzip -dcf'
            . ' | mysql --defaults-extra-file="/tmp/my.cnf" {database}',
            $command
        );
    }

    public function testOmitsDecompressionWhenGzipIsUnavailable(): void
    {
        $command = RestoreCommand::build(self::MYSQL_COMMAND, 'aes-256-cbc', 'SB_KEY_ABC', false);

        self::assertStringNotContainsString('gzip', $command);
        self::assertStringContainsString('openssl enc -d', $command);
    }

    public function testDropsTheStdinRedirect(): void
    {
        // Left in place, the shell would redirect the file over the piped stdin and
        // the database client would silently receive ciphertext instead of SQL.
        $command = RestoreCommand::build(self::MYSQL_COMMAND, 'aes-256-cbc', 'SB_KEY', true);

        self::assertStringNotContainsString('< "{file}"', $command);
    }

    public function testKeepsExactlyOneFilePlaceholder(): void
    {
        $command = RestoreCommand::build(self::MYSQL_COMMAND, 'aes-256-cbc', 'SB_KEY', true);

        self::assertSame(1, substr_count($command, '{file}'));
        self::assertStringContainsString('{database}', $command);
    }

    public function testNeverPutsTheKeyOnTheCommandLine(): void
    {
        // Only the *name* of the environment variable may appear. A passphrase in argv
        // is readable by any user who can run `ps`.
        $command = RestoreCommand::build(self::MYSQL_COMMAND, 'aes-256-cbc', 'SB_KEY', true);

        self::assertStringContainsString('-pass env:SB_KEY', $command);
    }

    public function testHonoursANonDefaultCipher(): void
    {
        $command = RestoreCommand::build(self::MYSQL_COMMAND, 'aes-128-cbc', 'SB_KEY', true);

        self::assertStringContainsString('openssl enc -d -aes-128-cbc', $command);
    }

    public function testWrapsACustomRestoreCommand(): void
    {
        // A site may set its own `restoreCommand`; the wrapping has to survive that.
        $custom = 'mysql --host=db --user=root somedb < "{file}"';

        self::assertSame(
            'openssl enc -d -aes-256-cbc -pbkdf2 -pass env:K -in "{file}"'
            . ' | gzip -dcf'
            . ' | mysql --host=db --user=root somedb',
            RestoreCommand::build($custom, 'aes-256-cbc', 'K', true)
        );
    }
}
