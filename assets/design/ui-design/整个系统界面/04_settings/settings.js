(() => {
  const controls = [...document.querySelectorAll('.range-input')];

  function updateControl(input, output) {
    const value = Number(input.value);
    input.style.setProperty('--level', `${value}%`);
    input.setAttribute('aria-valuetext', `${value}%`);
    output.value = `${value}%`;
    output.textContent = `${value}%`;

    try {
      window.localStorage.setItem(`dentiscope.${input.dataset.setting}`, String(value));
    }
    catch {
      // The control remains usable if local storage is unavailable.
    }
  }

  controls.forEach((input) => {
    const output = document.querySelector(`output[for="${input.id}"]`);
    if(!output) return;

    try {
      const savedValue = window.localStorage.getItem(`dentiscope.${input.dataset.setting}`);
      if(savedValue !== null && Number(savedValue) >= 0 && Number(savedValue) <= 100) {
        input.value = savedValue;
      }
    }
    catch {
      // Use the HTML default value.
    }

    updateControl(input, output);
    input.addEventListener('input', () => updateControl(input, output));
  });
})();
