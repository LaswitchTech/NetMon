Read CLAUDE.md and follow it strictly.

Task:
Implement Phase 4 of the NetMon domain evolution: add minimal Devices CRUD and switch the write path to the new interfaces/addresses model.

Context:
- The devices list page exists and is data-driven
- Read path already uses device_interfaces + device_addresses with fallback to devices.host
- devices.host is still present as a transitional column
- device_interfaces and device_addresses exist and have been backfilled
- devices now also support deleted_at and merged_into_device_id
- This phase is about write-path evolution, not full feature polish

Goals:
- Add create/edit/delete support for devices
- Make device writes use the new schema correctly
- Keep the current list page working
- Preserve backward-compatible transitional behavior

Requirements:

1. Repository / write-path changes
- Extend DeviceRepository with minimal write methods:
  - create(array $data)
  - update(int $id, array $data)
  - softDelete(int $id)
  - findById(int $id)
- Create/update logic must:
  - create or update the logical device row
  - create or update one default management interface
  - create or update one primary device address
- During this transitional phase, continue syncing devices.host with the selected primary address so older fallback logic remains safe
- All active-device queries must exclude soft-deleted rows using deleted_at IS NULL

2. Routes
- Add browser routes protected by WebAuth for:
  - GET /devices/create
  - POST /devices
  - GET /devices/{id}/edit
  - POST or PUT /devices/{id}
  - POST or DELETE /devices/{id}/delete
- Keep routing simple and consistent with the current architecture

3. Controller
- Extend DeviceController to support:
  - createForm()
  - store()
  - editForm()
  - update()
  - delete()
- Keep controllers thin
- Put validation and persistence logic in repository/service-level code as appropriate
- Redirect back to /devices after success
- Return simple browser-friendly validation feedback

4. Views
- Add minimal Bootstrap 5 browser views for:
  - create device form
  - edit device form
- Fields for this phase should be limited to:
  - device name
  - primary host/address
  - optional description if already supported cleanly
- Do NOT build multi-interface editing UI yet
- Do NOT build advanced service/port editing UI yet
- Update the Devices list page actions so a user can reach edit/delete flows

5. Delete behavior
- Delete must be a soft delete only:
  - set deleted_at
  - do not hard-delete device row
- Do NOT implement merge logic in this phase
- Do NOT delete interfaces/addresses physically in this phase unless clearly necessary and safely handled

6. Validation
- Validate:
  - required device name
  - required host/address
  - basic address/hostname sanity
- Keep validation simple and explicit
- Prevent editing or deleting already soft-deleted devices through normal UI flows

7. Documentation
- Update relevant docs in /docs:
  - docs/devices.md
  - docs/schema.md if needed
  - docs/domain-model.md progress notes if needed
- Clearly document the transitional write behavior:
  - writes go to interfaces/addresses
  - devices.host is still synchronized temporarily

Constraints:
- Do NOT remove devices.host yet
- Do NOT implement merge workflows yet
- Do NOT implement monitored services CRUD yet
- Do NOT build advanced UI polish yet
- Do NOT introduce heavy dependencies
- Keep this phase focused on write-path transition and minimal usable browser CRUD

Implementation guidance:
- Prefer one default management interface per device for now
- Prefer one primary address per device for now
- Structure the code so future multi-interface editing can be added cleanly
- Keep behavior safe, incremental, and reversible where possible

After completion:
- Summarize all created/modified files
- Explain how create/update now write to device_interfaces and device_addresses
- Explain how devices.host remains synchronized during the transitional phase
- Explain how soft delete is enforced
- Identify what remains before devices.host can eventually be retired
