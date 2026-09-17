(() => {
  const keyboard = document.querySelector('.edit-keyboard');
  const shiftButton = keyboard.querySelector('[data-edit-action="shift"]');
  const letterButtons = [...keyboard.querySelectorAll('[data-edit-key]')]
    .filter((button) => /^[A-Z]$/.test(button.dataset.editKey));

  let activeInput = null;
  let shifted = true;

  function selectActiveInput() {
    activeInput = document.querySelector('.password-modal:target .password-input');
  }

  document.querySelectorAll('.password-input').forEach((input) => {
    input.addEventListener('focus', () => {
      activeInput = input;
    });
  });

  function insertText(text) {
    if(!activeInput) return;
    const start = activeInput.selectionStart;
    const end = activeInput.selectionEnd;
    activeInput.value = `${activeInput.value.slice(0, start)}${text}${activeInput.value.slice(end)}`;
    const nextPosition = start + text.length;
    activeInput.setSelectionRange(nextPosition, nextPosition);
    activeInput.focus();
  }

  function backspace() {
    if(!activeInput) return;
    const start = activeInput.selectionStart;
    const end = activeInput.selectionEnd;
    if(start !== end) {
      activeInput.value = `${activeInput.value.slice(0, start)}${activeInput.value.slice(end)}`;
      activeInput.setSelectionRange(start, start);
    }
    else if(start > 0) {
      activeInput.value = `${activeInput.value.slice(0, start - 1)}${activeInput.value.slice(start)}`;
      activeInput.setSelectionRange(start - 1, start - 1);
    }
    activeInput.focus();
  }

  function updateShift() {
    shiftButton.classList.toggle('is-shifted', shifted);
    shiftButton.setAttribute('aria-pressed', String(shifted));
    letterButtons.forEach((button) => {
      button.textContent = shifted ? button.dataset.editKey : button.dataset.editKey.toLowerCase();
    });
  }

  keyboard.addEventListener('pointerdown', (event) => {
    if(event.target.closest('button')) event.preventDefault();
  });

  keyboard.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if(!button) return;

    if(button.dataset.editKey) {
      const key = button.dataset.editKey;
      insertText(/^[A-Z]$/.test(key) && !shifted ? key.toLowerCase() : key);
      if(/^[A-Z]$/.test(key) && shifted) {
        shifted = false;
        updateShift();
      }
      return;
    }

    switch(button.dataset.editAction) {
      case 'shift':
        shifted = !shifted;
        updateShift();
        break;
      case 'space':
        insertText(' ');
        break;
      case 'backspace':
        backspace();
        break;
      case 'connect':
        window.location.hash = 'wifi-screen';
        break;
      default:
        break;
    }
  });

  window.addEventListener('hashchange', selectActiveInput);
  selectActiveInput();
  updateShift();
})();
