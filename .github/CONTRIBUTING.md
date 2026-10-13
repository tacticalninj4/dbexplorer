# Contribution Guide

Thank you for considering contributing to DbExplorer! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Licensing of contributions

DbExplorer is dual-licensed under the AGPL-3.0-only and a commercial license ([LICENSING.md](../LICENSING.md)). By submitting a contribution (a pull request, patch or other code), you agree that:

1. you wrote it, or otherwise have the right to submit it under these terms;
2. it is licensed under the AGPL-3.0-only, like the rest of the project; and
3. you grant the copyright holder of DbExplorer a perpetual, worldwide, non-exclusive, royalty-free, irrevocable license to use, modify, sublicense and distribute your contribution under any other license terms, including commercial ones.

You keep the copyright in your contribution. If you can't agree to this, please open an issue describing the change instead of sending code.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```
