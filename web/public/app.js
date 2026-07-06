const state = {
  videoFrames: 0,
  photoFrames: 0,
  latestVideoBlob: null,
  latestPhotoBlob: null,
  latestVideoUrl: null,
  latestPhotoUrl: null,
  videoDetecting: false,
  photoDetecting: false,
  videoDetections: 0,
  photoDetections: 0,
};

const els = {
  overallDot: document.getElementById("overallDot"),
  overallStatus: document.getElementById("overallStatus"),
  rawVideo: document.getElementById("rawVideo"),
  rawVideoEmpty: document.getElementById("rawVideoEmpty"),
  rawVideoMeta: document.getElementById("rawVideoMeta"),
  rawVideoState: document.getElementById("rawVideoState"),
  photoImage: document.getElementById("photoImage"),
  photoEmpty: document.getElementById("photoEmpty"),
  photoMeta: document.getElementById("photoMeta"),
  photoState: document.getElementById("photoState"),
  videoCanvas: document.getElementById("videoCanvas"),
  videoCanvasEmpty: document.getElementById("videoCanvasEmpty"),
  videoDetectMeta: document.getElementById("videoDetectMeta"),
  videoDetectState: document.getElementById("videoDetectState"),
  photoCanvas: document.getElementById("photoCanvas"),
  photoCanvasEmpty: document.getElementById("photoCanvasEmpty"),
  photoDetectMeta: document.getElementById("photoDetectMeta"),
  photoDetectState: document.getElementById("photoDetectState"),
  videoFrames: document.getElementById("videoFrames"),
  photoFrames: document.getElementById("photoFrames"),
  videoDetections: document.getElementById("videoDetections"),
  photoDetections: document.getElementById("photoDetections"),
  refreshButton: document.getElementById("refreshButton"),
  detectPhotoButton: document.getElementById("detectPhotoButton"),
};

function setPill(el, text, kind = "") {
  el.textContent = text;
  el.classList.toggle("ok", kind === "ok");
  el.classList.toggle("bad", kind === "bad");
}

function setOverall(text, kind = "") {
  els.overallStatus.textContent = text;
  els.overallDot.classList.toggle("ok", kind === "ok");
  els.overallDot.classList.toggle("bad", kind === "bad");
}

function showImage(img, empty, blob) {
  const url = URL.createObjectURL(blob);
  if (img === els.rawVideo && state.latestVideoUrl) URL.revokeObjectURL(state.latestVideoUrl);
  if (img === els.photoImage && state.latestPhotoUrl) URL.revokeObjectURL(state.latestPhotoUrl);
  if (img === els.rawVideo) state.latestVideoUrl = url;
  if (img === els.photoImage) state.latestPhotoUrl = url;
  img.src = url;
  img.classList.add("visible");
  empty.classList.add("hidden");
}

function formatBytes(bytes) {
  if (!bytes) return "0 KB";
  return `${Math.round(bytes / 1024)} KB`;
}

function sameOriginWs(path) {
  const protocol = window.location.protocol === "https:" ? "wss:" : "ws:";
  return `${protocol}//${window.location.host}${path}`;
}

function connectVideo() {
  setPill(els.rawVideoState, "连接中");
  const ws = new WebSocket(sameOriginWs("/video-viewer"));
  ws.binaryType = "blob";

  ws.onopen = () => {
    setPill(els.rawVideoState, "已连接", "ok");
    setOverall("视频已连接", "ok");
  };

  ws.onmessage = (event) => {
    const blob = event.data instanceof Blob ? event.data : new Blob([event.data], { type: "image/jpeg" });
    state.latestVideoBlob = blob;
    state.videoFrames += 1;
    els.videoFrames.textContent = String(state.videoFrames);
    els.rawVideoMeta.textContent = `${state.videoFrames} 帧，${formatBytes(blob.size)}`;
    showImage(els.rawVideo, els.rawVideoEmpty, blob);
  };

  ws.onclose = () => {
    setPill(els.rawVideoState, "已断开", "bad");
    setOverall("视频断开", "bad");
    window.setTimeout(connectVideo, 2500);
  };

  ws.onerror = () => {
    setPill(els.rawVideoState, "异常", "bad");
  };
}

function connectPhotoEvents() {
  const events = new EventSource("/image/events");

  events.addEventListener("image-frame", async (event) => {
    const payload = JSON.parse(event.data);
    await loadLatestPhoto(payload.frames, payload.bytes);
    await detectPhoto();
  });

  events.addEventListener("stats", async (event) => {
    const payload = JSON.parse(event.data);
    if (payload.image?.latestBytes) {
      state.photoFrames = payload.image.frames;
      els.photoFrames.textContent = String(state.photoFrames);
      els.photoMeta.textContent = `${payload.image.frames} 张，${formatBytes(payload.image.latestBytes)}`;
      setPill(els.photoState, "有照片", "ok");
    }
  });

  events.onerror = () => {
    setPill(els.photoState, "事件断开", "bad");
  };
}

