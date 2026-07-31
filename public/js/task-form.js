// Task form cascade: show the department step only for per-department roles, and narrow the person
// list to those who hold the chosen role in the chosen department. Progressive enhancement — with no
// JS the fields stay visible, every candidate is selectable and the server-side validation rejects a
// person who does not hold that role in that department.
//
// The person step comes in two shapes and this handles both, because they are the same question asked
// once or many times:
//   - EDIT: a single <select> (a custom listbox over a hidden native one, see select-menu.js);
//   - CREATE: a list of checkboxes, because the centre asked to send one task to several people, a whole
//     department or the entire staff. There, leaving the department empty means "todos los
//     departamentos", so the list is narrowed by role alone and a "Marcar todas" button covers the
//     collective in one click.
//
// Runs on DOMContentLoaded so it executes AFTER select-menu.js has enhanced the <select>s: after we
// add/remove native options we ask it to refresh (cselectRefresh) — otherwise it keeps showing its
// initial snapshot.
(function () {
  'use strict';

  function init() {
    var roleSelect = document.querySelector('[name$="[responsibilityRole]"]');
    if (!roleSelect) {
      return;
    }

    var deptRow = document.querySelector('.form-row[data-dept-step]');
    var deptSelect = document.querySelector('[name$="[responsibilityUnit]"]');
    var userSelect = document.querySelector('select[name$="[responsibilityUser]"]');
    var checkboxes = Array.prototype.slice.call(
      document.querySelectorAll('input[type="checkbox"][name*="responsibilityUsers"]')
    );

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

    function holdsRole(node, roleId) {
      var roles = (node.getAttribute('data-roles') || '').split(' ');
      return roleId !== '' && roles.indexOf(roleId) !== -1;
    }

    // Rebuild the person list with only the people who hold the selected role and — for a per-department
    // role — belong to the selected department, preserving the current pick when it still qualifies.
    // Aquí el departamento SÍ es obligatorio: sin él no hay nadie elegible, porque la tarea que se edita
    // pertenece a un departamento concreto.
    function filterSelect() {
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
        var inDepartment = !perDepartment || (unitId !== '' && option.getAttribute('data-unit') === unitId);
        if (!holdsRole(option, roleId) || !inDepartment) {
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

    // Con casillas no se quitan del DOM: se ocultan y se DESMARCAN, para que no viaje en el envío alguien
    // que ya no cumple. Y el departamento vacío no deja la lista a cero: significa "todos".
    function filterCheckboxes() {
      var roleId = roleSelect.value;
      var unitId = selectedIsPerDepartment() && deptSelect ? deptSelect.value : '';
      var visible = 0;

      checkboxes.forEach(function (box) {
        var row = box.closest('.pick-option') || box.parentNode;
        var ok = holdsRole(box, roleId) && (unitId === '' || box.getAttribute('data-unit') === unitId);
        row.hidden = !ok;
        if (!ok) {
          box.checked = false;
        } else {
          visible += 1;
        }
      });

      var empty = document.querySelector('[data-people-empty]');
      if (empty) {
        empty.hidden = visible > 0;
      }
      var bulk = document.querySelector('[data-people-bulk]');
      if (bulk) {
        bulk.hidden = visible < 2;
      }
    }

    function onChange() {
      toggleDept();
      if (userSelect) {
        filterSelect();
      }
      if (checkboxes.length > 0) {
        filterCheckboxes();
      }
    }

    // "Marcar todas" sobre lo que está a la vista: así se manda una tarea a un departamento entero o a
    // todo el claustro sin ir marcando de una en una.
    var bulkButton = document.querySelector('[data-people-bulk] button');
    if (bulkButton) {
      bulkButton.addEventListener('click', function () {
        var shown = checkboxes.filter(function (box) {
          var row = box.closest('.pick-option') || box.parentNode;
          return !row.hidden;
        });
        var allChecked = shown.length > 0 && shown.every(function (box) { return box.checked; });
        shown.forEach(function (box) { box.checked = !allChecked; });
        bulkButton.textContent = allChecked ? 'Marcar todas' : 'Desmarcar todas';
      });
    }

    roleSelect.addEventListener('change', onChange);
    if (deptSelect) {
      deptSelect.addEventListener('change', onChange);
    }
    onChange();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
