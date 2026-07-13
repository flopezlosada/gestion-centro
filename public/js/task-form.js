// Task form cascade: show the department step only when the chosen role is per-department.
// Progressive enhancement — with no JS the department field stays visible and validation guides.
(function () {
  'use strict';

  var roleSelect = document.querySelector('[name$="[responsibilityRole]"]');
  var deptRow = document.querySelector('.form-row[data-dept-step]');
  if (!roleSelect || !deptRow) {
    return;
  }

  function selectedIsPerDepartment() {
    var option = roleSelect.options[roleSelect.selectedIndex];
    return !!option && option.getAttribute('data-per-department') === '1';
  }

  function apply() {
    deptRow.hidden = !selectedIsPerDepartment();
  }

  // The native select stays the source of truth even when select-menu.js enhances it, and it fires
  // "change" on selection, so listening here works with or without the custom combobox.
  roleSelect.addEventListener('change', apply);
  apply();
})();
