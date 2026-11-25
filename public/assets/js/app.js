'use strict';

document.querySelector('.directory-toolbar, .dashboard-term-filter')?.addEventListener('change', event => {
  if (event.target.matches('select')) event.currentTarget.requestSubmit();
});

document.querySelectorAll('[data-toast]').forEach(toast => setTimeout(() => toast.remove(), 3000));

function showToast(message, kind='danger') {
  const toast = document.createElement('div');
  toast.className = 'app-toast alert alert-' + kind;
  toast.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
  toast.textContent = message;
  document.body.append(toast);
  setTimeout(() => toast.remove(), 3000);
}

function setInlineFieldError(form, input, message) {
  let error = input.name === 'email' ? form.querySelector('[data-email-error]') : null;
  if (!error) {
    const label = input.closest('.form-label');
    error = label?.nextElementSibling;
    if (!error?.hasAttribute('data-client-field-error')) {
      error = document.createElement('p');
      error.className = 'field-validation-error';
      error.dataset.clientFieldError = '';
      label?.after(error);
    }
  }
  if (error) {
    error.id ||= 'modal-field-error-' + input.name;
    error.textContent = message;
    error.hidden = !message;
    input.setAttribute('aria-describedby', error.id);
  }
  input.classList.toggle('is-invalid', Boolean(message));
  if (message) input.setAttribute('aria-invalid', 'true');
  else input.removeAttribute('aria-invalid');
}

function showModalSummaryError(form, message) {
  let error = form.querySelector('[data-modal-server-error]');
  if (!error) {
    error = document.createElement('p');
    error.className = 'field-validation-error';
    error.dataset.modalServerError = '';
    error.setAttribute('role', 'alert');
    form.prepend(error);
  }
  error.textContent = message;
}

function bindModalForm(form) {
  form.dataset.modalForm = '1';
  if (form.hasAttribute('data-user-form')) return;
  const clearError = event => {
    const input = event.target;
    if (input instanceof HTMLElement && input.hasAttribute('aria-invalid')) setInlineFieldError(form, input, '');
  };
  form.addEventListener('input', clearError);
  form.addEventListener('change', clearError);
}

async function submitModalForm(form, submitter) {
  form.setAttribute('aria-busy', 'true');
  form.querySelector('[data-modal-server-error]')?.remove();
  if (submitter instanceof HTMLElement) submitter.classList.add('is-loading');
  try {
    const data = submitter instanceof HTMLElement && submitter.form === form ? new FormData(form, submitter) : new FormData(form);
    const response = await fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-Modal-Request': '1'},
      body: data,
    });
    const result = await response.json();
    if (response.ok && result.ok && typeof result.redirect === 'string') {
      window.location.assign(result.redirect);
      return;
    }
    const message = typeof result.message === 'string' ? result.message : 'Check the highlighted field and try again.';
    const input = typeof result.field === 'string' ? form.elements.namedItem(result.field) : null;
    if (input instanceof HTMLElement) {
      setInlineFieldError(form, input, message);
      input.focus();
    } else {
      showModalSummaryError(form, message);
    }
  } catch (error) {
    console.error(error);
    showModalSummaryError(form, 'The form could not be saved. Please try again.');
  } finally {
    form.removeAttribute('aria-busy');
    if (submitter instanceof HTMLElement) submitter.classList.remove('is-loading');
  }
}

function bindRoleSelect(select) {
  const form = select.form;
  const teachingField = form.querySelector('[data-teaching-field]');
  const teachingCheckbox = teachingField?.querySelector('input[type=checkbox]');
  const departmentField = form.querySelector('[data-department-field]');
  const departmentSelect = departmentField?.querySelector('select');
  function showRoleFields() {
    const faculty = select.value.toLowerCase() === 'faculty';
    const administrator = select.value.toLowerCase() === 'administrator';
    if (teachingField && teachingCheckbox) {
      teachingField.hidden = faculty || administrator;
      teachingCheckbox.disabled = faculty || administrator;
      if (administrator) teachingCheckbox.checked = false;
    }
    if (departmentField && departmentSelect) {
      departmentField.hidden = administrator;
      departmentSelect.disabled = administrator;
    }
  }
  select.addEventListener('change', showRoleFields);
  showRoleFields();
}

