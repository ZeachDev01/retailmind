# Owner Remote Monitoring Runbook: Cloudflare Tunnel & Access Setup

## 1. Prerequisites & Cost Review

> [!IMPORTANT]
> Always review and confirm domain and service costs with the Store owner before purchase or activation.

- **Cost Breakdown**:
  - **Cloudflare Account & DNS**: \$0 (Cloudflare Free Tier).
  - **Cloudflare Zero Trust**: \$0 (Free Plan covers up to 50 users; this installation requires only 1 user: the Store owner).
  - **Cloudflare Tunnel (`cloudflared`)**: \$0 (Included with Zero Trust Free Plan).
  - **Domain Name**: \$0 additional cost if using owner's existing domain or subdomain (e.g., `monitor.ownerstore.com`). If a new domain is required, owner must approve registrar pricing (typically \$8–\$12/year on Cloudflare Registrar at wholesale pricing with no markup) prior to purchase.
- **Confirmed Assets**:
  - Exact Owner Email: Confirm owner's primary email address (e.g., `owner@store.com`).
  - Target Hostname: Confirm dedicated subdomain (e.g., `monitor.store.com`).
  - Windows Store PC: Dedicated PC running Apache/PHP and MySQL/MariaDB Windows services.

---

## 2. Cloudflare Zero Trust Access Application Setup (Gate 1)

> [!CAUTION]
> Create and enforce the Cloudflare Access Application **BEFORE** publishing the tunnel route. Never expose an unauthenticated tunnel route to the public internet.

1. Navigate to **Cloudflare Zero Trust Dashboard** (`https://one.dash.cloudflare.com`).
2. Go to **Access** -> **Applications** -> **Add an Application** -> Select **Self-hosted**.
3. **Application Configuration**:
   - Application Name: `RetailMind Store Monitoring`
   - Application Domain: Enter confirmed subdomain and domain (e.g., `monitor` . `store.com`).
   - Session Duration: `24 hours` (or owner preference; matches unattended mobile monitoring needs).
4. **Identity Providers**:
   - Enable **One-time PIN (OTP)**.
   - Disable public social logins or alternative identity providers.
5. **Add Policy (The Owner Gate)**:
   - Policy Name: `Owner-Only Access`
   - Action: `Allow`
   - **Configure Rules**:
     - Rule Type: `Include`
     - Selector: `Emails`
     - Value: `<EXACT_OWNER_EMAIL>` (e.g. `owner@store.com`).
     - **Do NOT** use `Emails ending in`, `IP ranges`, or `Everyone`.
6. **Capture Settings**:
   - Under **Overview**, copy the **Application Audience (AUD) Tag**.
   - Note your **Team Name** (found in Zero Trust Settings -> Custom Pages or URL `https://<team-name>.cloudflareaccess.com`).

---

## 3. Store PC Configuration & Remote Ingress

