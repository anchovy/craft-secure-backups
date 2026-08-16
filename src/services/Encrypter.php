<?php

namespace anchovy\securebackups\services;

use anchovy\securebackups\models\Settings;
use anchovy\securebackups\SecureBackups;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\base\Exception;

/**
 * Encrypts and decrypts database backup files.
 *
 * The on-disk format is deliberately made of standard parts rather than anything
 * bespoke: gzip inside `openssl enc`, so a backup can always be recovered with a
 * two-command pipeline and no Craft, no PHP and no copy of this plugin. That matters
 * for a backup tool, where the worst possible failure is an archive that can only be
 * opened by software that is no longer installed or maintained.
 *
 * Compression happens before encryption, which is the only order that helps: ciphertext
 * is indistinguishable from random and will not compress afterwards.
 *
 * The key is never passed on the command line, where it would be visible to any user
 * who can run `ps`. On the backup path it is written to the child's stdin. On the
 * restore path Craft owns the process, so a transient environment variable is used
 * instead and unset immediately afterwards (see
 * SecureBackups::_decryptingRestoreCommand()).
 *
 * @author Anchovy <ben@anchovy.nz>
 * @since 1.0.0
 */
class Encrypter extends Component
{
    // Constants
    // =========================================================================

    /**
     * The header `openssl enc -salt` writes at the start of every file it produces.
     *
     * Used to tell an encrypted backup from a plain SQL dump without relying on the
     * file extension, which cannot be changed on the Control Panel path (Craft
     * computes the backup path before the dump runs and asserts the file still
     * exists at exactly that path afterwards).
     */
    public const MAGIC = 'Salted__';

    /**
     * The two header bytes every gzip member starts with.
     *
     * Only ever seen *inside* the ciphertext, since compression happens before
     * encryption. Used to tell a decrypted payload that still needs decompressing
     * from one that is already plain SQL, so a backup made with `compress` off (or
     * before the setting existed) is recognised for what it is.
     */
    public const GZIP_MAGIC = "\x1f\x8b";

    /**
     * The cipher used for new backups.
     *
     * AES-256-CBC with PBKDF2 key derivation, matching `openssl enc -aes-256-cbc
     * -pbkdf2`. Decryption reads the cipher from the settings too, so an operator
     * who changes this can still restore older backups by changing it back.
     */
    public const DEFAULT_CIPHER = 'aes-256-cbc';

    /**
     * How long to wait for openssl before giving up and killing it, in seconds.
     *
     * A backup that legitimately takes longer than this is possible on a very large
     * database, so the setting is configurable. The point of the bound is that a
     * wedged child must never hang a web request or a cron run indefinitely.
     */
    public const DEFAULT_TIMEOUT = 900;

    // Public Methods
    // =========================================================================

    /**
     * Returns the encryption key, or null if none is configured.
     *
     * Read through App::env() rather than getenv() so it resolves the same way every
     * other Craft setting does: `$_SERVER` (which is where nginx `fastcgi_param` and
     * some Docker setups put it), then the real environment, then a
     * `CRAFT_SECRETS_PATH` file.
     *
     * @return string|null
     */
    public function getKey(): ?string
    {
        $name = $this->getSettings()->keyEnvVar;

        if ($name === '') {
            return null;
        }

        $key = App::env($name);

        if (!is_string($key) || $key === '') {
            return null;
        }

        return $key;
    }

    /**
     * Returns whether the plugin has everything it needs to encrypt.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->getKey() !== null;
    }

    /**
     * Returns whether the file at the given path is an encrypted backup.
     *
     * Sniffs the magic header rather than trusting the extension, because on the
     * Control Panel path the encrypted file keeps its original `.sql` name.
     *
     * @param string $path
     * @return bool
     */
    public function isEncrypted(string $path): bool
    {
        return $this->_startsWith($path, self::MAGIC);
    }

    /**
     * Returns whether the file at the given path is gzip-compressed.
     *
     * @param string $path
     * @return bool
     */
    public function isCompressed(string $path): bool
    {
        return $this->_startsWith($path, self::GZIP_MAGIC);
    }

