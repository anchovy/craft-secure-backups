# Secure Backups for Craft CMS

Compresses and encrypts every database backup Craft produces, and reverses both transparently
when one is restored.

Unlike backup plugins that add their own parallel backup system, this hooks Craft's existing
pipeline. Every route to a database dump *that goes through Craft* goes through
`craft\db\Connection::backupTo()`, so covering that one chokepoint covers all of them:

| Path | Covered |
|---|---|
| Control Panel, Utilities, Database Backup (including "Download backup") | Yes |
| `php craft db/backup` | Yes |
| Automatic backups taken before migrations (`backupOnUpdate`) | Yes |
| `php craft db/restore` | Decrypts automatically |
| Anything that dumps the database without Craft (`mysqldump`, `ddev export-db`, hosting panel backups, provider snapshots, a GUI client's export) | **No** |

That last row matters, so it is spelled out under [Limitations](#limitations).

## Requirements

- Craft CMS 4.3.5 or later, or Craft CMS 5
- PHP 8.0.2 or later
- The `openssl` and `gzip` binaries available on the server

Both Craft 4 and Craft 5 are supported by the same release. The Craft 4 floor is 4.3.5 because
that is the version that introduced `Craft::$app->onInit()`.

## Installation

```sh
composer require anchovyx/craft-secure-backups
php craft plugin/install secure-backups
```

Then set an encryption key on **every environment that takes backups**:

```sh
# .env
SECURE_BACKUPS_KEY="a long random passphrase"
```

Generate one with `openssl rand -base64 48`.

The key itself is never stored in project config. Only the *name* of the environment variable
is, so each environment can hold a different key and nothing secret reaches version control.

> **Keep the key somewhere other than the server the backups came from.** A backup you cannot
> decrypt is not a backup. Put it in a password manager or secrets store before you need it.

## Compression

Backups are gzipped before they are encrypted, which is the only order that saves anything.
Ciphertext is indistinguishable from random and will not compress, so compressing afterwards
(which is what Craft's own zip on the Control Panel path does) achieves almost nothing.

The same backup of a freshly installed Craft 5 site, taken three ways:

| | Size | vs. raw |
|---|---|---|
| Raw SQL | 101,197 bytes | |
| Encrypted only | 101,248 bytes | 1.0x |
| **Gzipped, then encrypted** | **17,584 bytes** | **5.8x smaller** |

Note the middle row: encrypting on its own adds 51 bytes and saves nothing, which is precisely
why the order matters. Expect a better ratio than this on a real site, since a bare install is
mostly schema, and it is the repetitive content rows that compress best.

Turn it off with the `compress` setting if you would rather not. It is safe to change in either
direction at any time: the restore pipe always passes the decrypted stream through `gzip -dcf`,
and with `-c` the `-f` flag makes gzip copy input it does not recognise straight through. One
command therefore restores a compressed backup, an uncompressed one, and any backup taken before
this plugin compressed anything.

## How it works

The two halves use different mechanisms, because only one works on each side.

**Backups** are encrypted in place by an `EVENT_AFTER_CREATE_BACKUP` handler, keeping the
original filename. That last part is not cosmetic: Craft's `UtilitiesController` captures the
backup path *before* the dump runs and then asserts that the file still exists at exactly that
path, so renaming it to `.enc` breaks the Control Panel utility. The file therefore keeps its
`.sql` name and simply contains ciphertext. It is identified by its `Salted__` header rather
than by extension.

**Restores** cannot use `EVENT_BEFORE_RESTORE_BACKUP` to substitute a decrypted file, because
`Connection::restore()` never reads `$event->file` back; it uses its own local variable. Instead
the handler sets the `restoreCommand` config setting while that event is running, which Craft
reads a few lines later. Decryption is spliced in as a pipe:

```
openssl enc -d -aes-256-cbc -pbkdf2 -pass env:… -in "backup.sql" | gzip -dcf | mysql …
```

so the decrypted SQL exists only in a pipe and is never written to disk.

## The key is never exposed on the command line

A passphrase in `argv` is visible to any user who can run `ps`. On the backup path the key is
written to the child process's standard input instead. On the restore path Craft owns the
process, so a randomly named environment variable is used and unset immediately afterwards.

Note that a value present only in `$_ENV` or `$_SERVER` is **not** inherited by child processes.
Whether your `.env` reaches the real environment depends on how your project's `bootstrap.php`
loads it (`createUnsafeMutable` does, `createImmutable` does not), so the plugin reads the key
through `craft\helpers\App::env()` and hands it to the child explicitly rather than assuming.

## Encrypted backups still end in `.sql`

This trips people up, so it is worth stating plainly: **an encrypted backup keeps the `.sql`
name Craft gave it.** Open one in a text editor and you will see binary, beginning with the
eight characters `Salted__`. That is not a corrupt dump, it is the file working as intended.

The extension cannot be changed. Craft's `UtilitiesController` computes the backup path *before*
the dump runs, asserts a file still exists at exactly that path afterwards, and then zips that
same path for download. Renaming the file breaks the Control Panel's Database Backup utility
outright, and the name inside the downloaded zip is taken from the on-disk name anyway. Renaming
on only the console path would work, but then the same site would produce differently named
backups depending on how they were taken, which is worse than the inconsistency it fixes.

Nothing in the plugin depends on the extension. Encrypted backups are recognised by that
`Salted__` header, so you are free to rename a copy to `.sql.enc` yourself. `craft db/restore`
will still recognise and decrypt it.

## Recovering a backup without this plugin

The on-disk format is made of standard parts: gzip inside plain `openssl enc` output. Any backup
this plugin created can be recovered on any machine, with no Craft and no copy of this plugin.

Decrypt to a plain SQL file next to the original, leaving the encrypted one untouched:

```sh
BACKUP="/path/to/storage/backups/my-site--2026-08-16-101500--v5.10.13.sql"

openssl enc -d -aes-256-cbc -pbkdf2 -in "$BACKUP" | gzip -dcf > "${BACKUP%.sql}.decrypted.sql"
```

That writes `my-site--2026-08-16-101500--v5.10.13.decrypted.sql` into the same directory. You
will be prompted for the passphrase, which is the value of your `SECURE_BACKUPS_KEY`.

Or, in one step, without a decrypted copy ever touching the disk:

```sh
openssl enc -d -aes-256-cbc -pbkdf2 -in "$BACKUP" | gzip -dcf | mysql -u user -p dbname
```

Both commands are correct whether or not the backup was compressed, because `gzip -dcf` passes
data it does not recognise straight through.

### A backup downloaded from the Control Panel

*Download backup* gives you a `.zip`. Unzip it first, then decrypt the `.sql` inside exactly as
above:

```sh
unzip my-site--2026-08-16-101500--v5.10.13.zip
```

This is all deliberate. For a backup tool, the worst failure is an archive that can only be
opened by software that is no longer installed or maintained.

## Settings

| Setting | Default | Notes |
|---|---|---|
| `enabled` | `true` | Encrypt new backups. Restores still decrypt when off, so it is safe to toggle. |
| `compress` | `true` | Gzip each backup before encrypting it. Safe to toggle; see [Compression](#compression). |
| `keyEnvVar` | `SECURE_BACKUPS_KEY` | Name of the environment variable holding the key. |
| `unencryptedRestore` | `prompt` | `prompt`, `deny`, or `allow`. See below. |
| `failIfUnableToEncrypt` | `true` | Delete the plaintext dump and fail, rather than leave it unencrypted. |
| `cipher` | `aes-256-cbc` | Any `openssl enc` cipher. |
| `timeout` | `900` | Seconds to wait for `openssl` before killing it. |

Settings can also be set in `config/secure-backups.php`, which takes precedence over the
Control Panel and is the better home for them on a multi-environment site.

### Restoring an unencrypted backup

Backups made before installing the plugin, or handed to you by someone else, are not encrypted.
By default the plugin asks before restoring one. When there is nobody to ask (cron, CI, a deploy
script) it refuses instead, so an unattended run never loads a dump of unknown provenance on your
behalf. Set `unencryptedRestore` to `allow` to skip the question entirely.

### When a backup cannot be encrypted

If the key is missing or `openssl` fails, the default is to delete the plaintext dump Craft has
already written and fail loudly. Leaving it would be the worst of both worlds: the operator is
told the backup failed while an unencrypted copy of the whole database sits on disk. Set
`failIfUnableToEncrypt` to `false` to keep the dump and log a warning instead.

## Limitations

**Only backups taken through Craft are encrypted.** This is a Craft plugin, so it can only act
when Craft is running. A dump produced by a process that never loads Craft is written in
plaintext, and nothing here can intervene. That includes, at least:

- `mysqldump` or `pg_dump` run directly, by hand or from a cron job
- `ddev export-db`, and equivalents in other local development tooling
- Hosting control panel backups (cPanel, Forge, Ploi, and similar)
- Managed database snapshots (RDS, Cloud SQL, DigitalOcean, and similar)
- Exports from a GUI client such as TablePlus or Sequel Ace
- Replication, and filesystem or VM snapshots of the database's data directory

This is inherent rather than an oversight: no Craft plugin can intercept a shell command that
does not involve Craft. It is worth checking what actually takes your backups, because a nightly
`mysqldump` on the server is a common arrangement, and installing this plugin does not change
what that produces.

If you want those covered, either route the scheduled job through `php craft db/backup` so it
goes down the encrypted path, or encrypt at that layer separately.

Related, and worth being clear about: this encrypts *backup artifacts*, not the database. Anyone
holding valid database credentials can still read the data directly. What it protects against is
a plaintext copy of the whole database sitting in `storage/backups`, ending up in a downloaded
zip, or being swept into an offsite sync.

**PostgreSQL `custom`, `directory` and `tar` formats are not supported.** Craft restores those
with `pg_restore`, which takes the backup as a file argument rather than on standard input, so
there is nothing to pipe decryption into. Craft's own source notes it "can't use STDIN, as it may
be a directory". The `directory` format is not even a single file. Use the default `plain` format
(`backupCommandFormat`) to encrypt PostgreSQL backups. MySQL and MariaDB are unaffected.

**A failing pipe stage can be masked.** The restore runs as a shell pipeline, and a POSIX shell
reports only the last stage's exit code. In practice a failed decryption still fails the restore,
because the database client is handed ciphertext and rejects it. This is inherent to splicing
into Craft's restore command and is not introduced by compression.

**Craft's own zip still saves nothing.** Craft zips backups on the Control Panel path, and that
zip is applied after encryption, where it cannot help. The plugin compresses before encrypting
instead, so the file is already small by the time Craft's zip wraps it. The zip is harmless, just
redundant.

**Restoring onto an empty database will not auto-decrypt.** Craft loads its plugin registry
from the database, so when you point it at a database that has never had this plugin installed
(a fresh server, a brand new database), the plugin is not loaded and cannot hook the restore.
`craft db/restore` will hand the ciphertext straight to `mysql` and fail.

This matters most in exactly the situation backups exist for, so plan for it. Decrypt first,
then restore the result:

```sh
openssl enc -d -aes-256-cbc -pbkdf2 -in backup.sql | gzip -dcf > plain.sql
php craft db/restore plain.sql
```

Restoring over a database that already has the plugin installed works normally.

## License

Commercial, licensed per Craft installation through the [Craft Plugin Store][store], including
one year of updates. Development, staging and testing installations that support a licensed
production site are covered at no extra cost.

See the [Plugin Store listing][store] for current pricing, and [LICENSE.md](LICENSE.md) for the
full terms.

Note that nothing in the licence restricts what you can do with the backups themselves. They are
plain `gzip` inside plain `openssl enc` precisely so you can always recover them yourself, with
no Craft and no copy of this plugin. See [Recovering a backup without this
plugin](#recovering-a-backup-without-this-plugin).

[store]: https://plugins.craftcms.com/secure-backups
