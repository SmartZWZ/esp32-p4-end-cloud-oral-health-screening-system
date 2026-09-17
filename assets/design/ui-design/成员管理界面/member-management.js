(() => {
  const STORAGE_KEY = 'dentiscope.members';
  const defaultMembers = [
    { id: 'alex-chen', name: 'ALEX CHEN' },
    { id: 'jamie-li', name: 'JAMIE LI' },
    { id: 'taylor-wu', name: 'TAYLOR WU' },
  ];

  const list = document.querySelector('#member-list');
  const count = document.querySelector('#member-count');
  const addButton = document.querySelector('#add-member');
  const dialogs = [...document.querySelectorAll('.editor-layer')];
  let members = loadMembers();
  let editingId = null;
  let activeInput = null;
  let uppercase = true;

  function loadMembers() {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
      if(Array.isArray(saved)) return saved.filter((member) => member?.id && member?.name);
    }
    catch { /* Use the starter members when saved data is invalid. */ }
    return [...defaultMembers];
  }

  function saveMembers() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(members));
  }

  function initials(name) {
    return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('');
  }

  function createIconButton(action, member, icon, label) {
    const button = document.createElement('button');
    button.className = 'icon-button';
    button.type = 'button';
    button.dataset.action = action;
    button.dataset.memberId = member.id;
    button.setAttribute('aria-label', `${label} ${member.name}`);
    const image = document.createElement('img');
    image.src = `assets/${icon}`;
    image.alt = '';
    button.append(image);
    return button;
  }

  function renderMembers() {
    list.querySelectorAll('.member-row').forEach((row) => row.remove());
    count.textContent = `${members.length} MEMBER${members.length === 1 ? '' : 'S'}`;

    members.forEach((member) => {
      const row = document.createElement('article');
      row.className = 'member-row';
      const avatar = document.createElement('span');
      avatar.className = 'member-avatar';
      avatar.textContent = initials(member.name);
      const name = document.createElement('p');
      name.textContent = member.name;
      const actions = document.createElement('div');
      actions.className = 'row-actions';
      actions.append(
        createIconButton('edit', member, 'edit.svg', 'Edit'),
        createIconButton('delete', member, 'delete.svg', 'Delete'),
      );
      row.append(avatar, name, actions);
      list.append(row);
    });
  }

  function openDialog(type, member = null) {
    closeDialogs();
    const dialog = document.querySelector(`#${type}-member-dialog`);
    const input = dialog.querySelector('input');
    editingId = member?.id ?? null;
    input.value = member?.name ?? '';
    activeInput = input;
    uppercase = true;
    dialog.querySelector('.wide-key').classList.remove('is-shift-active');
    dialog.classList.add('is-open');
    dialog.setAttribute('aria-hidden', 'false');
    window.setTimeout(() => input.focus(), 0);
  }

  function closeDialogs() {
    dialogs.forEach((dialog) => {
      dialog.classList.remove('is-open');
      dialog.setAttribute('aria-hidden', 'true');
    });
    editingId = null;
    activeInput = null;
    if(window.location.hash) history.replaceState(null, '', window.location.pathname);
  }

  function saveCurrentMember() {
    const dialog = dialogs.find((item) => item.classList.contains('is-open'));
    const input = dialog?.querySelector('input');
    const name = input?.value.trim().replace(/\s+/g, ' ').toUpperCase();
    if(!name) {
      input?.focus();
      return;
    }
    if(editingId) {
      const member = members.find((item) => item.id === editingId);
      if(member) member.name = name;
    }
    else {
      members.push({ id: `member-${Date.now()}`, name });
    }
    saveMembers();
    renderMembers();
    closeDialogs();
  }

  function removeMember(id) {
    members = members.filter((member) => member.id !== id);
    saveMembers();
    renderMembers();
  }

  function insertAtCursor(text) {
    if(!activeInput) return;
    const start = activeInput.selectionStart ?? activeInput.value.length;
    const end = activeInput.selectionEnd ?? start;
    activeInput.setRangeText(text, start, end, 'end');
    activeInput.focus();
  }

  function handleKeyboard(button) {
    const icon = button.querySelector('img');
    if(icon?.alt === 'Backspace') {
      if(!activeInput) return;
      const start = activeInput.selectionStart ?? activeInput.value.length;
      const end = activeInput.selectionEnd ?? start;
      if(start !== end) activeInput.setRangeText('', start, end, 'end');
      else if(start > 0) activeInput.setRangeText('', start - 1, start, 'end');
      activeInput.focus();
      return;
    }
    if(icon?.alt === 'Shift') {
      uppercase = !uppercase;
      button.classList.toggle('is-shift-active', !uppercase);
      return;
    }
    const key = button.textContent.trim();
    if(key === 'SPACE') insertAtCursor(' ');
    else if(key) insertAtCursor(uppercase ? key : key.toLowerCase());
  }

  addButton.addEventListener('click', (event) => {
    event.preventDefault();
    openDialog('add');
  });

  list.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]');
    if(!button) return;
    const member = members.find((item) => item.id === button.dataset.memberId);
    if(button.dataset.action === 'edit' && member) openDialog('edit', member);
    if(button.dataset.action === 'delete') removeMember(button.dataset.memberId);
  });

  dialogs.forEach((dialog) => {
    const input = dialog.querySelector('input');
    input.addEventListener('focus', () => { activeInput = input; });
    dialog.querySelector('.editor-backdrop').addEventListener('click', closeDialogs);
    dialog.querySelectorAll('[data-dialog-action]').forEach((action) => action.addEventListener('click', (event) => {
      event.preventDefault();
      if(action.dataset.dialogAction === 'save') saveCurrentMember();
      else closeDialogs();
    }));
    dialog.querySelectorAll('.keyboard button').forEach((button) => button.addEventListener('click', () => handleKeyboard(button)));
  });

  renderMembers();
})();
