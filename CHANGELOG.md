# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
once a first stable release is published.

## [Unreleased]

### Added

- Project specification and README.
- Repository community files (CONTRIBUTING, CODE_OF_CONDUCT, SECURITY,
  LICENSE).
- Laravel 13 + React/Inertia application scaffold, with Sail for local
  development.
- Workspaces and multi-workspace user membership, with role-based
  authorization (`admin`/`user`), workspace isolation enforced through
  Policies, session-based workspace context resolution, and a
  self-hosted single-workspace mode (`MULTI_WORKSPACE_ENABLED`).
