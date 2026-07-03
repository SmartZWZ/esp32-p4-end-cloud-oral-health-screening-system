#!/usr/bin/env node
'use strict';

const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const HOST = process.env.HOST || '0.0.0.0';
const WS_PORT = Number(process.env.WS_PORT || 8888);
const HTTP_PORT = Number(process.env.HTTP_PORT || 8889);
const MAX_FRAME_BYTES = Number(process.env.MAX_FRAME_BYTES || 8 * 1024 * 1024);

let latestVideoFrame = null;
let latestImageFrame = null;
let latestVideoAt = 0;
let latestImageAt = 0;
let videoFrames = 0;
let imageFrames = 0;
let videoBytes = 0;
let imageBytes = 0;

const wsClients = new Set();
const videoEvents = new Set();
const imageEvents = new Set();

function log(message) {
  console.log(`[${new Date().toISOString()}] ${message}`);
}

function sendJson(res, status, payload) {
  const body = JSON.stringify(payload, null, 2);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
    'Access-Control-Allow-Origin': '*',
  });
  res.end(body);
}

function sendHtml(req, res, html) {
  res.writeHead(200, {
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Length': Buffer.byteLength(html),
    'Cache-Control': 'no-store',
  });
  if (req.method === 'HEAD') {
    res.end();
    return;
  }
  res.end(html);
}

function sendImage(res, frame) {
  if (!frame) {
    res.writeHead(404, {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store',
      'Access-Control-Allow-Origin': '*',
    });
    res.end('no image yet\n');
    return;
  }

  res.writeHead(200, {
    'Content-Type': 'image/jpeg',
    'Content-Length': frame.length,
    'Cache-Control': 'no-store, no-cache, must-revalidate, proxy-revalidate',
    'Pragma': 'no-cache',
    'Expires': '0',
    'Access-Control-Allow-Origin': '*',
  });
  res.end(frame);
}

function stats() {
  return {
    ok: true,
    video: {
      port: WS_PORT,
      upload: `/esp32`,
      preview: `/`,
      frames: videoFrames,
      bytes: videoBytes,
      viewers: [...wsClients].filter((client) => client.role === 'viewer').length,
      publishers: [...wsClients].filter((client) => client.role === 'publisher').length,
      latestAt: latestVideoAt ? new Date(latestVideoAt).toISOString() : null,
      latestBytes: latestVideoFrame ? latestVideoFrame.length : 0,
    },
    image: {
      port: HTTP_PORT,
      upload: `/upload`,
      preview: `/`,
      frames: imageFrames,
      bytes: imageBytes,
      latestAt: latestImageAt ? new Date(latestImageAt).toISOString() : null,
      latestBytes: latestImageFrame ? latestImageFrame.length : 0,
    },
  };
}

