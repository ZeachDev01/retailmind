# Windows Service Setup, Startup Verification & Outage Recovery Runbook

Governed by Spec #318 (Issue #324) and local Windows deployment #271.

---

## 1. Standalone Windows Services Installation

Configure services to launch automatically at boot under Windows Service Control Manager (`sc.exe`), independent of Laragon or interactive desktop sessions.

### 1.1 MariaDB / MySQL Service (Database Engine)
Install and configure as root service:
```cmd
:: From MariaDB/MySQL bin directory (e.g. C:\laragon\bin\mysql\mariadb-10.x\bin):
mysqld.exe --install MySQL --defaults-file="C:\laragon\bin\mysql\mariadb-10.x\my.ini"
sc.exe config MySQL start= auto
sc.exe failure MySQL reset= 86400 actions= restart/5000/restart/10000/restart/60000
sc.exe start MySQL
```

### 1.2 Apache HTTP Server 2.4 Service (Web Ingress)
Install and bind dependency to `MySQL`:
```cmd
:: From Apache bin directory (e.g. C:\laragon\bin\apache\httpd-2.4.x\bin):
httpd.exe -k install -n "Apache2.4"
sc.exe config Apache2.4 start= auto depend= MySQL
sc.exe failure Apache2.4 reset= 86400 actions= restart/5000/restart/10000/restart/60000
sc.exe start Apache2.4
```

### 1.3 Cloudflare Tunnel Service (`cloudflared`)
Install daemon and bind dependency to `Apache2.4`:
```cmd
:: From cloudflared directory (e.g. C:\Program Files\cloudflared):
cloudflared.exe service install
sc.exe config cloudflared start= auto depend= Apache2.4
sc.exe failure cloudflared reset= 86400 actions= restart/5000/restart/10000/restart/60000
sc.exe start cloudflared
```

---

## 2. Post-Reboot Verification Checklist

Verify unattended operation after a cold boot:

1. **Reboot Windows Host**:
   ```cmd
   shutdown /r /t 0
   ```
2. **Confirm Services Without Desktop Login**:
   Once Windows boots to the login screen, open PowerShell from another LAN machine or log in as normal user (do **NOT** launch Laragon):
   ```powershell
   Get-Service MySQL, Apache2.4, cloudflared | Select-Object Name, Status, StartType
   ```
   - Target status: all 3 services show `Status: Running`, `StartType: Automatic`.
3. **Verify Local Checkout**:
   - Open browser on Store PC: `http://localhost/components/cashier/pos.php`.
   - POS opens immediately without popup or manual server start.
4. **Verify Owner Remote Monitoring**:
   - Open browser on phone/laptop: `https://monitor.your-store-domain.com`.
   - Cloudflare Access login screen displays requesting owner email PIN.
   - Enter owner email, submit PIN, and verify dashboard loads with Store overview and shifts.

---

## 3. ISP Disconnect & Outage Test Procedure

Verify store checkout operates 100% offline while remote route fails closed:

1. **Disconnect ISP / WAN**:
   - Unplug the WAN Ethernet cable from router, or disable the external WAN interface.
   - Leave the Store PC powered on and local LAN connected.
2. **Verify Local Checkout**:
   - On Store PC browser, navigate to `http://localhost/components/cashier/pos.php`.
   - Scan barcode or select item into cart.
   - Tender cash payment and complete checkout.
   - Verify sale is saved to local database (`sales` table).
   - Verify Sale Receipt prints to physical thermal printer.
   - Confirm: zero network errors or hangs on the cash register.
3. **Verify Remote Route Failure**:
   - From phone (on cellular data): refresh `https://monitor.your-store-domain.com`.
   - Expected response: HTTP 530 / 502 Bad Gateway (Cloudflare Tunnel Error 1033).
   - Confirm: remote ingress fails closed immediately; no partial data or unprotected views leaked.

---

## 4. Connectivity Restoration & Host Shutdown

### 4.1 Connectivity Restoration
1. **Reconnect ISP / WAN**:
   - Re-plug Ethernet cable / re-enable WAN interface.
2. **Verify Automatic Tunnel Recovery**:
   - Observe `cloudflared` logs in Event Viewer or `C:\ProgramData\cloudflared\cloudflared.log`.
   - Tunnel automatically reconnects to Cloudflare edge within 15–30 seconds.
   - Do **NOT** restart Apache or MySQL (services maintain uptime and active sessions).
3. **Verify Remote Monitoring Resumes**:
   - Refresh phone browser at `https://monitor.your-store-domain.com`.
   - Monitoring view reloads successfully under Access session.

### 4.2 Host PC Shutdown
1. **Power Off Store PC**:
   ```cmd
   shutdown /s /t 0
   ```
2. **Verify Remote Behavior**:
   - Remote link immediately returns HTTP 530 / 502 from Cloudflare edge.
   - Zero access available while host PC is powered off.

---

## 5. Dependency & Recovery Matrix

| Component | Depends On | Outage Mode | Impact on Local Checkout | Impact on Remote Monitoring | Recovery Action |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Store PC Hardware / Power** | Mains Power / UPS | Power Loss | Register down; use manual paper receipts | Stops immediately (HTTP 530/502 at edge) | Restore power / restart PC; services auto-start via SCM |
| **MariaDB / MySQL Service** | Local Filesystem / Disk | Service Crash / Disk Full | Blocked; transactions require local database | Blocked; monitoring reports database error | Restart service: `sc.exe start MySQL`; clear disk space |
| **Apache2.4 Service** | MySQL Service, Loopback port 80 & 8081 | Service Stop / Config Error | Local web app unreachable | Remote route returns HTTP 502 Bad Gateway | Restart service: `sc.exe start Apache2.4`; check `error.log` |
| **cloudflared Service** | Apache2.4 (port 8081), Local network stack | Service Stop | **Zero impact** (local checkout runs on port 80) | Remote route returns HTTP 530 Tunnel Unavailable | Restart service: `sc.exe start cloudflared`; check credentials |
| **Store ISP / WAN Link** | Router / ISP modem | Fiber cut / ISP downtime | **Zero impact** (local checkout runs 100% offline) | Fails closed (HTTP 530); no access leaked | Wait for ISP restoration; tunnel auto-reconnects without restart |
| **Cloudflare Edge / Zero Trust** | Cloudflare Network | Global / Regional outage | **Zero impact** (local checkout runs 100% offline) | Remote monitoring unreachable | Wait for Cloudflare network restoration |
| **Thermal Printer / Barcode Scanner** | USB / COM port on Store PC | Disconnected cable | Cashier keys barcodes manually; queue receipts | Zero impact on remote monitoring | Reconnect USB cable / check paper roll |

---

## 6. Automated Verification

Execute the automated test suite:
```powershell
# 1. Run contract test:
php src/backend/tests/windows_service_outage_contract.php

# 2. Run simulation & environment verification:
php scripts/verify-windows-startup-outage.php

# 3. On live Windows deployment with services registered:
php scripts/verify-windows-startup-outage.php --check-services
```
