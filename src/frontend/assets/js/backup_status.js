// Shared Database Backup status client (#69).
//
// Every signed-in session polls a lightweight status endpoint so that a
// session which opens or reconnects during a capture is told as well. While
// the pause is active the client shows one small shared notice, keeps browsing
// and unsaved form input intact, and refuses to let a form submit navigate away
// and discard that input. The backend still enforces the pause; this only
// avoids a pointless round trip and keeps the operator informed.

(function () {
  "use strict";

  const RM = (window.RetailMindUI = window.RetailMindUI || {});

  const IDLE_INTERVAL_MS = 20000;
  const ACTIVE_INTERVAL_MS = 4000;
  const PAUSE_NOTICE =
    "A database backup is in progress. Saving changes is temporarily paused.";

  const state = {
    paused: false,
    endpoint: "",
    timer: null,
    notice: null,
    inFlight: false,
  };

  function isAuthenticatedPage() {
    return document.body && document.body.dataset.rmAuthenticated === "true";
  }

  function statusEndpoint() {
    const attribute =
      document.body.getAttribute("data-rm-backup-status") ||
      document.querySelector("[data-rm-backup-status]")?.getAttribute("data-rm-backup-status") ||
      "";
    return String(attribute || "").trim();
  }

  function noticeElement() {
    if (state.notice && document.body.contains(state.notice)) {
      return state.notice;
    }
    const existing = document.querySelector("[data-rm-backup-notice]");
    if (existing) {
      state.notice = existing;
      return existing;
    }
    const element = document.createElement("div");
    element.className = "rm-backup-notice";
    element.setAttribute("data-rm-backup-notice", "");
    element.setAttribute("role", "status");
    element.setAttribute("aria-live", "polite");
    element.hidden = true;
    document.body.appendChild(element);
    state.notice = element;
    return element;
  }

  function render(paused, message) {
    const element = noticeElement();
    if (paused) {
      element.textContent = message || PAUSE_NOTICE;
      element.hidden = false;
    } else {
      element.hidden = true;
      element.textContent = "";
    }
  }

  function schedule() {
    if (state.timer) {
      window.clearTimeout(state.timer);
    }
    state.timer = window.setTimeout(
      check,
      state.paused ? ACTIVE_INTERVAL_MS : IDLE_INTERVAL_MS,
    );
  }

  function apply(payload) {
    const paused = !!(payload && payload.paused);
    const previous = state.paused;
    state.paused = paused;

    if (paused) {
      render(true, (payload && payload.message) || PAUSE_NOTICE);
    } else {
      render(false, "");
      if (previous) {
        announceResumed();
      }
    }
    schedule();
  }

  function announceResumed() {
    const element = noticeElement();
    element.textContent = "Saving changes is available again.";
    element.hidden = false;
    element.classList.add("is-resumed");
    window.setTimeout(function () {
      element.classList.remove("is-resumed");
      if (!state.paused) {
        render(false, "");
      }
    }, 6000);
    if (RM.toast) {
      RM.toast("Saving changes is available again.", "success", "Update", 6000);
    }
  }

  function check() {
    if (state.inFlight || !state.endpoint) {
      schedule();
      return;
    }
    state.inFlight = true;
    window
      .fetch(state.endpoint, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        cache: "no-store",
      })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (payload) {
        state.inFlight = false;
        if (payload) {
          apply(payload);
        } else {
          schedule();
        }
      })
      .catch(function () {
        // A failed status check never invents a pause and never blocks work.
        state.inFlight = false;
        schedule();
      });
  }

  function onSubmit(event) {
    const form = event.target;
    if (form && form.dataset && form.dataset.rmBackupBypass === "true") {
      return;
    }
    if (state.paused) {
      if (!form || (form.method && String(form.method).toLowerCase() !== "post")) {
        return;
      }
      event.preventDefault();
      if (RM.alert) {
        RM.alert(
          PAUSE_NOTICE +
            " Your work is still here — wait a moment and save again.",
          "warning",
        );
      }
      return;
    }
    markPreparing(form);
  }

  // The requester sees that a backup is being prepared instead of pressing the
  // action again. No percentage is invented: the capture cannot be measured.
  function markPreparing(form) {
    if (!form || typeof form.querySelectorAll !== "function") {
      return;
    }
    const buttons = Array.from(form.querySelectorAll("[data-backup-create]"));
    buttons.forEach(function (button) {
      if (button.dataset && button.dataset.rmBackupPending === "true") {
        return;
      }
      if (button.dataset) {
        button.dataset.rmBackupPending = "true";
      }
      button.setAttribute("aria-busy", "true");
      button.disabled = true;
      if (button.dataset) {
        button.dataset.rmBackupLabel = button.textContent;
      }
      button.textContent = "Preparing your backup…";
    });
  }

  function start() {
    if (!isAuthenticatedPage()) {
      return;
    }
    state.endpoint = statusEndpoint();
    if (!state.endpoint) {
      return;
    }
    document.addEventListener("submit", onSubmit, true);
    // A page that is already open when the capture starts must show the notice
    // as soon as the pause is observed, not only on the next navigation.
    check();
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) {
        check();
      }
    });
  }

  RM.backupStatus = {
    PAUSE_NOTICE: PAUSE_NOTICE,
    start: start,
    check: check,
    isPaused: function () {
      return state.paused;
    },
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
