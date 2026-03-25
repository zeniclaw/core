import {
  makeWASocket,
  useMultiFileAuthState,
  makeCacheableSignalKeyStore,
  fetchLatestBaileysVersion,
  DisconnectReason,
} from "@whiskeysockets/baileys";
import express from "express";
import pino from "pino";
import QRCode from "qrcode";
import fs from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const LANDING_HTML = fs.readFileSync(path.join(__dirname, "landing.html"), "utf-8");

const WEBHOOK_URL = process.env.WEBHOOK_URL || "http://app:80/webhook/whatsapp/1";
const API_KEY = process.env.API_KEY || "zeniclaw-waha-2026";
const PORT = parseInt(process.env.PORT || "3000", 10);
const AUTH_DIR = "/data/auth";

const logger = pino({ level: process.env.LOG_LEVEL || "warn" });

let sock = null;
let sessionStatus = "STOPPED";
let qrCodeData = null;
let meInfo = null;
let reconnectTimer = null;

// Ensure auth dir
fs.mkdirSync(AUTH_DIR, { recursive: true });

async function connectWhatsApp() {
  if (sessionStatus === "STARTING") return;
  sessionStatus = "STARTING";
  qrCodeData = null;

  try {
    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version } = await fetchLatestBaileysVersion();

    const baileysLogger = pino({ level: "silent" });

    sock = makeWASocket({
      auth: {
        creds: state.creds,
        keys: makeCacheableSignalKeyStore(state.keys, baileysLogger),
      },
      version,
      logger: baileysLogger,
      printQRInTerminal: false,
      browser: ["ZeniClaw", "server", "1.0.0"],
      syncFullHistory: false,
      markOnlineOnConnect: false,
      // Reduce presence noise
      shouldIgnoreJid: (jid) => jid?.endsWith("@broadcast") || jid?.endsWith("@status"),
    });

    sock.ev.on("creds.update", saveCreds);

    sock.ev.on("connection.update", (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        qrCodeData = qr;
        sessionStatus = "SCAN_QR_CODE";
        logger.info("QR code received, waiting for scan...");
      }

      if (connection === "open") {
        sessionStatus = "WORKING";
        qrCodeData = null;
        meInfo = sock.user;
        logger.info({ me: meInfo?.id }, "WhatsApp connected");
      }

      if (connection === "close") {
        const statusCode =
          lastDisconnect?.error?.output?.statusCode ??
          lastDisconnect?.error?.status;

        logger.warn({ statusCode }, "Connection closed");

        if (statusCode === DisconnectReason.loggedOut) {
          sessionStatus = "STOPPED";
          meInfo = null;
          logger.error("Session logged out - need to re-scan QR");
          // Clear auth to force fresh QR
          try {
            fs.rmSync(AUTH_DIR, { recursive: true, force: true });
            fs.mkdirSync(AUTH_DIR, { recursive: true });
          } catch {}
        } else {
          // Reconnect for any other disconnect reason (including 515)
          sessionStatus = "STOPPED";
          const delay = statusCode === 515 ? 2000 : 5000;
          logger.info({ delay, statusCode }, "Reconnecting...");
          clearTimeout(reconnectTimer);
          reconnectTimer = setTimeout(() => connectWhatsApp(), delay);
        }
      }
    });

    // Handle incoming messages -> forward to webhook
    sock.ev.on("messages.upsert", async (upsert) => {
      if (upsert.type !== "notify") return;

      for (const msg of upsert.messages || []) {
        if (msg.key?.fromMe) continue;
        if (!msg.message) continue;

        const remoteJid = msg.key?.remoteJid;
        if (!remoteJid) continue;
        if (remoteJid.endsWith("@broadcast") || remoteJid.endsWith("@status")) continue;

        // Extract text from various message types
        const text =
          msg.message?.conversation ||
          msg.message?.extendedTextMessage?.text ||
          msg.message?.imageMessage?.caption ||
          msg.message?.videoMessage?.caption ||
          msg.message?.documentMessage?.caption ||
          "";

        // Download media if present
        let mediaUrl = null;
        const hasMedia = !!(
          msg.message?.imageMessage ||
          msg.message?.audioMessage ||
          msg.message?.videoMessage ||
          msg.message?.documentMessage ||
          msg.message?.stickerMessage
        );
        if (hasMedia && sock) {
          try {
            const { downloadMediaMessage, downloadContentFromMessage, getContentType } = await import("@whiskeysockets/baileys");
            let buffer = null;

            // Method 1: downloadMediaMessage with proper options
            try {
              buffer = await downloadMediaMessage(msg, "buffer", {
                logger: pino({ level: "silent" }),
                reuploadRequest: sock.updateMediaMessage,
              });
            } catch (err1) {
              logger.warn({ error: err1.message, msgId: msg.key?.id }, "downloadMediaMessage failed, trying fallback...");

              // Method 2: downloadContentFromMessage (works with more baileys versions)
              try {
                const mediaType = msg.message?.imageMessage ? "imageMessage"
                  : msg.message?.audioMessage ? "audioMessage"
                  : msg.message?.videoMessage ? "videoMessage"
                  : msg.message?.documentMessage ? "documentMessage"
                  : msg.message?.stickerMessage ? "stickerMessage"
                  : null;

                if (mediaType && msg.message?.[mediaType]) {
                  const stream = await downloadContentFromMessage(msg.message[mediaType], mediaType.replace("Message", ""));
                  const chunks = [];
                  for await (const chunk of stream) {
                    chunks.push(chunk);
                  }
                  buffer = Buffer.concat(chunks);
                }
              } catch (err2) {
                logger.warn({ error: err2.message, msgId: msg.key?.id }, "downloadContentFromMessage also failed");
              }
            }

            if (buffer && buffer.length > 0) {
              const mediaId = msg.key?.id || Date.now().toString();
              const mediaDir = "/data/media";
              fs.mkdirSync(mediaDir, { recursive: true });
              const ext = msg.message?.imageMessage ? "jpg"
                : msg.message?.audioMessage ? "ogg"
                : msg.message?.videoMessage ? "mp4"
                : msg.message?.documentMessage ? "bin"
                : msg.message?.stickerMessage ? "webp"
                : "bin";
              const mediaFile = `${mediaDir}/${mediaId}.${ext}`;
              fs.writeFileSync(mediaFile, buffer);
              mediaUrl = `http://waha:${PORT}/media/${mediaId}.${ext}`;
              logger.info({ mediaId, size: buffer.length, ext }, "Media downloaded");
            } else {
              logger.warn({ msgId: msg.key?.id, hasMedia }, "Media download returned empty buffer");
            }
          } catch (err) {
            logger.error({ error: err.message, msgId: msg.key?.id }, "Media download failed completely");
          }
        }

        // Build WAHA-compatible webhook payload
        const payload = {
          event: "message",
          session: "default",
          engine: "BAILEYS",
          payload: {
            id: msg.key?.id,
            timestamp: msg.messageTimestamp,
            from: remoteJid,
            fromMe: false,
            to: meInfo?.id,
            body: text,
            hasMedia,
            mediaUrl,
            // Audio detection
            mimetype: msg.message?.audioMessage
              ? "audio/ogg"
              : msg.message?.imageMessage
                ? msg.message.imageMessage.mimetype
                : msg.message?.documentMessage
                  ? msg.message.documentMessage.mimetype
                  : null,
            ack: 0,
            _data: {
              pushName: msg.pushName || "",
              notifyName: msg.pushName || "",
            },
          },
        };

        // Send to webhook
        try {
          const res = await fetch(WEBHOOK_URL, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
            signal: AbortSignal.timeout(10000),
          });
          logger.info({ from: remoteJid, status: res.status }, "Webhook delivered");
        } catch (err) {
          logger.error({ error: err.message, from: remoteJid }, "Webhook delivery failed");
        }
      }
    });
  } catch (err) {
    logger.error({ error: err.message }, "Failed to create socket");
    sessionStatus = "STOPPED";
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => connectWhatsApp(), 5000);
  }
}

