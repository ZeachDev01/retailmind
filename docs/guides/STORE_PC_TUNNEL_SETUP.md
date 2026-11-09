# Store PC Remote Tunnel Setup

See the complete 5-minute setup guide at [docs/DEPLOYMENT_REMOTE_TUNNEL.md](../DEPLOYMENT_REMOTE_TUNNEL.md).

The recommended path is **Platform Settings &rarr; Remote Access &rarr; Generate Store PC setup block**. Copy the generated block into an elevated PowerShell on the Store PC once; it installs or repairs the service and removes the legacy Quick Tunnel task.

For manual script reference on dedicated Store PCs:
```powershell
# Permanent named tunnel
powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_install.ps1 -Token "<YOUR_TOKEN>" -Hostname "supervisor.mystore.com"

# Interim Quick Tunnel (no domain required; URL changes on restart)
powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_install.ps1 -Mode Quick
```
Service starts automatically at boot with auto-restart on unexpected crashes.
