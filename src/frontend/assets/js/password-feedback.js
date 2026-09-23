(function () {
  "use strict";

  // Immediate password feedback for ticket #35 (Store staff forms).
  // Server-side password_policy_error() remains the source of truth;
  // this module only blocks short passwords client-side before any round-trip
  // and surfaces the same 8-character expectation as the static hint copy.
  var MIN_LENGTH = 8;
  var SHORT_MESSAGE = "Password must contain at least 8 characters.";

  function isMonitored(input) {
    return (
      input instanceof HTMLInputElement &&
      input.type === "password" &&
      input.getAttribute("minlength") === String(MIN_LENGTH)
    );
  }

  function validate(input) {
    var value = input.value || "";
    if (value.length > 0 && value.length < MIN_LENGTH) {
      input.setCustomValidity(SHORT_MESSAGE);
    } else {
      input.setCustomValidity("");
    }
  }

  function bind(input) {
    if (input.dataset.passwordFeedbackBound === "1") {
      return;
    }
    input.dataset.passwordFeedbackBound = "1";
    // Immediate feedback while typing, plus a hook for assistive tech.
    input.addEventListener("input", function () {
      validate(input);
    });
    // Re-validate on submit attempt so the browser blocks before any round-trip.
    var form = input.form;
    if (form && form.dataset.passwordFeedbackBound !== "1") {
      form.dataset.passwordFeedbackBound = "1";
      form.addEventListener("submit", function () {
        var fields = Array.prototype.slice.call(
          form.querySelectorAll('input[type="password"][minlength="8"]'),
        );
        fields.forEach(validate);
      });
    }
    validate(input);
  }

  function init() {
    var inputs = Array.prototype.slice.call(
      document.querySelectorAll('input[type="password"][minlength="8"]'),
    );
    inputs.forEach(function (input) {
      if (isMonitored(input)) {
        bind(input);
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
