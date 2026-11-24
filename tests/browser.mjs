// Attach to the loopback debugging port of a disposable Chromium profile.
// Requires Node 22+ with native fetch and WebSocket.
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
import {join} from 'node:path';
const debug = process.env.FACULTRACK_DEBUG_URL || 'http://127.0.0.1:9327';
const base = process.env.FACULTRACK_TEST_URL || 'http://127.0.0.1:18317/';
const employeeId = process.env.FACULTRACK_TEST_EMPLOYEE_ID;
const password = process.env.FACULTRACK_TEST_PASSWORD;
const role = process.env.FACULTRACK_TEST_ROLE || 'faculty';
const pages = {
  faculty: ['dashboard', 'requirements', 'upload', 'submissions', 'history', 'profile'],
  head: ['dashboard', 'faculty', 'review', 'manage', 'deadlines', 'reports'],
  admin: ['dashboard', 'users', 'departments', 'academic-terms', 'manage', 'review', 'types', 'reports', 'settings'],
}[role];
if (!employeeId || !password) throw new Error('Set the temporary test Employee ID and password.');
const tab = await (await fetch(debug + '/json/new?about:blank', { method: 'PUT' })).json();
const socket = new WebSocket(tab.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});
let id = 0;
const pending = new Map();
const errors = [];
socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
  const waiter = pending.get(message.id);
  if (!waiter) return;
  pending.delete(message.id);
  message.error ? waiter.reject(Error(message.error.message)) : waiter.resolve(message.result);
});
function call(method, params = {}) {
  return new Promise((resolve, reject) => {
    pending.set(++id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
}
async function evaluate(expression) {
  const result = await call('Runtime.evaluate', { expression, returnByValue: true });
  if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
  return result.result.value;
}
async function until(expression) {
  for (let n = 0; n < 50; n++) {
    if (await evaluate(expression)) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw Error('Browser condition failed: ' + expression);
}
async function navigate(page) {
  const url = new URL(base);
  url.searchParams.set('p', page);
  await call('Page.navigate', { url: url.href });
  await until('document.readyState === "complete" && !!document.body');
}
try {
  await call('Runtime.enable');
  await navigate('login');
  await evaluate("localStorage.removeItem('facultrack-nav-minimized')");
  await until('!!document.querySelector("input[type=password]")');
  assert.ok(await evaluate("document.querySelector('input[name=csrf]').value.length > 0"), 'login CSRF token is preserved');
  assert.equal(await evaluate("document.querySelector('#account-password').readOnly"), true, 'password autofill is blocked before focus');
  assert.equal(await evaluate("document.querySelector('#account-password').autocomplete"), 'new-password', 'password autofill is discouraged');
  await evaluate("document.querySelector('#account-password').focus()");
  assert.equal(await evaluate("document.querySelector('#account-password').readOnly"), true, 'restored focus does not unlock password autofill');
  await evaluate("document.querySelector('#account-password').dispatchEvent(new KeyboardEvent('keydown', {key:'a', bubbles:true}))");
  assert.equal(await evaluate("document.querySelector('#account-password').readOnly"), false, 'keyboard entry unlocks the password field');
  await evaluate("document.querySelector('[data-password-toggle]').click()");
  assert.equal(await evaluate("document.querySelector('#account-password').type"), 'text');
  await evaluate("document.querySelector('[data-password-toggle]').click()");
  assert.equal(await evaluate("document.querySelector('#account-password').type"), 'password');
  await evaluate(`document.querySelector('#account-employee_id').value = 'EMP-TEST';
    document.querySelector('#account-password').value = 'temporary-password';
    document.querySelector('#account-employee_id').value = '';
    document.querySelector('#account-employee_id').dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('.login-form form').requestSubmit();`);
  assert.equal(await evaluate("document.querySelector('[data-field-error=\"employee_id\"]').hidden"), false, 'empty sign-in shows required-field errors');
  await call('Page.reload');
  await until('document.readyState === "complete" && !!document.querySelector(".login-form")');
  assert.equal(await evaluate("document.querySelector('#account-employee_id').value"), '', 'refresh clears the Employee ID');
  assert.equal(await evaluate("document.querySelector('#account-password').value"), '', 'refresh clears the password');
  assert.equal(await evaluate("document.querySelector('#account-password').readOnly"), true, 'refresh restores password autofill protection');
  assert.equal(await evaluate("document.querySelector('[data-field-error=\"employee_id\"]').hidden && document.querySelector('[data-field-error=\"password\"]').hidden"), true, 'refresh clears field errors');
  assert.equal(await evaluate("!document.querySelector('.login-auth-error')"), true, 'refresh clears authentication errors');
  assert.equal(await evaluate("document.querySelector('.login-form input[name=\"remember\"]')"), null, 'sign-in has no Remember me option');
  assert.equal(await evaluate("document.querySelector('.login-form a')"), null, 'sign-in guidance is not a link');
  assert.equal(await evaluate("document.querySelector('.login-guidance').textContent.trim()"), 'Need an account or forgot your password? Contact the System Administrator.', 'sign-in shows administrator guidance');
  assert.equal(await evaluate("document.querySelector('.login-form form').lastElementChild.textContent.trim()"), 'Sign In', 'Sign In follows the password field');
  await evaluate("document.querySelector('[data-field-error=\"employee_id\"]').hidden = true; document.querySelector('[data-field-error=\"password\"]').hidden = true");
  await evaluate(`document.querySelector('input[name=employee_id]').value = ${JSON.stringify(employeeId)};
    document.querySelector('input[type=password]').value = ${JSON.stringify(password)};
    document.querySelector('input[type=password]').form.requestSubmit();`);
  await until('!!document.querySelector("a[href*=profile], a[href*=settings]")');
  if (role === 'admin') {
    assert.equal(await evaluate("!!document.querySelector('.sidebar a[href*=subjects]')"), false, 'administrator navigation omits Subjects');
    await evaluate("fetch(new URL('?p=subjects', location.href)).then(response => window.subjectsPageStatus = response.status)");
    await until('window.subjectsPageStatus !== undefined');
    assert.equal(await evaluate('window.subjectsPageStatus'), 403, 'administrator cannot open the Subjects page directly');
  }
  for (const width of [1440, 768, 390]) {
    await call('Emulation.setDeviceMetricsOverride', { width, height: 900, mobile: width < 500, deviceScaleFactor: 1 });
    for (const page of pages) {
      await navigate(page);
      await until('!!document.querySelector("main")');
      const result = await evaluate('({ viewport: window.innerWidth, content: document.documentElement.scrollWidth, title: document.title })');
      assert.equal(result.viewport, width, 'viewport meta tag at ' + page);
      assert.ok(result.content <= width + 1, page + ' overflows at ' + width + 'px by ' + (result.content - width));
      if (page === 'dashboard' && width === 1440) {
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.topbar-brand')).display"), 'none', 'expanded sidebar does not duplicate the FaculTrack title');
        await evaluate("document.querySelector('[data-menu-toggle]').click()");
        assert.equal(await evaluate("document.body.classList.contains('nav-minimized')"), true, 'desktop navigation minimizes');
        assert.notEqual(await evaluate("getComputedStyle(document.querySelector('.topbar-brand-mark')).display"), 'none', 'minimized header restores the navigation logo');
        assert.equal(await evaluate("document.querySelector('.topbar-brand').textContent.trim()"), 'FaculTrack', 'minimized header restores the title');
        assert.deepEqual(await evaluate("Array.from(document.querySelector('.topbar-brand-group').children).map(element => element.className)"), ['topbar-brand-mark', 'topbar-nav-toggle', 'topbar-brand'], 'logo, expand button, and title appear in order');
        if (role === 'admin') {
          assert.equal(await evaluate("document.querySelector('.dashboard-term-filter button[type=submit]')"), null, 'academic term filter has no Apply button');
          await evaluate(`window.termFilterSubmitted = false;
            const termForm = document.querySelector('.dashboard-term-filter');
            termForm.addEventListener('submit', event => { event.preventDefault(); window.termFilterSubmitted = true; }, { once: true });
            termForm.querySelector('select').dispatchEvent(new Event('change', { bubbles: true }));`);
          assert.equal(await evaluate('window.termFilterSubmitted'), true, 'changing the academic term submits the filter automatically');
          const gap = await evaluate("document.querySelector('.topbar-brand').getBoundingClientRect().left - document.querySelector('[data-menu-toggle]').getBoundingClientRect().right");
          assert.ok(gap >= 0 && gap <= 16, 'FaculTrack sits beside the navigation button');
          assert.ok(await evaluate("document.querySelector('.topbar-brand-mark').getBoundingClientRect().right <= document.querySelector('.sidebar').getBoundingClientRect().right"), 'logo aligns with the minimized rail');
        } else {
          assert.ok(await evaluate("Math.abs(document.querySelector('.topbar-brand-group').getBoundingClientRect().left + document.querySelector('.topbar-brand-group').getBoundingClientRect().width / 2 - window.innerWidth / 2) < 1"), 'minimized navigation group is centered');
        }
        assert.equal(await evaluate("document.querySelector('[data-menu-toggle]').getAttribute('aria-label')"), 'Expand navigation');
        await evaluate("document.querySelector('[data-menu-toggle]').click()");
        assert.equal(await evaluate("document.body.classList.contains('nav-minimized')"), false, 'desktop navigation expands');
      }
      if (page === 'users' && width === 1440 && role === 'admin') {
        assert.deepEqual(await evaluate("Array.from(document.querySelectorAll('[data-directory=users] select')).map(select => select.options[0].textContent)"), ['All roles', 'All departments', 'All statuses'], 'users filters use plural all-options');
        assert.ok(await evaluate("Array.from(document.querySelectorAll('[data-directory=users] select')).every(select => select.getBoundingClientRect().width >= parseFloat(getComputedStyle(select).minWidth))"), 'users filter dropdowns meet their minimum widths');
        assert.ok(await evaluate("Array.from(document.querySelectorAll('.content .table th,.content .table td:not(.empty-state)')).every(cell => getComputedStyle(cell).textAlign === 'left')"), 'users table columns are left-aligned');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.user-actions')).justifyContent"), 'flex-start', 'user actions are left-aligned');
        assert.ok(await evaluate("getComputedStyle(document.querySelector('.table-footer nav [aria-current=page]')).fontFamily.includes('Bitter')"), 'pagination numbers use the FaculTrack brand font');
        assert.ok(await evaluate("!!document.querySelector('a[data-form-modal] .bi-plus-lg')"), 'Add User button has a leading plus icon');
        await evaluate("window.beforeAddModalUrl = location.href; document.querySelector('a[data-form-modal]').click()");
        await until('document.querySelector("[data-edit-modal]").open && !!document.querySelector("[data-edit-modal-body] form")');
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-title]').textContent"), 'Add User', 'Add User opens in the centered form modal');
        assert.equal(await evaluate("location.href === window.beforeAddModalUrl"), true, 'Add User does not navigate away from the list');
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] input[name=modal_form]').value"), '1', 'add form marks modal validation handling');
        await evaluate("document.querySelector('[data-edit-modal-body] form button[type=submit]').click()");
        const requiredErrors = await evaluate("(() => { const body = document.querySelector('[data-edit-modal-body]'); const after = selector => body.querySelector(selector).closest('.form-label').nextElementSibling.textContent.trim(); return [after('input[name=full_name]'), after('input[name=employee_id]'), body.querySelector('[data-email-error]').textContent.trim(), after('input[name=password]'), after('select[name=department_id]')]; })()");
        assert.deepEqual(requiredErrors, ['Full name is required.', 'Use 3–48 letters, numbers or hyphens.', 'Email address is required.', 'Use at least 8 characters.', 'Choose a department.'], 'modal validation messages appear beside their fields');
        assert.equal(await evaluate("!!document.querySelector('.app-toast')"), false, 'field errors stay inside the modal');
        const filteredName = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=full_name]'); input.value = 'maria ñO-NEZ. 2smith'; input.dispatchEvent(new Event('input', { bubbles: true })); return input.value; })()");
        assert.equal(filteredName, 'maria ñO-NEZ. smith', 'full name input removes numbers and unsupported characters');
        await evaluate("document.querySelector('[data-edit-modal-body] input[name=full_name]').dispatchEvent(new Event('blur'))");
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] input[name=full_name]').value"), 'Maria Ño-Nez. Smith', 'full name capitalizes every word on blur');
        const filteredEmployeeId = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=employee_id]'); input.value = 'EMP-42#'; input.dispatchEvent(new Event('input', { bubbles: true })); return input.value; })()");
        assert.equal(filteredEmployeeId, 'EMP-42', 'Employee ID input allows only letters, numbers, and hyphens');
        const invalidEmails = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=email]'); const states = []; for (const value of ['maria.school', 'maria@school']) { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); states.push(input.closest('.user-email-group').querySelector('[data-email-error]').hidden); } return states; })()");
        assert.deepEqual(invalidEmails, [false, false], 'email error remains until both @ and a domain dot are present');
        const validEmail = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=email]'); input.value = 'valid@example.test'; input.dispatchEvent(new Event('input', { bubbles: true })); return [input.checkValidity(), input.closest('.user-email-group').querySelector('[data-email-error]').hidden]; })()");
        assert.deepEqual(validEmail, [true, true], 'valid email immediately clears inline error');
        assert.ok(await evaluate("!!document.querySelector('[data-edit-modal-body] [data-password-requirements]') && !!document.querySelector('[data-edit-modal-body] [data-password-toggle]')"), 'initial password includes requirements and an eye control');
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] [data-password-toggle]').hidden"), true, 'empty initial password hides the eye control');
        const initialPasswordRules = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=password]'); input.value = 'abcdefgh'; input.dispatchEvent(new Event('input', { bubbles: true })); return Array.from(document.querySelectorAll('[data-edit-modal-body] [data-password-rule]')).map(rule => rule.classList.contains('met')); })()");
        assert.deepEqual(initialPasswordRules, [true, false], 'initial password requirements update as the user types');
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] [data-password-toggle]').hidden"), false, 'typing a password shows the eye control');
        await evaluate("document.querySelector('[data-edit-modal-body] [data-password-toggle]').click()");
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] input[name=password]').type"), 'text', 'initial password can be revealed');
        await evaluate("document.querySelector('[data-edit-modal-body] [data-password-toggle]').click()");
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] input[name=password]').type"), 'password', 'initial password can be hidden');
        await evaluate("document.querySelector('[data-edit-modal-body] form button[type=submit]').click()");
        assert.ok(await evaluate("Array.from(document.querySelectorAll('[data-edit-modal-body] .field-validation-error')).some(error => error.textContent.includes('Include at least one number'))"), 'invalid password error appears below its field');
        assert.ok(await evaluate("Array.from(document.querySelectorAll('[data-edit-modal-body] .field-validation-error')).some(error => error.textContent === 'Choose a department.')"), 'missing department error appears below its field');
        assert.equal(await evaluate("!!document.querySelector('.app-toast')"), false, 'modal field validation does not use a bottom toast');
        const invalidPasswordEyeState = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=password]'); input.value = 'a'; input.dispatchEvent(new Event('input', { bubbles: true })); return [input.classList.contains('is-invalid'), getComputedStyle(input).backgroundImage, input.closest('.password-field-wrap').querySelector('[data-password-toggle]').hidden]; })()");
        assert.deepEqual(invalidPasswordEyeState, [true, 'none', false], 'typing into an invalid password hides its validation icon so the eye is visible');
        await evaluate("(() => { const body = document.querySelector('[data-edit-modal-body]'); body.querySelector('input[name=employee_id]').value = 'EMP-ADMIN'; body.querySelector('input[name=employee_id]').dispatchEvent(new Event('input', { bubbles: true })); body.querySelector('input[name=password]').value = 'abc12345'; body.querySelector('input[name=password]').dispatchEvent(new Event('input', { bubbles: true })); const department = body.querySelector('select[name=department_id]'); department.value = department.options[1].value; department.dispatchEvent(new Event('change', { bubbles: true })); body.querySelector('form button[type=submit]').click(); })()");
        await until('document.querySelector("[data-edit-modal-body] input[name=employee_id]").closest(".form-label").nextElementSibling?.textContent.includes("already in use")');
        assert.equal(await evaluate("document.querySelector('[data-edit-modal]').open"), true, 'server validation keeps the form modal open');
        assert.equal(await evaluate("!!document.querySelector('.app-toast')"), false, 'server validation stays inline instead of becoming a bottom toast');
        await evaluate("document.querySelector('[data-close-edit]').click()");
        await evaluate("document.querySelector('.user-action[data-edit-modal]').click()");
        await until('document.querySelector("[data-edit-modal]").open && !!document.querySelector("[data-edit-modal-body] form")');
        assert.ok(await evaluate("document.querySelector('[data-edit-modal-title]').textContent.trim().length > 0"), 'edit modal shows the record title');
        assert.ok(await evaluate("Math.abs(document.querySelector('[data-edit-modal]').getBoundingClientRect().x + document.querySelector('[data-edit-modal]').offsetWidth / 2 - window.innerWidth / 2) < 1 && Math.abs(document.querySelector('[data-edit-modal]').getBoundingClientRect().y + document.querySelector('[data-edit-modal]').offsetHeight / 2 - window.innerHeight / 2) < 1"), 'edit modal is centered in the viewport');
        assert.equal(await evaluate("document.querySelector('[data-edit-modal-body] input[name=modal_form]').value"), '1', 'modal form marks submissions for list-page error handling');
        await evaluate("document.querySelector('[data-close-edit]').click()");
        assert.equal(await evaluate("document.querySelector('[data-edit-modal]').open"), false, 'edit modal closes with its X button');
        assert.ok(await evaluate("!!document.querySelector('[data-reset-user]')"), 'users list offers password reset actions');
        await evaluate("document.querySelector('[data-reset-user]').click()");
        assert.equal(await evaluate("document.querySelector('[data-reset-password-dialog]').open"), true, 'reset-password modal opens');
        assert.ok(await evaluate("Math.abs(document.querySelector('[data-reset-password-dialog]').getBoundingClientRect().x + document.querySelector('[data-reset-password-dialog]').offsetWidth / 2 - window.innerWidth / 2) < 1 && Math.abs(document.querySelector('[data-reset-password-dialog]').getBoundingClientRect().y + document.querySelector('[data-reset-password-dialog]').offsetHeight / 2 - window.innerHeight / 2) < 1"), 'reset-password modal is centered in the viewport');
        assert.ok(await evaluate("document.querySelector('[data-reset-user-name]').textContent.trim().length > 0"), 'reset modal shows the selected employee name');
        assert.ok(await evaluate("document.querySelector('[data-reset-employee-id]').textContent.trim().length > 0"), 'reset modal shows the selected Employee ID');
        assert.ok(await evaluate("!!document.querySelector('[data-reset-password-dialog] [data-password-requirements]')"), 'reset modal shows password requirements');
        const resetPasswordInput = await evaluate("(() => { const input = document.querySelector('#reset-password-form input[name=password]'); input.value = 'abcdefgh'; input.dispatchEvent(new Event('input', { bubbles: true })); return Array.from(document.querySelectorAll('#reset-password-form [data-password-rule]')).map(rule => [rule.classList.contains('met'), getComputedStyle(rule.querySelector('.password-requirement-check')).display]); })()");
        assert.deepEqual(resetPasswordInput, [[true, 'none'], [false, 'none']], 'reset modal checklist hides unmet checks');
        const validResetPassword = await evaluate("(() => { const input = document.querySelector('#reset-password-form input[name=password]'); input.value = 'abc12345'; input.dispatchEvent(new Event('input', { bubbles: true })); return Array.from(document.querySelectorAll('#reset-password-form [data-password-rule]')).map(rule => [rule.classList.contains('met'), getComputedStyle(rule.querySelector('.password-requirement-check')).display]); })()");
        assert.deepEqual(validResetPassword, [[true, 'inline-block'], [true, 'inline-block']], 'reset modal checklist shows satisfied checks');
        await evaluate("document.querySelector('#reset-password-form [data-password-toggle]').click()");
        assert.equal(await evaluate("document.querySelector('#reset-password-form input[name=password]').type"), 'text', 'reset password can be shown');
        await evaluate("document.querySelector('#reset-password-form [data-password-toggle]').click()");
        assert.equal(await evaluate("document.querySelector('#reset-password-form input[name=password]').type"), 'password', 'reset password can be hidden');
        await evaluate("document.querySelector('.reset-password-close').click()");
        assert.equal(await evaluate("document.querySelector('[data-reset-password-dialog]').open"), false, 'reset modal closes with its X button');
        await evaluate("document.querySelector('[data-reset-user]').click()");
        await until('!Array.from(document.querySelectorAll("#reset-password-form [data-password-rule]")).some(rule => rule.classList.contains("met"))');
        assert.equal(await evaluate("Array.from(document.querySelectorAll('#reset-password-form [data-password-rule]')).some(rule => rule.classList.contains('met'))"), false, 'reopening reset modal clears prior checklist state');
        await evaluate("document.querySelector('[data-close-reset]:last-child').click()");
        assert.equal(await evaluate("document.querySelector('[data-reset-password-dialog]').open"), false, 'reset modal closes with Cancel');
        await evaluate("document.querySelector('.user-action-form[data-confirm-label=\"Deactivate\"] .user-action-switch').click()");
        await until('document.querySelector("[data-confirm-dialog]").open');
        assert.ok(await evaluate("Math.abs(document.querySelector('[data-confirm-dialog]').getBoundingClientRect().x + document.querySelector('[data-confirm-dialog]').offsetWidth / 2 - window.innerWidth / 2) < 1 && Math.abs(document.querySelector('[data-confirm-dialog]').getBoundingClientRect().y + document.querySelector('[data-confirm-dialog]').offsetHeight / 2 - window.innerHeight / 2) < 1"), 'account status confirmation is centered in the viewport');
        assert.ok(await evaluate("document.querySelector('[data-confirm-dialog]').classList.contains('confirmation-dialog') && document.querySelector('[data-confirm-message]').textContent.trim().length > 0"), 'account status uses the themed confirmation dialog');
        assert.equal(await evaluate("document.querySelector('[data-confirm-accept]').textContent"), 'Deactivate', 'deactivation confirmation names the action');
        assert.equal(await evaluate("document.querySelector('[data-confirm-accept]').classList.contains('btn-danger')"), true, 'deactivation confirmation uses a red action button');
        await evaluate("document.querySelector('.confirmation-dialog-actions [data-confirm-cancel]').click()");
        assert.equal(await evaluate("document.querySelector('[data-confirm-dialog]').open"), false, 'account status confirmation can be canceled');
        await evaluate("document.querySelector('.user-action-form[data-confirm-label=\"Deactivate\"] .user-action-switch').click()");
        await until('document.querySelector("[data-confirm-dialog]").open');
        await evaluate("window.confirmationAccepted = false; document.addEventListener('submit', event => { if (event.target.matches('.user-action-form[data-confirm]')) { event.preventDefault(); window.confirmationAccepted = true; } }, { once: true })");
        await evaluate("document.querySelector('[data-confirm-accept]').click()");
        await until('window.confirmationAccepted === true && !document.querySelector("[data-confirm-dialog]").open');
      }
      if (page === 'departments' && width === 1440 && role === 'admin') {
        assert.ok(await evaluate("Array.from(document.querySelectorAll('.department-table th,.department-table td:not(.empty-state)')).every(cell => getComputedStyle(cell).textAlign === 'left')"), 'department table columns are left-aligned');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.department-actions')).justifyContent"), 'flex-start', 'department actions are left-aligned');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.department-action')).borderTopColor"), 'rgb(219, 228, 220)', 'department actions use the bordered user-action style');
        await evaluate("document.querySelector('a[data-form-modal]').click()");
        await until('document.querySelector("[data-edit-modal]").open && !!document.querySelector("[data-edit-modal-body] form")');
        const filteredDepartmentFields = await evaluate("(() => { const body = document.querySelector('[data-edit-modal-body]'); const name = body.querySelector('input[name=name]'); const code = body.querySelector('input[name=code]'); name.value = 'Bio! 2026'; name.dispatchEvent(new Event('input', { bubbles: true })); code.value = 'BIO-26!'; code.dispatchEvent(new Event('input', { bubbles: true })); return [name.value, code.value]; })()");
        assert.deepEqual(filteredDepartmentFields, ['Bio 2026', 'BIO-26'], 'department name and code filter disallowed characters');
        assert.ok(await evaluate("(() => { const label = document.querySelector('[data-edit-modal-body] textarea[name=description]').closest('label'); const optional = label.querySelector('.optional-label'); return optional?.textContent.trim() === '(optional)' && getComputedStyle(optional).color !== getComputedStyle(label).color; })()"), 'department description is marked optional in muted text');
        await evaluate("(() => { const body = document.querySelector('[data-edit-modal-body]'); const name = body.querySelector('input[name=name]'); const code = body.querySelector('input[name=code]'); name.value = '!!!'; name.dispatchEvent(new Event('input', { bubbles: true })); code.value = '###'; code.dispatchEvent(new Event('input', { bubbles: true })); body.querySelector('form button[type=submit]').click(); })()");
        await until('document.querySelector("[data-edit-modal-body] input[name=name]").closest(".form-label").nextElementSibling?.textContent.includes("Department name is required")');
        assert.ok(await evaluate("document.querySelector('[data-edit-modal-body] input[name=code]').closest('.form-label').nextElementSibling?.textContent.includes('Department code is required')"), 'empty department code shows a required-field error');
        assert.equal(await evaluate("!!document.querySelector('.app-toast')"), false, 'department required-field errors stay in the modal');
        await evaluate("document.querySelector('[data-close-edit]').click()");
        await evaluate("document.querySelector('.department-action-delete').click()");
        await until('document.querySelector("[data-confirm-dialog]").open');
        assert.equal(await evaluate("document.querySelector('[data-confirm-accept]').textContent"), 'Delete', 'delete confirmation names the action');
        assert.equal(await evaluate("document.querySelector('[data-confirm-accept]').classList.contains('btn-danger')"), true, 'delete confirmation uses a red action button');
        await evaluate("document.querySelector('.confirmation-dialog-actions [data-confirm-cancel]').click()");
        assert.equal(await evaluate("document.querySelector('[data-confirm-dialog]').open"), false, 'delete confirmation can be canceled');
      }
      if (page === 'academic-terms' && width === 1440 && role === 'admin') {
        assert.ok(await evaluate("Array.from(document.querySelectorAll('.academic-term-table th,.academic-term-table td:not(.empty-state)')).every(cell => getComputedStyle(cell).textAlign === 'left')"), 'academic-term table columns are left-aligned');
        const actionAlignment = await evaluate("document.querySelector('.term-actions') ? getComputedStyle(document.querySelector('.term-actions')).justifyContent : 'flex-start'");
        assert.equal(actionAlignment, 'flex-start', 'academic-term actions are left-aligned');
        await evaluate("document.querySelector('a[data-form-modal]').click()");
        await until('document.querySelector("[data-edit-modal]").open && !!document.querySelector("[data-edit-modal-body] form")');
        assert.ok(await evaluate("!!document.querySelector('[data-edit-modal-body] select[name=academic_year]')"), 'academic year uses a year-range picker');
        assert.equal(await evaluate("document.querySelectorAll('[data-edit-modal-body] select[name=academic_year] option').length"), 2, 'new academic terms offer only the previous and current academic years');
        const themedDateFields = await evaluate("Array.from(document.querySelectorAll('[data-edit-modal-body] input[type=date]')).every(input => getComputedStyle(input).colorScheme === 'light' && getComputedStyle(input).accentColor !== 'auto')");
        assert.equal(themedDateFields, true, 'start and end date controls use the light FaculTrack color scheme');
        await evaluate("document.querySelector('[data-close-edit]').click()");
      }
      if (page === 'dashboard' && width === 1440 && role === 'admin') {
        await evaluate("showToast('Test notification')");
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.app-toast')).position"), 'fixed', 'notifications are fixed to the viewport');
        await until('!document.querySelector(".app-toast")');
      }
      if (page === 'types' && width === 1440 && role === 'admin') {
        assert.ok(await evaluate("Array.from(document.querySelectorAll('.document-type-table th,.document-type-table td:not(.empty-state)')).every(cell => getComputedStyle(cell).textAlign === 'left')"), 'document-type table columns are left-aligned');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.document-type-actions')).justifyContent"), 'flex-start', 'document-type actions are left-aligned');
        assert.deepEqual(await evaluate("Array.from(document.querySelectorAll('.document-type-table thead th')).map(cell => cell.textContent.trim())"),
          ['Document Name', 'Instructions', 'Template', 'Allowed Formats', 'Actions'], 'documents list has the requested columns');
        assert.ok(await evaluate("!!document.querySelector('input[type=search][aria-label=\"Search document types\"]')"), 'documents list has document-type search');
        assert.ok(await evaluate("!!document.querySelector('.document-type-table .document-type-action')"), 'documents list uses icon actions');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.document-type-action')).borderTopColor"), 'rgb(219, 228, 220)', 'document-type actions use the bordered user-action style');
        assert.equal(await evaluate("!!document.querySelector('.document-tools, .document-type-table th:nth-child(6)')"), false, 'documents list omits workflow shortcuts and status');
        await evaluate("document.querySelector('a[data-form-modal]').click()");
        await until('document.querySelector("[data-edit-modal]").open && !!document.querySelector("[data-edit-modal-body] form")');
        const documentName = await evaluate("(() => { const input = document.querySelector('[data-edit-modal-body] input[name=name]'); input.value = 'Annual Record #1!?'; input.dispatchEvent(new Event('input', { bubbles: true })); return input.value; })()");
        assert.equal(documentName, 'Annual Record #1!?', 'document type name does not filter characters');
        assert.ok(await evaluate("(() => { const label = document.querySelector('[data-edit-modal-body] textarea[name=description]').closest('label'); const optional = label.querySelector('.optional-label'); return optional?.textContent.trim() === '(optional)' && getComputedStyle(optional).color !== getComputedStyle(label).color; })()"), 'document instructions are marked optional in muted text');
        assert.equal(await evaluate("(() => { const checkbox = document.querySelector('[data-edit-modal-body] .document-format-options .form-check-input'); checkbox.checked = true; return getComputedStyle(checkbox).backgroundColor; })()"), 'rgb(25, 91, 55)', 'document format checklist uses the theme green');
        await evaluate("document.querySelector('[data-close-edit]').click()");
      }
      if (page === 'reports' && width === 1440 && role === 'admin') {
        assert.deepEqual(await evaluate("Array.from(document.querySelectorAll('.report-table thead th')).map(cell => cell.textContent.trim())"),
          ['Faculty', 'Department', 'Document Type', 'Status', 'Submitted Date'], 'reports table matches the requested columns');
        assert.ok(await evaluate("!!document.querySelector('[aria-label=\"Academic Term\"]') && !!document.querySelector('[aria-label=\"Department\"]') && !!document.querySelector('[aria-label=\"Document Type\"]') && !!document.querySelector('[aria-label=\"Submission Status\"]')"), 'reports page has the requested filters');
        assert.ok(await evaluate("!!document.querySelector('.page-heading .report-actions a[href*=csv]') && !!document.querySelector('.page-heading .report-actions [data-print]')"), 'reports exports align with the page title');
      }
      if (page === 'settings' && width === 1440 && role === 'admin') {
        await evaluate("document.querySelector('.settings-tabs a[href*=\"tab=password\"]').click()");
        await until('!!document.querySelector("[data-password-requirements]")');
        const passwordInput = await evaluate("document.querySelector('input[name=password]').value = 'abcdefgh'; document.querySelector('input[name=password]').dispatchEvent(new Event('input', { bubbles: true })); Array.from(document.querySelectorAll('[data-password-rule]')).map(rule => [rule.classList.contains('met'), getComputedStyle(rule.querySelector('.password-requirement-check')).display])");
        assert.deepEqual(passwordInput, [[true, 'none'], [false, 'none']], 'password checklist hides checks for unmet requirements');
        const validPassword = await evaluate("document.querySelector('input[name=password]').value = 'abc12345'; document.querySelector('input[name=password]').dispatchEvent(new Event('input', { bubbles: true })); Array.from(document.querySelectorAll('[data-password-rule]')).map(rule => [rule.classList.contains('met'), getComputedStyle(rule.querySelector('.password-requirement-check')).display])");
        assert.deepEqual(validPassword, [[true, 'inline-block'], [true, 'inline-block']], 'password checklist shows green checks for satisfied requirements');
        for (const name of ['current_password', 'password']) {
          const toggle = `[data-password-toggle][aria-controls="password-${name}"]`;
          await evaluate(`document.querySelector('${toggle}').click()`);
          assert.equal(await evaluate(`document.querySelector('#password-${name}').type`), 'text', name + ' can be shown');
          await evaluate(`document.querySelector('${toggle}').click()`);
          assert.equal(await evaluate(`document.querySelector('#password-${name}').type`), 'password', name + ' can be hidden');
        }
      }
      if (page === 'dashboard' && process.env.FACULTRACK_QA_SCREENSHOTS) {
        const screenshot = await call('Page.captureScreenshot', { format: 'png' });
        await writeFile(join(process.env.FACULTRACK_QA_SCREENSHOTS, role + '-dashboard-' + width + '.png'), Buffer.from(screenshot.data, 'base64'));
      }
      if (page === 'dashboard' && width === 390) {
        assert.notEqual(await evaluate("getComputedStyle(document.querySelector('.topbar-brand-group')).display"), 'none', 'mobile header retains navigation controls while closed');
        assert.notEqual(await evaluate("getComputedStyle(document.querySelector('.topbar-brand-mark')).display"), 'none', 'mobile header retains the navigation logo');
      }
      if (page === 'dashboard' && width === 390) {
        await evaluate('document.querySelector("[data-menu-toggle]").click()');
        await until('document.querySelector("[data-menu-toggle]").getAttribute("aria-expanded") === "true"');
        assert.equal(await evaluate("getComputedStyle(document.querySelector('.topbar-brand-group')).display"), 'none', 'open mobile navigation avoids duplicating its header controls');
        await call('Input.dispatchKeyEvent',{ type: 'keyDown', key: 'Escape', code: 'Escape' });
        await until('document.querySelector("[data-menu-toggle]").getAttribute("aria-expanded") === "false"');
      }
      console.log('PASS', role, page, width + 'px');
    }
  }
  assert.deepEqual(errors, [], 'browser script errors');
} finally {
  socket.close();
  await fetch(debug + '/json/close/' + tab.id);
}

