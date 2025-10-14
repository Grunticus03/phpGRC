# Login Layout Build Guide

## Audience & Objective
This guide is for AI coding agents working in the phpGRC repo. It explains how to add or modify a login layout while honoring project guardrails, animation expectations, and theming hooks.

## Prerequisites
- Read `docs/STYLEGUIDE.md`, `AGENTS.md`, and `docs/Charter.md` to ensure compliance.
- Familiarize yourself with the existing login implementation in `web/src/routes/auth/Login.tsx` and related assets (e.g., `LoginLayout3.css`).
- Ensure `npm run typecheck` and `npm run test -- <suite>` are available and passing before finishing.

## Layout Architecture
1. **Layout Selection**
   - Layout choice comes from theme settings (`ThemeSettings.theme.login.layout`).
   - Update both frontend enum/union types and backend validation (`UiSettingsUpdateRequest`) when adding new layout identifiers.
2. **State Management**
   - Centralize form state in `Login.tsx` (email, password, error/info feedback).
   - Add layout-specific state (e.g., animation phases) via `useState` and guard with `prefersReducedMotion`.
3. **API Integration**
   - Reuse `authLogin`, `consumeIntendedPath`, and `consumeSessionExpired`.
   - Redirect after success with `window.location.assign(dest)`, optionally delaying for exit animations.

## Animation Guidelines
- Prefer CSS keyframes scoped to a dedicated file (e.g., `LoginLayout3.css`) and import it in `Login.tsx`.
- Use `prefers-reduced-motion` media queries to disable non-essential motion.
- Keep animation state transitions deterministic:
  1. Trigger exit animation.
  2. Wait for exit duration before switching views.
  3. Start enter animation for the next panel.
- Use `setTimeout` (wrapped in a helper like `schedule`) to coordinate timing; track timer IDs for cleanup in `useEffect`.

## Accessibility & UX Checks
- Maintain focus management with refs and `requestAnimationFrame`, focusing the active input after each transition.
- Provide fallback messaging via the existing `err`/`info` alerts.
- Ensure controls are disabled during transitions or loading states to prevent double submissions.
- Include reduced-motion fallback paths that skip timed animations yet preserve view changes.

## Styling Conventions
- Stick to Bootstrap utility classes and project variables (`--bs-*` tokens) for colors, spacing, and shadows.
- When adding new CSS:
  - Names should be in the `login-layoutX__element` format.
  - Include responsive adjustments (e.g., `@media (max-width: 480px)`) when layout dimensions change.
  - Provide reduced-motion overrides at the bottom of the file.

## Configuration Touchpoints
- Update theme defaults in `web/src/routes/admin/themeData.ts` to expose new layout options.
- Extend the admin Theme Configurator and its tests to persist the new layout identifier.
- Mirror backend changes:
  - Add the identifier to `UiSettingsUpdateRequest` validation.
  - Update `UiSettingsService::sanitizeLoginLayout`.
  - Add feature tests in `api/tests/Feature/Settings/UiSettingsApiTest.php`.
- Adjust seed data or migrations if the default layout changes.

## Testing Checklist
1. `npm run lint`
2. `npm run typecheck`
3. Relevant Vitest suites (e.g., `npm run test -- BrandingCard`, `npm run test -- ThemePreferences`)
4. PHPUnit suite or targeted tests touching UI settings (`php ./vendor/bin/phpunit --filter UiSettingsApiTest`)
5. Manual smoke test in the browser when feasible to validate real animation timings.

## Submission Guidelines
- Keep diffs focused; avoid touching unrelated layouts unless necessary.
- Document timing changes or UX behavior in PR descriptions.
- Ensure new assets or helpers respect existing import order and linting rules.
