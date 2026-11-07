# Local browser assets

Superadmin pages serve these existing libraries locally so loading does not depend on external asset hosts. Their licenses are included alongside the files (DataTables extensions use `datatables/License.txt`).

| Library | Version | Distribution source |
| --- | --- | --- |
| SweetAlert2 | 11.26.25 | `https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/` (`dist/sweetalert2.all.min.js`, `dist/sweetalert2.min.css`, `LICENSE`) |
| Chart.js | 4.4.4 | `https://cdn.jsdelivr.net/npm/chart.js@4.4.4/` (`dist/chart.umd.min.js`, `LICENSE.md`) |
| Bootstrap Icons | 1.11.3 | `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/` (`font/bootstrap-icons.min.css`, `font/fonts/bootstrap-icons.woff2`, `font/fonts/bootstrap-icons.woff`, `LICENSE`) |
| DataTables ColumnControl | 2.0.2 | `https://cdn.datatables.net/columncontrol/2.0.2/` (`css/columnControl.dataTables.min.css`, `js/dataTables.columnControl.min.js`) |
| DataTables DateTime | 2.0.0 | `https://cdn.datatables.net/datetime/2.0.0/` (`css/dataTables.dateTime.min.css`, `js/dataTables.dateTime.min.js`) |
| Tesseract.js | 6.0.1 | `https://cdn.jsdelivr.net/npm/tesseract.js@6.0.1/` (`dist/tesseract.min.js`, `dist/worker.min.js`, `LICENSE.md`) |
| Tesseract.js Core | 6.0.0 | `https://cdn.jsdelivr.net/npm/tesseract.js-core@6.0.0/` (`tesseract-core-simd-lstm.wasm.js`, `tesseract-core-lstm.wasm.js`, `LICENSE`) |
| Tesseract English data | 4.0.0 | `https://tessdata.projectnaptha.com/4.0.0/eng.traineddata.gz` |

DataTables core 3.0.4 was already vendored. When updating these files, change their URLs or the static service-worker cache version so installed clients receive the new assets.
