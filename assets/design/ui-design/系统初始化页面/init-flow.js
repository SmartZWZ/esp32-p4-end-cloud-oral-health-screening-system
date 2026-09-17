(() => {
  const initializationDuration = 3000;
  const startResponseWindow = 5000;
  const startLink = document.querySelector('.start-button');

  const failureTimer = window.setTimeout(() => {
    window.location.assign('failure.html');
  }, initializationDuration + startResponseWindow);

  startLink.addEventListener('click', () => {
    window.clearTimeout(failureTimer);
  });
})();
