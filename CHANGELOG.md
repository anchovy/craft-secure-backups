# Release Notes for Secure Backups

## 1.0.0 - 2026-08-16

### Added
- Initial release.
- Encrypts every database backup Craft creates: the Control Panel utility, `craft db/backup`,
  and the automatic backups taken before migrations.
- Decrypts automatically on `craft db/restore`, as a pipe, so the plaintext is never written
  to disk.
- Gzips each backup before encrypting it, typically making it several times smaller.
  Compressing after encryption saves nothing, so the order is what matters. Controlled by the
  `compress` setting and safe to change at any time.
- Prompts before restoring an unencrypted backup, and refuses when the session is not
  interactive.
- Deletes the plaintext dump and fails loudly when a backup cannot be encrypted.
- Supports Craft 4.3.5 and later as well as Craft 5, from a single release.