function broadcastEvent(clients, event, data) {
  const payload = `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;
  for (const res of [...clients]) {
    if (res.destroyed) {
      clients.delete(res);
      continue;
    }
    res.write(payload);
  }
}

function acceptSse(req, res, clients) {
  res.writeHead(200, {
    'Content-Type': 'text/event-stream; charset=utf-8',
    'Cache-Control': 'no-cache, no-transform',
    'Connection': 'keep-alive',
    'Access-Control-Allow-Origin': '*',
  });
  res.write(`event: stats\ndata: ${JSON.stringify(stats())}\n\n`);
  clients.add(res);
  req.on('close', () => clients.delete(res));
}

function wsAcceptKey(key) {
  return crypto
    .createHash('sha1')
    .update(`${key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`)
    .digest('base64');
}

function encodeWsFrame(payload, opcode = 2) {
  const length = payload.length;
  let header;

  if (length < 126) {
    header = Buffer.alloc(2);
    header[1] = length;
  } else if (length <= 0xffff) {
    header = Buffer.alloc(4);
    header[1] = 126;
    header.writeUInt16BE(length, 2);
  } else {
    header = Buffer.alloc(10);
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(length), 2);
  }

  header[0] = 0x80 | opcode;
  return Buffer.concat([header, payload]);
}

function sendWs(client, payload, opcode = 2) {
  if (client.socket.destroyed) {
    return false;
  }
  try {
    return client.socket.write(encodeWsFrame(payload, opcode));
  } catch {
    client.socket.destroy();
    return false;
  }
}

function closeWs(client, code = 1000, reason = 'bye') {
  const reasonBuffer = Buffer.from(reason);
  const payload = Buffer.alloc(2 + reasonBuffer.length);
  payload.writeUInt16BE(code, 0);
  reasonBuffer.copy(payload, 2);
  sendWs(client, payload, 8);
  client.socket.end();
}

function broadcastVideoFrame(frame) {
  const packet = encodeWsFrame(frame, 2);
  for (const client of [...wsClients]) {
    if (client.role !== 'viewer') {
      continue;
    }
    if (client.socket.destroyed) {
      wsClients.delete(client);
      continue;
    }
    try {
      client.socket.write(packet);
    } catch {
      client.socket.destroy();
      wsClients.delete(client);
    }
  }
}

function frameFromText(text) {
  const trimmed = text.trim();
  const match = trimmed.match(/^data:image\/(?:jpeg|jpg);base64,(.+)$/i);
  if (match) {
    return Buffer.from(match[1], 'base64');
  }
  if (/^[A-Za-z0-9+/=\s]+$/.test(trimmed) && trimmed.length > 100) {
    return Buffer.from(trimmed.replace(/\s/g, ''), 'base64');
  }
  return null;
}

function handleVideoMessage(client, opcode, payload) {
  if (opcode === 9) {
    sendWs(client, payload, 10);
    return;
  }
  if (opcode === 8) {
    closeWs(client);
    return;
  }
  if (opcode !== 1 && opcode !== 2) {
    return;
  }

  let frame = payload;
  if (opcode === 1) {
    frame = frameFromText(payload.toString('utf8'));
    if (!frame) {
      return;
    }
  }
  if (!frame.length || frame.length > MAX_FRAME_BYTES) {
    log(`drop video frame: ${frame.length} bytes`);
    return;
  }

  client.role = client.role === 'viewer' ? 'viewer' : 'publisher';
  latestVideoFrame = Buffer.from(frame);
  latestVideoAt = Date.now();
  videoFrames += 1;
  videoBytes += frame.length;
  broadcastVideoFrame(latestVideoFrame);
  broadcastEvent(videoEvents, 'stats', stats());
}

function parseWsBuffer(client) {
  while (client.buffer.length >= 2) {
    const first = client.buffer[0];
    const second = client.buffer[1];
    const fin = Boolean(first & 0x80);
    const opcode = first & 0x0f;
    const masked = Boolean(second & 0x80);
    let length = second & 0x7f;
    let offset = 2;

    if (length === 126) {
      if (client.buffer.length < offset + 2) return;
      length = client.buffer.readUInt16BE(offset);
      offset += 2;
    } else if (length === 127) {
      if (client.buffer.length < offset + 8) return;
      const bigLength = client.buffer.readBigUInt64BE(offset);
      if (bigLength > BigInt(MAX_FRAME_BYTES)) {
        closeWs(client, 1009, 'frame too large');
        return;
      }
      length = Number(bigLength);
      offset += 8;
    }

    let mask = null;
    if (masked) {
      if (client.buffer.length < offset + 4) return;
      mask = client.buffer.subarray(offset, offset + 4);
      offset += 4;
    }
    if (client.buffer.length < offset + length) {
      return;
    }
    if (length > MAX_FRAME_BYTES) {
      closeWs(client, 1009, 'frame too large');
      return;
    }

    let payload = client.buffer.subarray(offset, offset + length);
    client.buffer = client.buffer.subarray(offset + length);

    if (mask) {
      payload = Buffer.from(payload);
      for (let i = 0; i < payload.length; i += 1) {
        payload[i] ^= mask[i & 3];
      }
    }

    if (opcode === 0) {
      if (!client.fragmentOpcode) {
        closeWs(client, 1002, 'unexpected continuation');
        return;
      }
      client.fragments.push(payload);
      if (fin) {
        const message = Buffer.concat(client.fragments);
        const messageOpcode = client.fragmentOpcode;
        client.fragmentOpcode = 0;
        client.fragments = [];
        handleVideoMessage(client, messageOpcode, message);
      }
      continue;
    }

    if (!fin && (opcode === 1 || opcode === 2)) {
      client.fragmentOpcode = opcode;
      client.fragments = [payload];
      continue;
    }

    handleVideoMessage(client, opcode, payload);
  }
}

function handleUpgrade(req, socket) {
  const key = req.headers['sec-websocket-key'];
  if (!key) {
    socket.end('HTTP/1.1 400 Bad Request\r\n\r\n');
    return;
  }

  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const role = url.pathname === '/viewer' ? 'viewer' : 'publisher';
  const accept = wsAcceptKey(key);

  socket.write(
    'HTTP/1.1 101 Switching Protocols\r\n' +
      'Upgrade: websocket\r\n' +
      'Connection: Upgrade\r\n' +
      `Sec-WebSocket-Accept: ${accept}\r\n` +
      '\r\n',
  );

  const client = {
    socket,
    role,
    buffer: Buffer.alloc(0),
    fragmentOpcode: 0,
    fragments: [],
  };
  wsClients.add(client);
  log(`video websocket connected: ${role} ${req.socket.remoteAddress || ''}`);

  if (role === 'viewer' && latestVideoFrame) {
    sendWs(client, latestVideoFrame, 2);
  }

  socket.on('data', (chunk) => {
    client.buffer = Buffer.concat([client.buffer, chunk]);
    parseWsBuffer(client);
  });
  socket.on('end', () => {
    wsClients.delete(client);
    socket.destroy();
    broadcastEvent(videoEvents, 'stats', stats());
  });
  socket.on('close', () => {
    wsClients.delete(client);
    broadcastEvent(videoEvents, 'stats', stats());
  });
  socket.on('error', () => {
    wsClients.delete(client);
  });

  broadcastEvent(videoEvents, 'stats', stats());
}

function commonCss() {
  return `
    :root {
      color-scheme: dark;
      font-family: Arial, "Microsoft YaHei", sans-serif;
      background: #0d1117;
      color: #e6edf3;
    }
    body {
      margin: 0;
      min-height: 100vh;
      background: #0d1117;
    }
    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 18px 24px;
      border-bottom: 1px solid #30363d;
      background: #161b22;
    }
    h1 {
      margin: 0;
      font-size: 22px;
      font-weight: 700;
    }
    main {
      padding: 16px;
    }
    .panel {
      border: 1px solid #30363d;
      border-radius: 8px;
      background: #161b22;
      overflow: hidden;
    }
    .bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 14px;
      border-bottom: 1px solid #30363d;
    }
    .title {
      font-weight: 700;
    }
    .meta {
      color: #9da7b3;
      font-size: 13px;
      white-space: nowrap;
    }
    .stage {
      display: grid;
      place-items: center;
      height: calc(100vh - 170px);
      min-height: 360px;
      background: #05070a;
    }
    img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }
    .placeholder {
      color: #7d8590;
      font-size: 15px;
      padding: 18px;
      text-align: center;
    }
    footer {
      padding: 0 16px 18px;
      color: #9da7b3;
      font-size: 13px;
      line-height: 1.7;
    }
    code {
      color: #79c0ff;
    }
    @media (max-width: 760px) {
      header {
        align-items: flex-start;
        flex-direction: column;
      }
      .stage {
        height: calc(100vh - 210px);
        min-height: 280px;
      }
    }
  `;
}

const videoHtml = `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ESP32 视频流预览</title>
  <style>${commonCss()}</style>