### 3.1 Web Server Ingress Configuration (Apache / PHP)
Configure Apache to listen on a dedicated loopback port (e.g., `127.0.0.1:8081`):
```apache
Listen 127.0.0.1:8081

<VirtualHost 127.0.0.1:8081>
    DocumentRoot "C:/laragon/www/retailmind/src/frontend"
    ServerName monitor.your-store-domain.com

    SetEnv RETAILMIND_REMOTE_INGRESS 1
    SetEnv RETAILMIND_REMOTE_PORT 8081
    SetEnv RETAILMIND_REMOTE_HOST monitor.your-store-domain.com
    SetEnv RETAILMIND_REMOTE_OWNER_EMAIL owner@your-store-domain.com
    SetEnv RETAILMIND_CF_ACCESS_AUD <YOUR_AUD_TAG>
    SetEnv RETAILMIND_CF_ACCESS_TEAM <YOUR_TEAM_NAME>
    SetEnv RETAILMIND_REQUIRE_ACCESS_TOKEN 1

    <Directory "C:/laragon/www/retailmind/src/frontend">
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

### 3.2 Cloudflare Tunnel (`cloudflared`) Setup
1. Download `cloudflared-windows-amd64.exe` to `C:\Program Files\cloudflared\cloudflared.exe`.
2. Authenticate:
   ```cmd
   cloudflared tunnel login
   ```
3. Create named tunnel:
   ```cmd
   cloudflared tunnel create retailmind-monitor
   ```
4. Write configuration to `C:\ProgramData\cloudflared\config.yml` (using template from `deploy/cloudflare/config.example.yml`):
   ```yaml
   tunnel: <TUNNEL_UUID>
   credentials-file: C:\ProgramData\cloudflared\<TUNNEL_UUID>.json

   ingress:
     - hostname: monitor.your-store-domain.com
       service: http://127.0.0.1:8081
       originRequest:
         access:
           required: true
           teamName: <YOUR_TEAM_NAME>
           aud:
             - <YOUR_AUD_TAG>
     - service: http_status:404
   ```
5. Route DNS in Cloudflare:
   ```cmd
   cloudflared tunnel route dns retailmind-monitor monitor.your-store-domain.com
   ```
6. Install and start Windows Service:
   ```cmd
   cloudflared service install
   sc config cloudflared start= auto
   sc start cloudflared
   ```

---

## 4. Verification & Testing Procedure

Execute the automated verification script:
```powershell
php scripts/verify-cloudflare-monitoring.php --config="C:\ProgramData\cloudflared\config.yml"
```

### Manual Two-Gate Verification:
1. **Gate 1 (Cloudflare Access)**:
   - Browse to `https://monitor.your-store-domain.com`.
   - Cloudflare Access login screen appears requesting owner email.
   - Test unapproved email (e.g. `staff@store.com` or `intruder@gmail.com`): Access denied immediately; RetailMind login page is never reached.
   - Enter exact owner email (`owner@store.com`): Receive 6-digit one-time PIN in mailbox; submit PIN.
2. **Gate 2 (RetailMind Login)**:
   - RetailMind sign-in page displays under HTTPS.
   - Authenticate with owner administrator credentials.
   - Verify store status cards, cash flow, cashier shifts, and report analytics load.
   - Confirm export buttons (CSV, download) are omitted or disabled.
   - Direct requests to mutations, POS (`pos.php`), or backups return HTTP 403.
3. **Local Store Isolation**:
   - Verify local checkout on Store PC continues normally on `http://localhost/` (or port 80).
   - Simulate ISP outage by unplugging WAN: local checkout operates without internet; remote link gracefully pauses.

---

## 5. Emergency Revocation & Access Management

If the owner's phone or laptop is lost, stolen, or remote access must be revoked immediately:

1. **Instant Cloudflare Revocation (No Store PC access needed)**:
   - Log in to Cloudflare Dashboard -> **Zero Trust** -> **Access** -> **Applications**.
   - Select `RetailMind Store Monitoring` -> **Policies**.
   - Edit policy: remove owner email, or change action to **Block**, or delete the Application.
   - *Result*: Cloudflare edge instantly blocks all remote requests globally within seconds.
2. **Revoke Active Cloudflare User Sessions**:
   - Go to **Zero Trust** -> **My Team** -> **Users**.
   - Find owner user -> Click **Revoke Sessions**.
3. **Host-Side Tunnel Shutdown (On Store PC)**:
   - Open Command Prompt / PowerShell as Administrator:
     ```powershell
     sc stop cloudflared
     sc config cloudflared start= disabled
     ```
   - Stops outbound tunnel connection immediately.
4. **RetailMind Session Revocation**:
   - Increment `session_version` in RetailMind database or reset owner password:
     ```sql
     UPDATE users SET session_version = session_version + 1 WHERE role_id = (SELECT role_id FROM roles WHERE role_name = 'admin');
     ```
   - All existing active application sessions are invalidated instantly.

---

## 6. Repository Credential Safety Policy

- **Zero Credentials in Git**: Never commit `.env`, `config.yml`, `<TUNNEL_UUID>.json`, cert.pem, or production owner email addresses to git.
- **Tracked Artifacts**: Only templates (`config.example.yml`, `.env.remote.example`) and verification scripts remain tracked.