function filterFieldInput(input, invalidCharacters) {
  const value = input.value;
  const cursor = input.selectionStart ?? value.length;
  const filtered = value.replace(invalidCharacters, '');
  if (filtered === value) return;
  input.value = filtered;
  const position = value.slice(0, cursor).replace(invalidCharacters, '').length;
  input.setSelectionRange(position, position);
}

function bindDepartmentForm(form) {
  const name = form.elements.namedItem('name');
  const code = form.elements.namedItem('code');
  if (!(name instanceof HTMLInputElement) || !(code instanceof HTMLInputElement)) return;
  name.required = true;
  code.required = true;
  name.maxLength = 120;
  code.maxLength = 25;
  const filterName = () => filterFieldInput(name, /[^\p{L}\p{M}\p{N} ]/gu);
  const filterCode = () => filterFieldInput(code, /[^A-Za-z0-9-]/g);
  name.addEventListener('input', filterName);
  code.addEventListener('input', filterCode);
  form.addEventListener('submit', event => {
    filterName();
    filterCode();
    const errors = [
      [name, name.value.trim() ? '' : 'Department name is required.'],
      [code, code.value.trim() ? '' : 'Department code is required.'],
    ];
    for (const [input, message] of errors) setInlineFieldError(form, input, message);
    const firstInvalid = errors.find(([, message]) => message);
    if (firstInvalid) {
      event.preventDefault();
      firstInvalid[0].focus();
    }
  });
}

function bindUserForm(form) {
  const fullName = form.elements.namedItem('full_name');
  const employeeId = form.elements.namedItem('employee_id');
  const email = form.elements.namedItem('email');
  if (!(fullName instanceof HTMLInputElement) || !(employeeId instanceof HTMLInputElement) || !(email instanceof HTMLInputElement)) return;
  fullName.required = true;
  fullName.maxLength = 140;
  employeeId.required = true;
  employeeId.maxLength = 48;
  employeeId.pattern = '[A-Za-z0-9][A-Za-z0-9-]{2,47}';
  email.required = true;
  const emailError = form.querySelector('[data-email-error]');
  if (emailError) emailError.id ||= 'user-field-error-email';
  let emailTouched = false;
  const password = form.elements.namedItem('password');
  const role = form.elements.namedItem('role');
  const department = form.elements.namedItem('department_id');
  if (role instanceof HTMLSelectElement) role.required = true;
  if (department instanceof HTMLSelectElement) department.required = true;

  function capitalizeName() {
    fullName.value = fullName.value.toLowerCase().replace(/(^|[ .-])(\p{L})/gu, (_, separator, letter) => separator + letter.toUpperCase());
  }
  function setFieldError(input, message) {
    setInlineFieldError(form, input, message);
  }
  function fullNameError() {
    const pattern = /^\p{L}[\p{L}\p{M}]*(?:[ .-]+\p{L}[\p{L}\p{M}]*)*$/u;
    if (!fullName.value) return 'Full name is required.';
    return pattern.test(fullName.value) ? '' : 'Use letters, spaces, periods or hyphens only.';
  }
  function employeeIdError() {
    return employeeId.checkValidity() ? '' : 'Use 3–48 letters, numbers or hyphens.';
  }
  function passwordError() {
    if (!(password instanceof HTMLInputElement) || !password.required) return '';
    if (Array.from(password.value).length < 8) return 'Use at least 8 characters.';
    return /[\p{N}\p{P}\p{S}]/u.test(password.value) ? '' : 'Include at least one number or special character.';
  }
  function validateEmail(revealError=false) {
    const at = email.value.lastIndexOf('@');
    const domain = at < 0 ? '' : email.value.slice(at + 1);
    const valid = email.value !== '' && email.checkValidity() && at > 0 && domain.includes('.');
    const showError = !valid && (emailTouched || revealError);
    if (emailError) {
      emailError.textContent = email.value ? 'Invalid email format' : 'Email address is required.';
      emailError.hidden = !showError;
      email.setAttribute('aria-describedby', emailError.id);
    }
    email.classList.toggle('is-invalid', showError);
    if (showError) email.setAttribute('aria-invalid', 'true');
    else email.removeAttribute('aria-invalid');
    return valid;
  }
  fullName.addEventListener('input', () => {
    filterFieldInput(fullName, /[^\p{L}\p{M} .-]/gu);
    if (fullName.hasAttribute('aria-invalid')) setFieldError(fullName, fullNameError());
  });
  fullName.addEventListener('blur', () => {
    capitalizeName();
    if (fullName.hasAttribute('aria-invalid')) setFieldError(fullName, fullNameError());
  });
  employeeId.addEventListener('input', () => {
    filterFieldInput(employeeId, /[^A-Za-z0-9-]/g);
    if (employeeId.hasAttribute('aria-invalid')) setFieldError(employeeId, employeeIdError());
  });
  email.addEventListener('input', () => {
    emailTouched = true;
    validateEmail();
  });
  email.addEventListener('blur', () => {
    emailTouched = true;
    email.value = email.value.trim();
    validateEmail();
  });
  if (password instanceof HTMLInputElement) {
    password.addEventListener('input', () => {
      if (password.hasAttribute('aria-invalid')) setFieldError(password, passwordError());
    });
  }
  for (const select of [role, department]) {
    if (select instanceof HTMLSelectElement) select.addEventListener('change', () => {
      if (select.hasAttribute('aria-invalid')) setFieldError(select, select.value ? '' : 'Choose an option.');
    });
  }
  form.addEventListener('submit', event => {
    if (event.submitter?.name === 'registration_decision' && event.submitter.value === 'decline') return;
    filterFieldInput(fullName, /[^\p{L}\p{M} .-]/gu);
    filterFieldInput(employeeId, /[^A-Za-z0-9-]/g);
    capitalizeName();
    email.value = email.value.trim();
    const errors = [
      [fullName, fullNameError()],
      [employeeId, employeeIdError()],
      [email, validateEmail(true) ? '' : email.value ? 'Invalid email format' : 'Email address is required.'],
      [password, passwordError()],
      [role, role instanceof HTMLSelectElement && !role.value ? 'Choose a primary role.' : ''],
      [department, department instanceof HTMLSelectElement && !department.value ? 'Choose a department.' : ''],
    ].filter(([input]) => input instanceof HTMLElement);
    for (const [input, message] of errors) setFieldError(input, message);
    const firstInvalid = errors.find(([, message]) => message);
    if (firstInvalid) {
      event.preventDefault();
      firstInvalid[0].focus();
    }
  });
}

