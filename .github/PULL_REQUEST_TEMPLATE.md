## Summary

<!-- What changed, and why? -->

## Evidence and compatibility

<!-- Call out Pest lifecycle, stable/private evidence, replay, baseline, or schema decisions. -->

## Checklist

- [ ] I added or updated tests for behavioral changes.
- [ ] I preserved ordinary Pest behavior and explicit eval-mode safety.
- [ ] I kept private evaluation material out of stable scorecards.
- [ ] I updated documentation, schema fixtures, and the changelog when needed.
- [ ] `composer test` passes.
- [ ] `composer analyse` passes.
- [ ] `composer lint:check` passes.
- [ ] `composer validate --strict` and `composer audit` pass.
