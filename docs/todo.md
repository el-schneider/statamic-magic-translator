# TODO — harden locale switcher integration

## Goal

Make Sites panel integration deterministic across CP locales and remove brittle dependence on the literal "Sites" heading.

## Plan

1. Inspect Statamic v6 source to verify where the localization switcher is rendered and which stable structural markers exist.
2. Replace heading-text based panel lookup with structural candidate scoring using known site names/handles.
3. Keep badge row matching translation-safe (site name, handle, and translated name token support).
4. Run frontend formatting/build checks.
5. Report tradeoffs and next step for translate-button injection into the same panel.