const editDialog = document.querySelector('[data-edit-modal]');
if (editDialog) {
  const modalTitle = editDialog.querySelector('[data-edit-modal-title]');
  const modalBody = editDialog.querySelector('[data-edit-modal-body]');
  let editRequest = 0;
  editDialog.querySelector('[data-close-edit]')?.addEventListener('click', () => editDialog.close());
  editDialog.addEventListener('click', event => {
    if (event.target === editDialog) editDialog.close();
  });
  editDialog.addEventListener('close', () => editRequest++);
  document.addEventListener('click', async event => {
    if (!(event.target instanceof Element)) return;
    const link = event.target.closest('a[data-edit-modal], a[data-form-modal]');
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const requestId = ++editRequest;
    modalTitle.textContent = link.hasAttribute('data-form-modal') ? 'Add' : 'Edit';
    modalBody.textContent = 'Loading...';
    editDialog.showModal();
    try {
      const response = await fetch(link.href, {credentials: 'same-origin'});
      if (!response.ok) throw new Error('Edit form request failed with HTTP ' + response.status);
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (requestId !== editRequest || !editDialog.open) return;
      const sourceForm = page.querySelector('main.content form');
      if (!sourceForm) throw new Error('Edit form was not present in the response');
      const form = sourceForm.cloneNode(true);
      const modalMarker = document.createElement('input');
      modalMarker.type = 'hidden';
      modalMarker.name = 'modal_form';
      modalMarker.value = '1';
      form.append(modalMarker);
      bindModalForm(form);
      const panelBody = document.createElement('div');
      panelBody.className = 'panel-body';
      panelBody.append(form);
      modalBody.replaceChildren(panelBody);
      modalTitle.textContent = page.querySelector('main.content .page-heading h1')?.textContent.trim() || 'Edit';
      modalBody.querySelectorAll('[data-user-form]').forEach(bindUserForm);
      modalBody.querySelectorAll('[data-department-form]').forEach(bindDepartmentForm);
      modalBody.querySelectorAll('[data-role-select]').forEach(bindRoleSelect);
      modalBody.querySelectorAll('[data-password-requirements]').forEach(bindPasswordRequirements);
      modalBody.querySelectorAll('[data-password-toggle]').forEach(bindPasswordToggle);
      modalBody.querySelector('input:not([type=hidden]), select, textarea')?.focus();
    } catch (error) {
      if (requestId !== editRequest) return;
      console.error(error);
      if (editDialog.open) editDialog.close();
      showToast('Could not open the edit form. Please try again.');
    }
  });
}