    /**
     * Encrypts the file at the given path in place.
     *
     * Writes to a sibling temp file and renames over the original, so an interrupted
     * run can never leave a half-written backup that looks complete. The original
     * plaintext is removed only once the encrypted file is safely in place.
     *
     * When the `compress` setting is on the dump is gzipped first, into a second temp
     * file that is discarded once the encrypted result is in place.
     *
     * @param string $path
     * @throws Exception if no key is configured, or a child process fails or times out
     */
    public function encryptFile(string $path): void
    {
        $key = $this->getKey();

        if ($key === null) {
            throw new Exception('No encryption key is configured.');
        }

        if (!is_file($path)) {
            throw new Exception("No backup file exists at $path.");
        }

        // Already encrypted: nothing to do. Guards against double-encryption if some
        // other code path has already run the file through us.
        if ($this->isEncrypted($path)) {
            return;
        }

        $settings = $this->getSettings();
        $tempPath = $path . '.sb-tmp';
        $compressedPath = $path . '.sb-gz';

        try {
            // Compress before encrypting, never after. Ciphertext is indistinguishable
            // from random and will not compress, which is why Craft's zip on the
            // Control Panel path saves so little. In this order the dump is already
            // several times smaller by the time the cipher sees it.
            //
            // Compressing plaintext before encrypting it can leak information through
            // ciphertext length when an attacker chooses part of the plaintext and can
            // watch sizes repeatedly (the CRIME/BREACH shape). A backup is written once
            // to disk with no such oracle, so the concern does not apply here.
            $source = $path;

            if ($settings->compress) {
                $this->_compressFile($path, $compressedPath, $settings->timeout);
                $source = $compressedPath;
            }

            $this->_runOpenssl([
                'openssl',
                'enc',
                '-' . $settings->cipher,
                '-pbkdf2',
                '-salt',
                '-pass',
                'stdin',
                '-in',
                $source,
                '-out',
                $tempPath,
            ], $key, $settings->timeout);

            if (!is_file($tempPath) || filesize($tempPath) === 0) {
                throw new Exception('openssl produced no output while encrypting the backup.');
            }

            if (!@rename($tempPath, $path)) {
                throw new Exception("Could not move the encrypted backup into place at $path.");
            }
        } catch (Exception $e) {
            $this->_removeIfPresent($tempPath);
            throw $e;
        } finally {
            // Always discard the intermediate gzip, on success and on failure alike.
            // It is a complete copy of the database, just a smaller one.
            $this->_removeIfPresent($compressedPath);
        }
    }

    /**
     * Returns whether the `openssl` binary is available on this server.
     *
     * Surfaced on the settings screen so a misconfigured server is visible before
     * someone discovers it during an incident.
     *
     * @return bool
     */
    public function opensslIsAvailable(): bool
    {
        return $this->getOpensslVersion() !== null;
    }

    /**
     * Returns the version string reported by the `openssl` binary, or null.
     *
     * @return string|null
     */
    public function getOpensslVersion(): ?string
    {
        try {
            $output = $this->_capture(['openssl', 'version'], 10);
        } catch (Exception) {
            return null;
        }

        $output = trim($output);

        return $output !== '' ? $output : null;
    }

    /**
     * Returns whether the `gzip` binary is available on this server.
     *
     * Needed to compress a new backup, and to decompress one on the way into a
     * restore. Surfaced on the settings screen alongside openssl so a server that
     * cannot do both halves is visible before someone finds out during an incident.
     *
     * @return bool
     */
    public function gzipIsAvailable(): bool
    {
        return $this->getGzipVersion() !== null;
    }

    /**
     * Returns the first line reported by the `gzip` binary, or null.
     *
     * @return string|null
     */
    public function getGzipVersion(): ?string
    {
        try {
            $output = $this->_capture(['gzip', '--version'], 10);
        } catch (Exception) {
            return null;
        }

        // GNU gzip prints several lines; Apple's prints one. Either way the first
        // line is the one worth showing.
        $output = trim(strtok($output, "\n") ?: '');

        return $output !== '' ? $output : null;
    }

