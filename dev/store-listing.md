# Plugin Store listing copy

Kept here so the listing and the README stay in step. `dev/` is `export-ignore`d, so this does
not ship to sites.

## Short description

> Compresses and encrypts every database backup Craft produces, making it several times smaller,
> and reverses both transparently when one is restored.

## Features

Repeatable name + description pairs on the listing.

**Every backup, automatically**
Hooks the backup pipeline Craft already has rather than adding a parallel one, so the Control
Panel utility, `craft db/backup` and the automatic backups taken before migrations are all
covered. Nothing changes about how you or your client take a backup.

**Encrypted at rest**
Each dump is encrypted in place with AES-256-CBC and PBKDF2 key derivation as soon as Craft
writes it. If encryption cannot be completed, the plaintext is deleted and the backup fails
loudly rather than leaving a readable copy of the database on disk.

**Decrypts on restore, inside a pipe**
`craft db/restore` decrypts automatically, feeding the database client directly, so the plaintext
SQL is never written to disk at all.

**Several times smaller**
Backups are gzipped before they are encrypted, which is the only order that saves anything:
encrypted data does not compress. A 101 KB dump from a real Craft 5 site becomes 17 KB.

**Recoverable without this plugin**
The format is standard gzip inside standard `openssl enc` output. One command restores any backup
on any machine, with no Craft installed and no copy of this plugin. Your backups are never
hostage to software you might not have later.

**The key never reaches the process list**
Anything on a command line is visible to any user who can run `ps`, so the key is passed on
standard input when creating a backup and through a transient environment variable when restoring
one. Only the *name* of the environment variable holding it is stored in project config, so the
key itself never enters version control and can differ per environment.

**Refuses restores nobody vouched for**
Prompts before restoring a backup that is not encrypted, and refuses outright when there is
nobody to ask, so cron jobs, CI and deploy scripts never load a dump of unknown provenance on
your behalf.

## Long description

Every Craft site takes database backups, and by default every one of them is plain SQL: a
complete, readable copy of your client's database sitting in `storage/backups`, inside the zip
someone downloads from the Control Panel, and in whatever offsite sync happens to pick that
folder up.

Secure Backups compresses and encrypts every backup Craft produces, and reverses both
automatically on restore. Nothing changes about how you or your client take a backup.

### When a client's IT department asks whether database backups are encrypted

That question comes up in every security review, usually phrased as "at rest and in transit", and
without this plugin the honest answer for a stock Craft site is no.

**At rest.** Craft writes its dump, and the plugin immediately encrypts it in place with
`openssl` using AES-256-CBC and PBKDF2 key derivation, leaving nothing but ciphertext on disk. If
encryption cannot be completed, the plaintext dump is deleted and the backup fails loudly, so a
failure never quietly leaves an unencrypted copy of the database behind.

**In transit.** Because the file is encrypted before it leaves the server, it stays encrypted
wherever it goes: downloaded through the Control Panel, synced to S3 or Backblaze, copied onto a
laptop, attached to a ticket. The payload is never plaintext in motion, independently of whatever
transport carries it. Transport-level security such as HTTPS and SSH remains your host's
responsibility; what this guarantees is that the thing being transported is not a readable
database dump.

**On restore**, decryption happens inside a pipe feeding the database client directly, so the
plaintext SQL is never written to disk at all.

### Every backup, not a parallel system

Rather than adding its own backup mechanism beside Craft's, this hooks the one Craft already has.
Everything that produces a database dump goes through the same place internally, so all of it is
covered:

- Control Panel → Utilities → Database Backup, including *Download backup*
- `php craft db/backup`
- The automatic backups Craft takes before running migrations
- `php craft db/restore`, which decrypts automatically

### Smaller, as well as safer

Backups are gzipped before they are encrypted, which is the only order that saves anything:
encrypted data does not compress, so compressing afterwards achieves almost nothing. On a real
Craft 5 site, a 101 KB dump becomes 17 KB. Expect a better ratio on a content-heavy site.

### Your backups are never hostage to this plugin

The on-disk format is standard gzip inside standard `openssl enc` output, and nothing else. Any
backup this plugin creates can be restored on any machine with a single command, with no Craft
installed and no copy of this plugin:

```sh
openssl enc -d -aes-256-cbc -pbkdf2 -in backup.sql | gzip -dcf | mysql -u user -p dbname
```

That is deliberate. For a backup tool, the worst possible failure is an archive that can only be
opened by software you no longer have.

### The encryption key never appears in the process list

A passphrase passed on a command line is visible to any user who can run `ps`. On backup, the key
is written to the encrypting process's standard input. On restore, it is passed through a
randomly named environment variable that is cleared immediately afterwards. It is also never
stored in project config: only the *name* of the environment variable holding it is, so the key
itself stays out of version control and can differ per environment.

### Requirements

Craft CMS 4.3.5 or later, or Craft CMS 5. PHP 8.0.2 or later. The `openssl` and `gzip` binaries
available on the server. MySQL and MariaDB are fully supported; PostgreSQL is supported in the
default `plain` backup format.

Backups taken outside Craft, such as a `mysqldump` cron job or a hosting control panel's own
backups, are not affected by this plugin and remain unencrypted.
