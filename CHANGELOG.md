# Changelog

All notable changes to `plugin_manager` should be documented in this file.

## [Unreleased]

- Ongoing development builds use `plugin_manager::PLUGIN_VERSION` with a `+dev` suffix until the next release is cut.
- Added more shipped example maintenance review webhooks and commented config examples for additional popular plugins.
- Moved the recent maintenance activity panel below the plugin list and added a persisted hide toggle for admins who do not want to see it.
- Tightened two small filesystem-safety checks: config load/save now targets only resolved live plugin directories, and update ZIP archives are sanity-checked for unsafe paths before extraction.

## [1.6.0] - 2026-04-19

- Added bulk backup deletion for selected plugins with strict path validation, backup-name checks, symlink refusal, and clear result summaries.
- Added post-update advisory inspection for migration and upgrade signals found in plugin files and `composer.json`.
- Added allowlisted maintenance hooks, manual `Run maintenance`, reusable preflight validation, per-plugin maintenance badges, and a bounded maintenance audit trail.
- Shipped example maintenance review webhooks and commented config examples for multiple common plugins to help administrators bootstrap safe post-update checks.

## [1.0.0] - 2026-04-11

- Formalized the plugin's self-metadata through `plugin_manager::PLUGIN_VERSION` and `plugin_manager::info()`.
- Aligned self-versioning with a cleaner release workflow while keeping managed-plugin version detection unchanged.