// --- Express API (WAHA-compatible) ---
const app = express();
app.use(express.json());

// Auth middleware (skip for homepage and health)
function checkAuth(req, res, next) {
  if (req.path === "/" || req.path === "/api/health") return next();
  const key = req.headers["x-api-key"];
  if (key !== API_KEY) {
    return res.status(401).json({ error: "Unauthorized" });
  }
  next();
}

app.use(checkAuth);

// Server status
app.get("/api/server/status", (req, res) => {
  res.json({ status: "running" });
});

// Get session
app.get("/api/sessions/default", (req, res) => {
  res.json({
    name: "default",
    status: sessionStatus,
    config: { webhooks: [{ url: WEBHOOK_URL, events: ["message"] }] },
    me: meInfo ? { id: meInfo.id, pushName: meInfo.name } : null,
    engine: { engine: "BAILEYS" },
  });
});

// List sessions
app.get("/api/sessions", (req, res) => {
  res.json([
    {
      name: "default",
      status: sessionStatus,
      me: meInfo ? { id: meInfo.id } : null,
    },
  ]);
});

// Start session (POST /api/sessions/start or /api/sessions/default/start)
app.post(["/api/sessions/start", "/api/sessions/default/start"], async (req, res) => {
  if (sessionStatus === "WORKING" || sessionStatus === "STARTING") {
    return res.json({ name: "default", status: sessionStatus });
  }
  connectWhatsApp();
  res.json({ name: "default", status: "STARTING" });
});