</head>
<body>
  <header>
    <h1>ESP32 视频流</h1>
    <div class="meta" id="status">连接中...</div>
  </header>
  <main>
    <section class="panel">
      <div class="bar">
        <div class="title">WebSocket 视频端口 :${WS_PORT}</div>
        <div class="meta" id="videoMeta">等待帧</div>
      </div>
      <div class="stage">
        <img id="videoImage" alt="">
        <div class="placeholder" id="placeholder">等待 ESP32 发送 JPEG 二进制帧到 ws://服务器IP:${WS_PORT}/esp32</div>
      </div>
    </section>
  </main>
  <footer>
    ESP32 上传：<code>ws://服务器IP:${WS_PORT}/esp32</code>；浏览器预览：<code>http://服务器IP:${WS_PORT}/</code>。
  </footer>
  <script>
    const image = document.getElementById('videoImage');
    const meta = document.getElementById('videoMeta');
    const statusEl = document.getElementById('status');
    const placeholder = document.getElementById('placeholder');
    let frames = 0;
    let lastObjectUrl = null;

    function connectWs() {
      const ws = new WebSocket('ws://' + location.hostname + ':${WS_PORT}/viewer');
      ws.binaryType = 'blob';
      ws.onopen = () => {
        statusEl.textContent = '视频预览已连接';
      };
      ws.onmessage = (event) => {
        frames += 1;
        if (lastObjectUrl) URL.revokeObjectURL(lastObjectUrl);
        lastObjectUrl = URL.createObjectURL(event.data);
        image.src = lastObjectUrl;
        placeholder.style.display = 'none';
        meta.textContent = frames + ' 帧';
      };
      ws.onclose = () => {
        statusEl.textContent = '视频通道断开，正在重连...';
        setTimeout(connectWs, 1000);
      };
      ws.onerror = () => {
        statusEl.textContent = '视频通道异常';
      };
    }

    function connectStats() {
      const es = new EventSource('/events');
      es.addEventListener('stats', (event) => {
        const data = JSON.parse(event.data);
        if (data.video.latestBytes) {
          meta.textContent = data.video.frames + ' 帧，' + Math.round(data.video.latestBytes / 1024) + ' KB';
        }
      });
    }

    connectWs();
    connectStats();
  </script>
