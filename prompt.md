Read CLAUDE.md and follow it strictly.

Task:
Repair and complete the web setup wizard wiring without reimplementing existing logic.

Context:
- The CLI installer is fully working and must not be modified unless required
- SetupController and wizard view were partially generated but the task was interrupted
- /setup currently returns 404
- There is a known syntax error in public/index.php related to "use" inside a block

Requirements:

1. Fix the syntax error in public/index.php:
   - Remove invalid "use" statements inside conditional blocks
   - Use either a top-level import OR fully-qualified class names

2. Ensure the boot guard correctly handles setup routing:
   - When not installed:
     - All non-/setup routes redirect to /setup
     - /setup routes are dispatched to SetupController
   - When installed:
     - /setup routes return 403

3. Complete SetupController routing only:
   - Ensure dispatch(method, path) correctly maps:
     - GET /setup
     - POST /setup/check
     - POST /setup/db
     - POST /setup/config
     - POST /setup/admin
     - POST /setup/install
     - GET /setup/done
   - Do NOT redesign logic — reuse existing SetupService

4. Ensure Apache + router compatibility:
   - Verify that /setup is properly routed through public/index.php
   - Do not assume framework routing — respect current front controller logic

5. Validate end-to-end flow:
   - /setup loads the wizard view
   - POST endpoints return JSON
   - No 404 or fatal errors occur

Constraints:
- Do NOT rewrite SetupService
- Do NOT redesign the wizard UI
- Do NOT introduce new architecture
- Do NOT modify unrelated files
- Keep changes minimal and targeted

After completion:
- Summarize exactly what was fixed
- Explain why /setup was returning 404
- Confirm routing works for both installed and non-installed states
- List any remaining incomplete parts of the wizard (if any)
