(() => {
  const video = document.querySelector('#camera-preview');
  const canvas = document.querySelector('#captured-frame');
  const context = canvas.getContext('2d');
  const viewfinder = document.querySelector('.viewfinder');
  const status = document.querySelector('#capture-status');
  const backControl = document.querySelector('#back-control');
  const captureButton = document.querySelector('#capture-button');
  const capturedActions = document.querySelector('#captured-actions');
  const detectButton = document.querySelector('#start-detection');
  const uploadButton = document.querySelector('#upload-cloud');
  const uploadNotice = document.querySelector('#upload-notice');
  const resultPanel = document.querySelector('#result-panel');
  const detectionModal = document.querySelector('#detection-modal');
  const modalBackdrop = document.querySelector('#modal-backdrop');
  const playVoiceButton = document.querySelector('#play-voice');
  const voiceLabel = document.querySelector('#voice-label');
  const closeResultButton = document.querySelector('#close-result');
  let stream = null;
  let screenState = 'live';

  function setStatus(label) {
    status.lastChild.textContent = label;
  }

  function stopCamera() {
    if(stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    video.srcObject = null;
    viewfinder.classList.remove('has-stream');
  }

  async function startCamera() {
    canvas.hidden = true;
    setStatus('LIVE');
    if(!navigator.mediaDevices?.getUserMedia) {
      setStatus('CAMERA UNAVAILABLE');
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 720 }, height: { ideal: 720 } },
        audio: false,
      });
      video.srcObject = stream;
      await video.play();
      viewfinder.classList.add('has-stream');
    }
    catch {
      setStatus('CAMERA UNAVAILABLE');
    }
  }

  function showLiveState() {
    window.speechSynthesis?.cancel();
    detectionModal.classList.remove('is-open');
    detectionModal.setAttribute('aria-hidden', 'true');
    resultPanel.hidden = true;
    capturedActions.hidden = true;
    captureButton.hidden = false;
    uploadNotice.hidden = true;
    playVoiceButton.classList.remove('is-playing');
    voiceLabel.textContent = 'PLAY VOICE';
    screenState = 'live';
    startCamera();
  }

  function showCapturedState() {
    window.speechSynthesis?.cancel();
    closeDetectionChoices();
    resultPanel.hidden = true;
    captureButton.hidden = true;
    capturedActions.hidden = false;
    canvas.hidden = false;
    stopCamera();
    setStatus('CAPTURED');
    screenState = 'captured';
  }

  function captureFrame() {
    if(video.videoWidth && video.videoHeight) {
      context.drawImage(video, 0, 0, canvas.width, canvas.height);
    }
    canvas.hidden = false;
    stopCamera();
    setStatus('CAPTURED');
    showCapturedState();
  }

  function openDetectionChoices() {
    screenState = 'detection-choice';
    detectionModal.classList.add('is-open');
    detectionModal.setAttribute('aria-hidden', 'false');
  }

  function closeDetectionChoices() {
    detectionModal.classList.remove('is-open');
    detectionModal.setAttribute('aria-hidden', 'true');
    if(screenState === 'detection-choice') screenState = 'captured';
  }

  function showResult() {
    closeDetectionChoices();
    capturedActions.hidden = true;
    resultPanel.hidden = false;
    screenState = 'result';
  }

  function uploadToCloud() {
    uploadButton.classList.add('is-uploading');
    uploadButton.disabled = true;
    uploadButton.querySelector('span').textContent = 'UPLOADING…';

    window.setTimeout(() => {
      uploadNotice.hidden = false;
      uploadButton.classList.remove('is-uploading');
      uploadButton.disabled = false;
      uploadButton.querySelector('span').textContent = 'UPLOAD TO CLOUD';
      window.setTimeout(showLiveState, 1100);
    }, 650);
  }

  function goBack() {
    if(screenState === 'detection-choice') {
      closeDetectionChoices();
      return;
    }
    if(screenState === 'result') {
      showCapturedState();
      return;
    }
    if(screenState === 'captured') {
      showLiveState();
      return;
    }
    if(window.history.length > 1) {
      window.history.back();
      return;
    }
    window.location.href = '../%E6%95%B4%E4%B8%AA%E7%B3%BB%E7%BB%9F%E7%95%8C%E9%9D%A2/03_home/index.html';
  }

  function playVoice() {
    if(!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    const speech = new SpeechSynthesisUtterance('Analysis complete. Result ready for review.');
    speech.lang = 'en-US';
    speech.onstart = () => {
      playVoiceButton.classList.add('is-playing');
      voiceLabel.textContent = 'VOICE PLAYING';
    };
    speech.onend = () => {
      playVoiceButton.classList.remove('is-playing');
      voiceLabel.textContent = 'PLAY VOICE';
    };
    speech.onerror = speech.onend;
    window.speechSynthesis.speak(speech);
  }

  captureButton.addEventListener('click', captureFrame);
  backControl.addEventListener('click', goBack);
  detectButton.addEventListener('click', openDetectionChoices);
  uploadButton.addEventListener('click', uploadToCloud);
  modalBackdrop.addEventListener('click', closeDetectionChoices);
  document.querySelectorAll('[data-mode]').forEach((button) => button.addEventListener('click', showResult));
  playVoiceButton.addEventListener('click', playVoice);
  closeResultButton.addEventListener('click', showLiveState);
  window.addEventListener('beforeunload', stopCamera);
  startCamera();
})();