    /**
     * Puts the key into the environment under a single-use variable name and
     * returns that name, for use in a shell command Craft will run itself.
     *
     * Used only on the restore path, where the command is a string executed by
     * Craft rather than a process this plugin controls, so stdin is not available.
     * putenv() is the only channel a child process reliably inherits: a value that
     * exists only in `$_ENV` or `$_SERVER` is not visible to it.
     *
     * @return string The generated environment variable name
     * @throws Exception if no key is configured
     */
    public function exportKeyToEnv(): string
    {
        $key = $this->getKey();

        if ($key === null) {
            throw new Exception('No encryption key is configured.');
        }

        $name = 'SECURE_BACKUPS_TRANSIENT_' . strtoupper(StringHelper::randomString(12));
        putenv("$name=$key");

        return $name;
    }

    /**
     * Removes a variable previously created by exportKeyToEnv().
     *
     * @param string $name
     */
    public function clearKeyFromEnv(string $name): void
    {
        putenv($name);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns the plugin's settings.
     *
     * Every read goes through here rather than reaching for the plugin singleton
     * inline, so a test can supply settings without standing up a Craft application.
     * Nothing else in this class depends on Craft being booted.
     *
     * @return Settings
     */
    protected function getSettings(): Settings
    {
        return SecureBackups::getInstance()->getSettings();
    }

    // Private Methods
    // =========================================================================

    /**
     * Deletes a file if it is there, quietly, and does nothing if it is not.
     *
     * The cleanup paths run whether or not a given temp file was ever created: there is
     * no `.sb-gz` when compression is off, and no `.sb-tmp` when openssl failed to
     * start. FileHelper::unlink() raises a PHP warning on a missing path, which would
     * put noise in the error log on every such backup.
     *
     * @param string $path
     */
    private function _removeIfPresent(string $path): void
    {
        if (is_file($path)) {
            FileHelper::unlink($path);
        }
    }

    /**
     * Returns whether the file at the given path begins with the given bytes.
     *
     * Sniffing the header is how both file formats are recognised, because neither
     * can be identified by extension: on the Control Panel path the encrypted file
     * has to keep its original `.sql` name.
     *
     * @param string $path
     * @param string $magic
     * @return bool
     */
    private function _startsWith(string $path, string $magic): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = @fread($handle, strlen($magic));
        @fclose($handle);

        return $header === $magic;
    }

    /**
     * Gzips a file to a new path.
     *
     * `-c` is what keeps this non-destructive: plain `gzip <file>` would replace the
     * dump with a `.gz` alongside it, and the Control Panel path requires the backup
     * to stay at exactly the path Craft computed for it.
     *
     * @param string $path
     * @param string $destination
     * @param int $timeout
     * @throws Exception if gzip cannot start, fails, times out, or produces nothing
     */
    private function _compressFile(string $path, string $destination, int $timeout): void
    {
        $this->_run(['gzip', '-c', $path], null, $timeout, $destination);

        if (!is_file($destination) || filesize($destination) === 0) {
            throw new Exception('gzip produced no output while compressing the backup.');
        }
    }

    /**
     * Runs openssl with the key on stdin.
     *
     * @param string[] $args
     * @param string $key
     * @param int $timeout
     * @throws Exception if the process cannot start, fails, or exceeds the timeout
     */
    private function _runOpenssl(array $args, string $key, int $timeout): void
    {
        // The key goes over the pipe and nowhere else: not into argv, where `ps` would
        // show it, and not into the environment, which is readable from /proc.
        $this->_run($args, $key, $timeout);
    }

