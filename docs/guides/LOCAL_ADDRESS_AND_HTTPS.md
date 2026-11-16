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
| Chosen hostname and application host-policy result | |
| Local DNS mechanism: router host mapping / lease hostname / none | |
| Certificate mechanism and issuer | |
| Server certificate SHA-256 fingerprint and `Not After` | |
| Root CA SHA-256 fingerprint, `Not After`, public copy and protected CA-key custody | |
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
3. Inspect whether a hostname field actually creates a local DNS record. A
   device label alone does not prove name resolution; verify section 4.
4. Reboot the Store PC, then confirm the address is unchanged:

   ```powershell
   ipconfig | Select-String "IPv4"
   ```

Record the result. Without a reservation, DHCP can hand out a different address
after a reboot and the icon stops working.

## 4. Choose the hostname and its resolver

Do not select a name from router capability alone. Current
`src/backend/bootstrap/app.php::is_remote_request()` recognizes private IPs,
`.local`, and `.localhost` as local; an arbitrary router name or `.home.arpa`
name is classified as remote monitoring and blocks normal inventory writes.
`DATABASE_ENV=local` chooses the database; it does not override that policy.
Do not add a LAN hostname to tunnel metadata or disable remote restrictions.

`.local` is an mDNS namespace: use it only if resolution from the actual tablet
and a second LAN device is verified, including without internet. `.localhost`
is unsuitable for reaching another computer. A `.home.arpa` name requires a
separately reviewed, narrowly scoped application host-policy change before it
can serve the required Workspaces. No hostname is selected by this guide.

Then choose exactly one resolver option:

| Option | Requirement | Result |
| --- | --- | --- |
| A. Verified local name resolution | Router mapping or existing mDNS mechanism resolves the same name on both devices; application policy permits it | Candidate memorable address; still needs HTTPS and role checks |
| B. No local DNS available | Router offers neither | Keep the reserved numeric IP as the stable address |

Option B is a real possibility. A reserved IP removes the `ipconfig` hunt and
still works after reboot, but it is not memorable and no tablet can edit a hosts
file without root or jailbreak. If B applies, record it as an open gap against
the "memorable address" requirement rather than claiming a hostname exists.
If neither an existing resolver nor router capability can meet this requirement,
record that setup gap and agree the smallest supported resolver before deployment.
Do not silently introduce a new service.

Verify the chosen option from the Store PC:

```powershell
nslookup <hostname>
nslookup <hostname> <router-ip>
```

For router DNS, both must return the reserved Store PC address. `nslookup` does
not prove mDNS resolution; verify the actual browser route for an mDNS candidate.
If only the second succeeds,
the router resolves the name but the PC uses another DNS server; fix the PC's
DNS setting or revisit option A before continuing.

## 5. Choose the certificate mechanism

For the LAN-only setup, evaluate a private CA the tablet can explicitly trust.
The example below uses `mkcert`, a separate deployment tool, not an application
dependency. It is an unexecuted candidate; confirm its installation and key
custody with the real operator before adopting it. The leaf must list the hostname used on
the tablet in its Subject Alternative Name. Add the reserved IP as a second SAN
if anyone will open the HTTPS URL by IP. Record the mechanism in section 1.

## 6. Create the certificate (mkcert path)