// QR code image endpoint (WAHA-compatible: /api/default/auth/qr)
app.get("/api/default/auth/qr", async (req, res) => {
  if (sessionStatus === "WORKING") {
    return res.status(200).json({ connected: true });
  }
  if (sessionStatus === "SCAN_QR_CODE" && qrCodeData) {
    try {
      const buffer = await QRCode.toBuffer(qrCodeData, { type: "png", width: 300 });
      res.set("Content-Type", "image/png");
      return res.send(buffer);
    } catch (err) {
      return res.status(500).json({ error: "QR generation failed" });
    }
  }
  return res.status(404).json({ error: "No QR available", status: sessionStatus });
});

// Stop session
app.post("/api/sessions/default/stop", (req, res) => {
  if (sock) {
    try { sock.ws?.close(); } catch {}
  }
  sessionStatus = "STOPPED";
  res.json({ name: "default", status: "STOPPED" });
});

// Delete session
app.delete("/api/sessions/default", (req, res) => {
  if (sock) {
    try { sock.ws?.close(); } catch {}
  }
  sessionStatus = "STOPPED";
  meInfo = null;
  try {
    fs.rmSync(AUTH_DIR, { recursive: true, force: true });
    fs.mkdirSync(AUTH_DIR, { recursive: true });
  } catch {}
  res.json({});
});

// Send text message (WAHA-compatible)
app.post("/api/sendText", async (req, res) => {
  const { chatId, text } = req.body;

  if (!chatId || !text) {
    return res.status(400).json({ error: "chatId and text required" });
  }

  if (sessionStatus !== "WORKING" || !sock) {
    return res.status(503).json({ error: "Session not connected" });
  }

  try {
    const result = await sock.sendMessage(chatId, { text });
    res.json(result);
  } catch (err) {
    logger.error({ error: err.message, chatId }, "sendText failed");
    res.status(500).json({ error: err.message });
  }
});

