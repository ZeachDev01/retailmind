"use strict";
// Database Backup pause notice (ticket #69): shared active-user client.
//
// Behavior-level check of backup_status.js with no JS test stack: plain Node
// assert plus a minimal window/document sandbox. It asserts that an active
// session is told when saving is paused, that the notice is plain English with
// no technical detail, that a paused save is refused without navigating away or
// discarding the page's unsaved input, that saving resumes when the status
// clears, and that a failed status check never invents a pause or blocks work.

const assert = require("node:assert");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "../../..");
const source = fs.readFileSync(
  path.join(root, "src/frontend/assets/js/backup_status.js"),
  "utf8",
);

const PAUSE_NOTICE = "A database backup is in progress. Saving changes is temporarily paused.";
const ENDPOINT = "/components/backup/backup_status.php";

const failures = [];
function check(condition, message) {
  if (!condition) failures.push(message);
}

let harnessBody = null;

function createElement(tagName) {
  return {
    tagName,
    className: "",
    children: [],
    hidden: true,
    dataset: {},
    attributes: {},
    listeners: {},
    textContent: "",
    classes: new Set(),
    setAttribute(name, value) {
      this.attributes[name] = String(value);
    },
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(this.attributes, name)
        ? this.attributes[name]
        : null;
    },
    appendChild(child) {
      this.child = child;
      harnessBody.children.push(child);
    },
    addEventListener(type, handler) {
      this.listeners[type] = this.listeners[type] || [];
      this.listeners[type].push(handler);
    },
    contains(node) {
      return this.children.indexOf(node) !== -1;
    },
    classList: {
      add(name) {},
      remove(name) {},
      contains() {
        return false;
      },
    },
  };
}

function loadHarness(responses, options) {
  const authenticated = !options || options.authenticated !== false;
  const body = createElement("body");
  harnessBody = body;
  body.dataset.rmAuthenticated = authenticated ? "true" : "false";
  body.getAttribute = function (name) {
    if (name === "data-rm-authenticated") return authenticated ? "true" : "false";
    if (name === "data-rm-backup-status") return ENDPOINT;
    return null;
  };
  const document = {
    readyState: "complete",
    hidden: false,
    body,
    head: { appendChild() {} },
    documentElement: {},
    children: [],
    documentListeners: {},
    querySelector(selector) {
      if (selector === "[data-rm-backup-status]") return body;
      if (selector === "[data-rm-backup-notice]") {
        return body.children.find((c) => c.attributes["data-rm-backup-notice"] !== undefined) || null;
      }
      return null;
    },
    querySelectorAll() {
      return [];
    },
    createElement(tagName) {
      return createElement(tagName);
    },
    addEventListener(type, handler) {
      this.documentListeners[type] = this.documentListeners[type] || [];
      this.documentListeners[type].push(handler);
    },
  };

  const alerts = [];
  const toasts = [];
  const fetches = [];
  const sandbox = {
    window: {
      RM_DEBUG: false,
      RetailMindUI: {
        alert(message, kind) {
          alerts.push({ message, kind });
          return Promise.resolve();
        },
        toast(message, kind) {
          toasts.push({ message, kind });
        },
      },
      setTimeout: (fn, ms) => {
        sandbox.timers.push({ fn, ms });
        return sandbox.timers.length;
      },
      clearTimeout: () => {},
    },
    document,
    fetch: undefined,
    console: { error() {} },
    Promise,
    URLSearchParams,
    setTimeout: (fn, ms) => {
      sandbox.timers.push({ fn, ms });
      return sandbox.timers.length;
    },
    clearTimeout: () => {},
    fetch(url, options) {
      fetches.push({ url, options });
      const next = responses.length ? responses.shift() : { payload: { paused: false, state: "idle", message: "" } };
      if (next instanceof Error) return Promise.reject(next);
      return Promise.resolve({
        ok: next.payload !== null,
        json: () => Promise.resolve(next.payload),
      });
    },
    timers: [],
  };
  sandbox.window.document = document;
  sandbox.window.fetch = sandbox.fetch;
  sandbox.window.setTimeout = sandbox.setTimeout;
  sandbox.window.clearTimeout = sandbox.clearTimeout;
  vm.createContext(sandbox);
  vm.runInContext(source, sandbox, { filename: "backup_status.js" });

  return {
    RM: sandbox.window.RetailMindUI.backupStatus,
    document,
    body,
    alerts,
    toasts,
    fetches,
    sandbox,
  };
}

function settle() {
  return new Promise((resolve) => setImmediate(resolve));
}

// The status client chains several promises before it renders, so let every
// queued microtask drain before asserting. Scheduled polls are deliberately not
// fired here: the test drives each status change explicitly.
async function flush(rounds) {
  for (let i = 0; i < (rounds || 8); i++) {
    await settle();
  }
}

function submit(document, form) {
  const handlers = document.documentListeners.submit || [];
  let prevented = false;
  const event = {
    target: form,
    preventDefault() {
      prevented = true;
    },
  };
  for (const handler of handlers) handler(event);
  return prevented;
}

