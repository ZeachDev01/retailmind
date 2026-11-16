# Organisadong tablet layout ug sayon nga access sulod sa Store

Status: specification, 2026-11-16. Ang publication nag-organisa sa umaabot nga implementation; wala kini magpasabot nga nahuman na ang UI o ang live network setup.

Published as [GitHub issue #465](https://github.com/ZeachDev01/retailmind/issues/465), labelled `ready-for-agent`.

## Problem Statement

Ang Administrator ug Inventory Manager nagkinahanglan og kompleto ug organisadong RetailMind sa gamay nga tablet nga naka-landscape. Sa kasamtangang kasinatian, adunay mga elemento nga dili tarong ang kahimutang o ma-stretch sa gamay nga screen. Dili igo nga pakagamyon lang ang desktop UI o itago ang mga function aron moigo.

Hasol usab ang pagpangita sa Store PC address pinaagi sa `ipconfig` ug pag-type sa numeric IP ug application path sa tablet. Sulod ra sa Store Wi-Fi ang gikinahanglang access niini nga feature. Adunay router settings access ang operator; PLDT Fibr ang network, apan wala pa mailhi ang router model.

Ang tablet gibanabana nga iPad mini kadak-on, karaan ang OS, ug lagmit Chrome ang browser. Wala pa mapamatud-i ang model, platform, browser version, o aktuwal nga magamit nga viewport. Dili kini ebidensiya nga iPad ang device o nga suportado niini ang tanang gikinahanglang browser feature.

## Solution

Ipahiangay ang kasamtangang Administrator ug Inventory Manager workspaces sa magamit nga gilapdon ug kataas. Pabiling mabasa ang sulod, mapislit ang mga controls, ug maabot ang tanang gitugot nga actions. Mopilo ang navigation kon huot, mohan-ay pag-usab ang panels ug forms, ug sulod ra sa tables ang horizontal scrolling kon kinahanglan.

Hatagi ang tablet og RetailMind home-screen icon para usa ka tap, ug sayon hinumdoman nga address para sa unang pag-abli sa laing device. Ang address kinahanglan permanente nga moabot sa Store PC sa lokal nga network ug mogamit og trusted HTTPS aron magamit usab ang encrypted inventory draft recovery. Dili kinahanglan ang public tunnel o internet para sa ordinaryong lokal nga paggamit samtang buhi ang Store PC, application services, ug lokal nga network.

Sulayan una ang kasamtangang Chrome. Kon kulang ang suporta, sulayan ang suportadong browser o OS update. Kon dili gihapon kaya ang kompletong functions, mas bag-ong device ang giuyonang fallback; dili hilom nga kuhaan og function o seguridad ang app.

## User Stories

1. Isip Administrator, gusto nako og organisadong workspace sa gamay nga landscape tablet, aron makadumala ko bisan wala sa desktop.
2. Isip Inventory Manager, gusto nako og tablet layout nga dili ma-stretch o mag-overlap, aron mahuman nako ang inventory work duol sa mga produkto.
3. Isip tablet operator, gusto nako nga magpabilin ang pamilyar nga hitsura sa RetailMind, aron dili ko magkat-on og lahi nga app.
4. Isip tablet operator, gusto nako nga mopilo ang sidebar kon huot ang screen, aron igo ang lugar sa akong trabaho.
5. Isip tablet operator, gusto nako nga maablihan ug masirad-an pinaagi sa touch ang navigation, aron makabalhin ko sa tanang gitugot nga pages.
6. Isip tablet operator, gusto nako nga mohan-ay pag-usab ang summary panels, aron dili maputol ang impormasyon.
7. Isip tablet operator, gusto nako nga mabasa ang labels, values, ug error messages, aron dili kinahanglan nga i-zoom out ang tibuok app.
8. Isip tablet operator, gusto nako og mapislit nga buttons ug fields, aron malikayan ang sayop nga action.
9. Isip tablet operator, gusto nako nga ang table ra ang mo-scroll pahalang, aron dili mawala sa screen ang tibuok page ug navigation.
10. Isip tablet operator, gusto nako nga maabot ang tanang table columns ug row actions, aron walay mawala nga impormasyon o function.
11. Isip tablet operator, gusto nako nga magamit gihapon ang search, filters, sorting kon anaa, ug pagination, aron makit-an nako ang hustong record.
12. Isip tablet operator, gusto nako nga ang taas nga ngalan, daghang rows, ug empty results dili makaguba sa layout, aron kasaligan ang normal nga trabaho.
13. Isip tablet operator, gusto nako nga ang dialogs ug drawers moigo sa mubo nga screen, aron mabasa ug mahuman nako ang ilang sulod.
14. Isip tablet operator, gusto nako nga maabot ang Save, Cancel, ug confirmation actions, aron dili ko maipit sa bukas nga form.
15. Isip tablet operator, gusto nako nga magamit ang form bisan bukas ang on-screen keyboard, aron mahuman nako ang pag-type ug pag-save.
16. Isip tablet operator, gusto nako nga ang focus ug scrolling magpabiling tarong sa pagbukas ug pagsira sa overlays, aron dili ko mawala sa akong trabaho.
17. Isip Administrator, gusto nako nga magamit ang Store Overview, GCash Overview, notifications, reports, ug ilang details, aron makamonitor ko sa Store sa tablet.
18. Isip Administrator, gusto nako nga maabot gihapon ang akong kasamtangang gitugot nga management actions, aron dili mahimong monitoring-only ang tablet.
19. Isip Inventory Manager, gusto nako nga magamit ang Products, Inventory Counts, Stock Receiving, Stock Issue reviews, ug Supplier Pullouts, aron kompleto ang akong shelf work.
20. Isip Inventory Manager, gusto nako nga magpabilin ang validation ug klarong save result, aron mahibalo ko kon nadawat ba ang akong giusab.
21. Isip Inventory Manager, gusto nako nga magamit gihapon ang encrypted draft Resume ug Discard, aron dili mawala ang recoverable nga wala pa nahuman nga trabaho.
22. Isip Inventory Manager, gusto nako nga ang retry human sa naputol nga response dili magdoble sa stock operation, aron husto ang inventory records.
23. Isip Staff Account holder, gusto nako nga akong assigned Workspace permissions gihapon ang masunod sa tablet, aron dili mausab ang akong awtoridad tungod sa device.
24. Isip Store operator, gusto nako og RetailMind icon sa home screen, aron usa ka tap ra ang regular nga pag-abli.
25. Isip Store operator, gusto nako og sayon hinumdoman nga address, aron maablihan ang app sa laing authorized device nga walay numeric IP ug application path nga i-type.
26. Isip Store operator, gusto nako nga magpabilin ang address human sa PC reboot o router reconnect, aron dili na ko balik-balik og `ipconfig`.
27. Isip Store operator, gusto nako nga ang lokal nga app magamit kon maputol ang internet apan buhi ang Store PC ug Wi-Fi, aron makapadayon ang Store work.
28. Isip tablet operator, gusto nako og trusted HTTPS nga walay certificate-warning bypass, aron magamit ang browser features nga nagkinahanglan og secure context.
29. Isip Store operator, gusto nako nga magpabilin ang access gikan sa Store PC ug ubang kasamtangang devices, aron dili makabalda ang bag-ong shortcut ug address.
30. Isip tag-iya sa karaang tablet, gusto nako nga sulayan una ang kasamtangang Chrome ug available updates, aron dili dayon kinahanglan ilisan ang device.
31. Isip Store operator, gusto nako og klarong resulta kon dili suportado ang karaang device, aron makapili ko sa giuyonang mas bag-ong device imbis mogamit og kulang nga functions.
32. Isip Store operator, gusto nako og TestSprite evidence sa UI behavior, aron ang reported nga resulta adunay masubay nga tests ug artifacts.
33. Isip Store operator, gusto nako nga lahi ang emulated ug physical-device results, aron dili masayop og sabot nga nasulayan na ang akong tablet.

## Implementation Decisions

- Padayon ang kasamtangang server-rendered application, house components, role workspaces, ug visual identity. Walay separate tablet app, bag-ong design system, o framework migration.
- Shared navigation, layout grids, forms, tables, ug overlay components ang unang ayohon; page-specific changes kon kinahanglan lamang. Ipreserbar ang existing identifiers, event lifecycle, validation, ug accessibility contracts.
- Available width ug height ang basehan sa reflow, dili device name, user-agent detection, o hardware screen resolution. Dili i-assume nga ang gibanabanang iPad mini size adunay piho nga CSS viewport.
- Ang kasamtangang navigation mode nga mo-switch lamang sa 900px paubos usa ka candidate cause sa landscape issue, dili pa physical-device diagnosis. Pilion ang breakpoint base sa rendered content ug verified behavior; walay giuyonang eksaktong bag-ong breakpoint.
- Dili ipadako ang page gawas sa viewport aron ma-accommodate ang tables. Gamiton ang existing table scrolling ug reflow patterns; walay wagtangon nga impormasyon o authorized actions.
- Ang long forms ug overlays kinahanglan adunay reachable actions ug bounded internal scrolling bisan mubo ang landscape viewport o bukas ang keyboard. Ipreserbar ang entered values ug existing focus behavior.
- Kompleto nga functions nagpasabot sa kasamtangang gitugot nga functions sa matag Workspace; dili kini dugang nga role permission. Administrator Stock Issue Oversight magpabiling read-only.
- Gamiton pag-usab ang kasamtangang encrypted draft recovery ug safe-retry behavior. Dili usbon ang stock-posting contracts o maghimo og offline posting tungod lang sa tablet adaptation.
- Ang Store PC mao gihapon ang sole live installation ug database host. Kinahanglan stable LAN addressing ug local name resolution para sa memorable address. Ang exact hostname, address, DHCP arrangement, ug resolver pilion human masusi ang aktuwal nga network; dili mag-assume nga suportado ang local DNS tungod lang kay PLDT Fibr ang service.
- Trusted HTTPS kinahanglan molihok sa chosen hostname ug target browsers, apil ang certificate trust ug renewal arrangement. Dili dawaton isip kompleto ang plain LAN HTTP o certificate-warning bypass. Irekord ang setup steps nga kinahanglan sa matag device.
- Ang routine local route dili mosalig sa public tunnel o internet DNS lookup aron makapadayon kon walay internet. Ang home-screen icon kinahanglan moabli sa final stable local address; dili kinahanglan og bag-ong PWA o offline app installation mechanism.
- Ipreserbar ang simultaneous Store PC, LAN, ug existing remote access arrangements. Dili usbon ang accepted remote-access policies o Super Administrator restrictions aron lang mapagana ang bag-ong address. Local requests dili pugson ngadto sa public domain.
- Device acceptance magsugod sa kasamtangang Chrome; supported browser/OS update sunod, unya newer-device fallback kon dili kaya ang complete behavior. Walay arbitrary minimum OS version nga imbentohon sa wala pa compatibility testing.
- Walay bag-ong business API, schema migration, dependency, o domain module nga gikinahanglan sa agreed scope. Infrastructure details kinahanglan i-verify sa setup ug idokumento; dili sila permiso sa speculative abstractions o unrelated system changes.

## Testing Decisions

- **User-selected primary UI seam: TestSprite frontend browser workflows.** Ang user espesipikong mipili sa TestSprite para sa UI testing. Test observable behavior sa signed-in Administrator ug Inventory Manager workflows, dili CSS strings, private helpers, o exact breakpoint implementation.
- Gamiton ang existing RetailMind TestSprite project kon verified nga mao ang hustong target; ang existing workspace/device verification note nagtudlo sa project `abe16d0b-21f0-432e-8a86-72b1436c42fe`. I-verify ang project ug existing tests una sa pag-run. Ayaw duplicate og project o suite kon naa nay reusable coverage.
- Padagana ang local frontend tests batok sa independent application copy ug disposable database. Ang authenticated TestSprite CLI nga nasusi sa specification phase kay version 0.16.0 ug V3 routing. I-check pag-usab ang auth, project, reachable local port, ug supported viewport controls sa execution. Local frontend runs kinahanglan local tunnel; walay tests batok sa live Store records.
- I-extend ang existing tests sa login, Workspace navigation, Administrator summaries/details, Products, Inventory Counts, Stock Receiving, Stock Issue reviews, Supplier Pullouts, ug shared overlays. Apilon ang populated, empty, long-content, validation-error, ug connection-error states, ug actual successful authorized actions sa disposable data.
- Initial proposed emulation matrix para sa duha ka workspaces: 640x360, 800x480, 960x600, 1024x768, ug 1280x800 CSS pixels, uban sa 1366x768 desktop regression. Kini mga test inputs, dili claim sa actual tablet dimensions o guaranteed browser support. Dugangan sa actual tablet viewport ug relevant navigation-boundary sizes kon mailhan na.
- Kinahanglan evidence sa actual viewport sa matag TestSprite device row. Kon dili makakontrol o makapamatuod ang TestSprite sa requested viewport, markahi kana nga row nga unverified. Existing local Playwright coverage mahimong supplemental geometry evidence, apan dili kini ilabel nga TestSprite pass o gamiton isip hilom nga kapuli sa gipiling UI tool.
- Assert nga walay unintended whole-page horizontal overflow, clipped o overlapping controls, o unreachable navigation ug actions. Test horizontal table scrolling, search/filter/pagination, dialogs ug drawers, focus return, touch operation, ug retained values human sa viewport changes. Gamiton ang existing responsive system/component, page navigation, overlay lifecycle, ug product drawer tests isip prior art ug focused regression checks.
- Gamiton ang existing inventory private-recovery ug submission-reconciliation browser/HTTP coverage aron mapamatud-an nga wala madaot ang Resume/Discard, account isolation, retained values, ug exactly-once persisted stock effects human sa uncertain response. Walay bag-ong lower-level seam kon kaya na sa existing workflow ug persistence assertions.
- **Physical acceptance seam:** sa actual Store tablet, irekord ang model, OS, browser version, viewport, ug result. Sulayan ang landscape layout, on-screen keyboard, long forms, reachable Save/Cancel, home-screen icon, trusted HTTPS, ug encrypted draft recovery. TestSprite emulation dili physical acceptance sa karaang Chrome o mobile keyboard.
- **Local network acceptance:** ablihi ang memorable address ug icon gikan sa tablet; sulayi human sa Store PC reboot ug router reconnect. Sulayi nga putol ang internet samtang buhi ang LAN ug Store PC services. I-verify ang certificate trust, sign-in, navigation, form submission, ug existing direct-PC/LAN access. Ang Store PC nga naka-off dili offline-app test ug dili gilaom nga makaserbisyo.
- Sa matag TestSprite run, tipigi ang run ID, dashboard URL, exact environment/viewport evidence, verdict, ug artifacts; basaha ang failing steps ug screenshots una sa pagreport. Human sa owned local timeout, sundon ang CLI cancellation ug new-run rules imbis mag-assume nga buhi pa ang tunnel. Ang CLI preflight dili test pass.
- Run applicable existing style, view, smoke, contract, ug focused browser checks sa implementation changes. Kini nga specification-only task wala nagkinahanglan og application test run ug wala mag-angkon og bag-ong passing results.

## Out of Scope

- Bag-ong native tablet app, PWA/offline posting project, o complete visual redesign.
- Pag-usab sa Cashier POS layout, printer/scanner integration, o business behavior gawas sa pagpreserbar sa existing shared-component behavior.
- Pagdugang o pagkunhod sa Workspace permissions, Super Administrator tablet access, o pag-usab sa remote-access ADRs.
- Cloud migration, another live database, cross-device draft synchronization, o operation nga walay reachable Store PC.
- Bag-ong public-domain purchase, public router exposure, o mandatory public tunnel para sa local access.
- Garantiya sa tanang karaang OS/browser, automatic device upgrades, o hardware purchases.
- Live application deployment, router changes, certificate installation, o pag-usab sa Store data atol niini nga specification task.

## Further Notes

Kini follow-up sa existing role-device optimization specification ug issue #314, nga adunay mas lapad nga POS/scanner/receipt scope. Ang bag-ong feature nakapunting sa gamay nga landscape tablet para sa Administrator ug Inventory Manager ug convenient local entry; dili niini ablihan pag-usab ang receipt-printing decisions.

Ang actual tablet, browser version, router model, ug failing page wala pa mapamatud-i. Acceptance inputs kini sa implementation ug setup, dili rason nga mag-imbento og hardware facts o mosulti nga resolved na ang compatibility. Adunay router settings access ang user. Wala pay chosen hostname, DNS provider, certificate mechanism, o confirmed supported minimum browser.

Ang user mipili sa TestSprite para sa UI testing sa 2026-11-16. Existing role/device TestSprite planning ug previous responsive results mahimong prior art, apan dili sila resulta para niini nga bag-ong acceptance matrix. Publication gamiton ang `ready-for-agent` label; implementation ug physical/network acceptance lahi nga umaabot nga trabaho.