function bindPasswordRequirements(list) {
  const input = list.closest('form')?.elements.namedItem('password');
  if (!(input instanceof HTMLInputElement)) return;
  const rules = {
    length: value => Array.from(value).length >= 8,
    character: value => /[\p{N}\p{P}\p{S}]/u.test(value),
  };
  function updatePasswordRequirements() {
    for (const [name, predicate] of Object.entries(rules)) {
      list.querySelector('[data-password-rule="' + name + '"]')?.classList.toggle('met', predicate(input.value));
    }
  }
  input.addEventListener('input', updatePasswordRequirements);
  updatePasswordRequirements();
}

function bindPasswordToggle(button) {
  const input = button.closest('.password-wrap')?.querySelector('input') || document.getElementById(button.getAttribute('aria-controls'));
  if (!input) return;
  const passwordFieldWrap = button.closest('.password-field-wrap');
  function setVisibilityIcon(revealed) {
    button.setAttribute('aria-label', revealed ? 'Hide password' : 'Show password');
    const bootstrapIcon = button.querySelector('.bi');
    bootstrapIcon?.classList.toggle('bi-eye', revealed);
    bootstrapIcon?.classList.toggle('bi-eye-slash', !revealed);
    const symbol = button.querySelector('use');
    if (symbol) symbol.setAttribute('href', symbol.getAttribute('href').split('#')[0] + (revealed ? '#eye' : '#eye-slash'));
  }
  function updateEmptyState() {
    if (!passwordFieldWrap) return;
    const hasPassword = input.value.length > 0;
    button.hidden = !hasPassword;
    passwordFieldWrap.classList.toggle('has-password-value', hasPassword);
    if (!hasPassword && input.type !== 'password') {
      input.type = 'password';
      setVisibilityIcon(false);
    }
  }
  updateEmptyState();
  if (passwordFieldWrap) input.addEventListener('input', updateEmptyState);
  button.addEventListener('click', () => {
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    setVisibilityIcon(reveal);
  });
}

document.querySelectorAll('[data-password-requirements]').forEach(bindPasswordRequirements);
document.querySelectorAll('[data-password-toggle]').forEach(bindPasswordToggle);

const resetPasswordDialog = document.querySelector('[data-reset-password-dialog]');
if (resetPasswordDialog) {
  const form = resetPasswordDialog.querySelector('form');
  const id = form.elements.namedItem('id');
  const name = resetPasswordDialog.querySelector('[data-reset-user-name]');
  const employeeId = resetPasswordDialog.querySelector('[data-reset-employee-id]');
  document.querySelectorAll('[data-reset-user]').forEach(button => {
    button.addEventListener('click', () => {
      id.value = button.dataset.userId;
      name.textContent = button.dataset.userName;
      employeeId.textContent = button.dataset.employeeId || '—';
      resetPasswordDialog.showModal();
      form.elements.namedItem('password').focus();
    });
  });
  resetPasswordDialog.querySelectorAll('[data-close-reset]').forEach(button => {
    button.addEventListener('click', () => resetPasswordDialog.close());
  });
  resetPasswordDialog.addEventListener('close', () => {
    form.reset();
    form.elements.namedItem('password')?.dispatchEvent(new Event('input', {bubbles: true}));
  });
}

