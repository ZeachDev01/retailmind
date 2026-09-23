# Password Alphanumeric Validation Research Report

## Executive Summary

Password validation in the RetailMind codebase **does enforce alphanumeric requirements** (specifically requiring uppercase letters, lowercase letters, and numeric digits), but **does not restrict passwords to alphanumeric characters only** (special characters are allowed). 

Validation is enforced server-side during user password creation, updates, and resets. However, there are minor inconsistencies across user lifecycle entry points, a distinction in minimum length rules between operational staff and offline recovery accounts, and a lack of client-side HTML5 pattern/minlength validation on frontend input forms.

---

## 1. Password Validation Rules & Character Complexity Requirements

### 1.1 Standard User Password Policy
The central password policy validation function `password_policy_error()` is defined in `C:\xampp\htdocs\retailmind\src\backend\includes\auth.php:47-57`.

- **Rules Enforced**:
  - **Minimum Length**: Minimum of `10` characters by default (configurable via `PASSWORD_MIN_LENGTH` environment variable, enforced to be at least `8`).
  - **Uppercase Letter**: Must contain at least one uppercase letter (`/[A-Z]/`).
  - **Lowercase Letter**: Must contain at least one lowercase letter (`/[a-z]/`).
  - **Numeric Digit**: Must contain at least one numeric digit (`/\d/`).
- **Alphanumeric Analysis**: The rule requires a combination of alphabetic (uppercase + lowercase) and numeric characters. It does not forbid non-alphanumeric (special) characters, nor does it require special characters.

### 1.2 Recovery Account Password Policy
Offline recovery accounts use a separate helper `recovery_password_hash()` located in `C:\xampp\htdocs\retailmind\src\backend\scripts\recovery_account.php:36-37`.

- **Rules Enforced**:
  - **Minimum Length**: `12` characters minimum (stricter than standard accounts).
  - **Character Complexity**: Must contain uppercase (`/[A-Z]/`), lowercase (`/[a-z]/`), and numeric (`/\d/`) characters.

---

## 2. Password Entry Points and Flows

### 2.1 Enforced Flows (Uses `password_policy_error`)

1. **Self-Service Password Change (`change_password.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\frontend\components\auth\change_password.php:30`
   - Flow: Verifies current password, confirms matching new password, checks that new password differs from old, and executes `password_policy_error($newPassword)`.

2. **Self-Service Password Reset (`reset_password.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\frontend\components\auth\reset_password.php:26`
   - Flow: Verifies token hash and expiry, confirms password match, and executes `password_policy_error($password)`.

3. **User Creation via User Manager (`user_manager.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\frontend\components\user_manager\user_manager.php:76`
   - Flow: Validates password via `password_policy_error($password)` before passing `password_hash($password, PASSWORD_DEFAULT)` to `UserLifecycleService`.

4. **User Password Reset/Update via User Manager (`user_manager.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\frontend\components\user_manager\user_manager.php:113`
   - Flow: When `new_password` is provided, executes `password_policy_error($newPassword)` before calling `UserLifecycleService::resetPassword()`.

5. **Legacy Admin User Management (`manage_users.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\backend\legacy\routes\admin\manage_users.php:31` (Creation), `line 104` (Update).
   - Flow: Invokes `password_policy_error()` on creation and update POST submissions.

### 2.2 Unenforced Seams & Non-Policy Password Operations

1. **Backend Service Layer (`UserLifecycleService.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\backend\app\Services\UserLifecycleService.php:46,137`
   - Analysis: Methods `create()` and `resetPassword()` accept a pre-hashed password (`password_hash`) and only check that the string is non-empty (`$passwordHash === ''`). They rely entirely on callers (e.g., `user_manager.php`) to perform `password_policy_error()` checking. If the service is called directly (e.g., via CLI tools, seeds, or contract tests), policy validation is bypassed.

2. **Backend Recovery Service Layer (`RecoveryAccountService.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\backend\app\Services\RecoveryAccountService.php:33,86`
   - Analysis: Methods `provision()` and `rotateCredentials()` check for non-empty `$passwordHash`. Policy logic (12-char min + alphanumeric checks) is located in the CLI script wrapper (`recovery_account.php:36`).

3. **User Authentication / Login (`auth.php` & `index.php`)**
   - Files: `C:\xampp\htdocs\retailmind\src\backend\includes\auth.php:96` and `C:\xampp\htdocs\retailmind\src\frontend\index.php:255`
   - Flow: Login verifies input against stored hashes using `password_verify()`. No policy checks are executed upon login (intended behavior).

4. **POS Supervisor Authorization (`SalesWorkflowService.php`)**
   - File: `C:\xampp\htdocs\retailmind\src\backend\app\Services\SalesWorkflowService.php:276`
   - Flow: Supervisor authentication for discounts >10% verifies credentials using `password_verify()`. No policy check is needed or performed.

---

## 3. Findings and Inconsistencies

1. **Alphanumeric Complexity Enforcement**:
   - All password creation/reset/change flows require at least one uppercase character (`[A-Z]`), one lowercase character (`[a-z]`), and one numeric digit (`\d`).
   - Special characters are neither required nor prohibited.

2. **Standard vs. Recovery Account Minimum Length**:
   - Standard user passwords require at least **10 characters** (or `PASSWORD_MIN_LENGTH`, floor 8) (`C:\xampp\htdocs\retailmind\src\backend\includes\auth.php:49`).
   - Recovery Account passwords require at least **12 characters** (`C:\xampp\htdocs\retailmind\src\backend\scripts\recovery_account.php:36`).

3. **Missing Client-Side HTML/JS Validation**:
   - Input forms in `add_user_modal.php`, `manage_user_modal.php`, `change_password.php`, and `reset_password.php` lack HTML5 validation attributes (`pattern`, `minlength`).
   - Validation relies entirely on server-side evaluation during form submission.

4. **Service Layer Seam**:
   - `UserLifecycleService` does not execute `password_policy_error()`. Any backend component or script invoking `UserLifecycleService` directly with a custom hash will bypass password policy validation.

---

## Summary of Cites

- `C:\xampp\htdocs\retailmind\src\backend\includes\auth.php:47-57` (Central standard password policy definition)
- `C:\xampp\htdocs\retailmind\src\backend\scripts\recovery_account.php:36-37` (Recovery account password policy definition)
- `C:\xampp\htdocs\retailmind\src\frontend\components\auth\change_password.php:30,81` (Password change form & handler)
- `C:\xampp\htdocs\retailmind\src\frontend\components\auth\reset_password.php:26,57` (Password reset form & handler)
- `C:\xampp\htdocs\retailmind\src\frontend\components\user_manager\user_manager.php:76,113` (User Manager POST handlers)
- `C:\xampp\htdocs\retailmind\src\backend\legacy\routes\admin\manage_users.php:31,104` (Legacy User Manager POST handlers)
- `C:\xampp\htdocs\retailmind\src\frontend\components\user_manager\modals\add_user_modal.php:21` (Add user form modal)
- `C:\xampp\htdocs\retailmind\src\frontend\components\user_manager\modals\manage_user_modal.php:51` (Manage user drawer modal)
- `C:\xampp\htdocs\retailmind\src\backend\app\Services\UserLifecycleService.php:46,137` (User Lifecycle service layer)
- `C:\xampp\htdocs\retailmind\src\backend\app\Services\RecoveryAccountService.php:33,86` (Recovery Account service layer)
- `C:\xampp\htdocs\retailmind\src\backend\app\Services\SalesWorkflowService.php:276` (POS supervisor authorization)
