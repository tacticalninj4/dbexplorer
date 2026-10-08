---
name: package-testing
description: "Use this skill when writing, editing, fixing, or reviewing package tests with Pest (1–5) and Orchestra Testbench (7–11), including TDD, feature tests, unit tests, type coverage, arch tests, workbench behavior, commands, routes, config, migrations, and publishable resources."
license: MIT
metadata:
  author: laravel
---

# Package Testing

## Primary Goal

Prove package behavior with Pest, Orchestra Testbench, and the local `tests/TestCase.php` setup before or alongside implementation. The same suite runs on Pest 1 (Laravel 9) through Pest 5 (Laravel 13), so tests must use syntax every lane supports.

## Pest 1 Syntax Limits

- Use only `it()`, `test()`, `expect()`, `beforeEach()`, `afterEach()`, `uses()` and datasets (`->with()`), plus `->throws()` and `->group()`.
- Don't use `describe()`, `arch()`, `->todo()`, `covers()` or other Pest 2+ APIs in shared tests. `tests/ArchTest.php` returns early unless `arch()` and the Pest 3 presets exist.
- Test code that runs in compat lanes must also parse on PHP 8.0 and use only Laravel 9 / Testbench 7 APIs.
- Name security tests after the requirement they prove (e.g. `it('S7 never sends connection config ...')`).

## Workflow

1. Start with TDD: write the smallest failing package test for the requested behavior, then implement the smallest change that makes it pass.
2. Cover happy-path, unhappy-path, and edge-case behavior when the feature has meaningful failure modes.
3. Prefer focused feature tests for package integration behavior and arch tests for broad constraints.
4. Use `composer test:unit -- --filter ...` while iterating, `composer test:types` when type-sensitive tests or code changed, and `composer test` before finishing. `composer test` uses the local tooling stack; check older lanes as described in `package-compatibility`.
5. Keep real package tests in the suite and remove only throwaway tests that were explicitly created for local scaffolding experiments.

## References

- `tests/TestCase.php`
- `tests/Pest.php`
- `tests/Feature/`
- `tests/Unit/`
- `tests/ArchTest.php`
- `composer.json` scripts

## Examples

- Test config merge and config override by asserting default package config, then overriding the host config value in the Testbench app.
- Test publishable assets, migrations, views, lang files, or config by invoking vendor publish behavior and asserting the target path exists.
- Test routes with Testbench HTTP requests, commands with Artisan assertions, migrations with a SQLite test database, and workbench behavior after `composer build` when needed.
- Set config that must exist before the package boots (`enabled`, `path`, `domain`, `middleware`) with `$this->bootWith([...])` from `tests/TestCase.php`.
- Tests that need a real PostgreSQL are tagged `->group('pgsql')` and skip when the `DB_PGSQL_*` env vars are missing.

## Anti-Patterns

- Deleting real package tests because they are inconvenient.
- Relying only on smoke tests when behavior needs assertions.
- Testing implementation details when observable package behavior is available.
- Keeping throwaway scaffolding experiment tests in the package test suite.
