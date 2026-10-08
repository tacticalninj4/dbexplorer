---
name: package-compatibility
description: "Use this skill when reviewing Laravel package compatibility across composer constraints, PHP versions, Laravel versions, Testbench versions, dependency stability lanes, Windows CI, or matrix-sensitive code and workflow changes."
license: MIT
metadata:
  author: laravel
---

# Package Compatibility

## Primary Goal

Keep package code, dependencies, and workflows compatible with the supported matrix: Laravel 9–13, PHP 8.0+, Orchestra Testbench 7–11 and Pest 1–5.

## Support Matrix

| Laravel | PHP | Testbench | Pest | Lane |
|---|---|---|---|---|
| 9 | 8.0, 8.2 | 7 | 1 | compat |
| 10 | 8.1, 8.3 | 8 | 2 | compat |
| 11 | 8.2, 8.4 | 9 | 3 | compat |
| 12 | 8.3, 8.5 | 10.6+ | 4 | compat |
| 13 | 8.4 | 11 | 5 | compat (prefer-lowest) |
| 13 | 8.5 | 11 | 5 | tooling |

- **Compat lanes** remove the tooling dev dependencies (`larastan/larastan`, `laravel/pint`, `laravel/pao`, `pestphp/pest-plugin-type-coverage`, which need PHP 8.2+/8.3+), set `composer config audit.block-insecure false`, pin the lane's Laravel, Testbench and Pest majors, and run only `vendor/bin/pest`.
- **The tooling lane** (L13/PHP 8.5) also runs `composer run prepare`, PHPStan, Pint and type coverage. Arch tests run only on Pest 3+.
- `prefer-lowest` runs on the lowest PHP of each Laravel version. One Windows lane (L13/PHP 8.4) runs without Postgres.
- Source in `src/`, `config/`, `database/` and `routes/` must parse on PHP 8.0: no enums, `readonly`, `never`, first-class callables, `new` in initializers, pure intersection types, `array_is_list()` or constants in traits. PHPStan runs with `phpVersion: 80000`.
- Framework APIs must exist in Laravel 9. Known traps: `publishesMigrations()` (L11+), the `Process` facade (L10+), `Context`/`Number`/`defer()`/`once()` (L11+), the model `casts()` method (L11+).

## Workflow

1. Read `composer.json` first to determine PHP, Laravel, and Testbench constraints.
2. Check changed code against Laravel 9 APIs and PHP 8.0 syntax before adopting newer framework or language features.
3. Review `.github/workflows/tests.yml` for dependency stability lanes, prefer-lowest coverage, prefer-stable coverage, and Windows concerns.
4. When changing dependencies, confirm constraints still allow the intended Laravel and Testbench versions.
5. Validate with the smallest local command available. For an older lane, copy the repo without `vendor/` to a throwaway directory and run that lane's CI steps in `php:<version>-cli` with Docker (or Herd's PHP binaries); never install lane dependencies into the repo's own `vendor/` or `composer.lock`. Rely on CI for the full OS and dependency matrix.

## References

- `composer.json`
- `.github/workflows/tests.yml`
- `phpstan.neon.dist`
- `tests/`
- `workbench/`

## Examples

- Review a new Laravel API call by checking whether it exists in Laravel 9 through 13 before merging it into shared package code.
- Review a dependency bump by checking Composer constraints, Testbench constraints, prefer-lowest behavior, and Windows path assumptions.

## Anti-Patterns

- Assuming the latest local dependency version represents the whole support matrix.
- Adding PHP syntax or Laravel APIs that exceed `composer.json` constraints.
- Ignoring Windows path separators, executable assumptions, or shell-only syntax in tests and workflows.
- Removing dependency stability lanes because they are slower than a single happy path.
- Letting a compat lane's resolver drift to a newer Pest major instead of pinning it.
