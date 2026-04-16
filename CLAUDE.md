# NetMon - Claude Instructions

## Project Overview
NetMon is a modular network monitoring web application.

Stack:
- Backend: PHP (no heavy framework)
- Frontend: JavaScript (AJAX), Bootstrap 5, Bootstrap Icons, LESS for Bootstrap customization and theming, DataTables for all application tables
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
- All application styling must be implemented in LESS
- Build theming so light mode, dark mode, and future themes are first-class supported concerns
- Do NOT hardcode a single theme into component styling
- Theme variables/tokens should be structured so new themes can be added without rewriting component styles
- All application tables should use DataTables unless there is a clear documented reason not to

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

### Suggestion Systems (Duplicates & Matches)

The system may provide **suggestions** based on identity signals, but must NEVER take automatic action.

Rules:
- Suggestions are **informational only**
- No automatic merge, link, or mutation is allowed
- Operator must always confirm actions

Signal strength:
- MAC address = strong signal (preferred)
- Hostname = weaker, heuristic signal

Implementation guidelines:
- Always label the signal clearly in the UI
- Always explain that suggestions are not actions
- Never hide uncertainty from the operator

Separation of concerns:
- Device duplicate suggestions (device ↔ device)
- Discovery match suggestions (finding ↔ device)

These systems must:
- Avoid false certainty
- Avoid destructive automation
- Remain safe by default

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

## Theme & Styling Strategy

Styling is a platform concern and must be designed for reuse across future applications.

### Core Rules

- All styling must be authored in LESS
- The theme system must support:
  - dark theme
  - light theme
  - future additional themes
- Component styles must consume theme variables/tokens instead of embedding fixed colors directly
- Theme architecture must make it easy to add a new theme without rewriting component-level LESS files

### Reference Assets

- Files under /docs/reference may be used as visual and structural references for styling
- Reference assets must be adapted into the project's LESS architecture, not copied blindly
- Existing reference files currently reflect dark-mode styling only; implementation must also define a proper light theme

### Suggested Theme Structure

The styling system should evolve toward:

- shared design tokens / variables
- reusable component LESS files
- theme-specific variable overrides
- minimal JavaScript for theme switching if needed later

### Tables

- All application tables should use DataTables by default
- DataTables styling must be integrated into the shared theme system so dark/light/future themes remain visually consistent
- Avoid one-off table styling that bypasses the common theme layer

---

## Reusable Modules Strategy

NetMon will progressively extract **reusable modules** that can be shared across future applications.

Two initial modules are planned:

### Notes Module (Reusable)

Purpose:
- Allow attaching notes to arbitrary entities (devices, alerts, discovery findings, etc.)

Core design:
- Notes must be polymorphic:
  - entity_type (string)
  - entity_id (int)
- Notes must include:
  - author_user_id
  - content
  - created_at / updated_at

Rules:
- Notes are always **non-destructive** (no cascading deletes)
- Notes must not assume a specific entity type
- Notes must be attachable to any future domain object

Future considerations:
- threaded notes
- visibility (private/public/internal)
- pinning or highlighting

---

### Notifications Module (Reusable)

Purpose:
- Provide a unified system for:
  - in-app notifications
  - email notifications
  - SMS notifications (future)

Core concepts:
- Notification (event)
- Notification delivery (per channel)
- Notification channel (email, sms, internal)
- Notification preference (per user)

Rules:
- Notifications must be triggered by **domain events** (e.g. alerts), not raw monitoring data
- Delivery must be decoupled from generation
- Throttling must be respected (reuse existing alert throttling logic)

Design constraints:
- Must be usable outside NetMon
- Must not depend on NetMon-specific models (devices, alerts)
- Must support multiple channels without branching logic in core code
- Must be theme-agnostic at the UI layer so future apps can present notifications consistently across themes

---

## Device Detail Page (NetMon-Specific)

The device detail page is the **primary operational view** for a device.

It must evolve into a structured, multi-section page including:

- Identity / overview
- Current status (derived, not static)
- Monitoring graphs:
  - uptime
  - latency
- Network structure:
  - interfaces
  - addresses
- Services:
  - monitored services
  - current state
- Activity:
  - recent checks
  - related alerts
- Context:
  - notes (via Notes module)
  - discovery / merge hints (future)

Rules:
- Must rely on historical data (device_checks, service_checks)
- Must NOT depend solely on summary columns (devices.status)
- Must remain performant (limit history queries)
- Must inherit shared theme/component styles rather than defining page-specific hardcoded color schemes

---

## Implementation Order Guidance

When introducing these features:

1. Implement Notes module first (lowest risk, high reuse)
2. Expand device detail page to consume notes and existing monitoring data
3. Implement Notifications module UI last (largest scope, depends on alerts system)

Avoid:
- implementing all modules at once
- coupling reusable modules to NetMon-specific logic

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
- Theme work should adapt reference styling from /docs/reference into reusable LESS files rather than copying raw CSS/HTML directly
- The first theme implementation must support both dark mode and light mode
- Future themes should be addable primarily through variable/token overrides
- DataTables is the default table layer across the application and should be themed consistently with the rest of the UI
