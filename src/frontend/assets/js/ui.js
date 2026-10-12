(function () {
  "use strict";

  const RM = (window.RetailMindUI = window.RetailMindUI || {});
  RM.navigate = (url) => {
    const navigation = window.RetailMindNavigation || (window.parent !== window && window.parent.RetailMindNavigation);
    return navigation ? navigation.navigate(new URL(url, window.location.href).href) : window.location.assign(url);
  };

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }
  function qsa(selector, root) {
    return Array.from((root || document).querySelectorAll(selector));
  }

  const GENERIC_ALERT = {
    error:
      "Something went wrong. Please try again. Tell your Administrator if this keeps happening.",
    warning:
      "This could not be completed right now. Please try again. Tell your Administrator if this keeps happening.",
    success: "Update saved.",
    info: "Update saved.",
  };

  const TECH_PATTERNS = [
    /SQLSTATE/i,
    /\b(?:PDOException|RuntimeException|LogicException|DomainException|InvalidArgumentException|UnexpectedValueException|Throwable|Exception|Error)\b/,
    /[A-Za-z_][A-Za-z0-9_\\]*(?:Exception|Error)\b/,
    /Stack trace/i,
    /#\d+\s+\S+\.php/,
    /\.php\s*:\s*\d+/,
    /[A-Za-z]:\\[^\s]+/,
    /\/(?:var|home|usr|etc|opt|proc|xampp|Users|Applications)\/[^\s]+/,
    /Object of class/i,
    /\bobject\([^\)]*\)#\d+/i,
    /Call to (?:a )?member function/i,
    /Undefined (?:variable|array key| property)/i,
    /allowed memory size/i,
    /Maximum execution time/i,
    /syntax error/i,
    /Array to string conversion/i,
  ];

  function looksTechnical(text) {
    return TECH_PATTERNS.some(function (pattern) {
      return pattern.test(text);
    });
  }

  RM.isDebug = function () {
    return window.RM_DEBUG === true || window.RM_DEBUG === "true";
  };

  // Safety net: unknown technical text never reaches the shop floor as-is.
  // Returns { message, tech } — tech is only rendered when debug is on.
  // A technical first line demotes the whole message; otherwise only
  // technical-looking tail lines are demoted and plain lines stay.
  RM.sanitizeAlert = function (message, type) {
    const kind = type || "info";
    const raw = String(message == null ? "" : message);
    const lines = raw
      .split(/\r?\n/)
      .map(function (line) {
        return line.trim();
      })
      .filter(Boolean);
    const first = lines[0] || "";
    const rest = lines.slice(1);
    let easy;
    let techParts;
    if (!first || looksTechnical(first)) {
      easy = GENERIC_ALERT[kind] || GENERIC_ALERT.info;
      techParts = lines.slice();
    } else {
      const plainTail = rest.filter(function (line) {
        return !looksTechnical(line);
      });
      techParts = rest.filter(looksTechnical);
      easy = [first].concat(plainTail).join("\n");
    }

    const tech = RM.isDebug() && techParts.length ? techParts.join("\n") : "";
    if (tech && typeof console !== "undefined" && console.error) {
      console.error("Operator Alert technical detail:", raw);
    }
    return { message: easy, tech: tech };
  };

  RM.toast = function (message, type, title, duration) {
    const stack =
      qs("#rm-toast-stack") ||
      (() => {
        const node = document.createElement("div");
        node.id = "rm-toast-stack";
        node.className = "rm-toast-stack";
        node.setAttribute("aria-live", "polite");
        document.body.appendChild(node);
        return node;
      })();
    const kind = type || "info";
    const icons = {
      success: "bi-check-circle-fill",
      error: "bi-exclamation-circle-fill",
      warning: "bi-exclamation-triangle-fill",
      info: "bi-info-circle-fill",
    };
    const labels = {
      success: "Success",
      error: "Unable to continue",
      warning: "Attention",
      info: "Update",
    };
    const toast = document.createElement("div");
    toast.className = "rm-toast " + kind;
    toast.setAttribute("role", kind === "error" ? "alert" : "status");
    toast.innerHTML =
      '<i class="bi ' +
      (icons[kind] || icons.info) +
      ' rm-toast-icon" aria-hidden="true"></i>' +
      '<div class="rm-toast-copy"><strong></strong><span></span></div>' +
      '<button type="button" class="rm-toast-close" aria-label="Dismiss notification"><i class="bi bi-x-lg"></i></button>';
    qs("strong", toast).textContent = title || labels[kind] || labels.info;
    const clean = RM.sanitizeAlert(message, kind);
    qs("span", toast).textContent = clean.message;
    if (clean.tech) {
      const techLine = document.createElement("small");
      techLine.className = "rm-toast-tech";
      techLine.textContent = clean.tech;
      qs(".rm-toast-copy", toast).appendChild(techLine);
    }
    const remove = () => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 250);
    };
    qs(".rm-toast-close", toast).addEventListener("click", remove);
    stack.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add("show"));
    setTimeout(remove, Number(duration || 4800));
    return toast;
  };

  RM.alert = function (message, type, title) {
    const kind = type || "info";
    const labels = {
      success: "Success",
      error: "Unable to continue",
      warning: "Attention",
      info: "Update",
    };
    if (window.Swal && typeof window.Swal.fire === "function") {
      if (!document.getElementById("rm-swal-layer-style")) {
        const layerStyle = document.createElement("style");
        layerStyle.id = "rm-swal-layer-style";
        layerStyle.textContent =
          ".swal2-container { z-index: 10000 !important; }";
        document.head.appendChild(layerStyle);
      }
      const clean = RM.sanitizeAlert(message, kind);
      const escapeHtml = function (value) {
        return String(value)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;");
      };
      const html =
        "<p>" +
        escapeHtml(clean.message) +
        "</p>" +
        (clean.tech
          ? '<div class="rm-swal-tech">' + escapeHtml(clean.tech) + "</div>"
          : "");
      return window.Swal.fire({
        icon: kind,
        title: title || labels[kind] || labels.info,
        html: html,
        confirmButtonText: "OK",
        buttonsStyling: false,
        customClass: { confirmButton: "btn rm-swal-confirm" },
      });
    }
    return Promise.resolve(RM.toast(message, kind, title));
  };

  let alertQueue = Promise.resolve();
  RM.queueAlert = function (message, type, title) {
    alertQueue = alertQueue.then(() => RM.alert(message, type, title));
    return alertQueue;
  };

  const overlaySelector = ".command-overlay, .rm-modal-overlay, .rm-drawer-overlay, .checkout-modal, .user-modal-overlay, .user-drawer-overlay";
  const overlayState = new WeakMap();
  const overlayOrder = [];
  const boundForms = new WeakSet();

  function openOverlays() {
    return qsa(overlaySelector).filter((overlay) => overlay.classList.contains("open"));
  }

  function topOverlay() {
    const open = openOverlays();
    return overlayOrder.filter((overlay) => open.includes(overlay)).pop() || open.pop();
  }

  function focusableIn(overlay) {
    return qsa('summary, input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled), a[href], [tabindex]:not([tabindex="-1"])', overlay)
      .filter((node) => node.getClientRects().length && getComputedStyle(node).visibility !== "hidden");
  }

  RM.syncScrollLock = function () {
    document.body.classList.toggle("no-scroll", openOverlays().length > 0 || !!qs("#sidebarOverlay.open, dialog[open]"));
  };

  RM.openOverlay = function (overlay) {
    if (!overlay || overlay.classList.contains("open")) return;
    const zIndex = overlay.style.zIndex;
    const layers = openOverlays().map((node) => Number(getComputedStyle(node).zIndex) || 2000);
    overlayState.set(overlay, {trigger: document.activeElement, zIndex});
    overlayOrder.push(overlay);
    if (layers.length) overlay.style.zIndex = String(Math.max(...layers) + 1);
    overlay.classList.add("open");
    overlay.setAttribute("aria-hidden", "false");
    RM.syncScrollLock();
    overlay.dispatchEvent(new CustomEvent("retailmind:overlayopen", {bubbles: true}));
    setTimeout(() => {
      if (qs("dialog[open]") || window.Swal?.isVisible?.()) return;
      if (overlay === topOverlay() && !overlay.contains(document.activeElement)) focusableIn(overlay)[0]?.focus({preventScroll: true});
    }, 30);
  };

  RM.closeOverlay = function (overlay) {
    if (!overlay || !overlay.classList.contains("open")) return;
    const restoreFocus = overlay === topOverlay();
    const state = overlayState.get(overlay);
    overlay.classList.remove("open");
    overlay.setAttribute("aria-hidden", "true");
    if (state) overlay.style.zIndex = state.zIndex;
    overlayState.delete(overlay);
    const index = overlayOrder.indexOf(overlay);
    if (index !== -1) overlayOrder.splice(index, 1);
    RM.syncScrollLock();
    if (restoreFocus) {
      const next = topOverlay();
      const target = state?.trigger?.isConnected && (!next || next.contains(state.trigger))
        ? state.trigger : next && focusableIn(next)[0];
      target?.focus({preventScroll: true});
    }
    overlay.dispatchEvent(new CustomEvent("retailmind:overlayclose", {bubbles: true}));
  };

  RM.confirm = function (options) {
    const opts = options || {};
    return new Promise((resolve) => {
      const overlay = document.createElement("div");
      overlay.className = "rm-modal-overlay";
      overlay.dataset.rmConfirm = "";
      overlay.setAttribute("aria-hidden", "true");
      overlay.innerHTML =
        '<section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="rm-confirm-title">' +
        '<header class="rm-modal-header"><div><h2 id="rm-confirm-title"></h2><p></p></div><button class="rm-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button></header>' +
        '<div class="rm-modal-body"></div><footer class="rm-modal-actions"><button type="button" class="btn btn-quiet rm-cancel">Cancel</button><button type="button" class="btn rm-accept">Confirm</button></footer></section>';
      qs("h2", overlay).textContent = opts.title || "Confirm action";
      qs(".rm-modal-header p", overlay).textContent =
        opts.subtitle || "Review this action before continuing.";
      const cleanBody = RM.sanitizeAlert(
        opts.message || "Are you sure you want to continue?",
        opts.danger ? "error" : "warning",
      );
      qs(".rm-modal-body", overlay).textContent = cleanBody.message;
      const accept = qs(".rm-accept", overlay);
      accept.textContent = opts.confirmText || "Confirm";
      if (opts.danger) accept.classList.add("btn-danger");
      let settled = false;
      const finish = (value) => {
        if (settled) return;
        settled = true;
        RM.closeOverlay(overlay);
        setTimeout(() => overlay.remove(), 200);
        resolve(value);
      };
      overlay.addEventListener("retailmind:overlayclose", () => finish(false), {once: true});
      qs(".rm-close", overlay).addEventListener("click", () => finish(false));
      qs(".rm-cancel", overlay).addEventListener("click", () => finish(false));
      accept.addEventListener("click", () => finish(true));
      overlay.addEventListener("click", (event) => {
        if (event.target === overlay) finish(false);
      });
      document.body.appendChild(overlay);
      RM.openOverlay(overlay);
    });
  };

  function initQueryToasts() {
    const params = new URLSearchParams(window.location.search);
    const hasNotification =
      params.has("success") || params.has("error") || params.has("warning");
    if (params.get("success")) RM.queueAlert(params.get("success"), "success");
    if (params.get("error")) RM.queueAlert(params.get("error"), "error");
    if (params.get("warning")) RM.queueAlert(params.get("warning"), "warning");
    if (hasNotification && window.history.replaceState) {
      ["success", "error", "warning"].forEach((key) => params.delete(key));
      const cleanQuery = params.toString();
      window.history.replaceState(
        {},
        document.title,
        window.location.pathname +
          (cleanQuery ? "?" + cleanQuery : "") +
          window.location.hash,
      );
    }
  }

  function initServerNotifications() {
    const flash = qs("#rm-flash-messages");
    const hasSwal = window.Swal && typeof window.Swal.fire === "function";
    const notices = [];
    if (flash && hasSwal) {
      flash.hidden = true;
      ["success", "error", "warning", "info"].forEach((kind) => {
        const message = flash.dataset[kind];
        if (message) notices.push({ message, kind });
      });
    }
    qsa(".alert, .error-msg, .message, .pos-alert").forEach((node) => {
      if (node === flash) return;
      if (node.dataset.rmAlertProcessed === "1") return;
      const message = (node.textContent || "")
        .split(/\r?\n/)
        .map(function (line) {
          return line.replace(/\s+/g, " ").trim();
        })
        .filter(Boolean)
        .join("\n");
      if (!message) return;
      const kind = node.matches(
        ".error-msg, .message.error, .alert-error, .pos-alert.error, .tag-warning, .alert-warning",
      )
        ? node.matches(".tag-warning, .alert-warning")
          ? "warning"
          : "error"
        : node.matches(
              ".message.success, .alert-success, .pos-alert.success, .tag-success",
            )
          ? "success"
          : "info";
      node.dataset.rmAlertProcessed = "1";
      if (hasSwal) {
        node.hidden = true;
        notices.push({ message, kind });
      }
    });
    let lastAlert = null;
    notices.forEach((notice) => {
      lastAlert = RM.queueAlert(notice.message, notice.kind);
    });
    if (flash && flash.dataset.redirect) {
      if (hasSwal && lastAlert) {
        lastAlert.then(() => RM.navigate(flash.dataset.redirect));
      } else if (!hasSwal) {
        window.setTimeout(
          () => RM.navigate(flash.dataset.redirect),
          3500,
        );
      }
    }
  }

  function initCommandPalette() {
    const overlay = qs("#commandPalette");
    const input = qs("#commandSearch");
    const results = qs("#commandResults");
    if (!overlay || !input || !results) return;
    const rawItems = qsa("#appSidebar a[href]")
      .filter((link) => !link.classList.contains("sidebar-logout"))
      .map((link) => ({
        label: (link.textContent || "").trim().replace(/\s+/g, " "),
        href: link.href,
        icon: (qs("i", link) || {}).className || "bi bi-arrow-right",
        section:
          (
            (link.closest(".sidebar-section") &&
              qs(".sidebar-section-title", link.closest(".sidebar-section"))) ||
            {}
          ).textContent || "Navigation",
      }));
    const unique = rawItems.filter(
      (item, index, all) =>
        item.label &&
        all.findIndex((candidate) => candidate.href === item.href) === index,
    );
    let activeIndex = 0;
    let visibleItems = [];
    let productItems = [];
    let productSearchTimer = null;
    let productAbortController = null;
    const productsApi = overlay.dataset.productsApi || "";
    const productTarget = overlay.dataset.productTarget || "";

    function render() {
      const term = input.value.trim().toLowerCase();
      const navigationItems = unique.filter(
        (item) =>
          !term ||
          (item.label + " " + item.section).toLowerCase().includes(term),
      );
      visibleItems = [...productItems, ...navigationItems]
        .filter(
          (item, index, all) =>
            all.findIndex(
              (candidate) =>
                candidate.href === item.href && candidate.label === item.label,
            ) === index,
        )
        .slice(0, 12);
      activeIndex = Math.min(activeIndex, Math.max(0, visibleItems.length - 1));
      if (!visibleItems.length) {
        results.innerHTML =
          '<div class="command-empty"><i class="bi bi-search"></i><br>No matching page found.</div>';
        return;
      }
      results.innerHTML = visibleItems
        .map(
          (item, index) =>
            '<a class="command-result ' +
            (index === activeIndex ? "active" : "") +
            '" href="' +
            item.href +
            '" data-index="' +
            index +
            '">' +
            '<i class="' +
            item.icon +
            '"></i><span class="command-result-copy"><strong></strong><span></span></span></a>',
        )
        .join("");
      qsa(".command-result", results).forEach((node, index) => {
        qs("strong", node).textContent = visibleItems[index].label;
        qs(".command-result-copy span", node).textContent =
          visibleItems[index].section.trim();
      });
    }
    function open() {
      RM.openOverlay(overlay);
      input.value = "";
      render();
    }
    function close() {
      RM.closeOverlay(overlay);
    }
    qsa("[data-command-open]").forEach((button) =>
      button.addEventListener("click", open),
    );
    qsa("[data-command-close]", overlay).forEach((button) =>
      button.addEventListener("click", close),
    );
    overlay.addEventListener("click", (event) => {
      if (event.target === overlay) close();
    });
    input.addEventListener("input", function () {
      const term = input.value.trim();
      productItems = [];
      render();
      clearTimeout(productSearchTimer);
      if (productAbortController) {
        productAbortController.abort();
        productAbortController = null;
      }
      if (term.length < 2 || !productsApi || !productTarget) return;
      productSearchTimer = setTimeout(async function () {
        try {
          productAbortController = new AbortController();
          const response = await fetch(
            productsApi +
              "?scope=all&q=" +
              encodeURIComponent(term) +
              "&limit=6",
            { signal: productAbortController.signal },
          );
          const data = await response.json();
          productItems = data.success
            ? (data.products || []).map((product) => ({
                label: product.name || product.product_name || "Product",
                href:
                  productTarget +
                  "?q=" +
                  encodeURIComponent(
                    product.sku || product.barcode || product.name || term,
                  ),
                icon: "bi bi-box-seam",
                section:
                  "Product · " + (product.sku || product.barcode || "No code"),
              }))
            : [];
        } catch (error) {
          if (error && error.name === "AbortError") return;
          productItems = [];
        }
        render();
      }, 220);
    });
    input.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        activeIndex = Math.min(visibleItems.length - 1, activeIndex + 1);
        render();
      }
      if (event.key === "ArrowUp") {
        event.preventDefault();
        activeIndex = Math.max(0, activeIndex - 1);
        render();
      }
      if (event.key === "Enter" && visibleItems[activeIndex]) {
        RM.navigate(visibleItems[activeIndex].href);
      }
    });
    document.addEventListener("keydown", (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        overlay.classList.contains("open") ? close() : open();
      }
    });
  }

  function initConnectionStatus() {
    const status = qs("#rm-connection-status");
    if (!status) return;
    function update() {
      const online = navigator.onLine;
      status.classList.toggle("offline", !online);
      status.textContent = online ? "Online" : "Offline";
      status.title = online
        ? "Connection available"
        : "Connection unavailable. Unsaved actions may fail.";
      if (!online)
        RM.toast(
          "You are offline. Check your connection and try again. Tell your Administrator if this keeps happening.",
          "warning",
          "Attention",
          6500,
        );
    }
    window.addEventListener("online", () => {
      update();
      RM.toast("Connection restored.", "success");
    });
    window.addEventListener("offline", update);
    update();
  }

  function initSidebarState() {
    const collapse = qs("#sidebarCollapse");
    const sidebar = qs("#appSidebar");
    const accountMenu = qs("#sidebarAccountMenu");
    const accountMenuTriggers = qsa("[data-account-menu-open]");
    if (collapse && sidebar) {
      const stored =
        localStorage.getItem("retailmind_sidebar_collapsed") === "1";
      if (stored && window.innerWidth > 900)
        document.body.classList.add("sidebar-collapsed");
      collapse.addEventListener("click", () => {
        document.body.classList.toggle("sidebar-collapsed");
        const collapsed = document.body.classList.contains("sidebar-collapsed");
        localStorage.setItem(
          "retailmind_sidebar_collapsed",
          collapsed ? "1" : "0",
        );
        collapse.setAttribute(
          "aria-label",
          collapsed ? "Expand sidebar" : "Collapse sidebar",
        );
        const icon = qs("i", collapse);
        if (icon)
          icon.className =
            "bi " +
            (collapsed
              ? "bi-layout-sidebar-inset"
              : "bi-layout-sidebar-inset-reverse");
      });
    }
    if (accountMenu && accountMenuTriggers.length) {
      const submenus = qsa(".sidebar-workspace-switcher, .sidebar-preferences-menu", accountMenu);
      const positionSubmenu = (submenu) => {
        const panel = submenu.querySelector(".sidebar-workspace-options, .sidebar-preferences-options");
        const summary = submenu.querySelector("summary");
        if (!panel || !summary) return;
        const anchor = summary.getBoundingClientRect();
        const menuBounds = accountMenu.getBoundingClientRect();
        panel.style.left = "0px";
        panel.style.top = "0px";
        panel.style.maxHeight = "";
        panel.style.overflowY = "";
        const width = panel.offsetWidth;
        let height = panel.offsetHeight;
        const rightFits = menuBounds.right + 8 + width <= window.innerWidth - 12;
        const leftFits = menuBounds.left - width - 8 >= 12;
        let left = rightFits ? menuBounds.right + 8 : menuBounds.left - width - 8;
        let top = Math.max(12, Math.min(anchor.top, window.innerHeight - height - 12));
        if (!rightFits && !leftFits) {
          left = Math.max(12, Math.min(anchor.left, window.innerWidth - width - 12));
          const below = Math.max(0, window.innerHeight - anchor.bottom - 20);
          const above = Math.max(0, anchor.top - 20);
          const placeBelow = height <= below || (height > above && below >= above);
          const available = placeBelow ? below : above;
          if (height > available) {
            panel.style.maxHeight = `${available}px`;
            panel.style.overflowY = "auto";
            height = panel.offsetHeight;
          }
          top = placeBelow ? anchor.bottom + 8 : anchor.top - height - 8;
        }
        // Fixed descendants use the transformed account menu as their origin.
        panel.style.left = `${left - menuBounds.left}px`;
        panel.style.top = `${top - menuBounds.top}px`;
      };
      submenus.forEach((submenu) => {
        submenu.addEventListener("toggle", () => {
          const summary = submenu.querySelector("summary");
          if (summary) summary.setAttribute("aria-expanded", String(submenu.open));
          if (!submenu.open) return;
          submenus.forEach((other) => {
            if (other !== submenu) other.open = false;
          });
          positionSubmenu(submenu);
        });
        const initialSummary = submenu.querySelector("summary");
        if (initialSummary) initialSummary.setAttribute("aria-expanded", "false");
      });
      window.addEventListener("resize", () => {
        submenus.forEach((submenu) => {
          if (submenu.open) positionSubmenu(submenu);
        });
      });
      const setAccountMenuOpen = function (isOpen) {
        if (!isOpen) submenus.forEach((submenu) => { submenu.open = false; });
        accountMenu.classList.toggle("open", isOpen);
        accountMenu.setAttribute("aria-hidden", isOpen ? "false" : "true");
        accountMenuTriggers.forEach((trigger) => {
          trigger.setAttribute("aria-expanded", isOpen ? "true" : "false");
        });
      };
      accountMenuTriggers.forEach((trigger) => {
        trigger.addEventListener("click", (event) => {
          event.stopPropagation();
          setAccountMenuOpen(!accountMenu.classList.contains("open"));
        });
      });
      document.addEventListener("click", (event) => {
        if (!accountMenu.contains(event.target)) setAccountMenuOpen(false);
      });
      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") setAccountMenuOpen(false);
      });
    }
  }

  function initFullscreenToggle() {
    const button = qs("[data-fullscreen-toggle]");
    if (!button) return;
    const icon = qs("i", button);
    const label = qs("span", button);
    const storageKey = "retailmind_fullscreen";
    const fullscreenDocument = window.parent !== window && window.parent.RetailMindNavigation
      ? window.parent.document : document;
    const root = fullscreenDocument.documentElement;
    const nativeElement = () => fullscreenDocument.fullscreenElement || fullscreenDocument.webkitFullscreenElement;
    let nativeActive = !!nativeElement();
    let leaving = false;
    const update = (active) => {
      root.classList.toggle("rm-app-fullscreen", active);
      document.documentElement.classList.toggle("rm-app-fullscreen", active);
      button.setAttribute("aria-pressed", active ? "true" : "false");
      if (icon)
        icon.className = "bi " + (active ? "bi-fullscreen-exit" : "bi-arrows-fullscreen");
      if (label) label.textContent = active ? "Exit Full Screen" : "Full Screen";
    };
    const save = (active) => {
      update(active);
      try {
        localStorage.setItem(storageKey, active ? "1" : "0");
      } catch (_) {}
    };
    const restore = () => {
      leaving = false;
      try {
        update(!!nativeElement() || localStorage.getItem(storageKey) === "1");
      } catch (_) {} // Storage may be unavailable in private/restricted browsers.
    };
    button.addEventListener("click", async () => {
      const active = !nativeElement() && !root.classList.contains("rm-app-fullscreen");
      save(active);
      try {
        if (!active && nativeElement()) {
          if (fullscreenDocument.exitFullscreen) await fullscreenDocument.exitFullscreen();
          else if (fullscreenDocument.webkitExitFullscreen) await fullscreenDocument.webkitExitFullscreen();
        } else if (active) {
          if (root.requestFullscreen) await root.requestFullscreen({ navigationUI: "hide" });
          else if (root.webkitRequestFullscreen) await root.webkitRequestFullscreen();
        }
      } catch (_) {
        // Keep CSS app mode if native fullscreen is unavailable or rejected.
        if (nativeElement()) save(true);
      }
    });
    const nativeChanged = () => {
      const active = !!nativeElement();
      if (active) save(true);
      else if (nativeActive && !leaving && document.visibilityState !== "hidden") save(false);
      nativeActive = active;
    };
    fullscreenDocument.addEventListener("fullscreenchange", nativeChanged);
    fullscreenDocument.addEventListener("webkitfullscreenchange", nativeChanged);
    window.addEventListener("pagehide", () => {
      fullscreenDocument.removeEventListener("fullscreenchange", nativeChanged);
      fullscreenDocument.removeEventListener("webkitfullscreenchange", nativeChanged);
    });
    window.addEventListener("pagehide", () => { leaving = true; });
    // CSS mode also works when the browser does not support native fullscreen.
    window.addEventListener("pageshow", restore);
    restore();
  }

  function initDialogAccessibility() {
    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape" && event.key !== "Tab") return;
      const overlay = topOverlay();
      if (!overlay || event.defaultPrevented || qs("dialog[open]") || window.Swal?.isVisible?.()) return;
      // Checkout owns dismissal while payment submission is in progress.
      if (event.key === "Escape" && !overlay.matches(".checkout-modal")) {
        event.preventDefault();
        event.stopImmediatePropagation();
        RM.closeOverlay(overlay);
      }
      if (event.key === "Tab") {
        const nodes = focusableIn(overlay);
        if (!nodes.length) { event.preventDefault(); return; }
        const target = event.shiftKey ? nodes[nodes.length - 1] : nodes[0];
        if (!overlay.contains(document.activeElement) || (event.shiftKey ? document.activeElement === nodes[0] : document.activeElement === nodes[nodes.length - 1])) {
          event.preventDefault();
          target.focus({preventScroll: true});
        }
      }
    });
    document.addEventListener("click", (event) => {
      const overlay = event.target.closest(overlaySelector);
      if (!overlay || overlay.matches(".checkout-modal")) return;
      if (overlay && event.target === overlay && overlay.classList.contains("open")) {
        RM.closeOverlay(overlay);
        return;
      }
      const closeBtn = event.target.closest("[data-close-modal], [data-close-drawer], .rm-close, .modal-close, .user-modal-close, .user-drawer-close");
      if (closeBtn) RM.closeOverlay(overlay);
    });
    document.addEventListener("submit", (event) => {
      const form = event.target;
      if (!form || event.defaultPrevented) return;
      if (form.dataset.rmSubmitting === "1") {
        event.preventDefault();
        return;
      }
      form.dataset.rmSubmitting = "1";
      setTimeout(() => {
        if (event.defaultPrevented) { delete form.dataset.rmSubmitting; return; }
        const submits = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        submits.forEach((btn) => {
          btn.disabled = true;
          btn.classList.add("btn-loading");
        });
      }, 0);
    });
    window.addEventListener("pageshow", () => {
      qsa("form[data-rm-submitting='1']").forEach((form) => {
        delete form.dataset.rmSubmitting;
        const submits = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        submits.forEach((btn) => {
          btn.disabled = false;
          btn.classList.remove("btn-loading");
        });
      });
      qsa("[data-backup-create]").forEach((btn) => {
        btn.disabled = false;
        btn.removeAttribute("aria-busy");
        if (btn.dataset.rmBackupLabel) {
          btn.textContent = btn.dataset.rmBackupLabel;
        }
      });
    });
  }

  function initForms() {
    qsa("form[data-required-field], form[data-confirm]").forEach((form) => {
      if (boundForms.has(form)) return;
      boundForms.add(form);
      let confirming = false;
      form.addEventListener("submit", async (event) => {
        if (event.defaultPrevented) return;
        const field = form.elements[form.dataset.requiredField];
        if (field && !String(field.value || "").trim()) {
          event.preventDefault();
          field.focus();
          RM.toast(form.dataset.requiredMessage || "Complete the required field before continuing.", "error");
          return;
        }
        if (!form.hasAttribute("data-confirm") || form.dataset.confirmed === "1") return;
        event.preventDefault();
        if (confirming) return;
        confirming = true;
        const ok = await RM.confirm({
          title: form.dataset.confirmTitle || "Confirm action",
          message: form.dataset.confirm || "Continue with this action?",
          confirmText: form.dataset.confirmButton || "Continue",
          danger: form.dataset.confirmDanger === "1",
        });
        confirming = false;
        if (ok && form.isConnected) {
          form.dataset.confirmed = "1";
          form.requestSubmit(event.submitter || undefined);
          delete form.dataset.confirmed;
        }
      });
    });
  }

  function initSmartTables() {
    qsa(".main-content .table-wrap table").forEach((table, tableIndex) => {
      if (
        table.matches(
          ".data-table, .cart-table, .receipt-table, [data-no-smart-table]",
        ) ||
        table.closest("#product-table") ||
        table.closest("#overview-product-view, #overview-panel-movements, #overview-panel-fefo, .product-overview-modal") ||
        table.closest(".overview-panel") ||
        (table.closest(".table-wrap") && (
          table.closest(".table-wrap").nextElementSibling?.classList.contains("overview-pagination") ||
          table.closest(".table-wrap").nextElementSibling?.classList.contains("table-pagination")
        ))
      )
        return;
      const body = table.tBodies[0];
      if (!body) return;
      const rows = qsa("tr", body).filter((row) => row.querySelector("td"));
      if (rows.length < 8) return;
      const wrap = table.closest(".table-wrap");
      if (
        !wrap ||
        wrap.previousElementSibling?.classList.contains("auto-table-toolbar")
      )
        return;

      const toolbar = document.createElement("div");
      toolbar.className = "table-toolbar auto-table-toolbar";
      toolbar.innerHTML =
        '<label class="toolbar-search"><i class="bi bi-search"></i><input type="search" placeholder="Search this table" aria-label="Search table"></label>' +
        '<button type="button" class="btn btn-quiet btn-icon auto-table-clear"><i class="bi bi-x-circle"></i>Clear</button>' +
        '<span class="toolbar-count"></span>';
      wrap.parentNode.insertBefore(toolbar, wrap);
      const footer = document.createElement("div");
      footer.className = "table-pagination auto-table-pagination";
      footer.innerHTML =
        '<span class="auto-page-summary"></span><label>Rows <select aria-label="Rows per page"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><div class="pagination-buttons"></div>';
      wrap.insertAdjacentElement("afterend", footer);
      const search = qs("input", toolbar);
      const count = qs(".toolbar-count", toolbar);
      const pageSize = qs("select", footer);
      const summary = qs(".auto-page-summary", footer);
      const buttons = qs(".pagination-buttons", footer);
      let page = 1;
      let filtered = rows.slice();

      function render(reset = false) {
        if (reset) page = 1;
        const term = search.value.trim().toLowerCase();
        filtered = rows.filter(
          (row) => !term || row.textContent.toLowerCase().includes(term),
        );
        const size = Number(pageSize.value || 25);
        const pages = Math.max(1, Math.ceil(filtered.length / size));
        page = Math.min(page, pages);
        const start = (page - 1) * size;
        const visible = new Set(filtered.slice(start, start + size));
        rows.forEach((row) => (row.hidden = !visible.has(row)));
        count.textContent =
          filtered.length + " result" + (filtered.length === 1 ? "" : "s");
        summary.textContent = filtered.length
          ? `Showing ${start + 1}–${Math.min(start + size, filtered.length)} of ${filtered.length}`
          : "Showing 0 results";
        buttons.innerHTML = "";
        const add = (label, target, disabled, active) => {
          const button = document.createElement("button");
          button.type = "button";
          button.textContent = label;
          button.disabled = disabled;
          button.classList.toggle("active", !!active);
          button.addEventListener("click", () => {
            page = target;
            render();
          });
          buttons.appendChild(button);
        };
        add("‹", Math.max(1, page - 1), page === 1, false);
        for (
          let p = Math.max(1, page - 2);
          p <= Math.min(pages, Math.max(1, page - 2) + 4);
          p++
        )
          add(String(p), p, false, p === page);
        add("›", Math.min(pages, page + 1), page === pages, false);
      }
      search.addEventListener("input", () => render(true));
      pageSize.addEventListener("change", () => render(true));
      qs(".auto-table-clear", toolbar).addEventListener("click", () => {
        search.value = "";
        render(true);
        search.focus();
      });
      render();
    });
  }

  RM.initPageContent = function () {
    initQueryToasts();
    initServerNotifications();
    initForms();
    initSmartTables();
  };

  initDialogAccessibility();
  document.addEventListener("DOMContentLoaded", function () {
    initCommandPalette();
    initConnectionStatus();
    initSidebarState();
    initFullscreenToggle();
    RM.initPageContent();
  });
})();
