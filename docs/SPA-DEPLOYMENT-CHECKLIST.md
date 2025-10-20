# SPA Deployment Checklist

- [ ] **Set web root**: Update the web server virtual host so the document root points to `api/public`.
- [ ] **Adjust rewrite rules**: Ensure the server forwards JSON/XHR requests to `index.php` before falling back to the SPA catch-all, preserving API access.
- [ ] **Build UI assets**: Run `npm ci` and `npm run build` inside `web/` to generate the Vite output.
- [ ] **Publish assets into Laravel**: Copy `web/dist/index.html` and the `web/dist/assets/` directory into `api/public/` (or wire them through a Laravel view).
- [ ] **Update deploy workflow**: Modify `.github/workflows/deploy.yml` (or equivalent) to package the built assets alongside the API when rsyncing/releases are prepared.
- [ ] **Verify Laravel routing**: Optionally add a Laravel route (e.g., `Route::view('/{any}', 'app')`) or confirm the static `index.html` is served correctly for history mode.
- [ ] **Warm PHP caches**: Run `composer install`, `php artisan config:cache`, `route:cache`, etc., after assets land in `api/public`.
- [ ] **Smoke test API**: Hit critical endpoints (e.g., `/admin/idp/providers/preview-health`) to confirm they still return JSON with the SPA in place.
- [ ] **Smoke test UI**: Load the SPA over HTTPS, confirm static assets resolve, and that API calls succeed with authenticated flows.
- [ ] **Document server changes**: Record any new environment variables, proxy settings, or maintenance steps for future deploys.