// Navigation remains persistent on desktop. Narrow layouts use a keyboard-accessible drawer.
const sidebar = document.querySelector('.sidebar');
const menuButton = document.querySelector('[data-menu-toggle]');
if (sidebar && menuButton) {
  const narrow = window.matchMedia('(max-width:850px)');
  const minimizedKey = 'facultrack-nav-minimized';
  const overlay = document.createElement('button');
  overlay.type = 'button';
  overlay.className = 'drawer-overlay';
  overlay.tabIndex = -1;
  overlay.setAttribute('aria-label', 'Close navigation');
  document.body.append(overlay);
  sidebar.id ||= 'workspace-navigation';
  if (!sidebar.querySelector('[data-menu-close]')) {
    const close = document.createElement('button');
    close.type = 'button';
    close.dataset.menuClose = '';
    close.setAttribute('aria-label', 'Close navigation');
    close.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
    sidebar.insertBefore(close, sidebar.querySelector('nav'));
  }
  menuButton.setAttribute('aria-controls', sidebar.id);
  if (!narrow.matches && localStorage.getItem(minimizedKey) === 'true') {
    document.body.classList.add('nav-minimized');
  }
  function updateToggle() {
    const expanded = narrow.matches
      ? document.body.classList.contains('menu-open')
      : !document.body.classList.contains('nav-minimized');
    menuButton.setAttribute('aria-expanded', String(expanded));
    menuButton.setAttribute('aria-label', narrow.matches
      ? (expanded ? 'Close navigation' : 'Open navigation')
      : (expanded ? 'Minimize navigation' : 'Expand navigation'));
  }
  updateToggle();
  function closeDrawer(restoreFocus = true) {
    document.body.classList.remove('menu-open');
    sidebar.removeAttribute('aria-modal');
    sidebar.removeAttribute('role');
    updateToggle();
    if (restoreFocus) menuButton.focus();
  }
  menuButton.addEventListener('click', () => {
    if (!narrow.matches) {
      const minimized = document.body.classList.toggle('nav-minimized');
      localStorage.setItem(minimizedKey, String(minimized));
      updateToggle();
      return;
    }
    if (document.body.classList.contains('menu-open')) {
      closeDrawer();
      return;
    }
    document.body.classList.add('menu-open');
    sidebar.setAttribute('role', 'dialog');
    sidebar.setAttribute('aria-modal', 'true');
    sidebar.setAttribute('aria-label', 'Workspace navigation');
    updateToggle();
    sidebar.querySelector('a, button')?.focus();
  });
  overlay.addEventListener('click', () => closeDrawer());
  sidebar.querySelector('[data-menu-close]')?.addEventListener('click', () => closeDrawer());
  narrow.addEventListener('change', () => {
    closeDrawer(false);
    if (!narrow.matches) {
      document.body.classList.toggle('nav-minimized', localStorage.getItem(minimizedKey) === 'true');
    }
    updateToggle();
  });
  document.addEventListener('keydown', event => {
    if (!document.body.classList.contains('menu-open')) return;
    if (event.key === 'Escape') closeDrawer();
    if (event.key !== 'Tab') return;
    const links = [...sidebar.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled)')]
      .filter(element => element.getClientRects().length > 0);
    const first = links[0];
    const last = links.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  });
}

for (const select of document.querySelectorAll('select[data-requirement]')) {
  function showTerm() {
    const option = select.selectedOptions[0];
    for (const key of ['year', 'semester']) {
      const output = select.form.querySelector('[data-' + key + '-output]');
      if (!output) continue;
      const value = option?.dataset[key] || '';
      if ('value' in output) output.value = value;
      else output.textContent = value || '—';
    }
    const formats = option?.dataset.formats || '';
    const formatOutput = select.form.querySelector('[data-formats-output]');
    if (formatOutput) formatOutput.textContent = formats.split(',').filter(Boolean).map(format => format.toUpperCase()).join(', ');
    const file = select.form.querySelector('input[type=file][name=file]');
    if (file) file.accept = formats.split(',').filter(Boolean).map(format => '.' + format).join(',');
  }
  select.addEventListener('change', showTerm);
  showTerm();
}

