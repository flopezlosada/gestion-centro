// Task form cascade: show the department step only for per-department roles, and narrow the person
// list to those who hold the chosen role in the chosen department. Progressive enhancement — with no
// JS the fields stay visible, every candidate is selectable and the server-side validation rejects a
// person who does not hold that role in that department.
//
// Runs on DOMContentLoaded so it executes AFTER select-menu.js has enhanced the <select>s: the person
// field is a custom listbox over a hidden native <select>, so after we add/remove native options we
// ask it to refresh (cselectRefresh) — otherwise it keeps showing its initial snapshot.
(function () {
  'use strict';

  function init() {
    var roleSelect = document.querySelector('[name$="[responsibilityRole]"]');
    if (!roleSelect) {
      return;
    }

    var deptRow = document.querySelector('.form-row[data-dept-step]');
    var deptSelect = document.querySelector('[name$="[responsibilityUnit]"]');
    var userSelect = document.querySelector('[name$="[responsibilityUser]"]');

    // Snapshot every candidate option up front (select-menu.js leaves the native options in place, so
    // they are all present here). We add/remove these nodes rather than toggling `hidden`, because
    // native <select> dropdowns still show hidden options.
    var placeholder = userSelect ? userSelect.querySelector('option[value=""]') : null;
    var candidates = userSelect
      ? Array.prototype.filter.call(userSelect.options, function (option) { return option.value !== ''; })
      : [];

    function selectedIsPerDepartment() {
      var option = roleSelect.options[roleSelect.selectedIndex];
      return !!option && option.getAttribute('data-per-department') === '1';
    }

    function toggleDept() {
      if (deptRow) {
        deptRow.hidden = !selectedIsPerDepartment();
      }
    }

    function isEligible(option, roleId, perDepartment, unitId) {
      var roles = (option.getAttribute('data-roles') || '').split(' ');
      var holdsRole = roleId !== '' && roles.indexOf(roleId) !== -1;
      var inDepartment = !perDepartment || (unitId !== '' && option.getAttribute('data-unit') === unitId);
      return holdsRole && inDepartment;
    }

    // Rebuild the person list with only the people who hold the selected role and — for a per-department
    // role — belong to the selected department, preserving the current pick when it still qualifies.
    function filterUsers() {
      if (!userSelect) {
        return;
      }
      var roleId = roleSelect.value;
      var perDepartment = selectedIsPerDepartment();
      var unitId = perDepartment && deptSelect ? deptSelect.value : '';
      var previous = userSelect.value;

      while (userSelect.firstChild) {
        userSelect.removeChild(userSelect.firstChild);
      }
      if (placeholder) {
        userSelect.appendChild(placeholder);
      }

      var stillValid = false;
      candidates.forEach(function (option) {
        if (!isEligible(option, roleId, perDepartment, unitId)) {
          return;
        }
        userSelect.appendChild(option);
        if (option.value === previous) {
          stillValid = true;
        }
      });

      userSelect.value = stillValid ? previous : '';
      if (typeof userSelect.cselectRefresh === 'function') {
        userSelect.cselectRefresh();
      }
    }

    function onChange() {
      toggleDept();
      filterUsers();
    }

    roleSelect.addEventListener('change', onChange);
    if (deptSelect) {
      deptSelect.addEventListener('change', filterUsers);
    }
    onChange();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
