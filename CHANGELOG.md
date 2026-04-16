# Changelog

All notable changes to `plugin_manager` should be documented in this file.

## [Unreleased]

- Ongoing development builds use `plugin_manager::PLUGIN_VERSION` with a `+dev` suffix until the next release is cut.

## [1.0.0] - 2026-04-11

- Formalized the plugin's self-metadata through `plugin_manager::PLUGIN_VERSION` and `plugin_manager::info()`.
- Aligned self-versioning with a cleaner release workflow while keeping managed-plugin version detection unchanged.

