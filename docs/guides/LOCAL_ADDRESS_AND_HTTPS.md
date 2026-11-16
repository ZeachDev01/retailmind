# Stable local address and trusted HTTPS

Use this guide to give the Store tablet one memorable RetailMind address that
survives a PC reboot and router reconnect, and to serve it over HTTPS the device
trusts without a certificate warning. The Store PC stays the only installation
and database host. Routine access stays inside the Store LAN: no public router
exposure, no domain purchase, and no tunnel dependency.

> **Verification status:** this procedure was derived from the current source
> tree and [the tablet access specification](../specs/tablet-layout-and-store-access-spec.md).
> It has not been executed on the live Store router, Store PC, or tablet as part
> of this work. The router model, its local DNS support, the hostname, and the
> tablet browser are still unknown. Record them in section 1 before choosing an
> option, and treat section 10 as the acceptance test.

Related: [fresh Windows installation](WINDOWS_LOCALHOST_INSTALL.md),
[home-screen installation](HOME_SCREEN_APP.md),
[encrypted tablet recovery](tablet-inventory-recovery.md),
[remote tunnel setup](STORE_PC_TUNNEL_SETUP.md).

## 1. Record the actual values first

Fill this table during setup. These are the facts the guide must not invent.
Replace every `<...>` placeholder in the commands below with the recorded value.

| Item | Actual value |
| --- | --- |
| Router make, model, firmware | |
| Router admin URL and who holds the password | |
| Store PC LAN adapter, IPv4 address, MAC address | |
| DHCP reservation (address and lease binding) | |
| Chosen hostname (for example `retailmind.home.arpa`) | |
| Local DNS mechanism: router host mapping / lease hostname / none | |
| Certificate mechanism and issuer | |
| Server certificate SHA-256 fingerprint and `Not After` | |
| Root CA SHA-256 fingerprint, `Not After`, and off-PC copy location | |
| Final HTTPS URL installed on the home-screen icon | |
| Tablet model, OS, browser version, trust result | |
| Dated backup location of the edited Apache config | |

`PLDT Fibr` is the internet service, not evidence of a router model or local DNS
support. Confirm both on the router before promising a hostname.

## 2. Confirm the current direct access

1. On the Store PC, open the current local URL and sign in.
2. From the tablet, open the current LAN address
   (`http://<store-pc-ip>/retailmind/` or the root vhost URL). Sign in.
3. Note that encrypted inventory draft recovery is unavailable over plain HTTP;
   that is expected until section 7 passes.

This baseline proves the tablet can already reach Apache. If it cannot, fix
Windows Firewall or router client isolation first, before adding HTTPS.

## 3. Reserve the Store PC address

1. On the Store PC, record the adapter's physical address:

   ```powershell
   ipconfig /all
   getmac /v /fo list
   ```

2. In the router's DHCP client or static-lease screen, bind that MAC address to
   the current IPv4 address. Use the same address, not a new one, so existing
   bookmarks keep working.
3. If the reservation screen offers a hostname or device-name field, set the
   chosen hostname there. That field is often the only local name resolution a
   consumer router provides.
4. Reboot the Store PC, then confirm the address is unchanged:

   ```powershell
   ipconfig | Select-String "IPv4"
   ```

Record the result. Without a reservation, DHCP can hand out a different address
after a reboot and the icon stops working.

## 4. Choose the hostname and its resolver

Pick one hostname and record it. Do not use `.local`: that suffix belongs to
mDNS and can conflict with Apple devices. `.home.arpa` is reserved for home
networks; a router-provided domain is also fine.

Then choose exactly one resolver option:

| Option | Requirement | Result |
| --- | --- | --- |
| A. Router local DNS | Router offers a host/DNS mapping or a lease hostname field | Hostname works on every LAN device, including tablets that cannot edit a hosts file |
| B. No local DNS available | Router offers neither | Keep the reserved numeric IP as the stable address |

Option B is a real possibility. A reserved IP removes the `ipconfig` hunt and
still works after reboot, but it is not memorable and no tablet can edit a hosts
file without root or jailbreak. If B applies, record it as an open gap against
the "memorable address" requirement rather than claiming a hostname exists.
Adding a new always-on DNS service is not part of this ticket.

