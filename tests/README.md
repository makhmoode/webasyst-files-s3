# Files S3 plugin tests

Standalone PHPUnit suite for `files/plugins/s3`. Do not rely on `tests/wa-apps/files/plugins/s3/`.

## Requirements

- Global PHPUnit 9.x (`phpunit` on PATH)
- Framework `tests/init.php` (Webasyst test environment)
- **Unit tests** (`s3-unit`): work with framework init only; Files app / MySQL optional
- **Integration tests** (`@group long_tests`): require MySQL + `wa('files')` (same as main `tests/`)

## Run

From the **framework repository root**:

```bash
# Unit tests only (default; excludes @group long_tests)
phpunit -c wa-apps/files/plugins/s3/tests/phpunit.xml.dist --testsuite s3-unit

# Full default suite (unit + integration without long_tests)
phpunit -c wa-apps/files/plugins/s3/tests/phpunit.xml.dist

# Integration / DB-heavy tests
phpunit -c wa-apps/files/plugins/s3/tests/phpunit.xml.dist --group long_tests
```

PHPUnit 6+ compatibility (legacy `PHPUnit_Framework_TestCase` name, `: void` hooks) is handled only inside this plugin: `tests/bootstrap.php` and `tests/php/FilesS3TestCase.php`. Framework files under `tests/` are not modified for this suite.
