(() => {
  const keyboard = document.querySelector('.virtual-keyboard');
  const nameInput = document.querySelector('#network-name');
  const passwordInput = document.querySelector('#network-password');
  const shiftButton = keyboard.querySelector('[data-action="shift"]');
  const letterButtons = [...keyboard.querySelectorAll('[data-key]')]
    .filter((button) => /^[A-Z]$/.test(button.dataset.key));

  let activeInput = nameInput;
  let shifted = true;

  [nameInput, passwordInput].forEach((input) => {
    input.addEventListener('focus', () => {
      activeInput = input;
    });
  });

  function insertText(text) {
    const start = activeInput.selectionStart;
    const end = activeInput.selectionEnd;
    activeInput.value = `${activeInput.value.slice(0, start)}${text}${activeInput.value.slice(end)}`;
    const nextPosition = start + text.length;
    activeInput.setSelectionRange(nextPosition, nextPosition);
    activeInput.focus();
  }

  function backspace() {
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
      button.textContent = shifted ? button.dataset.key : button.dataset.key.toLowerCase();
    });
  }

  keyboard.addEventListener('pointerdown', (event) => {
    if(event.target.closest('button')) event.preventDefault();
  });

  keyboard.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if(!button) return;

    if(button.dataset.key) {
      const key = button.dataset.key;
      insertText(/^[A-Z]$/.test(key) && !shifted ? key.toLowerCase() : key);
      if(/^[A-Z]$/.test(key) && shifted) {
        shifted = false;
        updateShift();
      }
      return;
    }

    switch(button.dataset.action) {
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
        if(nameInput.value.trim()) window.location.hash = 'wifi-screen';
        else nameInput.focus();
        break;
      default:
        break;
    }
  });

  updateShift();
})();