async function loadLatestPhoto(frames, bytes) {
  const response = await fetch(`/image/latest.jpg?t=${Date.now()}`, { cache: "no-store" });
  if (!response.ok) return;
  const blob = await response.blob();
  state.latestPhotoBlob = blob;
  state.photoFrames = frames || state.photoFrames + 1;
  els.photoFrames.textContent = String(state.photoFrames);
  els.photoMeta.textContent = `${state.photoFrames} 张，${formatBytes(bytes || blob.size)}`;
  setPill(els.photoState, "有照片", "ok");
  showImage(els.photoImage, els.photoEmpty, blob);
}

async function fetchJson(url, blob) {
  const form = new FormData();
  form.append("file", blob, "frame.jpg");
  const response = await fetch(url, { method: "POST", body: form });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

async function blobToImage(blob) {
  const bitmap = await createImageBitmap(blob);
  return bitmap;
}

function drawDetections(canvas, empty, bitmap, result) {
  const ctx = canvas.getContext("2d");
  canvas.width = bitmap.width;
  canvas.height = bitmap.height;
  ctx.drawImage(bitmap, 0, 0);
  const lineWidth = Math.max(4, Math.round(bitmap.width / 700));
  const fontSize = Math.max(24, Math.round(bitmap.width / 72));
  ctx.lineWidth = lineWidth;
  ctx.font = `700 ${fontSize}px Microsoft YaHei, Arial`;
  ctx.textBaseline = "top";

  for (const detection of result.detections || []) {
    const [x1, y1, x2, y2] = detection.box_xyxy;
    const label = `${detection.class_name} ${(detection.confidence * 100).toFixed(0)}%`;
    ctx.strokeStyle = "#ff3b30";
    ctx.fillStyle = "#ff3b30";
    ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
    const metrics = ctx.measureText(label);
    const labelHeight = fontSize + 10;
    const labelY = Math.max(0, y1 - labelHeight);
    ctx.fillRect(x1, labelY, metrics.width + 16, labelHeight);
    ctx.fillStyle = "#fff";
    ctx.fillText(label, x1 + 8, labelY + 5);
  }

  canvas.classList.add("visible");
  empty.classList.add("hidden");
}

async function detectVideoFrame() {
  if (!state.latestVideoBlob || state.videoDetecting) return;
  state.videoDetecting = true;
  setPill(els.videoDetectState, "检测中");
  try {
    const [result, bitmap] = await Promise.all([
      fetchJson("/yolo/api/v1/yolo/video-frame?conf=0.25&imgsz=640", state.latestVideoBlob),
      blobToImage(state.latestVideoBlob),
    ]);
    state.videoDetections = result.detections.length;
    els.videoDetections.textContent = String(state.videoDetections);
    els.videoDetectMeta.textContent = `${result.detections.length} 个目标，${result.elapsed_ms} ms`;
    setPill(els.videoDetectState, "已检测", "ok");
    drawDetections(els.videoCanvas, els.videoCanvasEmpty, bitmap, result);
  } catch (error) {
    els.videoDetectMeta.textContent = error.message;
    setPill(els.videoDetectState, "失败", "bad");
  } finally {
    state.videoDetecting = false;
  }
}

async function detectPhoto() {
  if (!state.latestPhotoBlob || state.photoDetecting) return;
  state.photoDetecting = true;
  setPill(els.photoDetectState, "检测中");
  try {
    const [result, bitmap] = await Promise.all([
      fetchJson("/yolo/api/v1/yolo/image?conf=0.25&imgsz=640", state.latestPhotoBlob),
      blobToImage(state.latestPhotoBlob),
    ]);
    state.photoDetections = result.detections.length;
    els.photoDetections.textContent = String(state.photoDetections);
    els.photoDetectMeta.textContent = `${result.detections.length} 个目标，${result.elapsed_ms} ms`;
    setPill(els.photoDetectState, "已检测", "ok");
    drawDetections(els.photoCanvas, els.photoCanvasEmpty, bitmap, result);
  } catch (error) {
    els.photoDetectMeta.textContent = error.message;
    setPill(els.photoDetectState, "失败", "bad");
  } finally {
    state.photoDetecting = false;
  }
}

async function bootstrapLatestPhoto() {
  try {
    const response = await fetch("/image/health", { cache: "no-store" });
    const stats = await response.json();
    if (stats.latestBytes) {
      await loadLatestPhoto(stats.frames, stats.latestBytes);
      await detectPhoto();
    }
  } catch {
    setPill(els.photoState, "待上传");
  }
}

els.refreshButton.addEventListener("click", () => window.location.reload());
els.detectPhotoButton.addEventListener("click", detectPhoto);

connectVideo();
connectPhotoEvents();
bootstrapLatestPhoto();
window.setInterval(detectVideoFrame, 2000);
