# ESP32 Preview Server

Two lightweight Node.js services in one process:

- `8888`: WebSocket video receiver and preview page
- `8889`: HTTP image receiver and preview page

No npm dependencies are required.

## Run Manually

```bash
node server.js
```

Open:

- Video preview: `http://SERVER_IP:8888/`
- Image preview: `http://SERVER_IP:8889/`

ESP32 upload endpoints:

- Video frames: `ws://SERVER_IP:8888/esp32`
- Photos: `POST http://SERVER_IP:8889/upload`

The video WebSocket expects one JPEG frame per binary message.
The image upload endpoint accepts raw JPEG body or `multipart/form-data`.

## Install As systemd Service

```bash
sudo mkdir -p /opt/esp32-preview
sudo cp server.js /opt/esp32-preview/server.js
sudo cp esp32-preview.service /etc/systemd/system/esp32-preview.service
sudo chmod 755 /opt/esp32-preview/server.js
sudo systemctl daemon-reload
sudo systemctl enable --now esp32-preview.service
```

Optional firewall rules:

```bash
sudo ufw allow 8888/tcp
sudo ufw allow 8889/tcp
```

Check status:

```bash
systemctl status esp32-preview.service
curl http://127.0.0.1:8888/health
curl http://127.0.0.1:8889/health
```
