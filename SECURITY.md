# Security Policy

## Supported versions

Security fixes are provided for the latest tagged release. Before `1.0.0`, fixes may require upgrading to the newest minor release because the public API and scorecard schema are still stabilizing.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability.

Until GitHub private vulnerability reporting is enabled for the public repository, email reports to [joey@jkudish.com](mailto:joey@jkudish.com). Include the affected version, impact, reproduction steps, and any suggested mitigation.

You can expect an acknowledgement within five business days. Confirmed issues will be coordinated privately until a fix and disclosure plan are ready.

## Sensitive evidence

Stable scorecards are designed to exclude prompts, outputs, expected values, and scorer inputs. Private replay bundles retain the minimum output required to rerun evaluation and must be protected as application data. Reports involving path traversal, unsafe replay reuse, fingerprint bypasses, unredacted stable evidence, or baseline provenance are especially welcome.
