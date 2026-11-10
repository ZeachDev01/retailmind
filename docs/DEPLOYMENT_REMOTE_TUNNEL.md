# Dedicated Store PC Remote Access Tunnel Deployment Guide

Quick 5-minute setup guide to configure a permanent Cloudflare Zero Trust tunnel with resilient Windows Service persistence for RetailMind on dedicated Store PCs.

For the current **free ngrok demo → optional Cloudflare custom domain** flow, follow [Store PC setup](guides/STORE_PC_TUNNEL_SETUP.md). Use the generated Remote Access blocks for verification and rollback; the manual Cloudflare reference below assumes a domain is already available. See [ADR-0031](adr/0031-free-ngrok-demo-with-cloudflare-domain-upgrade.md).

---

## Overview

RetailMind Remote Access lets authorized staff use the same Workspaces and
capabilities they have on the Store PC from a smartphone or offsite laptop,
without requiring:
- Inbound router port forwarding (zero open ports)
- Static public IP addresses
- Complex VPN client software

Tunnel sessions for `admin`, `inventory_manager`, and `cashier` keep full
localhost parity, including writes, exports, and receipt printing. The Super
Administrator Workspace stays local-only. See
[ADR-0030](adr/0030-tunnel-sessions-have-full-workspace-parity.md).

---

## 5-Minute Setup Walkthrough

### Step 1: Create Tunnel in Cloudflare Zero Trust (2 mins)

