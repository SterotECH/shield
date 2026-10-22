# Changelog

## [1.0.0](https://github.com/SterotECH/shield/compare/v0.2.1...v1.0.0) (2026-10-22)


### ⚠ BREAKING CHANGES

* **logs:** runtime and Laravel namespaces now use Stero\Shield. Sanitiser moves from CLI to Runtime. shield:logs replaces follow, limit, would-block and theme options; JSON output is an array and severity filtering is exact.

### Features

* **logs:** add paginated browsing and align namespaces ([0985f54](https://github.com/SterotECH/shield/commit/0985f5499d3ed0382a2942abb049f3c89228cb54))


### Bug Fixes

* **ci:** configure Pest without assertion-dependent XML generation ([a55d528](https://github.com/SterotECH/shield/commit/a55d528c37c885184f4c30ce9cca72f8dfbd5141))


### Miscellaneous Chores

* **ci:** add package quality gates and Husky hooks ([345c1aa](https://github.com/SterotECH/shield/commit/345c1aa0f971e7ad6576fd4830063e7a4731b19c))
* **deps-dev:** bump pestphp/pest from 3.8.7 to 5.3.3 in /packages/cli ([dcd4adc](https://github.com/SterotECH/shield/commit/dcd4adc9b340e51c9088b6c97afec08d4064190b))
* **deps-dev:** bump pestphp/pest from 3.8.7 to 5.3.3 in /packages/cli ([34707a6](https://github.com/SterotECH/shield/commit/34707a66b2c919f9a99ddb59d75fd2d6ac7bd9b5))
* **deps:** bump actions/checkout from 4.4.0 to 7.0.1 ([3878d01](https://github.com/SterotECH/shield/commit/3878d01349408b783c7e2ffd4f9bb0cc7082d142))
* **deps:** bump actions/checkout from 4.4.0 to 7.0.1 ([c1599d6](https://github.com/SterotECH/shield/commit/c1599d6ae80ea73c854ed32e62b0aeee628ec060))
* **deps:** bump actions/setup-node from 4.4.0 to 7.1.0 ([b55ca35](https://github.com/SterotECH/shield/commit/b55ca35487d13c35d12d894be34ae16c3c82a9a0))
* **deps:** bump actions/setup-node from 4.4.0 to 7.1.0 ([5786c20](https://github.com/SterotECH/shield/commit/5786c207154fe977d4b933e0f599cac195283080))
* **deps:** bump googleapis/release-please-action from 4.4.1 to 5.0.0 ([cfad00c](https://github.com/SterotECH/shield/commit/cfad00c3e96d9a0d4eac21ad394cef8cbd9add70))
* **deps:** bump googleapis/release-please-action from 4.4.1 to 5.0.0 ([a50956e](https://github.com/SterotECH/shield/commit/a50956e421b5a409aa8a8f6b94136a9c91ba339e))
* **deps:** bump symfony/console from 7.4.21 to 8.1.9 in /packages/cli ([0a48dec](https://github.com/SterotECH/shield/commit/0a48dec2b858365ba76de0d5c0747c173b9d12ad))
* **deps:** bump symfony/console from 7.4.21 to 8.1.9 in /packages/cli ([79ec375](https://github.com/SterotECH/shield/commit/79ec37570c6109ab4d1ac2c2943e6bb15285ada3))
* **release:** automate release PRs and package publishing ([05f9724](https://github.com/SterotECH/shield/commit/05f9724e8d0b8fe794af2975e920d17d90356b78))

## [0.2.1] - 2026-10-22

### Added

- Include the existing event viewer in the `stero/shield` Composer package,
  available as `vendor/bin/shield` without a separate repository clone.
- Add Laravel's `php artisan shield:logs` command, using the configured
  `shield.events_path` and showing the latest 50 matching events by default.
- Support live logs with `shield:logs --follow`, a configurable `--limit`,
  snapshot filters, JSON output and terminal themes. Snapshot filters cannot
  be combined with `--follow`.
- Add a root `composer check` command covering all five packages and the
  installation smoke test.

### Changed

- Register the shared CLI namespace and executable in the root package.
- Include Symfony Console and Termwind, the existing viewer dependencies,
  in the main Composer package. Symfony Console supports 7.4 and 8.x.
- Update the CLI bootstrap to use Composer's supplied autoloader when
  installed in a host application.
- Update documentation and distributed agent guidance for the included viewer.

### Verification

- Add Laravel command tests for configured paths, filters, rendering,
  errors, live following and clean interruption.
- Extend the installation smoke test to verify CLI archive inclusion and
  exact JSON output from the installed `vendor/bin/shield` executable.

[0.2.1]: https://github.com/sterotech/shield/compare/v0.2.0...v0.2.1
