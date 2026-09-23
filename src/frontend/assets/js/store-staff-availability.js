(function () {
  "use strict";

  // Live duplicate notice for ticket #39 (create dialog, username only).
  // Server-side availability query plus submit-time unique handling remain the
  // source of truth; this module only surfaces an advisory per-field notice
  // and blocks the Create action while the duplicate flag is active.
  var DEBOUNCE_MS = 300;
  var duplicateActive = false;

  function setDuplicate(group, input, submit, isTaken) {
    if (group) {
      group.classList.toggle("invalid", isTaken);
    }
    if (input) {
      if (isTaken) {
        input.setAttribute("aria-invalid", "true");
      } else {
        input.removeAttribute("aria-invalid");
      }
    }
    if (submit) {
      if (isTaken) {
        submit.disabled = true;
      } else if (duplicateActive) {
        submit.disabled = false;
      }
    }
    duplicateActive = isTaken;
  }

  function init() {
    var form = document.getElementById("createUserForm");
    if (!form) {
      return;
    }
    var input = document.getElementById("createUsernameInput");
    var group = document.getElementById("createUsernameGroup");
    var submit = document.getElementById("createUserSubmit");
    if (!input || !submit) {
      return;
    }
    if (!group) {
      group = input.closest ? input.closest(".form-group") : null;
    }

    var timer = null;
    var sequence = 0;

    function clear() {
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      sequence += 1;
      setDuplicate(group, input, submit, false);
    }

    function check(value, requestId) {
      var url = "?action=availability&username=" + encodeURIComponent(value);
      fetch(url, { headers: { Accept: "application/json" } })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("availability check failed");
          }
          return response.json();
        })
        .then(function (payload) {
          if (requestId !== sequence) {
            return;
          }
          setDuplicate(group, input, submit, payload && payload.username_taken === true);
        })
        .catch(function () {
          if (requestId !== sequence) {
            return;
          }
          setDuplicate(group, input, submit, false);
        });
    }

    input.addEventListener("input", function () {
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      var value = input.value || "";
      if (value.trim() === "") {
        sequence += 1;
        setDuplicate(group, input, submit, false);
        return;
      }
      timer = setTimeout(function () {
        timer = null;
        sequence += 1;
        check(value, sequence);
      }, DEBOUNCE_MS);
    });

    form.addEventListener("reset", clear);

    // The dialog open/close only toggles a class (no form reset), so start
    // each opening with a fresh flag; the next keystroke re-checks.
    var openButton = document.getElementById("openUserModal");
    if (openButton) {
      openButton.addEventListener("click", clear);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
