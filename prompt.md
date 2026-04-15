Read CLAUDE.md and follow it strictly.

Task:
Review, repair if needed, and complete the merge-suggestion feature that was interrupted by token limits.

Context:
- DeviceRepository::possibleDuplicates() was added
- DiscoveryRepository::possibleMatchesForFinding() was added
- DeviceController::show() was updated to pass $possibleDuplicates
- DiscoveryController::show() was updated to pass $possibleMatches
- app/Views/devices/show.php was updated with a “Possible Duplicate Devices” card
- app/Views/discovery/show.php was partially updated with a “Possible Device Matches” section
- The task was interrupted before completion and validation

Goals:
- Verify that the existing changes are correct
- Complete any missing wiring
- Update docs if not yet finished
- Ensure no broken routes/views remain

Requirements:

1. Review existing implementation first
- Inspect the code that was already added in:
  - app/Models/DeviceRepository.php
  - app/Models/DiscoveryRepository.php
  - app/NetMon/Controllers/DeviceController.php
  - app/NetMon/Controllers/DiscoveryController.php
  - app/Views/devices/show.php
  - app/Views/discovery/show.php
- Do NOT rewrite working code unnecessarily

2. Complete the interrupted UI work
- Ensure app/Views/discovery/show.php is syntactically correct and fully integrated
- Ensure the “Possible Device Matches” section renders only when suggestions exist
- Ensure signal labels are clear:
  - MAC match = strong signal
  - hostname match = weaker signal
- Ensure suggestions link correctly to devices and do not perform any automatic action

3. Validate controller/view wiring
- Confirm DeviceController::show() passes $possibleDuplicates correctly
- Confirm DiscoveryController::show() passes $possibleMatches correctly
- Confirm both views document their expected variables clearly in the header comments

4. Documentation
- Update docs/devices.md
- Update docs/discovery.md
- Document:
  - suggestion criteria
  - difference between MAC and hostname suggestions
  - that suggestions are informational only
  - that no auto-merge / auto-link occurs

5. Validation
- Run/prepare the usual local validation:
  - lint / syntax checks for all changed PHP files
- Make sure no syntax errors or broken references remain
- Verify no unrelated files are changed

Constraints:
- Do NOT introduce new schema changes
- Do NOT implement automatic merge
- Do NOT implement automatic link
- Do NOT redesign discovery or device workflows
- Keep this as a review + completion pass only

After completion:
- Summarize exactly what was reviewed
- Summarize exactly what was fixed or completed
- Confirm whether any code added before the interruption was left unchanged
- Confirm whether the feature is now complete and safe to test
- Note any remaining future improvements, but do not implement them