document.querySelectorAll('[data-role-select]').forEach(bindRoleSelect);
document.querySelectorAll('[data-user-form]').forEach(bindUserForm);
document.querySelectorAll('[data-department-form]').forEach(bindDepartmentForm);
document.querySelectorAll('[data-requirement-subject]').forEach(subjectSelect => {
  const form = subjectSelect.form;
  const warning = form.querySelector('[data-subject-assignee-warning]');
  const yearSelect = form.elements.namedItem('academic_year');
  const semesterSelect = form.elements.namedItem('semester');
  const assignees = [...form.querySelectorAll('[data-assignee-id]')];
  const normalizedYear = value => value.replace('–', '-');

  function updateWarnings(selectFaculty = false) {
    const option = subjectSelect.selectedOptions[0];
    const facultyId = option?.dataset.facultyId;
    const facultyCheckbox = assignees.find(input => input.dataset.assigneeId === facultyId);
    if (selectFaculty && facultyCheckbox) facultyCheckbox.checked = true;

    const messages = [];
    if (option?.value && yearSelect instanceof HTMLSelectElement && semesterSelect instanceof HTMLSelectElement
      && (normalizedYear(option.dataset.year || '') !== normalizedYear(yearSelect.value)
        || option.dataset.semester !== semesterSelect.value)) {
      messages.push('The selected subject belongs to a different academic term.');
    }
    if (facultyId && (!facultyCheckbox || !facultyCheckbox.checked)) {
      messages.push('The faculty member assigned to this subject is not selected. Add them to ensure they receive the requirement.');
    }
    warning.textContent = messages.join(' ');
    warning.hidden = messages.length === 0;
  }

  subjectSelect.addEventListener('change', () => updateWarnings(true));
  yearSelect?.addEventListener('change', () => updateWarnings());
  semesterSelect?.addEventListener('change', () => updateWarnings());
  assignees.forEach(input => input.addEventListener('change', () => updateWarnings()));
  updateWarnings();
});

const confirmationDialog = document.querySelector('[data-confirm-dialog]');
let pendingConfirmationForm = null;
let pendingConfirmationSubmitter = null;
if (confirmationDialog) {
  const confirmationMessage = confirmationDialog.querySelector('[data-confirm-message]');
  const confirmationButton = confirmationDialog.querySelector('[data-confirm-accept]');
  const clearPendingConfirmation = () => {
    pendingConfirmationForm = null;
    pendingConfirmationSubmitter = null;
  };
  confirmationDialog.querySelectorAll('[data-confirm-cancel]').forEach(button => {
    button.addEventListener('click', () => confirmationDialog.close());
  });
  confirmationDialog.addEventListener('click', event => {
    if (event.target === confirmationDialog) confirmationDialog.close();
  });
  confirmationDialog.addEventListener('close', clearPendingConfirmation);
  confirmationButton?.addEventListener('click', () => {
    const form = pendingConfirmationForm;
    const submitter = pendingConfirmationSubmitter;
    if (!form) return;
    clearPendingConfirmation();
    confirmationDialog.close();
    form.dataset.confirmBypass = '1';
    if (submitter instanceof HTMLElement && submitter.form === form) form.requestSubmit(submitter);
    else form.requestSubmit();
  });
}

document.addEventListener('submit', event => {
  const form = event.target;
  if (event.defaultPrevented || form.method === 'dialog') return;
  if (form.dataset.confirmBypass === '1') {
    delete form.dataset.confirmBypass;
  } else {
    const confirmMessage = event.submitter?.dataset.confirm || form.dataset.confirm;
    const teaching = form.querySelector('input[data-teaching]');
    const teachingChanged = teaching && !teaching.disabled && teaching.checked !== teaching.defaultChecked;
    const messages = [];
    if (teachingChanged) messages.push('Change this account’s teaching responsibilities?');
    if (confirmMessage) messages.push(confirmMessage);
    if (messages.length) {
      event.preventDefault();
      if (confirmationDialog) {
        confirmationDialog.querySelector('[data-confirm-message]').textContent = messages.join('\n\n');
        const confirmLabel = event.submitter?.dataset.confirmLabel || form.dataset.confirmLabel || 'Confirm';
        const confirmVariant = event.submitter?.dataset.confirmVariant || form.dataset.confirmVariant || 'primary';
        const acceptButton = confirmationDialog.querySelector('[data-confirm-accept]');
        acceptButton.textContent = confirmLabel;
        acceptButton.classList.toggle('btn-danger', confirmVariant === 'danger');
        acceptButton.classList.toggle('btn-primary', confirmVariant !== 'danger');
        pendingConfirmationForm = form;
        pendingConfirmationSubmitter = event.submitter;
        confirmationDialog.showModal();
        confirmationDialog.querySelector('[data-confirm-accept]')?.focus();
      } else if (!window.confirm(messages.join('\n\n'))) {
        return;
      } else {
        form.dataset.confirmBypass = '1';
        if (event.submitter instanceof HTMLElement) form.requestSubmit(event.submitter);
        else form.requestSubmit();
      }
      return;
    }
  }
  if (form.dataset.modalForm === '1') {
    event.preventDefault();
    submitModalForm(form, event.submitter);
    return;
  }
  form.setAttribute('aria-busy', 'true');
  if (event.submitter) event.submitter.classList.add('is-loading');
});
window.addEventListener('pageshow', () => {
  document.querySelectorAll('form[aria-busy]').forEach(form => form.removeAttribute('aria-busy'));
  document.querySelectorAll('.is-loading').forEach(button => button.classList.remove('is-loading'));
});
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));