</body>
</html>
`;

const imageHtml = `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ESP32 拍照图片预览</title>
  <style>${commonCss()}</style>
</head>
<body>
  <header>
    <h1>ESP32 拍照图片</h1>
    <div class="meta" id="status">等待上传...</div>
  </header>
  <main>
    <section class="panel">
      <div class="bar">
        <div class="title">HTTP 图片端口 :${HTTP_PORT}</div>
        <div class="meta" id="imageMeta">等待图片</div>
      </div>
      <div class="stage">
        <img id="photo" alt="">
        <div class="placeholder" id="placeholder">等待 ESP32 POST 图片到 http://服务器IP:${HTTP_PORT}/upload</div>
      </div>
    </section>
  </main>
  <footer>
    ESP32 上传：<code>POST http://服务器IP:${HTTP_PORT}/upload</code>；浏览器预览：<code>http://服务器IP:${HTTP_PORT}/</code>。
  </footer>
  <script>
    const photo = document.getElementById('photo');
    const meta = document.getElementById('imageMeta');
    const statusEl = document.getElementById('status');
    const placeholder = document.getElementById('placeholder');

    function showImage(data) {
      photo.src = '/latest.jpg?frame=' + data.frames + '&t=' + Date.now();
      placeholder.style.display = 'none';
      meta.textContent = data.frames + ' 张，' + Math.round(data.bytes / 1024) + ' KB';
      statusEl.textContent = '最后更新：' + new Date(data.at).toLocaleString();
    }

    function connectEvents() {
      const es = new EventSource('/events');
      es.addEventListener('image-frame', (event) => {
        showImage(JSON.parse(event.data));
      });
      es.addEventListener('stats', (event) => {
        const data = JSON.parse(event.data);
        if (data.image.latestBytes) {
          showImage({ frames: data.image.frames, bytes: data.image.latestBytes, at: data.image.latestAt });
        }
      });
      es.onerror = () => {
        statusEl.textContent = '状态通道重连中...';
      };
    }

    connectEvents();
  </script>
