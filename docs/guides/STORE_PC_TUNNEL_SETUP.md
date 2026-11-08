# Store PC Remote Tunnel Setup

See the complete 5-minute setup guide at [docs/DEPLOYMENT_REMOTE_TUNNEL.md](../DEPLOYMENT_REMOTE_TUNNEL.md).

For quick reference on dedicated Store PCs:
```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\tunnel\service_install.ps1 -Token "<YOUR_TOKEN>"
```
Service starts automatically at boot with auto-restart on unexpected crashes.
