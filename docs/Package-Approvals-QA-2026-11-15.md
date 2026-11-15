# Package approval workflow QA

The DOT package-approval module is connected to operator package submissions, real status transitions, operator notifications, history and public visibility. It is not static dashboard content.

## Why the screenshot shows zero

Read-only production inventory found five packages, all public and all linked to an operator, but none has `submitted_at` set. The approvals queue deliberately includes only packages that were submitted for review. Therefore Waiting, Changes requested, Approved and All are currently zero in this module, while the public package directory still has five entries. Existing/seeded public packages were not retroactively inserted into the review workflow.

## Functional verification

The application controllers, package model and checks in the isolated server copy matched production before QA. A CLI HTTP-kernel harness exercised actual routes using temporary QA operator/admin accounts inside one rollback transaction. CSRF is bypassed only within that CLI test app; route authentication and ownership rules remain active. No external messages were sent; only temporary in-system notification rows were exercised and rolled back.

Forty-two checks passed across: guest/operator denial of DOT routes; operator My packages; creation of a draft with itinerary and operator ownership; hidden draft/review states; denial of another operator's access; submission and review link; live queue/tab/KPI counts; required change-request note; exact note delivery and operator display; resubmission; expired-accreditation approval blocking; approval metadata; public detail and search visibility; rejection of duplicate decisions; pause/resume visibility; decision-time metric; history; and search by operator.

The repeatable harness is `deploy/check-package-approvals.php`; `--inventory` performs only the initial read-only inventory. Its QA run creates only temporary fixtures and rolls their package/account/notification/history rows back. The final production inventory remained five public packages and zero submissions. Full visual browser QA was not performed.

## Bug found and fixed

DOT's Resume endpoint previously made a paused package live without checking its operator's accreditation. The equivalent operator action and initial DOT approval already enforced that check. Reproduced the defect (HTTP 200 for an expired operator), then added the same `PackageChecks::operator()` guard to admin resume. It now returns validation HTTP 422 while keeping the package paused/hidden, without creating a false history event or notification. Restoring the operator's active accreditation permits resume again. Existing approval rules and sample-data policies were not changed.

A focused PHPUnit regression was added to `PackageApprovalTest`; PHP syntax checks passed. The complete local PHPUnit suite was not rerun because the local runner previously stalled. The regression behavior was verified by the actual-route harness above.

The controller fix is live. Original-file SHA-256 checked before install, final deployed bytes compared with the tested controller, and PHP-FPM reloaded. Backup: `/var/backups/exploredvo/package-approval-qa-20261115-153220/`. No production package status, accreditation record or notification was left changed. No migration was needed.