function bindLoginCard(card) {
  const form = card.querySelector('form');
  const fields = ['employee_id', 'password'].map(name => form.elements.namedItem(name));
  const passwordInput = fields.find(input => input.name === 'password');
  passwordInput.readOnly = true;
  const unlockPassword = () => { passwordInput.readOnly = false; };
  passwordInput.addEventListener('pointerdown', unlockPassword, {once: true});
  passwordInput.addEventListener('keydown', unlockPassword, {once: true});

  function updateField(input) {
    const empty = input.name === 'employee_id' ? input.value.trim() === '' : input.value === '';
    const error = card.querySelector('[data-field-error="' + input.name + '"]');
    if (error) error.hidden = !empty;
    input.classList.toggle('is-invalid', empty);
    if (empty) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
    return !empty;
  }

  form.addEventListener('submit', event => {
    card.querySelector('.login-auth-error')?.remove();
    let valid = true;
    for (const input of fields) valid = updateField(input) && valid;
    if (!valid) event.preventDefault();
  });

  for (const input of fields) {
    input.addEventListener('input', () => {
      const hasValue = input.name === 'employee_id' ? input.value.trim() !== '' : input.value !== '';
      const error = card.querySelector('[data-field-error="' + input.name + '"]');
      if (hasValue) {
        if (error) error.hidden = true;
        input.classList.remove('is-invalid');
        input.removeAttribute('aria-invalid');
        card.querySelector('.login-auth-error')?.remove();
      } else {
        if (error) {
          error.hidden = false;
          error.querySelector('span').textContent = input.name === 'employee_id' ? 'Employee ID is required.' : 'Password is required.';
        }
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
      }
    });
  }

  const clearCredentials = () => fields.forEach(input => { input.value = ''; });
  const clearLoginMessages = () => {
    card.querySelectorAll('.field-error').forEach(error => { error.hidden = true; });
    card.querySelector('.login-auth-error')?.remove();
    fields.forEach(input => {
      input.classList.remove('is-invalid');
      input.removeAttribute('aria-invalid');
    });
  };
  window.addEventListener('pageshow', event => {
    const navigation = performance.getEntriesByType('navigation')[0];
    if (event.persisted || navigation?.type === 'reload') {
      clearCredentials();
      clearLoginMessages();
    }
  });

}

const loginCard = document.querySelector('.login-form');
if (loginCard) bindLoginCard(loginCard);

document.querySelectorAll('[data-open-review]').forEach(button => {
  button.addEventListener('click', () => document.getElementById(button.dataset.openReview)?.showModal());
});

document.querySelectorAll('[data-cancel-upload]').forEach(button => {
  button.addEventListener('click', () => button.closest('dialog').close());
});
document.querySelectorAll('.faculty-drop').forEach(zone => {
  const input = zone.querySelector('input[type=file]');
  zone.addEventListener('dragover', event => {
    event.preventDefault();
    zone.classList.add('is-dragging');
  });
  zone.addEventListener('dragleave', () => zone.classList.remove('is-dragging'));
  zone.addEventListener('drop', event => {
    event.preventDefault();
    zone.classList.remove('is-dragging');
    if (event.dataTransfer.files.length === 1) input.files = event.dataTransfer.files;
    else showToast('Choose one document to upload.');
  });
});

const inbox = document.querySelector('.inbox');
if (inbox) {
  const all = inbox.querySelector('[data-inbox-all]');
  const items = [...inbox.querySelectorAll('[name="events[]"]')];
  function updateSelection() {
    const count = items.filter(item => item.checked).length;
    all.checked = count === items.length;
    all.indeterminate = count > 0 && count < items.length;
    inbox.querySelectorAll('button[name="action"]').forEach(button => button.disabled = count === 0);
  }
  inbox.addEventListener('change', event => {
    if (event.target === all) items.forEach(item => item.checked = all.checked);
    updateSelection();
  });
  updateSelection();
}
