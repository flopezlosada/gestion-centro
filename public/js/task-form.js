// Task form: show only the responsibility field that matches the chosen mode (cargo/person/role).
// Progressive enhancement — with no JS every field stays visible and validation still guides the user.
(function () {
  'use strict';

  var modeInputs = document.querySelectorAll('.resp-mode input[type="radio"]');
  if (!modeInputs.length) {
    return; // No mode selector on this page (a plain creator only ever sees the person field).
  }

  var rows = document.querySelectorAll('.form-row[data-resp-mode]');

  function apply() {
    var checked = document.querySelector('.resp-mode input[type="radio"]:checked');
    var mode = checked ? checked.value : null;
    rows.forEach(function (row) {
      row.hidden = mode !== null && row.getAttribute('data-resp-mode') !== mode;
    });
  }

  modeInputs.forEach(function (input) {
    input.addEventListener('change', apply);
  });
  apply();
})();
