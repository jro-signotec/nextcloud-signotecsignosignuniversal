# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 22.04.2026

### Added

- Notification language selector in remote signing dialog (auto-detects user's Nextcloud locale; supports German, English, French)
- Optional email customization fields in remote signing dialog (subject, greeting text, signature text)
- Webhook reachability hint in admin settings (signoSign/Universal server must reach Nextcloud)

### Changed

- Remote signing API call now passes `locale`, `mailSubject`, `mailMessage`, and `mailSignatureText` to signoSign/Universal
- Default URL pre-filled with `https://universal.signosign.com/` when no URL is stored

## [1.0.1] - 20.04.2026

### Changed

- PHP codebase reformatted to spaces
- Added `<php min-version="8.2" />` to app dependencies
- Makefile refactored with variables and new `deploy` target
- Updated psalm and dev tooling

## [1.0.0] - 17.04.2026

### Added

- First release
- Admin settings for connection (URL, username, password) and signature field configuration
- Connection test button with detailed error display
- Automatic webhook URL configuration in signoSign/Universal
- Comment templates for send, signed, and rejected events (with `@userid@`, `@mailto@`, `@reason@` placeholders)
- Configurable file tags for send, signed, and rejected states (mutually exclusive, auto-cleanup on state transition)
- User preference to choose between local and remote signing as default file action
- "Sign file" file action for local signing workflow
- "Send file for signing" file action for remote signing workflow (email recipient, auth type, password/TAN)
- Webhook processing for signed and rejected files (downloads signed PDF, overwrites Nextcloud file)
- Unit tests
