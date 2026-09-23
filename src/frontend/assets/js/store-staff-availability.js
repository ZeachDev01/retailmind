(function () {
  "use strict";

  // Live duplicate notice for tickets #39 (username) and #40 (email plus
  // duplicate summary). Server-side availability query plus submit-time
  // unique handling remain the source of truth; this module only surfaces
  // advisory per-field notices and blocks the Create action while any
  // duplicate flag is active.
  var DEBOUNCE_MS = 300;
  var usernameTaken = false;
  var emailTaken = false;
  var duplicateActive = false;

  function setFieldState(group, input, isTaken) {
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
  }

  function updateDuplicateState(usernameGroup, usernameInput, emailGroup, emailInput, summary, submit, nextUsernameTaken, nextEmailTaken) {
    // An empty email is optional and never counts as a duplicate, even if a
    // stale response arrives after the field was cleared.
    var usernameValue = usernameInput ? usernameInput.value || "" : "";
    var emailValue = emailInput ? emailInput.value || "" : "";
    if (usernameValue.trim() === "") {
      nextUsernameTaken = false;
    }
    if (emailValue.trim() === "") {
      nextEmailTaken = false;
    }
    usernameTaken = nextUsernameTaken;
    emailTaken = nextEmailTaken;
    var anyTaken = usernameTaken || emailTaken;
    setFieldState(usernameGroup, usernameInput, usernameTaken);
    setFieldState(emailGroup, emailInput, emailTaken);
    if (summary) {
      summary.hidden = !anyTaken;
    }
    if (submit) {
      if (anyTaken) {
        submit.disabled = true;
        duplicateActive = true;
      } else if (duplicateActive) {
        submit.disabled = false;
        duplicateActive = false;
      }
    }
  }

  function init() {
    var form = document.getElementById("createUserForm");
    if (!form) {
      return;
    }
    var usernameInput = document.getElementById("createUsernameInput");
    var usernameGroup = document.getElementById("createUsernameGroup");
    var emailInput = document.getElementById("createEmailInput");
    var emailGroup = document.getElementById("createEmailGroup");
    var summary = document.getElementById("createDuplicateSummary");
    var submit = document.getElementById("createUserSubmit");
    if (!usernameInput || !submit) {
      return;
    }
    if (!usernameGroup) {
      usernameGroup = usernameInput.closest ? usernameInput.closest(".form-group") : null;
    }
    if (emailInput && !emailGroup) {
      emailGroup = emailInput.closest ? emailInput.closest(".form-group") : null;
    }

    var timer = null;
    var sequence = 0;

    function clearDuplicateState() {
      updateDuplicateState(usernameGroup, usernameInput, emailGroup, emailInput, summary, submit, false, false);
    }

    function clear() {
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      sequence += 1;
      clearDuplicateState();
    }

    function check(usernameValue, emailValue, requestId) {
      var url =
        "?action=availability&username=" +
        encodeURIComponent(usernameValue) +
        "&email=" +
        encodeURIComponent(emailValue);
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
          updateDuplicateState(
            usernameGroup,
            usernameInput,
            emailGroup,
            emailInput,
            summary,
            submit,
            payload && payload.username_taken === true,
            payload && payload.email_taken === true
          );
        })
        .catch(function () {
          if (requestId !== sequence) {
            return;
          }
          clearDuplicateState();
        });
    }

    function schedule() {
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      var usernameValue = usernameInput.value || "";
      var emailValue = emailInput ? emailInput.value || "" : "";
      if (usernameValue.trim() === "" && emailValue.trim() === "") {
        sequence += 1;
        clearDuplicateState();
        return;
      }
      timer = setTimeout(function () {
        timer = null;
        sequence += 1;
        check(usernameValue, emailValue, sequence);
      }, DEBOUNCE_MS);
    }

    usernameInput.addEventListener("input", schedule);
    if (emailInput) {
      emailInput.addEventListener("input", schedule);
    }

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
