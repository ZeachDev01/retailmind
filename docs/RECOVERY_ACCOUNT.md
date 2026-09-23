# Offline Recovery Account procedure

The Recovery Account is a sealed `super_admin` identity for restoring privileged access when the primary Super Administrator cannot authenticate. It is not a routine second administrator account.

## Security requirements

- Run every command from a trusted console on the RetailMind application host.
- Keep the login password and activation secret in separate offline stores under separate custody.
- Prefer `*_FILE` inputs. Never put a secret on the command line, where process listings and shell history may expose it.
- Remove temporary credential files and environment variables after each command.
- Keep the account sealed except during an actual recovery.
- Review the `recovery_account` Protected Audit Records after every recovery.

The application UI exposes only the lifecycle status and last-use time. It never exposes the username, password, activation secret, hashes, or lifecycle controls.

## One-time provisioning

Create two files outside the web root, readable only by the operator running PHP:

- one containing a strong Recovery Account login password;
  (at least 8 characters with uppercase, lowercase, and a number — the same unified policy as standard Store staff);
- one containing a different activation secret of at least 20 characters.

Set the username in the environment, point the command at both files, and provision the sealed identity:

```bash
export RECOVERY_USERNAME='sealed_recovery'
export RECOVERY_LOGIN_PASSWORD_FILE='/offline-custody/login-password.txt'
export RECOVERY_ACTIVATION_SECRET_FILE='/separate-custody/activation-secret.txt'
php src/backend/scripts/recovery_account.php provision
php src/backend/scripts/recovery_account.php status
unset RECOVERY_USERNAME RECOVERY_LOGIN_PASSWORD_FILE RECOVERY_ACTIVATION_SECRET_FILE
```

Provisioning is single-use. The account starts disabled, has no email address, and is excluded from routine user management and password recovery.

## Activate during recovery

Retrieve only the separately stored activation secret and run:

```bash
export RECOVERY_ACTIVATION_SECRET_FILE='/separate-custody/activation-secret.txt'
php src/backend/scripts/recovery_account.php activate
unset RECOVERY_ACTIVATION_SECRET_FILE
```

The separately stored Recovery Account username and login password can now be used through the normal login form. Activation and each successful use create security-sensitive Protected Audit Records.

## Rotate credentials

After access is restored, create a new login password file and a different new activation-secret file. Keep them separate from each other and from the old credentials.

```bash
export RECOVERY_ACTIVATION_SECRET_FILE='/separate-custody/old-activation-secret.txt'
export RECOVERY_NEW_LOGIN_PASSWORD_FILE='/offline-custody/new-login-password.txt'
export RECOVERY_NEW_ACTIVATION_SECRET_FILE='/separate-custody/new-activation-secret.txt'
php src/backend/scripts/recovery_account.php rotate
unset RECOVERY_ACTIVATION_SECRET_FILE RECOVERY_NEW_LOGIN_PASSWORD_FILE RECOVERY_NEW_ACTIVATION_SECRET_FILE
```

Rotation revokes active Recovery Account sessions and invalidates the old activation secret. Confirm the two new credentials are stored successfully before destroying the old copies.

## Reseal

Use the **new** activation secret after rotation:

```bash
export RECOVERY_ACTIVATION_SECRET_FILE='/separate-custody/new-activation-secret.txt'
php src/backend/scripts/recovery_account.php reseal
php src/backend/scripts/recovery_account.php status
unset RECOVERY_ACTIVATION_SECRET_FILE
```

Resealing disables login and revokes sessions. Confirm status is `sealed`, review the Recovery Account Protected Audit Records, and return the separately stored credentials to offline custody.

## Denied normal paths

The sealed identity cannot be activated, edited, enabled, disabled, session-reset, or password-reset through Users & Access. It has no email reset path, password-reset tokens are rejected, and its credentials cannot be changed through the signed-in password-change page.
