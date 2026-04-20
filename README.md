<p align="center">
  <img src="public/assets/images/logo.png" alt="NetMon Logo" width="200" />
</p>

# NetMon
![License](https://img.shields.io/github/license/LaswitchTech/NetMon?style=for-the-badge)
![GitHub repo size](https://img.shields.io/github/repo-size/LaswitchTech/NetMon?style=for-the-badge&logo=github)
![GitHub top language](https://img.shields.io/github/languages/top/LaswitchTech/NetMon?style=for-the-badge)
![Version](https://img.shields.io/github/v/release/LaswitchTech/NetMon?label=Version&style=for-the-badge)

## Description
**Author**: Louis Ouellet

**NetMon** is a modular, extensible network monitoring platform designed to track devices, services, and infrastructure health in real time.

Built with a strong focus on:
- Simplicity
- Modularity
- Long-term maintainability

NetMon goes beyond basic monitoring by integrating:
- Discovery
- Alerting
- Notifications (multi-channel, async)
- Notes and audit logging
- Admin and RBAC management
- File management and extensible modules

---

## Features

### Core Monitoring
- Device monitoring (ICMP / latency)
- Service monitoring (TCP-based checks)
- Historical metrics and uptime tracking
- Graphs and monitoring history

### Discovery
- Subnet scanning (CIDR-based)
- Automatic device detection
- Match suggestions (MAC / hostname)
- Link findings to existing devices

### Alerts
- Real-time alert generation
- Open / resolved lifecycle
- Alert history and tracking

### Notifications
- In-app notification system
- Email notifications (SMTP)
- Queue-based async delivery
- User-level notification preferences

### Notes System
- Polymorphic notes (devices, alerts, findings)
- Contextual annotations for operations
- Audit-friendly tracking

### Admin & RBAC
- Users, Groups, Permissions
- Role-based access control
- System settings (DB-backed with fallback hierarchy)
- Audit log for administrative actions

### File Manager (Module)
- Secure file browsing
- Upload / download / delete
- Path traversal protection
- Configurable storage roots

### UI / UX
- Dark / Light theme support
- DataTables integration across UI
- Modular layout with reusable components
- Responsive design

---

## Architecture Highlights

- Modular design (`app/Modules/*`)
- Clear separation:
  - Controllers
  - Services
  - Repositories
- No heavy framework dependency
- Async processing via worker scripts:
  - `monitor.php`
  - `notify.php`
- Configuration hierarchy:
  1. Database (runtime overrides)
  2. `config/local.php`
  3. `.env`
  4. Defaults

---

## Installation

### Requirements
- PHP 8+
- SQLite or MySQL
- Web server (Apache/Nginx)
- Node.js (optional, for LESS compilation)

### Setup

```bash
git clone https://github.com/LaswitchTech/NetMon.git
cd NetMon

# Install dependencies (if applicable)
# Configure environment
cp .env.example .env

# Run migrations
php scripts/migrate.php

# Seed database (optional)
php scripts/seed.php

Run Monitoring

php scripts/monitor.php
php scripts/notify.php

Optional Cron Jobs

* * * * * php /path/to/scripts/monitor.php >> logs/monitor.log 2>&1
* * * * * php /path/to/scripts/notify.php  >> logs/notify.log  2>&1
```

---

## Development

CSS (LESS)
```bash
bash scripts/build-css.sh
bash scripts/build-css.sh --watch
```

Contributing Workflow
- Fork the repository
- Create a feature branch
- Submit a pull request

---

## Roadmap

Upcoming features:
- File previews and media support
- Task management module
- Chat module
- AI Agent integration
- External client agent (remote device reporting)
- Equipment tracking (location, owner, client possession)

---

## Security

Please disclose vulnerabilities responsibly.
Contact maintainers privately before public disclosure.

---

## License

This software is distributed under the [GPLv3](LICENSE) license.

---

## Documentation

Documentation is evolving alongside development.

Future full docs will include:
- Module architecture
- API usage
- Deployment guides

---

## Acknowledgments
- Built with inspiration from modern monitoring tools
- Designed with a modular, long-term maintainable approach
- Uses:
- Bootstrap 5
- DataTables
- Chart.js

---

## Download

Latest release available here:
👉 https://github.com/LaswitchTech/NetMon/releases/latest
