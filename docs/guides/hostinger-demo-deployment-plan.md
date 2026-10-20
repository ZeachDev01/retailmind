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
  The user reports both SQL imports and the `.env` upload are complete.
- Verified PHP 8.3.35, enabled PDO MySQL/Fileinfo/GD, `date.timezone=Asia/Manila`,
  `log_errors=On` and `display_errors=Off` on the new website.
- Built the allowlisted application under `.release/` and deployed it using the
  Hostinger PHP deployment tool. Dependencies are present on the server.
- HTTPS returns the RetailMind page. Requests for `/.env` return 403 and the
  backend configuration path returns 404. The login form renders and the
  forecast report redirects unauthenticated visitors to login. Staff
  authentication and data screens remain unverified by the agent.
- Verified the snapshot SHA-256 against `work/hostinger-demo/manifest.json`.
- Removed hosted database credential fallbacks. PHP syntax, hosted database
  configuration checks and the deployment contract pass.
- The old exposed credentials identify a different hosting account; rotation
  requires access to that account. The new Demo uses fresh credentials.

## Automatic redeployment

The workflow targets this Demo on pushes to `main` and manual dispatch. It uses
strict FTPS to `srv2062.hstgr.io`, a Demo-only FTP account rooted at this site's
`public_html`, and repository secrets `HOSTINGER_DEMO_FTP_USERNAME` and
`HOSTINGER_DEMO_FTP_PASSWORD`. It preserves the manually uploaded `.env` and
runtime storage, serializes uploads, and checks the HTTPS login and `/.env`
protection after deployment. It never imports SQL or runs migrations.

The FTPS certificate hostname was verified before configuring the workflow.
The previous workflow failed because its server IP did not match the certificate.
The user created a Demo-only FTP account and saved both repository secrets.
A read-only FTPS login confirmed that account's root contains this Demo's
`index.php`, `.env`, `src` and `vendor`.

The runner's initial TCP connection timed out during Node address selection;
the FTP action now allows ten seconds per address attempt through `NODE_OPTIONS`.
After that fix, the first-deployment check for a nonexistent sync-state file
reset the data socket and closed the FTP client. Initializing an empty
`.ftp-deploy-sync-state.json` with the action's own version and empty file list
avoids that missing-file path without declaring existing files managed or
deleting them. Read-back verification passed and the hosted `.env` was unchanged.
Keep the sync-state file: subsequent deployments update it automatically.

Staff login, inventory and the saved forecast report still require an
authenticated check. Do not upload SQL exports, the private import bundle or
the repository into `public_html`.
