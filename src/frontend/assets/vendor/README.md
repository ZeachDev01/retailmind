# Local browser assets

Superadmin pages serve these existing libraries locally so loading does not depend on external asset hosts. Their licenses are included alongside the files (DataTables extensions use `datatables/License.txt`).

| Library | Version | Distribution source |
| --- | --- | --- |
| SweetAlert2 | 11.26.25 | `https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/` (`dist/sweetalert2.all.min.js`, `dist/sweetalert2.min.css`, `LICENSE`) |
| Bootstrap Icons | 1.11.3 | `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/` (`font/bootstrap-icons.min.css`, `font/fonts/bootstrap-icons.woff2`, `font/fonts/bootstrap-icons.woff`, `LICENSE`) |
| DataTables ColumnControl | 2.0.2 | `https://cdn.datatables.net/columncontrol/2.0.2/` (`css/columnControl.dataTables.min.css`, `js/dataTables.columnControl.min.js`) |
| DataTables DateTime | 2.0.0 | `https://cdn.datatables.net/datetime/2.0.0/` (`css/dataTables.dateTime.min.css`, `js/dataTables.dateTime.min.js`) |

DataTables core 3.0.4 was already vendored. When updating these files, change their URLs or the static service-worker cache version so installed clients receive the new assets.
