# Filament Backoffice — pilot on MSKBA

## Goal
Migrate the **administrator interface** to Filament 5 without rewriting Vue/Inertia/Blade portal screens or replacing the established system-role and canonical-user authorization.

Pilot at \`/backoffice\`: native Filament resource for \`AccountDashboardWidget\`; existing \`/admin/account-dashboard\` stays intact until dev acceptance. Both interfaces operate on the **same rows** in \`account_dashboard_widgets\`. No table migration or duplication is needed.

## Security
- Filament panel uses the existing Laravel \`web\` session; unauthenticated users go to the portal login. No independent Filament registration/identity store.
- \`User::canAccessPanel()\` verifies confirmed canonical admin status.
- Dashboard widget \`Gate::policy\` enforces CRUD; HTML/JS code editing and deletion remain superadmin-only. UI hidden controls do not replace server-side checks.
- Model save/delete broadcast invalidation to Reverb, so editing via either admin interface immediately refreshes authorized user dashboards.
- Existing iframe sandbox for user-facing HTML/JS is not weakened.

## Delivery blocker to resolve before dev rollout
CI currently refuses deploys that modify composer.lock (and the staging VDS has only about 2 GiB RAM). The feature-only dependency workflow resolves a **real** reproducible \`composer.lock\` in GitHub Actions; it must never fabricate package versions. Before the pilot reaches \`dev\`, prepare safe server-side dependency activation with a verified vendor build + rollback/backup and full CI. The default dev CI and existing admin remain untouched until that release procedure is proven.

Filament installer docs: https://filamentphp.com/docs/5.x/introduction/installation