Verify the chosen option from the Store PC:

```powershell
nslookup <hostname>
nslookup <hostname> <router-ip>
```

Both must return the reserved Store PC address. If only the second succeeds,
the router resolves the name but the PC uses another DNS server; fix the PC's
DNS setting or revisit option A before continuing.

## 5. Choose the certificate mechanism

A public certificate authority cannot issue for a LAN-only name without a real
domain, so the tablet must trust a private root CA once. Two mechanisms fit:

| Mechanism | Trade-off |
| --- | --- |
| `mkcert` local CA (recommended) | One tool install; issues PEM pairs Apache reads directly; root trust installed on Windows with one command |
| Windows `New-SelfSignedCertificate` | No extra tool, but the PFX must be converted to PEM and the root exported by hand |

Whichever you choose, the leaf certificate must list the exact hostname used on
the tablet in its Subject Alternative Name. Add the reserved IP as a second SAN
if anyone will open the HTTPS URL by IP. Record the mechanism in section 1.

## 6. Create the certificate (mkcert path)

Run in an elevated PowerShell on the Store PC:

```powershell
winget install FiloSottile.mkcert
mkcert -install
mkcert -cert-file C:\xampp\apache\conf\ssl\retailmind-cert.pem -key-file C:\xampp\apache\conf\ssl\retailmind-key.pem <hostname> <reserved-ip>
mkcert -CAROOT
```

The last command prints the folder holding `rootCA.pem`. Copy `rootCA.pem` to
the off-PC location recorded in section 1; the tablet trust step needs it, and
renewal needs the same root to stay valid.

`mkcert -install` trusts the root in the Windows certificate store, so the Store
PC itself no longer warns. The tablet needs its own trust step (section 8).

Renewal: an mkcert leaf is valid for about two years and three months. Check the
date now and add a calendar reminder:

```powershell
C:\xampp\apache\bin\openssl.exe x509 -enddate -noout -in C:\xampp\apache\conf\ssl\retailmind-cert.pem
certutil -hashfile C:\xampp\apache\conf\ssl\retailmind-cert.pem SHA256
```

Record the root CA fingerprint too:

```powershell
C:\xampp\apache\bin\openssl.exe x509 -in "$(mkcert -CAROOT)\rootCA.pem" -noout -fingerprint -sha256 -enddate
```

Renew by re-running the `mkcert` command with the same hostname and IP, then
restarting Apache. The root CA does not change, so trusted devices stay trusted.
There is no automatic renewal; the reminder is the renewal arrangement.

If mkcert cannot be installed, use the Windows-native alternative in an elevated
PowerShell. The SAN text extension is required for an IP address: a DNS entry
holding an IP string does not satisfy a browser that opens the URL by IP.

```powershell
$cert = New-SelfSignedCertificate -Subject "CN=<hostname>" `
  -TextExtension @("2.5.29.17={text}DNS=<hostname>&IPAddress=<reserved-ip>") `
  -CertStoreLocation Cert:\LocalMachine\My -FriendlyName "RetailMind LAN"
$cert | Export-PfxCertificate -FilePath C:\xampp\apache\conf\ssl\retailmind.pfx -Password (Read-Host -AsSecureString "PFX password")
Export-Certificate -Cert $cert -FilePath C:\xampp\apache\conf\ssl\retailmind-root.cer
# Convert to PEM for Apache; enter the PFX password when prompted.
C:\xampp\apache\bin\openssl.exe pkcs12 -in C:\xampp\apache\conf\ssl\retailmind.pfx -clcerts -nokeys -out C:\xampp\apache\conf\ssl\retailmind-cert.pem
C:\xampp\apache\bin\openssl.exe pkcs12 -in C:\xampp\apache\conf\ssl\retailmind.pfx -nocerts -nodes -out C:\xampp\apache\conf\ssl\retailmind-key.pem
```

Import `retailmind-root.cer` into **Trusted Root Certification Authorities** on
the Store PC and install it as a trusted root on the tablet. The same renewal
and rollback steps apply.

## 7. Serve HTTPS from Apache

