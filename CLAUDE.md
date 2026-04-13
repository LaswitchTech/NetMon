# NetMon - Claude Instructions

## Project Overview
NetMon is a modular network monitoring web application.

Stack:
- Backend: PHP (no heavy framework)
- Frontend: JavaScript (AJAX), Bootstrap 5, Bootstrap Icons, LESS for Bootstrap customization
- Database: SQLite (default), MySQL/MariaDB (future)
- Architecture: Modular, service-based

---

## Core Features (Phase 1)
- User authentication (session + API tokens)
- Users, groups, permissions
- Token-based API authentication
- Basic dashboard (AJAX-driven UI)

---

## Architecture Rules

### General
- Keep code modular and readable
- Do NOT introduce heavy frameworks
- Prefer simple, explicit logic over magic

### Backend
- Use PDO for all database access
- Use a database abstraction layer:
  - DatabaseInterface
  - SQLiteDriver
  - MySQLDriver (future)
  - QueryBuilder
- Controllers must remain thin
- Business logic must live in Services

### Frontend
- Use AJAX (fetch API)
- Do NOT reload pages unless necessary
- Use Bootstrap 5 for layout
- Use LESS for Bootstrap customization and custom theme structure
- Keep JS modular and minimal
- Keep frontend assets organized so Bootstrap customizations remain maintainable

---

## Database Rules
- Default: SQLite
- Must support MySQL later
- No SQLite-specific hacks unless isolated
- All schema changes must use migrations

---

## Authentication & Authorization

### Required
- Users
- Groups
- Permissions
- API Tokens

### Rules
- Passwords must use password_hash / password_verify
- API tokens must:
  - be tied to a user
  - have their own permissions
  - be revocable
- Authorization checks must be centralized

### Architecture Notes
- Keep the auth service/container structure compatible with future auth providers.
- Design with these future abstractions in mind, but do NOT implement them yet:
  - AuthProviderInterface
  - LocalAuthProvider
  - LdapAuthProvider
  - ImapAuthProvider
  - OAuthProvider
- Session authentication and token authentication should be designed so they can later coexist with alternate identity providers.
- Avoid coupling core authorization logic to a single authentication backend.

---

## Future Features (DO NOT IMPLEMENT YET)
- LDAP authentication
- IMAP/SMTP authentication
- OAuth authentication (Google, Microsoft, Facebook, Apple, and other providers)
- Multi-database support switch
- External integrations
- CLI installation script
- Web-based installation wizard

---

## Workflow Rules

When working:
1. ALWAYS explain your plan before coding
2. Make small, safe changes
3. Never modify unrelated files
4. Summarize:
   - files created/modified
   - risks
   - next steps
5. Create and update documentation in /docs along the way when architecture, workflows, services, migrations, or usage patterns are introduced
6. When reference assets exist under /docs/reference, review and adapt them thoughtfully instead of copying them directly into production code

---

## Forbidden Actions
- Do NOT modify Docker config unless asked
- Do NOT install large dependencies
- Do NOT delete files without explanation
- Do NOT leave major new architecture or workflows undocumented when /docs should be updated
- Do NOT create or modify major subsystems without updating the relevant preferred documentation files when applicable
- Do NOT treat files under /docs/reference as production-ready code without adaptation

---

## Dev Notes
- Use /data/app.db for SQLite
- Use /storage for logs/cache
- Public entry point is /public/index.php
- Store developer, Claude-facing, and user-facing documentation in /docs
- When introducing a new subsystem or workflow, add or update the relevant documentation in /docs
- If a preferred documentation file does not exist yet and becomes relevant, create it
- Reference UI assets may be stored under /docs/reference for later adaptation into the project
- Installation must later support both a CLI installer and a web-based installation wizard
- Installation planning should account for environment checks, database setup, migration execution, seed/bootstrap execution, admin creation, config persistence, and install locking
- Installation lock should use both /storage/installed.lock and an APP_INSTALLED=true flag for defense in depth
- Persist long-lived application-level settings in .env
- Persist environment- or deployment-specific local settings in /config/local.php
- .env should hold application identity and baseline app settings that rarely change for a given app template
- /config/local.php should hold mutable local configuration such as database connection details and enabled authentication methods
- Installer should treat baseline application settings in .env as predefined defaults unless there is a strong reason to prompt for them
- Current installer prompts should be limited to:
  - application URL
  - database driver
  - administrator full name
  - administrator username
  - administrator email
  - administrator password
- Current predefined .env baseline should include:
  - APP_NAME
  - APP_ENV
  - APP_DEBUG
  - APP_INSTALLED
  - DB_SQLITE_PATH (or equivalent SQLite path setting)
- Until MySQLDriver exists, installer flows should present MySQL/MariaDB as planned but unavailable