// Send file/document (base64 encoded)
app.post("/api/sendFile", async (req, res) => {
  const { chatId, file, caption } = req.body;

  if (!chatId || !file?.data || !file?.filename) {
    return res.status(400).json({ error: "chatId and file (data, filename) required" });
  }

  if (sessionStatus !== "WORKING" || !sock) {
    return res.status(503).json({ error: "Session not connected" });
  }

  try {
    const buffer = Buffer.from(file.data, "base64");
    const mimetype = file.mimetype || "application/octet-stream";

    const result = await sock.sendMessage(chatId, {
      document: buffer,
      mimetype,
      fileName: file.filename,
      caption: caption || undefined,
    });
    res.json(result);
  } catch (err) {
    logger.error({ error: err.message, chatId }, "sendFile failed");
    res.status(500).json({ error: err.message });
  }
});

// Landing page with QR code
app.get("/", async (req, res) => {
  let statusText = "Gateway Running";
  let qrContent = "";
  let refreshScript = "";

  if (sessionStatus === "WORKING") {
    statusText = `Connected as ${meInfo?.name || meInfo?.id || "unknown"}`;
    qrContent = `
      <h2>WhatsApp Connected</h2>
      <p>Your WhatsApp session is active and ready.</p>
      <div style="display:inline-flex;align-items:center;gap:10px;padding:12px 24px;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);border-radius:10px;margin-top:1rem;">
        <span style="width:12px;height:12px;background:#10b981;border-radius:50%;display:inline-block;"></span>
        <span style="color:#10b981;font-weight:600;">${meInfo?.name || "Connected"}</span>
        <span style="color:#64748b;font-size:0.85rem;">${meInfo?.id || ""}</span>
      </div>`;
    refreshScript = "setTimeout(() => location.reload(), 30000);";
  } else if (sessionStatus === "SCAN_QR_CODE" && qrCodeData) {
    statusText = "Waiting for QR Scan";
    try {
      const qrImage = await QRCode.toDataURL(qrCodeData, { width: 280, margin: 0 });
      qrContent = `
        <h2>Scan QR Code to Connect</h2>
        <p>Open WhatsApp &gt; Linked Devices &gt; Link a Device</p>
        <div class="qr-container"><img src="${qrImage}" alt="QR Code" /></div>
        <div class="qr-status waiting">Waiting for scan...</div>`;
    } catch {
      qrContent = `<h2>QR Code Error</h2><p>Failed to generate QR code. Retrying...</p>`;
    }
    refreshScript = "setTimeout(() => location.reload(), 5000);";
  } else {
    statusText = `Status: ${sessionStatus}`;
    qrContent = `
      <h2>Initializing WhatsApp Session</h2>
      <p>The gateway is starting up. QR code will appear shortly.</p>
      <div class="qr-status offline">${sessionStatus === "STARTING" ? "Connecting..." : "Waiting for session to start..."}</div>`;
    refreshScript = "setTimeout(() => location.reload(), 3000);";
  }

  const html = LANDING_HTML
    .replace("{{STATUS_TEXT}}", statusText)
    .replace("{{QR_CONTENT}}", qrContent)
    .replace("{{REFRESH_SCRIPT}}", refreshScript);

  res.send(html);
});

// Health check
// Serve downloaded media files
app.get("/media/:filename", (req, res) => {
  const filePath = `/data/media/${req.params.filename}`;
  if (!fs.existsSync(filePath)) return res.status(404).json({ error: "Not found" });
  const ext = req.params.filename.split(".").pop();
  const mimeMap = { jpg: "image/jpeg", jpeg: "image/jpeg", png: "image/png", webp: "image/webp", ogg: "audio/ogg", mp4: "video/mp4", bin: "application/octet-stream" };
  res.setHeader("Content-Type", mimeMap[ext] || "application/octet-stream");
  res.send(fs.readFileSync(filePath));
});

app.get("/api/health", (req, res) => {
  res.json({ status: "ok", session: sessionStatus });
});

app.listen(PORT, () => {
  logger.info({ port: PORT }, "Gateway started");
  // Auto-start session
  connectWhatsApp();
});
