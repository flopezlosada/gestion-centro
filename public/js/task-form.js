// Task form cascade: show the department step only for per-department roles, and live-preview the
// people the chosen role + department resolves to. Progressive enhancement — with no JS the fields
// stay visible and validation guides; the preview is a nicety, not required to submit.
(function () {
  'use strict';

  var roleSelect = document.querySelector('[name$="[responsibilityRole]"]');
  if (!roleSelect) {
    return;
  }

  var deptRow = document.querySelector('.form-row[data-dept-step]');
  var deptSelect = document.querySelector('[name$="[responsibilityUnit]"]');
  var preview = document.querySelector('[data-responsibles]');
  var list = document.querySelector('[data-responsibles-list]');
  var url = preview ? preview.getAttribute('data-responsibles-url') : null;

  function selectedIsPerDepartment() {
    var option = roleSelect.options[roleSelect.selectedIndex];
    return !!option && option.getAttribute('data-per-department') === '1';
  }

  function toggleDept() {
    if (deptRow) {
      deptRow.hidden = !selectedIsPerDepartment();
    }
  }

  function refreshPreview() {
    if (!list || !url) {
      return;
    }
    var roleId = roleSelect.value;
    if (!roleId) {
      list.textContent = '—';
      return;
    }
    var unitId = selectedIsPerDepartment() && deptSelect ? deptSelect.value : '';
    fetch(url + '?role=' + encodeURIComponent(roleId) + '&unit=' + encodeURIComponent(unitId), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (response) { return response.ok ? response.json() : { holders: [] }; })
      .then(function (data) {
        var names = data.holders || [];
        list.textContent = names.length ? names.join(', ') : 'Nadie cumple ese rol en ese departamento todavía.';
      })
      .catch(function () { /* leave the previous value on a network hiccup */ });
  }

  function onChange() {
    toggleDept();
    refreshPreview();
  }

  roleSelect.addEventListener('change', onChange);
  if (deptSelect) {
    deptSelect.addEventListener('change', refreshPreview);
  }
  onChange();
})();
