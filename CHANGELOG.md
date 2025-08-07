# Changelog

All notable changes to the Moodle Clean-up Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2.2] - 2025-08-07

### Added
- Moodle Plugin CI workflow for automated code quality and standards checking

### Changed
- Updated code style to match Moodle standards

## [2.1] - 2025-08-02

### Fixed
- Check whether table `logstore_lanalytics_log` exists and skip its cleanup if not existent

## [2.0] - 2024-07-05

### Added
- Logs clean-up
- Grades clean-up
- Course modules clean-up
- CLI script for fixing stuck course module deletions (`cli/reinit_modules_cleanup.php`)
- Statistics and usage reporting via CLI (`cli/usage_statistics.php`)
- Batch file removal operations

### Changed
- Improved database cleanup, implemented dedicated clean-up steps
- Improved performance for large file operations

### Removed
- Statistics and batch removal web UI

## [1.4] - 2024-12-07

### Changed
- Compatibility improvements for Moodle 4.1 LTS

## [1.3] - 2023-06-10

### Added
- Initial plugin release
- Files clean-up functionality
- Files clean-up management (web UI)

## Compatibility

| Version | Moodle | PHP | Status |
|---------|--------|-----|--------|
| 2.1     | 4.1+   | 7.4+ | ✅ Current |
| 2.0     | 4.0+   | 7.4+ | 📦 Archived |
| 1.x     | 3.9+   | 7.2+ | ❌ EOL |