1. Back up the configuration before editing:

   ```powershell
   Copy-Item C:\xampp\apache\conf\httpd.conf C:\xampp\apache\conf\httpd.conf.bak-<date>
   Copy-Item C:\xampp\apache\conf\extra\httpd-ssl.conf C:\xampp\apache\conf\extra\httpd-ssl.conf.bak-<date>
   ```

2. In `C:\xampp\apache\conf\httpd.conf`, confirm these lines are uncommented:

   ```apache
   LoadModule ssl_module modules/mod_ssl.so
   Include conf/extra/httpd-ssl.conf
   ```

3. In `C:\xampp\apache\conf\extra\httpd-ssl.conf`, confirm the file already has
   `Listen 443`, then replace its default 443 virtual host with one for the app.
   Keep the existing DocumentRoot shape (subfolder or root) that already works
   on port 80:

   ```apache
   <VirtualHost *:443>
       ServerName <hostname>
       DocumentRoot "C:/xampp/htdocs/retailmind"
       SSLEngine on
       SSLCertificateFile "C:/xampp/apache/conf/ssl/retailmind-cert.pem"
       SSLCertificateKeyFile "C:/xampp/apache/conf/ssl/retailmind-key.pem"
       <Directory "C:/xampp/htdocs/retailmind">
           Options FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   That example serves the app at the site root. For a subfolder shape, set
   `DocumentRoot` to the parent folder and use `https://<hostname>/retailmind/`.

4. Validate and restart:

   ```powershell
   C:\xampp\apache\bin\httpd.exe -t
   ```

   Restart Apache from the XAMPP control panel. `httpd.exe -t` must report
   `Syntax OK`; a config error stops Apache and takes local checkout down, so do
   not restart until it passes.

5. Allow the port on the private network only:

   ```powershell
   New-NetFirewallRule -DisplayName "RetailMind HTTPS (LAN)" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow -Profile Private
   ```

   Check that the LAN adapter's network profile is `Private`
   (`Get-NetConnectionProfile`). The rule does not apply on a `Public` profile.

6. Verify from the Store PC:

   ```powershell
   curl.exe -sS -o NUL -w "%{http_code}`n" https://<hostname>/
   "" | C:\xampp\apache\bin\openssl.exe s_client -connect <hostname>:443 -servername <hostname> 2>&1 | Select-String "subject=|issuer=|Verify return code"
   ```

   Expect `200` and `Verify return code: 0 (ok)`.

The application needs no URL edit. `app_url()` derives the base path from the
request, so the same checkout serves a subfolder URL and a root vhost. Do not
add the hostname to `APP_URL`; it is not the source of generated links.

Session cookie behaviour: when `SESSION_SECURE_COOKIE` is absent, the app marks
the cookie secure only for HTTPS requests (`src/backend/app/Core/Session.php`).
Leave the key out while the Store PC keeps using plain HTTP localhost alongside
HTTPS LAN. Set it to `true` only when every route is HTTPS; setting it true while
plain HTTP is still in use breaks that sign-in. Confirm in the browser devtools
that the session cookie is marked `Secure` on the HTTPS address.

## 8. Trust the root on each tablet

Install the root CA on every device that will use the HTTPS address. This is the
only per-device step; the app is unchanged.

**iPad/iPhone (Safari or Chrome):**

1. Transfer `rootCA.pem` to the device (AirDrop, email attachment, or a
   temporary download from the Store PC while on the same LAN).
2. Open the file and install the profile: **Settings → Profile Downloaded →
   Install**.
3. Enable trust: **Settings → General → About → Certificate Trust Settings**,
   then switch on full trust for the RetailMind root. Without this step the
   certificate still shows as untrusted.
4. On an older iOS, add the home-screen icon from **Safari**; Chrome on iOS
   cannot install a standalone web app on those versions.

**Android (Chrome):**

1. Copy `rootCA.pem` to the device and open it, or use **Settings → Security →
   Encryption & credentials → Install a certificate → CA certificate**.
2. Accept the "your network may be monitored" notice; it is expected for a
   private CA.
3. Use Chrome for both the site and the home-screen icon.

Then open `https://<hostname>/` on the tablet. Expect a padlock, no warning
page, and a successful sign-in. If a warning remains, the profile install or the
full-trust switch did not apply; do not use "proceed anyway" as acceptance.