1. Sign in to the [Cloudflare Zero Trust Dashboard](https://one.dash.cloudflare.com/).
2. Navigate to **Networks** &rarr; **Tunnels** &rarr; **Add a tunnel**.
3. Select **Cloudflare Tunnel** and enter a recognizable name (e.g., `store-manila-01`).
4. In the installation steps, select **Windows 64-bit** and copy the **tunnel token** from the command snippet:
   ```text
   cloudflared.exe service install <COPIED_TOKEN_HERE>
   ```
   *(Copy only the long `eyJh...` token string).*
5. Click **Next**, then add a **Public Hostname**:
   - **Subdomain / Domain**: `supervisor.yourstore.com` (or your chosen hostname)
   - **Service Type**: `HTTP`
   - **URL**: `127.0.0.1:80` (or `localhost:80`)
6. Save the tunnel configuration in Cloudflare.

---

### Step 2: Configure & Install on Store PC (2 mins)

Choose the **one-paste setup block** (recommended), the **Automated Script**, or the **Store Configuration UI**.

#### Option A: One-paste Setup Block (Recommended)

1. On the Store PC, open RetailMind as **Super Administrator** &rarr; **Platform Settings** &rarr; **Remote Access**.
2. For a permanent named tunnel, save the tunnel token and public hostname. To use the interim Quick Tunnel while no domain exists, leave the token blank.
3. Click **Generate Store PC setup block**, then **Copy block**.
4. Open **PowerShell as Administrator** on the same Store PC, paste the whole block, and press Enter.

The block checks elevation, 64-bit Windows, internet access, and the local RetailMind origin before changing anything. It then provisions `cloudflared`, persists the token, removes the legacy Quick Tunnel scheduled task, registers the `RetailMindCloudflareTunnel` service with automatic startup and crash recovery, starts it, and prints the resulting state.

Re-run the same block to repair the service or apply a changed token. Use **Generate removal block** to stop and delete the service and the legacy scheduled task without deleting the application, database, or backups.

> **Interim Quick Tunnel:** with no saved token, the URL changes on every restart and Cloudflare Access is not in front of it. RetailMind sign-in and each account's existing Workspace permissions still apply. Save a token and hostname to move to a permanent named tunnel.

#### Option B: Automated Windows Service Script

Open **PowerShell as Administrator** on the Store PC and run:

```powershell
Set-Location "C:\xampp\htdocs\retailmind"
powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_install.ps1 -Token "<YOUR_COPIED_TOKEN>"
```

*Or double-click `scripts\tunnel\service_install.bat` and pass the token.*

This single command automatically:
1. Provisions the self-contained portable `cloudflared.exe` into `src/backend/storage/bin/`. The binary is downloaded from the official Cloudflare GitHub release when absent; it is not committed and a fresh clone does not contain it.
2. Stores the permanent token safely in `src/backend/storage/tunnel/tunnel_config.json`.
3. Registers the Windows Service `RetailMindCloudflareTunnel` configured with:
   - **Automatic startup at boot (`start= auto`)**: Launches before user login when PC turns on.
   - **Crash auto-recovery (`sc.exe failure`)**: Automatically restarts after 5s, 10s, and 20s if interrupted.
4. Starts the tunnel service immediately.

#### Option C: Store Configuration Web UI

1. Open RetailMind in a browser on the Store PC: `http://localhost/retailmind`
2. Log in as **Administrator** &rarr; Go to **Store Configuration** (`store_settings.php`).
3. Scroll to the **Remote Access** section.
4. Under **Permanent Tunnel Token Configuration**:
   - Paste the token into the **Permanent Tunnel Token** field.
   - Enter your public domain in **Public Domain / Hostname** (e.g. `supervisor.yourstore.com`).
   - Click **Save Tunnel Token**.
5. Switch the **Enable Cloudflare Tunnel** toggle to ON.

---

### Step 3: Verification (1 min)

1. Verify the service is running on the Store PC:
   ```powershell
   sc.exe query RetailMindCloudflareTunnel
   ```
   *State should show `RUNNING`.*

2. Alternatively, check status using the tunnel runner:
   ```powershell
   powershell.exe -File .\scripts\tunnel\run_tunnel.ps1 -Action status
   ```

3. From an external phone, tablet, or laptop (disconnected from store Wi-Fi), open:
   ```text
   https://supervisor.yourstore.com
   ```
4. Verify the RetailMind login page loads with valid HTTPS and sign in with an authorized account.

---

## Windows Service Management

| Operation | Command |
|---|---|
| **Check service state** | `sc.exe query RetailMindCloudflareTunnel` |
| **Inspect live logs** | `Get-Content .\src\backend\storage\tunnel\tunnel.log -Tail 50 -Wait` |
| **Restart service** | `powershell.exe -File .\scripts\tunnel\run_tunnel.ps1 -Action restart` |
| **Uninstall service** | `powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_uninstall.ps1` |

---

## Resilience & Boot Recovery Details

- **Boot Persistence**: The service is registered with `start= auto`. When the Store PC boots up after power restoration, Windows starts `RetailMindCloudflareTunnel` as a system daemon without requiring anyone to log into Windows.
- **Fault Recovery**: Windows Service Control Manager is configured with:
  ```powershell
  sc.exe failure RetailMindCloudflareTunnel reset= 86400 actions= restart/5000/restart/10000/restart/20000
  ```
  If an edge network glitch or system glitch halts the process, Windows automatically restarts it within seconds.
- **State Cleaning**: If an unexpected machine reboot leaves a stale PID file, `CloudflareTunnelService` detects the inactive PID on boot and heals its state automatically.

---

## Troubleshooting

| Symptom | Cause & Solution |
|---|---|
| **502 Bad Gateway on public domain** | Local Apache/PHP web server is down. Ensure Apache is running in XAMPP on port 80. |
| **Service creation fails with Error 5: Access Denied** | The installation script must be run from an elevated command prompt (**Run as Administrator**). |
| **Tunnel state shows offline after reboot** | Confirm the Store PC has internet connectivity. Test DNS with `ping 1.1.1.1`. |
| **Token revoked or changed** | Re-run `service_install.ps1 -Token "<NEW_TOKEN>"` or update via Store Configuration. |
| **Switch back to ephemeral quick tunnel** | Click **Clear Token** in Store Configuration or run `service_uninstall.ps1`. |
