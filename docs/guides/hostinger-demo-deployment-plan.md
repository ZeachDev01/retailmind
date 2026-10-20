# Hosted Demo deployment plan

Decisions confirmed on 2026-10-21 (Asia/Manila):

- Deploy a private Hosted Demo with isolated Demo Data today.
- Use the existing Hostinger plan with a separate demo site and database.
- Limit the hosted demonstration to inventory workflows and prepared forecast
  results. Real Store operations and Python training belong on the dedicated
  Store PC later; configuring that PC is outside today's hosted release.
- Use normal Staff login without an additional shared site password.
- Keep sample inventory and saved forecasts in the hosted Demo database.

## Verified constraints

- The connected Hostinger account has existing websites but no VPS instances.
- The inspected PHP site runs PHP 8.3 with PDO MySQL, Fileinfo and GD enabled.
  The new demo site's settings must be checked independently.
- Hostinger supports Python on VPS rather than Web/Cloud hosting. See its
  [language support documentation](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/).
- The [prepared replay snapshot](hostinger-demo-replay.md) must not receive a
  forecasting worker, retraining cron or Store activation. Choose an isolated
  training database and model-artifact location before enabling live training.
- The current supported live-training Demo runs locally with a separate Demo
  database. Hosted production-mode Demo pages disable training; the Python
  worker also rejects Demo training in production mode. Its database connection
  does not configure TLS, so pointing it at hosted MySQL is not a safe shortcut.
- Committed hosted database fallback credentials must be removed and the
  exposed password rotated before deployment. Coordinate rotation with any
  application still using that database user.

## Deployment target

- Generated free hostname: `antiquewhite-cattle-865506.hostingersite.com`.
- Existing hosting account: `u125532033`, order `1010207361`.
- Use a fresh database and fresh credentials; do not change existing website
  databases or import the snapshot into a Store database.

## Current status

- The separate website is provisioned and its setup is complete.
- Created database and user `u125532033_rmdemo26` for this website only.
  No snapshot has been imported yet.
- Verified PHP 8.3.35, enabled PDO MySQL/Fileinfo/GD, `date.timezone=Asia/Manila`,
  `log_errors=On` and `display_errors=Off` on the new website.
- Built the allowlisted application under `.release/` and deployed it using the
  Hostinger PHP deployment tool. Dependencies are present on the server.
- HTTPS returns the RetailMind page. Requests for `/.env` return 403 and the
  backend configuration path returns 404. Staff authentication and data screens
  are not verified until the environment and SQL imports are complete.
- Verified the snapshot SHA-256 against `work/hostinger-demo/manifest.json`.
- Removed hosted database credential fallbacks. PHP syntax, hosted database
  configuration checks and the deployment contract pass.
- The old exposed credentials identify a different hosting account; rotation
  requires access to that account. The new Demo uses fresh credentials.

## Remaining setup

The connected Hostinger tools lack a PHP environment-file editor and a SQL
import operation. Browser inventory is empty; opening a browser also failed.
An authenticated browser connection or a manual hPanel handoff is needed:

1. Import `work/hostinger-demo/replay-demo.sql` into the new empty database,
   then import `.scratch/hostinger-demo-private/activate-accounts.sql` there.
2. Upload `.scratch/hostinger-demo-private/upload/.env` into the new site's
   `public_html` directory. It already has the correct protected filename.
   Never upload it under a temporary public name before renaming it: it
   contains the private DB password. Do not share its contents.
3. The application release is already deployed. Do not upload the repository,
   SQL exports or the private import bundle into `public_html`.
4. Verify HTTPS, protected paths, staff login, inventory and the saved forecast
   report. The application is not live or verified until these checks pass.