Delete `rootCA.pem` from any temporary web-accessible location after install.

## 9. Install the home-screen icon on the final address

The manifest already uses origin-relative values (`start_url`, `scope`, and
`id` are all `./`), so no file change is needed: the icon binds to whichever
origin it is installed from, including this HTTPS hostname and subfolder.

1. On the tablet, open `https://<hostname>/` (or `https://<hostname>/retailmind/`).
2. Add it: Chrome **Install app / Add to Home screen**, or Safari **Share → Add
   to Home Screen**.
3. Launch the icon. The address bar stays hidden and the app loads over the same
   HTTPS origin. If an older shortcut exists, remove it first and re-add.
4. Record the final URL in section 1.

If the hostname ever changes, delete and re-add the icon. Never hardcode a
guessed hostname into the manifest: the existing browser test asserts that
`start_url`, `scope`, and `id` stay origin-relative at both deployment depths
(`node src/backend/tests/home_screen_app_browser_test.js`). That test needs
Playwright installed and a local PHP binary; a documentation review does not
replace running it.

## 10. Verification matrix

Run these on the actual devices. A documentation review is not acceptance.

| Check | Command or action | Expected |
| --- | --- | --- |
| Address survives reboot | Reboot the Store PC and router, then open the icon | Same HTTPS URL, sign-in works |
| Name resolves on LAN | `nslookup <hostname>` on a second PC | Reserved Store PC address |
| Certificate chain | `curl.exe -sS -o NUL -w "%{http_code}`n" https://<hostname>/` on the Store PC | `200`, no certificate error |
| Tablet trust | Open the HTTPS URL on the tablet | Padlock, no warning, sign-in works |
| Draft recovery | Inventory Manager: type into an inventory form, wait for the "Unsaved values kept" status, reload | **Resume** offered; status shows values kept |
| Home-screen icon | Launch the icon | Standalone view, no address bar, same origin |
| Internet outage | Disconnect WAN, keep router, PC, and Apache running | Icon and all local HTTPS access still work |
| Existing access preserved | Open localhost HTTP on the Store PC and the old LAN address | Still load; no forced redirect to the public domain |
| Remote access unaffected | Start or stop the tunnel as before | Public route unchanged; LAN route unaffected |

The last two rows prove the new HTTPS vhost did not replace the direct-PC or
remote arrangements. Local requests must not be forced to a public domain.

## 11. Renewal and rollback

Renewal, in order:

1. Check `Not After` from section 6 at least 30 days before expiry.
2. Re-run the certificate command with the same hostname and IP.
3. `C:\xampp\apache\bin\httpd.exe -t`, then restart Apache.
4. Re-open the icon on the tablet; no tablet change is needed because the root
   CA is unchanged.

Rollback, in order, if HTTPS blocks Store work:

1. Restore the dated backups of `httpd.conf` and `httpd-ssl.conf`, run
   `httpd.exe -t`, and restart Apache. Plain HTTP LAN access returns.
2. Remove the firewall rule:
   `Remove-NetFirewallRule -DisplayName "RetailMind HTTPS (LAN)"`.
3. Remove the home-screen icon from the tablet and re-add it from the old URL if
   it is still needed.
4. Restore the previous `SESSION_SECURE_COOKIE` value if it was changed.
5. Optionally clean the certificate and trust: `mkcert -uninstall` on the Store
   PC, and remove the RetailMind root from the tablet's trust settings.
6. Remove the DHCP reservation in the router if the old dynamic address must
   return.

Rollback changes no application files, database records, backups, or remote
access settings. Both directions are reversible without reinstalling RetailMind.

## 12. What still needs the live network and devices

- The actual router model, firmware, and whether it offers local DNS at all.
- The actual hostname, reserved address, certificate fingerprints, and expiry.
- Trust installation and the padlock on the real tablet, in its real browser.
- The home-screen icon launching standalone on that tablet.
- Behaviour after a real router reconnect and after an internet outage.
- Draft Resume on the real device, with the on-screen keyboard open.
- Whether the old tablet browser supports the complete workspace functions;
  apply the agreed browser/OS update, then newer-device fallback, if it does not.

Record the observed result for every row. Do not report this guide as a
completed setup.