    /**
     * Runs a command to completion, optionally writing to its stdin or capturing its
     * stdout to a file.
     *
     * proc_open is given an argument array rather than a string, so no shell is
     * involved and neither the paths nor anything written to stdin can be interpreted
     * as syntax.
     *
     * @param string[] $args
     * @param string|null $stdin Written to the child's standard input, if given
     * @param int $timeout
     * @param string|null $outputPath Where to send stdout; discarded if null
     * @throws Exception if the process cannot start, fails, or exceeds the timeout
     */
    private function _run(array $args, ?string $stdin, int $timeout, ?string $outputPath = null): void
    {
        $name = $args[0];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $outputPath !== null ? ['file', $outputPath, 'w'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($args, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception("Could not start $name. Is it installed and on the PATH?");
        }

        if ($stdin !== null) {
            @fwrite($pipes[0], $stdin);
        }

        @fclose($pipes[0]);

        $result = $this->_consume($process, $pipes, $timeout);

        if ($result === null) {
            throw new Exception("$name did not finish within {$timeout} seconds and was terminated.");
        }

        proc_close($process);

        if ($result['exitCode'] !== 0) {
            $detail = trim($result['stderr']) !== '' ? ' ' . trim($result['stderr']) : '';
            throw new Exception("$name exited with code {$result['exitCode']}.$detail");
        }
    }

    /**
     * Reads a process to completion within a deadline, killing it if it overruns.
     *
     * Returns the exit code rather than leaving the caller to fetch it, because
     * proc_get_status() reports the real code only on the first call that observes the
     * process as finished; every call after that returns -1. That first call happens in
     * the loop below, so this is the only place the code can still be read.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     * @param int $timeout
     * @return array{exitCode: int, stderr: string}|null Null if the process was killed
     */
    private function _consume($process, array $pipes, int $timeout): ?array
    {
        // Absent when stdout was pointed straight at a file, in which case there is
        // no stdout pipe to drain.
        $stdout = $pipes[1] ?? null;

        if ($stdout !== null) {
            stream_set_blocking($stdout, false);
        }

        stream_set_blocking($pipes[2], false);

        $stderr = '';
        $deadline = time() + $timeout;

        while (true) {
            $stderr .= (string)stream_get_contents($pipes[2]);

            // Drained and discarded, not because the output is wanted, but because a
            // child that fills its stdout buffer blocks forever waiting for a reader.
            if ($stdout !== null) {
                stream_get_contents($stdout);
            }

            $status = proc_get_status($process);

            if ($status['running'] === false) {
                $stderr .= (string)stream_get_contents($pipes[2]);

                if ($stdout !== null) {
                    @fclose($stdout);
                }

                @fclose($pipes[2]);

                return [
                    // Read straight off this status array: it is the first one to see
                    // the process finished, so it is the only one carrying the code.
                    'exitCode' => (int)$status['exitcode'],
                    'stderr' => $stderr,
                ];
            }

            if (time() >= $deadline) {
                proc_terminate($process, 9);

                if ($stdout !== null) {
                    @fclose($stdout);
                }

                @fclose($pipes[2]);
                proc_close($process);

                return null;
            }

            usleep(50000);
        }
    }

    /**
     * Runs a command and returns its output, used for cheap probes like `openssl version`.
     *
     * Falls back to stderr when stdout is empty, because the two binaries this plugin
     * shells out to disagree about where a version banner belongs: GNU gzip prints
     * `--version` to stdout, Apple's prints it to stderr. Reading only stdout makes
     * gzip look absent on macOS, which in turn drops the decompression stage from the
     * restore pipe and feeds gzip bytes straight to the database client.
     *
     * @param string[] $args
     * @param int $timeout
     * @return string
     * @throws Exception if the process cannot start or exceeds the timeout
     */
    private function _capture(array $args, int $timeout): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($args, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception('Could not start ' . ($args[0] ?? 'the command') . '.');
        }

        @fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = time() + $timeout;

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if ($status['running'] === false) {
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);

                // Read straight off this status array. Only the first
                // proc_get_status() after the process ends carries the real exit
                // code; every call after it reports -1.
                $exitCode = $status['exitcode'];
                break;
            }

            if (time() >= $deadline) {
                proc_terminate($process, 9);
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                proc_close($process);

                throw new Exception('The command did not finish in time.');
            }

            usleep(20000);
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);
        proc_close($process);

        if ($exitCode !== 0) {
            throw new Exception(sprintf('%s exited with code %d.', $args[0], $exitCode));
        }

        return trim($stdout) !== '' ? $stdout : $stderr;
    }
}