async function main() {
  try {
    assert.ok(source.length > 0, "backup_status.js must be readable");

    // --- idle session: no notice, saves go through untouched ---------------
    {
      const harness = loadHarness([
        { payload: { paused: false, state: "idle", message: "" } },
      ]);
      const form = { method: "post", dataset: {} };
      const prevented = submit(harness.document, form);
      check(!prevented, "an idle session must be able to submit normally");
      check(harness.alerts.length === 0, "an idle session must not be alerted");
      check(harness.fetches.length === 1, "an active session must check the shared status");
      check(
        harness.fetches[0].url === ENDPOINT &&
          harness.fetches[0].options.credentials === "same-origin" &&
          harness.fetches[0].options.cache === "no-store",
        "the status check must be same-origin and uncached",
      );
      await settle();
      check(
        harness.RM.isPaused() === false,
        "an idle status must not put the session into a paused state",
      );
    }

    // --- a capture starts: the plain notice appears for every session ------
    {
      const harness = loadHarness([
        { payload: { paused: false, state: "idle", message: "" } },
        { payload: { paused: true, state: "capturing", message: PAUSE_NOTICE } },
      ]);
      await flush();
      harness.RM.check();
      await flush();

      check(harness.RM.isPaused() === true, "a capture in progress must pause the session");
      const notice = harness.body.children.find(
        (c) => c.attributes["data-rm-backup-notice"] !== undefined,
      );
      check(Boolean(notice), "a shared pause notice must be rendered");
      check(notice && notice.hidden === false, "the pause notice must be visible");
      check(
        notice && notice.textContent === PAUSE_NOTICE,
        "the pause notice must use the agreed sentence, got: " +
          (notice ? notice.textContent : "none"),
      );
      check(
        notice && !/SQLSTATE|Exception|\.php:|stack trace/i.test(notice.textContent),
        "the pause notice must stay free of technical detail",
      );
      check(
        notice && notice.getAttribute("role") === "status" &&
          notice.getAttribute("aria-live") === "polite",
        "the pause notice must be announced politely to assistive technology",
      );
    }

    // --- a paused session keeps its page and its unsaved input -------------
    {
      const harness = loadHarness([
        { payload: { paused: true, state: "capturing", message: PAUSE_NOTICE } },
      ]);
      await flush();
      check(harness.RM.isPaused() === true, "the pause must be observed on first load");

      const form = {
        method: "post",
        dataset: {},
        // Unsaved work lives in the page and must survive the refusal.
        fields: { note: "half typed receipt" },
      };
      const prevented = submit(harness.document, form);
      check(prevented, "a paused save must not navigate away and discard the page");
      check(
        form.fields.note === "half typed receipt",
        "unsaved form input must be preserved while paused",
      );
      check(
        harness.alerts.length === 1 && harness.alerts[0].kind === "warning",
        "a refused save must be reported in the shared Operator Alert kind",
      );
      check(
        harness.alerts[0].message.includes(PAUSE_NOTICE) &&
          /wait a moment and save again/i.test(harness.alerts[0].message),
        "a refused save must tell the operator to try again, got: " +
          (harness.alerts[0] || {}).message,
      );

      const getForm = { method: "get", dataset: {} };
      check(
        !submit(harness.document, getForm),
        "browsing and GET submissions must keep working while paused",
      );
      const bypass = { method: "post", dataset: { rmBackupBypass: "true" } };
      check(
        !submit(harness.document, bypass),
        "an explicitly exempt form must still be able to submit",
      );
    }

    // --- the requester sees a preparation state, not a second chance ------
    {
      const harness = loadHarness([{ payload: { paused: false, state: "idle", message: "" } }]);
      await flush();
      const button = {
        textContent: "Create backup",
        disabled: false,
        attributes: {},
        dataset: {},
        setAttribute(name, value) {
          this.attributes[name] = value;
        },
      };
      const form = {
        method: "post",
        dataset: {},
        querySelectorAll(selector) {
          return selector === "[data-backup-create]" ? [button] : [];
        },
      };
      check(!submit(harness.document, form), "an idle requester must be able to start a backup");
      check(button.disabled === true, "the create action must be disabled while preparing");
      check(
        button.textContent === "Preparing your backup…",
        "the requester must see a preparation state, got: " + button.textContent,
      );
      check(
        button.attributes["aria-busy"] === "true",
        "the preparation state must be announced to assistive technology",
      );
      check(
        !/%|percent/i.test(button.textContent),
        "no percentage progress may be invented",
      );
    }

    // --- saving resumes and the session is told ----------------------------
    {
      const harness = loadHarness([
        { payload: { paused: true, state: "capturing", message: PAUSE_NOTICE } },
        { payload: { paused: false, state: "idle", message: "" } },
      ]);
      await flush();
      check(harness.RM.isPaused() === true, "the pause must start observed");
      harness.RM.check();
      await flush();
      check(harness.RM.isPaused() === false, "saving must be available again once the pause clears");
      check(
        harness.toasts.some((t) => /saving changes is available again/i.test(t.message)),
        "active users must be told when saving is available again",
      );
      const form = { method: "post", dataset: {} };
      check(
        !submit(harness.document, form),
        "a resumed session must be able to submit normally again",
      );
    }

    // --- a failed status check never invents a pause or blocks work -------
    {
      const harness = loadHarness([new Error("network down")]);
      await flush();
      check(harness.RM.isPaused() === false, "a failed status check must not pause the session");
      const form = { method: "post", dataset: {} };
      check(
        !submit(harness.document, form),
        "a failed status check must never block Store work",
      );
    }

    // --- an unauthenticated page never polls ------------------------------
    {
      const harness = loadHarness([], { authenticated: false });
      check(
        harness.fetches.length === 0,
        "an unauthenticated page must not call the backup status endpoint",
      );
    }
  } catch (error) {
    failures.push("Backup status client behavior test threw: " + error.message);
  }

  if (failures.length) {
    process.stderr.write(
      "Backup status client behavior test failed:\n- " + failures.join("\n- ") + "\n",
    );
    process.exit(1);
  }

  process.stdout.write("Backup status client behavior test: passed\n");
}

main();
