# One-paste Remote Access acceptance checklist

Manual Windows acceptance for the generated Store PC setup block (#458). CI cannot install Windows services, so record the outcome here after running on the live Store PC.

## Preconditions

- [ ] RetailMind runs locally under XAMPP and the login page loads at the configured local port.
- [ ] The operator is signed in as Super Administrator on the Store PC.
- [ ] The Cloudflare token and hostname fields are either both saved (permanent) or both blank (interim Quick Tunnel).

## Non-elevated shell

- [ ] Paste the generated setup block into a normal PowerShell window.
- [ ] The block exits immediately and asks the operator to reopen PowerShell as Administrator.
- [ ] No service, scheduled task, binary, or tunnel configuration is created.

## Elevated shell - permanent mode

- [ ] Save a token and hostname, generate the block, and paste it into an elevated PowerShell.
- [ ] Pre-flight passes: elevation, 64-bit Windows, internet access, local origin.
- [ ] `cloudflared.exe` is provisioned in `src/backend/storage/bin`.
- [ ] `src/backend/storage/tunnel/tunnel_config.json` contains the saved token and hostname.
- [ ] `RetailMindCloudflareTunnel` reaches `RUNNING`.
- [ ] `sc.exe qc RetailMindCloudflareTunnel` shows automatic startup.
- [ ] `sc.exe qfailure RetailMindCloudflareTunnel` shows restart actions at 5s, 10s, and 20s.
- [ ] The legacy `RetailMindQuickTunnel` scheduled task is gone.
- [ ] The card reports the permanent hostname after a refresh.
- [ ] The public hostname loads RetailMind over HTTPS and the owner-only Access policy is enforced.

## Elevated shell - interim Quick Tunnel

- [ ] With no saved token, generate the block and paste it into an elevated PowerShell.
- [ ] The block and the card both label the mode as interim, URL-changing, and without Cloudflare Access.
- [ ] `RetailMindCloudflareTunnel` reaches `RUNNING`.
- [ ] A `https://*.trycloudflare.com` URL appears in the block output and in the card status.
- [ ] Restarting the service produces a different Quick Tunnel URL.
- [ ] RetailMind sign-in and read-only remote restrictions still apply.

## Reboot and repair

- [ ] Reboot the Store PC.
- [ ] `RetailMindCloudflareTunnel` returns to `RUNNING` without a Windows login.
- [ ] Re-run the same setup block; it stops, deletes, and recreates the service successfully.
- [ ] Changing the saved token and regenerating the block applies the new token.

## Removal

- [ ] Generate and run the removal block in an elevated PowerShell.
- [ ] `RetailMindCloudflareTunnel` is stopped and deleted.
- [ ] The legacy `RetailMindQuickTunnel` scheduled task is removed.
- [ ] The application, database, backups, and local checkout still work.

## Offline behavior

- [ ] Disconnect the Store PC from the internet.
- [ ] Local checkout and printing still work.
- [ ] Remote access is unavailable, as expected for a single-PC deployment.
