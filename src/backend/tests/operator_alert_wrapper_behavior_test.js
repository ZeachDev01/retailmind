"use strict";
// Friendly alerts 01 (ticket #53): shared Operator Alert wrapper safety net.
//
// Behavior-level check of ui.js sanitizeAlert with no JS test stack: plain
// Node assert + a minimal window/document sandbox. Feeds raw technical text
// (SQLSTATE, exception class, file path, object dump) through the wrapper and
// asserts the shop-mode easy line, the debug-mode tech line + console detail,
// and that success/info messages stay unchanged.

const assert = require("node:assert");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "../../..");
const uiSource = fs.readFileSync(
  path.join(root, "src/frontend/assets/js/ui.js"),
  "utf8",
);

const GENERIC_ERROR =
  "Something went wrong. Please try again. Tell your Administrator if this keeps happening.";
const GENERIC_WARNING =
  "This could not be completed right now. Please try again. Tell your Administrator if this keeps happening.";

const TECH_SAMPLES = [
  "SQLSTATE[HY000] [2002] Connection refused",
  "PDOException: connection lost",
  "MyCustomException: payment gateway timed out",
  "TypeError: Unsupported operand types: int + string",
  "DivisionByZeroError: Division by zero",
  "Error: Call to undefined function branch_scope()",
  "in /var/www/retailmind/src/backend/app/Core/Database.php:44",
  "C:\\xampp\\htdocs\\retailmind\\src\\backend\\app.php:12",
  "object(stdClass)#1 (2) {\n  [\"id\"]=>\n  int(1)\n}",
  "Object of class DateTime could not be converted to string",
  "Stack trace:\n#0 /var/www/retailmind/src/backend/app.php(12): {main}",
];

function loadWrapper(debug) {
  const consoleCalls = [];
  const sandbox = {
    window: { RM_DEBUG: debug },
    document: {
      addEventListener() {},
    },
    console: {
      error() {
        consoleCalls.push(Array.from(arguments).map(String).join(" "));
      },
    },
    setTimeout,
    clearTimeout,
    URLSearchParams,
  };
  vm.createContext(sandbox);
  vm.runInContext(uiSource, sandbox, { filename: "ui.js" });
  return {
    RM: sandbox.window.RetailMindUI,
    consoleCalls,
  };
}

const failures = [];
function check(condition, message) {
  if (!condition) failures.push(message);
}

function checkKindSanitized(RM, raw, kind, expected) {
  const result = RM.sanitizeAlert(raw, kind);
  check(
    result.message === expected,
    "debug off + " +
      kind +
      " kind must show the generic easy line for " +
      JSON.stringify(raw) +
      ", got: " +
      JSON.stringify(result.message),
  );
  check(
    result.tech === "",
    "debug off must hide the tech line for " + JSON.stringify(raw),
  );
}

try {
  assert.ok(uiSource.length > 0, "ui.js must be readable");

  // --- AC1: debug off — only the generic easy line, correct red/yellow kind ---
  {
    const { RM, consoleCalls } = loadWrapper(false);
    check(RM && typeof RM.sanitizeAlert === "function", "wrapper must expose sanitizeAlert");

    TECH_SAMPLES.forEach((raw) => {
      checkKindSanitized(RM, raw, "error", GENERIC_ERROR);
      checkKindSanitized(RM, raw, "warning", GENERIC_WARNING);
    });

    // Plain first line + technical tail: first line stays, tech tail hides.
    const mixed = RM.sanitizeAlert(
      "Not enough stock for product #5.\nSQLSTATE[23000]: Integrity constraint violation",
      "error",
    );
    check(
      mixed.message === "Not enough stock for product #5.",
      "A plain first line must be kept in shop mode, got: " + JSON.stringify(mixed.message),
    );
    check(mixed.tech === "", "debug off must hide the technical tail");
    check(
      !mixed.message.includes("SQLSTATE"),
      "shop mode must never leak SQLSTATE",
    );

    // Benign multi-line text: every plain line stays with the operator.
    const benign = RM.sanitizeAlert(
      "The stock report could not be saved.\nCheck your connection and try again.",
      "warning",
    );
    check(
      benign.message ===
        "The stock report could not be saved.\nCheck your connection and try again.",
      "A benign multi-line message must pass through unchanged in shop mode, got: " +
        JSON.stringify(benign.message),
    );
    check(benign.tech === "", "Benign text must not produce a tech line");

    check(
      consoleCalls.length === 0,
      "debug off must not emit console detail, got: " + JSON.stringify(consoleCalls),
    );

    // --- AC3: success and info unchanged ---
    const success = RM.sanitizeAlert("Product saved.", "success");
    check(
      success.message === "Product saved." && success.tech === "",
      "success messages must pass through unchanged",
    );
    const info = RM.sanitizeAlert("2 rows updated.", "info");
    check(
      info.message === "2 rows updated." && info.tech === "",
      "info messages must pass through unchanged",
    );
  }

  // --- AC2: debug on — easy line plus tech line plus console detail ---
  {
    const { RM, consoleCalls } = loadWrapper(true);
    const raw =
      "SQLSTATE[HY000] [2002] Connection refused in /var/www/retailmind/src/backend/app/Core/Database.php:44";
    const result = RM.sanitizeAlert(raw, "error");
    check(
      result.message === GENERIC_ERROR,
      "debug on must still lead with the generic easy line, got: " +
        JSON.stringify(result.message),
    );
    check(
      result.tech.includes("SQLSTATE"),
      "debug on must surface the raw tech line, got: " + JSON.stringify(result.tech),
    );
    check(
      consoleCalls.some((line) => line.includes("SQLSTATE")),
      "debug on must emit console detail for the technical failure, got: " +
        JSON.stringify(consoleCalls),
    );

    const plain = RM.sanitizeAlert("Product saved.", "success");
    check(
      plain.tech === "",
      "clean success text must not produce a tech line",
    );
    check(
      consoleCalls.filter((line) => line.includes("Product saved.")).length === 0,
      "clean text must not be written to the console",
    );
  }

  // --- Titles stay the agreed Operator Alert shape (source-level seam) ---
  check(
    /error:\s*"Unable to continue"/.test(uiSource),
    'error alerts must keep the title "Unable to continue"',
  );
  check(
    /warning:\s*"Attention"/.test(uiSource),
    'warning alerts must keep the title "Attention"',
  );
  check(
    /success:\s*"Success"/.test(uiSource) && /info:\s*"Update"/.test(uiSource),
    "success and info titles must stay unchanged",
  );

  // --- Confirm renders through the same safety net (spec coverage) ---
  check(
    substrCount(uiSource, "sanitizeAlert") >= 4,
    "toast, alert, and confirm must all apply the safety net",
  );
} catch (exception) {
  failures.push("Operator alert wrapper behavior test threw: " + exception.message);
}

function substrCount(haystack, needle) {
  return haystack.split(needle).length - 1;
}

if (failures.length) {
  process.stderr.write(
    "Operator alert wrapper behavior test failed:\n- " + failures.join("\n- ") + "\n",
  );
  process.exit(1);
}

process.stdout.write("Operator alert wrapper behavior test: passed\n");
