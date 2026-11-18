---
target: Add Product and Stock Receiving workflow
total_score: 23
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
target_identity: "file:C:\\xampp\\htdocs\\retailmind\\src\\frontend\\components\\report\\stock_receiving.php"
target_fingerprint: "sha256:23f1ed357f11712cea1087957b67afdc587002dcfb654e4daace9e891750c017"
target_path: "C:\\xampp\\htdocs\\retailmind\\src\\frontend\\components\\report\\stock_receiving.php"
timestamp: 2026-11-17T08-49-05Z
slug: src-frontend-components-report-stock-receiving-php
---
Method: dual-agent (A: /root/workflow_review; B: /root/evidence). Source-based workflow assessment; live browser unavailable.

The main improvement is a continuous delivery workflow: scan/search -> enter quantity -> review -> save. RetailMind already fits its Store domain through unit conversion, stock summaries and role-aware work. The largest opportunity is reducing interruption during repetitive receiving.

Heuristic scores are provisional code-review judgments, not measured usability results.
| Heuristic | Score / 4 | Evidence |
|---|---|---|
| System status | 2 | Scan prompt can refer to a hidden quantity input |
| Real-world match | 2 | Receiving accepts base-unit cost even for carton quantities |
| Control and freedom | 3 | Back navigation and persisted drafts |
| Consistency | 2 | Add To Stock labels mean selection before final save |
| Error prevention | 3 | Duplicate protection, validation and final review |
| Recognition | 2 | Packaging concepts spread over multiple setup stages |
| Efficiency | 1 | Five-stage creation and selection/quantity separation |
| Minimalism | 2 | Optional planning visible in mandatory creation flow |
| Recovery | 3 | Draft support; unknown barcode lacks creation path |
| Help | 3 | Receiving help and conversion hints |
| Total | 23/40 | Provisional source assessment |

Strengths: retain barcode lookup, draft recovery, saved-cost fallback, accepted-unit conversion, sealed/loose stock handling and final review.

Priority issues:
1. P1: Unknown barcode has no create-and-return path. stock_receiving.php:435; products.php:266. Offer Create product using scanned barcode, retain delivery draft and add the created product to the delivery. Starting stock must be zero in this route to avoid double counting. Suggested action: shape.
2. P1: Selected products and quantities are separate stages. stock_receiving.php:490,1091; reports.css:335. Scanner tries to focus hidden quantity inputs. Show quantity beside selected product with search reachable; retain final review. Suggested action: shape.
3. P1: Simple products require Basic, Barcode, Pricing, Planning and Review. products.php:1471,3004. Expose essential identification and pricing fields first; disclose optional family, photo and planning inputs. Retain pack configuration when needed. Suggested action: distill.
4. P2: Receiving cost is per base unit, including when received quantities are cartons. stock_receiving.php:311. Reuse the existing purchase-pack calculation pattern from products.php:1515; show derived unit cost and total, preserving rounding. Suggested action: clarify.
5. P2: Add To Stock implies stock has changed, but only selects a delivery line. stock_receiving.php:804. Use Add to delivery; reserve Save receiving & update stock for the committed change. Suggested action: clarify.

Persona red flags: A new Inventory Manager cannot complete an unknown-code path from Receiving. A frequent receiver switches between selection and quantity stages and calculates carton cost per piece. Neither observation is a measured completion-time claim.

Minor observations: expiry is under Optional details for every row; evaluate surfacing it for products known to need expiry recording. Different expiration dates/lots need explicit treatment. Avoid copying dates silently.

Detector evidence: products.php 25 findings (1 warning, 24 advisory); stock_receiving.php 2 advisory. Most are design-token drift. Empty image preview warning at products.php:1488 appears to be a false positive because its container is hidden until file selection. These cosmetic findings do not explain the main workflow friction.

Questions skipped: user requested analysis; concrete prioritized recommendations suffice without blocking on preferences.