After a compatible hostname and mechanism have been recorded, obtain `mkcert`
from its [official installation instructions](https://github.com/FiloSottile/mkcert#installation).
Run it as the designated certificate custodian on the Store PC; keep the same
Windows account and CA directory for renewal. Create the output directory outside
the web root, then issue the certificate:

```powershell
New-Item -ItemType Directory -Path C:\xampp\apache\conf\ssl -Force
mkcert -install
mkcert -cert-file C:\xampp\apache\conf\ssl\retailmind-cert.pem -key-file C:\xampp\apache\conf\ssl\retailmind-key.pem <hostname> <reserved-ip>
mkcert -CAROOT
```

The last command prints the CA directory. Only `rootCA.pem` is distributed to
devices. Renewal also requires its matching `rootCA-key.pem`: keep that private
key outside the web root, restrict access to the custodian, and record protected
off-PC custody. Never copy the CA key or server key to tablets, the repo, a
download page, or routine shared storage. Loss of the CA key requires a new CA
and renewed device trust; compromise requires revoking that trust on every device.
Restrict the server-key file to the custodian and Apache's actual runtime account.

`mkcert -install` trusts the root in the Windows certificate store, so the Store
PC itself no longer warns. The tablet needs its own trust step (section 8).

Use the actual expiry, not an assumed lifetime, and add a calendar reminder:

```powershell
C:\xampp\apache\bin\openssl.exe x509 -enddate -noout -in C:\xampp\apache\conf\ssl\retailmind-cert.pem
C:\xampp\apache\bin\openssl.exe x509 -in C:\xampp\apache\conf\ssl\retailmind-cert.pem -noout -fingerprint -sha256
```

Record the root CA fingerprint too:

```powershell
C:\xampp\apache\bin\openssl.exe x509 -in "$(mkcert -CAROOT)\rootCA.pem" -noout -fingerprint -sha256 -enddate
```

Renew with the same CA, hostname and IP using section 11. Trusted devices retain
trust only while that CA remains valid and unchanged.
There is no automatic renewal; the reminder is the renewal arrangement.

If this CA mechanism cannot be supported on the real devices, leave HTTPS setup
pending. A directly trusted self-signed leaf is not a substitute for a tested CA
and renewal arrangement. Do not bypass certificate warnings.

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
   `Listen 443`; inspect existing virtual hosts with `httpd.exe -S`. Add a named
   vhost without replacing existing defaults or changing port 80/tunnel ingress.
   Do not add duplicate listeners. Preserve the working deployment depth:

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
   New-NetFirewallRule -DisplayName "RetailMind HTTPS (LAN)" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow -Profile Private -RemoteAddress <verified-store-subnet-CIDR>
   ```

   Inspect existing inbound allow rules first: a new narrow rule does not narrow
   an existing broad Apache rule. Record the exact new rule identity for rollback.
   Never add router port forwarding, DMZ, or public access.
   Check that the LAN adapter's network profile is `Private`
   (`Get-NetConnectionProfile`). The rule does not apply on a `Public` profile.

6. Verify from the Store PC:

   ```powershell
   curl.exe -sS -o NUL -w "%{http_code}`n" https://<hostname>/
   "" | C:\xampp\apache\bin\openssl.exe s_client -connect <hostname>:443 -servername <hostname> -CAfile "$(mkcert -CAROOT)\rootCA.pem" -verify_hostname <hostname> -verify_return_error 2>&1 | Select-String "subject=|issuer=|Verify return code"
   ```

   Use the recorded application path for the curl URL. Expect a successful page
   response and `Verify return code: 0 (ok)`. OpenSSL uses the explicit CA file;
   that does not prove tablet/browser trust. Never disable validation with `-k`.

For an accepted local hostname, generated page links need no URL edit.
`app_url()` derives the base path from the
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
per-device trust step. Resolution and browser compatibility also need verification.

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
page, and a successful sign-in. If a warning remains, inspect issuer, SAN hostname,
clock, expiry, and trust installation; do not use "proceed anyway" as acceptance.

Delete `rootCA.pem` from any temporary web-accessible location after install.

## 9. Install the home-screen icon on the final address

The manifest already uses origin-relative values (`start_url`, `scope`, and
`id` are all `./`), so no file change is needed: the icon binds to whichever
origin it is installed from, including this HTTPS hostname and subfolder.

1. On the tablet, open `https://<hostname>/` (or `https://<hostname>/retailmind/`).
2. Add it: Chrome **Install app / Add to Home screen**, or Safari **Share → Add
   to Home Screen**.
3. Launch the icon. Record whether the actual browser provides standalone mode
   or a browser shortcut; verify the same HTTPS origin. Replace old shortcuts.
4. Record the final URL in section 1.

If the hostname ever changes, delete and re-add the icon. Never hardcode a
guessed hostname into the manifest: the existing browser test asserts that
`start_url`, `scope`, and `id` stay origin-relative at both deployment depths
(`node src/backend/tests/home_screen_app_browser_test.js`). That test needs
Playwright installed and a local PHP binary; a documentation review does not
replace running it.

## 10. Verification matrix

All rows are **pending** until dated evidence from actual devices is attached.
Record operator, exact URL, device/OS/browser, actual viewport, result, and evidence
path per row. Keep physical/network results separate from TestSprite runs and
supplemental emulation. Use controlled disposable data for form/recovery checks;
never change live Store records just for acceptance.

| Check | Command or action | Expected |
| --- | --- | --- |
| Address survives PC reboot | Reboot the Store PC, then open the icon | Same address; Apache/database start; trusted sign-in works |
| Address survives router reconnect | Reconnect/reboot router separately, then open the icon | Same address and local resolution; trusted sign-in works |
| Name resolves on LAN | `nslookup <hostname>` on a second PC | Reserved Store PC address |
| Certificate chain | `curl.exe -sS -o NUL -w "%{http_code}`n" https://<hostname>/` on the Store PC | `200`, no certificate error |
| Tablet trust | Open the HTTPS URL on the tablet | Padlock, no warning, sign-in works |
| Authorized forms | Administrator and Inventory Manager sign-in, navigation, representative authorized submission on disposable data | Existing permissions and results preserved |
| Draft recovery | Inventory Manager: type into a form, wait for "Unsaved values kept", reload and Resume; repeat and Discard | Resume restores values; Discard removes draft; secure context has WebCrypto |
| Home-screen icon | Launch the icon | Same trusted origin; actual standalone/shortcut behavior recorded |
| Internet outage | Disconnect WAN, keep router, PC, and Apache running | Icon and all local HTTPS access still work |
| Existing access preserved | Open localhost HTTP on the Store PC and the old LAN address | Still load; no forced redirect to the public domain |
| Remote access unaffected | Compare existing authorized remote entry before/after with LAN simultaneously | Existing remote policy and local-only Super Administrator boundary preserved |

The last two rows prove the new HTTPS vhost did not replace the direct-PC or
remote arrangements. Local requests must not be forced to a public domain.

## 11. Renewal and rollback

Renewal, in order:

1. Check `Not After` from section 6 at least 30 days before expiry.
2. Use the original CA directory/account and key. Issue to new temporary leaf/key
   paths, inspect SAN/expiry and key permissions, then switch the vhost paths. Keep
   the prior certificate/key until browser verification succeeds.
3. `C:\xampp\apache\bin\httpd.exe -t`, then restart Apache.
4. Re-open the icon on the tablet; no tablet change is needed because the root
   CA is unchanged.

Rollback, in order, if HTTPS blocks Store work:

1. Restore the dated backups of `httpd.conf` and `httpd-ssl.conf`, run
   `httpd.exe -t`, and restart Apache. Plain HTTP LAN access returns.
2. Remove only the exact newly created firewall rule recorded during setup;
   preserve pre-existing rules and avoid broad display-name deletion.
3. Remove the home-screen icon from the tablet and re-add it from the old URL if
   it is still needed.
4. Restore the previous `SESSION_SECURE_COOKIE` value if it was changed.
5. Optionally clean the certificate and trust: `mkcert -uninstall` on the Store
   PC only if this CA is dedicated to this installation; remove that exact root
   from each device. Preserve roots used by another service.
6. Restore only the recorded DNS/DHCP changes if necessary. Prefer keeping the
   stable address if other devices rely on it; verify direct access afterward.

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

## 13. Read-only preparation evidence

Inspected the development workspace host on 2026-11-16; it is not confirmed to be
the live Store PC. Wi-Fi had a private IPv4 gateway but used public DNS resolvers,
so local router DNS is not established. Apache listened on 80/443, loaded SSL and
rewrite modules, and included the default SSL vhost (`www.example.com`, XAMPP
parent document root, bundled certificate paths). Listening ports do not prove a
trusted certificate, auto-start, or tablet reachability. No router, firewall,
certificate, service, application environment, or database setting was changed.

Source review confirmed origin-relative manifest values, request-derived URLs,
and HTTPS cookie autodetection. It also identified the hostname-policy constraint
in section 4. None of the physical/network acceptance rows has passed.

Supplemental `node src/backend/tests/home_screen_app_browser_test.js` failed at
line 45: the existing header adds a `?v=...` manifest query but the test expects
an unversioned URL. This documentation change does not alter either file; the
failure does not establish a tablet installation or network result.

References: [mkcert key custody and mobile trust](https://github.com/FiloSottile/mkcert),
[Apple manual certificate trust](https://support.apple.com/en-us/102390).
