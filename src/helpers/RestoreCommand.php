<?php

namespace anchovy\securebackups\helpers;

/**
 * Builds the shell command Craft runs to restore a backup.
 *
 * Kept here, pure and static, because this string is the most fragile thing in the
 * plugin: a mistake in it does not throw, it hands malformed input to the database
 * client during a restore, which is the worst possible moment to find out. Isolating
 * it from Craft makes it directly testable.
 *
 * @author Anchovy <ben@anchovy.nz>
 * @since 1.0.0
 */
abstract class RestoreCommand
{
    // Constants
    // =========================================================================

    /**
     * How every pipeable restore command ends.
     *
     * Both MySQL and plain-format PostgreSQL finish by feeding the backup in on
     * stdin, which is what makes a decryption pipe possible at all. The pg_restore
     * formats pass the path as an argument instead (Craft's own source notes it
     * "can't use STDIN, as it may be a directory"), leaving nothing to pipe into.
     */
    public const REDIRECT_SUFFIX = '< "{file}"';

    // Static Methods
    // =========================================================================

    /**
     * Returns whether decryption can be spliced into the given restore command.
     *
     * @param string $baseCommand
     * @return bool
     */
    public static function isPipeable(string $baseCommand): bool
    {
        return str_ends_with(rtrim($baseCommand), self::REDIRECT_SUFFIX);
    }

    /**
     * Rewrites a restore command so the backup is decrypted, and optionally
     * decompressed, on the way in.
     *
     * Turns `mysql … < "{file}"` into
     * `openssl enc -d … -in "{file}" | gzip -dcf | mysql …`, so the plaintext SQL
     * exists only in a pipe and is never written to disk.
     *
     * `$decompress` reflects whether gzip is installed, not whether the `compress`
     * setting is on. The setting describes how *new* backups are written and says
     * nothing about the one being restored, and `-f` alongside `-c` makes gzip copy
     * input it does not recognise straight through. Including the stage
     * unconditionally is therefore what lets one command restore a compressed backup,
     * an uncompressed one, and anything taken before compression existed.
     *
     * @param string $baseCommand The command to wrap, ending in the redirect suffix
     * @param string $cipher
     * @param string $keyEnvVar Name of the env var holding the key
     * @param bool $decompress Whether to include the decompression stage
     * @return string
     */
    public static function build(
        string $baseCommand,
        string $cipher,
        string $keyEnvVar,
        bool $decompress,
    ): string {
        // Strip the redirection; the same bytes now arrive on stdin from the pipe.
        $withoutRedirect = rtrim(substr(rtrim($baseCommand), 0, -strlen(self::REDIRECT_SUFFIX)));

        $stages = [
            sprintf('openssl enc -d -%s -pbkdf2 -pass env:%s -in "{file}"', $cipher, $keyEnvVar),
        ];

        if ($decompress) {
            $stages[] = 'gzip -dcf';
        }

        $stages[] = $withoutRedirect;

        return implode(' | ', $stages);
    }
}
