# Development

```bash
composer install
composer test      # Pest (pgvector tests are skipped without PostgreSQL)
composer analyse   # Larastan, level 8
composer lint      # Pint --test
composer format    # Pint, fixes in place
```

## pgvector integration tests

`tests/Integration` runs only when `DB_CONNECTION=pgsql`. To run it locally,
start a database with pgvector 0.8+ and point the suite at it:

```bash
docker run --rm -d -p 5432:5432 -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=testing pgvector/pgvector:pg16
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_USERNAME=postgres DB_PASSWORD=postgres vendor/bin/pest
```

## Tested with

PHP 8.4.25, Composer 2.10.3, Laravel 12.69.3 (Testbench 10.12.0) and Laravel
11.57.0 (Testbench 9.18.0), Pest 4.7.8, Larastan 3.13.0 (level 8), Pint
1.32.1, PostgreSQL 16.11 with pgvector 0.8.1 (integration tests).

## CI

The [CI workflow](../.github/workflows/ci.yml) runs:

- Pint and Larastan on PHP 8.4;
- Pest on PHP 8.3 and 8.4 against Laravel 11 and 12 (PHP 8.3 is covered by
  this matrix only);
- the whole suite, including the pgvector tests, against the
  `pgvector/pgvector:pg16` service image.

Laravel 11 is past its security-support window, so Composer's advisory
blocking is disabled for that matrix entry only, to check compatibility. New
projects should use Laravel 12.