</body>
</html>
`;

function extractMultipartImage(body, contentType) {
  const boundaryMatch = contentType.match(/boundary=(?:"([^"]+)"|([^;]+))/i);
  if (!boundaryMatch) {
    return body;
  }

  const boundary = Buffer.from(`--${boundaryMatch[1] || boundaryMatch[2]}`);
  let offset = 0;
  while (offset < body.length) {
    const start = body.indexOf(boundary, offset);
    if (start === -1) break;
    const headerStart = start + boundary.length;
    const headerEnd = body.indexOf(Buffer.from('\r\n\r\n'), headerStart);
    if (headerEnd === -1) break;
    const headers = body.subarray(headerStart, headerEnd).toString('latin1');
    const dataStart = headerEnd + 4;
    const nextBoundary = body.indexOf(boundary, dataStart);
    if (nextBoundary === -1) break;
    let dataEnd = nextBoundary;
    if (body[dataEnd - 2] === 13 && body[dataEnd - 1] === 10) {
      dataEnd -= 2;
    }
    if (/content-type:\s*image\/(?:jpeg|jpg|octet-stream)/i.test(headers)) {
      return body.subarray(dataStart, dataEnd);
    }
    offset = nextBoundary + boundary.length;
  }

  return body;
}

function handleImageUpload(req, res) {
  const chunks = [];
  let size = 0;
  req.on('data', (chunk) => {
    size += chunk.length;
    if (size > MAX_FRAME_BYTES) {
      res.writeHead(413, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('image too large\n');
      req.destroy();
      return;
    }
    chunks.push(chunk);
  });
  req.on('end', () => {
    const rawBody = Buffer.concat(chunks);
    const frame = extractMultipartImage(rawBody, req.headers['content-type'] || '');
    if (!frame.length) {
      sendJson(res, 400, { ok: false, error: 'empty image' });
      return;
    }
    latestImageFrame = Buffer.from(frame);
    latestImageAt = Date.now();
    imageFrames += 1;
    imageBytes += frame.length;
    const payload = {
      frames: imageFrames,
      bytes: frame.length,
      at: new Date(latestImageAt).toISOString(),
    };
    broadcastEvent(imageEvents, 'image-frame', payload);
    broadcastEvent(imageEvents, 'stats', stats());
    sendJson(res, 200, { ok: true, ...payload });
  });
  req.on('error', () => {
    if (!res.headersSent) {
      sendJson(res, 500, { ok: false, error: 'upload failed' });
    }
  });
}

function handleOptions(res) {
  res.writeHead(204, {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Access-Control-Max-Age': '86400',
  });
  res.end();
}

const videoServer = http.createServer((req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);

  if (req.method === 'OPTIONS') {
    handleOptions(res);
    return;
  }
  if ((req.method === 'GET' || req.method === 'HEAD') && url.pathname === '/') {
    sendHtml(req, res, videoHtml);
    return;
  }
  if (req.method === 'GET' && url.pathname === '/events') {
    acceptSse(req, res, videoEvents);
    return;
  }
  if (req.method === 'GET' && url.pathname === '/latest.jpg') {
    sendImage(res, latestVideoFrame);
    return;
  }
  if (req.method === 'GET' && url.pathname === '/health') {
    sendJson(res, 200, stats().video);
    return;
  }

  sendJson(res, 404, {
    ok: false,
    error: 'not found',
    upload: `ws://HOST:${WS_PORT}/esp32`,
    preview: `http://HOST:${WS_PORT}/`,
  });
});

const imageServer = http.createServer((req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);

  if (req.method === 'OPTIONS') {
    handleOptions(res);
    return;
  }
  if ((req.method === 'GET' || req.method === 'HEAD') && url.pathname === '/') {
    sendHtml(req, res, imageHtml);
    return;
  }
  if (req.method === 'GET' && url.pathname === '/events') {
    acceptSse(req, res, imageEvents);
    return;
  }
  if (req.method === 'GET' && url.pathname === '/latest.jpg') {
    sendImage(res, latestImageFrame);
    return;
  }
  if (req.method === 'GET' && url.pathname === '/health') {
    sendJson(res, 200, stats().image);
    return;
  }
  if (req.method === 'POST' && (url.pathname === '/upload' || url.pathname === '/image')) {
    handleImageUpload(req, res);
    return;
  }

  sendJson(res, 404, {
    ok: false,
    error: 'not found',
    upload: `POST http://HOST:${HTTP_PORT}/upload`,
    preview: `http://HOST:${HTTP_PORT}/`,
  });
});

videoServer.on('upgrade', handleUpgrade);

setInterval(() => {
  broadcastEvent(videoEvents, 'stats', stats());
  broadcastEvent(imageEvents, 'stats', stats());
}, 3000).unref();

videoServer.listen(WS_PORT, HOST, () => {
  log(`video page listening on http://${HOST}:${WS_PORT}/`);
  log(`video upload listening on ws://${HOST}:${WS_PORT}/esp32`);
});

imageServer.listen(HTTP_PORT, HOST, () => {
  log(`image page and upload listening on http://${HOST}:${HTTP_PORT}/`);
});
