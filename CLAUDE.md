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
- CLI installation script (implemented)
- Web-based installation wizard (implemented)

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
7. If a task is interrupted or incomplete, prioritize repairing and completing the existing implementation instead of rewriting it
8. Never leave partially implemented routing or boot logic in a broken state — ensure all entry points remain functional

---

## Forbidden Actions
- Do NOT modify Docker config unless asked
- Do NOT install large dependencies
- Do NOT delete files without explanation
- Do NOT leave major new architecture or workflows undocumented when /docs should be updated
- Do NOT create or modify major subsystems without updating the relevant preferred documentation files when applicable
- Do NOT treat files under /docs/reference as production-ready code without adaptation

---

## NetMon Domain Model Guidelines

NetMon is not a simple CRUD app — it models network infrastructure and monitoring state.

All future implementations MUST respect the following domain rules.

### Core Concepts

- A **Device** represents a logical host/system (not an IP)
- A device may have:
  - multiple network interfaces
  - multiple IP addresses
  - multiple monitored services (ports)

### Device Structure

Design must evolve toward:

- Device
- DeviceInterface (NICs)
- DeviceAddress (IPs)
- DeviceService (ports/services)

Avoid flattening everything into a single devices table long-term.

---

### Monitoring Model

Monitoring exists at two levels:

1. Device-level (reachability)
2. Service-level (ports/services)

Device status should be derived from checks:
- online
- offline
- degraded
- unknown
- disabled

Do NOT treat status as static-only data.


### Monitoring History (Time-Series Data)

Monitoring results MUST be stored historically, not only as the latest state.

Requirements:
- Device-level checks must be stored (e.g., device_checks)
- Service-level checks must be stored (e.g., service_checks)
- Each check should include:
  - timestamp (checked_at)
  - status
  - latency (if applicable)
  - optional message/error

Design rules:
- Current status fields (devices.status, etc.) are summaries only
- Historical tables are the source of truth for:
  - graphs
  - uptime calculations
  - alert correlation
  - trend analysis

Do NOT design monitoring as "last state only".

---

### Alerts (CRITICAL RULES)

Alerts must be **stateful**:

- Do NOT create duplicate alerts for the same issue
- Use a deterministic fingerprint (e.g. device/service + type)
- Reuse existing open alerts:
  - update last_seen_at
  - increment occurrence_count

Alert lifecycle:
- open
- acknowledged
- resolved
- suppressed

---

### Notifications

Notifications must:
- be based on alerts (NOT raw monitoring events)
- support throttling (e.g. every 15 minutes)
- support repeated reminders
- support escalation behavior

Avoid sending duplicate notifications for the same alert too frequently.

---

### Discovery

Discovery is separate from devices:

- DiscoveryJob (scan configuration)
- DiscoveryFinding (observed IP/MAC/hostname)

Findings must NOT blindly create devices.

---

### Device Identity & Merge

Devices may be discovered under multiple IPs.

Merging must:
- prefer strong identifiers (MAC address first)
- avoid unsafe auto-merging
- allow future manual merge workflows

Do NOT assume:
- one IP = one device

---

### Architecture Expectations

When implementing new features:

- Respect the domain separation:
  - device
  - interface
  - address
  - service
  - alert
  - notification
  - discovery

- Avoid premature simplification that would block:
  - multi-interface devices
  - service-level monitoring
  - alert deduplication
  - discovery merging

- All read queries for devices MUST exclude soft-deleted rows by default:
  - Use WHERE deleted_at IS NULL unless explicitly querying historical/merged records

- Transitional columns (e.g., devices.host) must be retired gradually:
  - Phase 1: schema + data migration
  - Phase 2: repository read path switch
  - Phase 3: write path switch
  - Phase 4: UI alignment
  - Phase 5: column removal (final cleanup)

---

### Implementation Strategy

When adding new features:

- Prefer incremental evolution of schema
- Document domain decisions in /docs
- Do NOT fully implement complex subsystems without planning first
- Separate:
  - planning (docs)
  - schema
  - services
  - UI

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
- scripts/uninstall.php should be used for development/testing resets
- The uninstall/reset flow should remove only local install artifacts and preserve baseline project files
- After a successful reset, /setup should be reachable again
- Installer and uninstall flows should respect the configured APP_URL, which may be a custom local development host such as https://netmon.local
- Monitoring system must support historical data storage for graphing and reporting (time-series checks)
- Device-level and service-level checks should be modeled separately to allow flexible monitoring strategies
