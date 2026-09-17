(() => {
  const storageKey = 'dentiscope.defaultWifi';
  const networkRows = [...document.querySelectorAll('[data-default-network]')];

  function selectDefault(networkName, persist = true) {
    networkRows.forEach((row) => {
      const isSelected = row.dataset.defaultNetwork === networkName;
      row.classList.toggle('is-default', isSelected);
      row.setAttribute('aria-checked', String(isSelected));
    });

    if(persist) {
      try {
        window.localStorage.setItem(storageKey, networkName);
      }
      catch {
        // The selection remains active for this session if storage is unavailable.
      }
    }
  }

  try {
    const storedNetwork = window.localStorage.getItem(storageKey);
    if(networkRows.some((row) => row.dataset.defaultNetwork === storedNetwork)) {
      selectDefault(storedNetwork, false);
    }
  }
  catch {
    // Keep DENTISCOPE LAB as the initial default.
  }

  networkRows.forEach((row) => {
    row.addEventListener('click', () => selectDefault(row.dataset.defaultNetwork));
  });
})();
