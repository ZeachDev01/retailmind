# Local OCR assets

GCash receipt OCR runs entirely from this directory so it works on localhost without internet access.

| File | Source |
| --- | --- |
| `tesseract.min.js` | `https://cdn.jsdelivr.net/npm/tesseract.js@6.0.1/dist/tesseract.min.js` |
| `worker.min.js` | `https://cdn.jsdelivr.net/npm/tesseract.js@6.0.1/dist/worker.min.js` |
| `core/tesseract-core-simd-lstm.wasm.js` | `https://cdn.jsdelivr.net/npm/tesseract.js-core@6.0.0/tesseract-core-simd-lstm.wasm.js` |
| `core/tesseract-core-lstm.wasm.js` | `https://cdn.jsdelivr.net/npm/tesseract.js-core@6.0.0/tesseract-core-lstm.wasm.js` |
| `lang/eng.traineddata.gz` | `https://tessdata.projectnaptha.com/4.0.0/eng.traineddata.gz` |

Only the English LSTM model is included because the application calls `createWorker('eng', 1, ...)`. Both SIMD and non-SIMD core builds are kept so devices without WebAssembly SIMD still work. Licenses are included beside the files.
