# Changelog

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
