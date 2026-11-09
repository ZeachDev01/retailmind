(function () {
  "use strict";

  // Shared visual checklist for staff password fields. Mirrors the reset /
  // change password meter: server-side password_policy_error() stays the source
  // of truth, this only gives immediate feedback while typing.
  var MIN_LENGTH = 8;
  var RULES = [
    { key: "len", test: function (value) { return value.length >= MIN_LENGTH; } },
    { key: "upper", test: function (value) { return /[A-Z]/.test(value); } },
    { key: "lower", test: function (value) { return /[a-z]/.test(value); } },
    { key: "num", test: function (value) { return /[0-9]/.test(value); } },
  ];

  function setChip(chip, met) {
    if (!chip) return;
    chip.classList.toggle("valid", met);
    var icon = chip.querySelector("i");
    if (icon) {
      icon.className = met ? "bi bi-check-circle-fill" : "bi bi-circle";
    }
  }

  function bind(input, panel) {
    var chips = {};
    Array.prototype.forEach.call(panel.querySelectorAll("[data-rule]"), function (chip) {
      chips[chip.dataset.rule] = chip;
    });
    var bars = Array.prototype.slice.call(panel.querySelectorAll("[data-strength-bar]"));

    function render() {
      var value = input.value || "";
      var score = 0;
      RULES.forEach(function (rule) {
        var met = rule.test(value);
        if (met) score += 1;
        setChip(chips[rule.key], met);
      });
      var level =
        score === 4 ? "active-strong" :
        score === 3 ? "active-good" :
        score === 2 ? "active-fair" : "active-weak";
      bars.forEach(function (bar, index) {
        bar.className = "cp-meter-bar";
        if (value.length > 0 && index < score) {
          bar.classList.add(level);
        }
      });
    }

    input.addEventListener("input", render);
    render();
  }

  function init() {
    Array.prototype.forEach.call(
      document.querySelectorAll("input[data-password-criteria]"),
      function (input) {
        var panel = document.getElementById(input.getAttribute("data-password-criteria"));
        if (!panel || panel.dataset.passwordCriteriaBound === "1") return;
        panel.dataset.passwordCriteriaBound = "1";
        bind(input, panel);
      },
    );
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
