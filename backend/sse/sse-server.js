// backend/sse/sse-server.js
// SSE-Server fuer Echtzeit-Benachrichtigungen via pg_notify
//
// Ermittelt die Company-DB ueber das Session-Cookie (wie DbhCompany in PHP).
// Liest DB-Credentials aus backend/config/settings.ini
//
// Verwendung:  node backend/sse/sse-server.js
// Oder:        SSE_PORT=3001 node backend/sse/sse-server.js

import http from 'node:http';
import { readFileSync, watchFile, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import pg from 'pg';

const __dirname = dirname(fileURLToPath(import.meta.url));

// --- Konfiguration aus settings.ini laden ---

function parseIni(filePath) {
    const content = readFileSync(filePath, 'utf-8');
    const result = {};
    let section = null;
    for (const line of content.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith(';') || trimmed.startsWith('#')) continue;
        const secMatch = trimmed.match(/^\[(.+)]$/);
        if (secMatch) {
            section = secMatch[1];
            result[section] = {};
            continue;
        }
        const kvMatch = trimmed.match(/^(\w+)\s*=\s*"?([^"]*)"?\s*$/);
        if (kvMatch && section) {
            result[section][kvMatch[1]] = kvMatch[2];
        }
    }
    return result;
}

// XOR-Entschluesselung (identisch zu PHP OserpConfig::dec)
function decryptPassword(encoded) {
    const key = 'k';
    const decoded = Buffer.from(encoded, 'base64');
    const result = Buffer.alloc(decoded.length);
    for (let i = 0; i < decoded.length; i++) {
        result[i] = decoded[i] ^ key.charCodeAt(i % key.length);
    }
    return result.toString('utf-8');
}

const iniPath = resolve(__dirname, '../config/settings.ini');
let config;
try {
    config = parseIni(iniPath);
} catch (err) {
    console.error('settings.ini nicht gefunden:', iniPath);
    process.exit(1);
}

const dbHost = config.database?.host || 'localhost';
const dbPort = parseInt(config.database?.port || '5432', 10);
const dbUser = config.database?.auth_user || 'postgres';
const dbPass = config.database?.auth_pass ? decryptPassword(config.database.auth_pass) : '';
const authDbName = config.database?.auth_db || 'oserp_auth';
const cookieName = config.session?.cookie_name || 'opensource_erp';

const SSE_PORT = parseInt(process.env.SSE_PORT || '3001', 10);

// --- Auth-DB Pool fuer Session-Lookups ---

const authPool = new pg.Pool({
    host: dbHost, port: dbPort, database: authDbName,
    user: dbUser, password: dbPass, max: 3,
});

// Idle-Client-Fehler im Pool MUESSEN abgefangen werden: node-pg wirft sonst
// bei einem Verbindungsabbruch (DB-Neustart, Idle-Timeout, Netz-Blip) eine
// uncaughtException und beendet den Prozess. Ohne Auto-Restart liefert Apache
// dann dauerhaft 503 unter /sse/.
authPool.on('error', (err) => {
    console.error('Auth-Pool Fehler (idle client):', err.message);
});

// Company-DB ueber Session-Cookie ermitteln (wie PHP DbhCompany::connectPDO)
async function lookupCompanyDbBySession(sessionId) {
    const res = await authPool.query(`
        SELECT c.dbhost, c.dbport, c.dbname, c.dbuser, c.dbpasswd
        FROM auth.session_oserp s
        JOIN auth.user u ON s.user_id = u.id
        JOIN auth.clients c ON s.client_id = c.id
        WHERE s.session_id = $1
    `, [sessionId]);
    if (!res.rows.length) return null;
    return res.rows[0];
}

// --- pg_listen pro Company-DB ---
// Mehrere User koennen in verschiedenen Company-DBs eingeloggt sein.
// Pro DB wird genau ein LISTEN-Client gehalten.

const listeners = new Map();    // dbname -> { pgClient, sseClients: Set<res> }

async function getOrCreateListener(dbInfo) {
    const key = dbInfo.dbname;
    if (listeners.has(key)) return listeners.get(key);

    const entry = { pgClient: null, sseClients: new Set(), reconnectTimer: null };
    listeners.set(key, entry);

    await connectListener(dbInfo, entry);
    return entry;
}

async function connectListener(dbInfo, entry) {
    try {
        const client = new pg.Client({
            host: dbInfo.dbhost, port: parseInt(dbInfo.dbport, 10),
            database: dbInfo.dbname,
            user: dbInfo.dbuser, password: dbInfo.dbpasswd,
        });

        client.on('error', (err) => {
            console.error(`PG Error (${dbInfo.dbname}):`, err.message);
            scheduleReconnect(dbInfo, entry);
        });

        client.on('end', () => {
            scheduleReconnect(dbInfo, entry);
        });

        await client.connect();
        await client.query('LISTEN crmti_change');
        await client.query('LISTEN whatsapp_message');
        await client.query('LISTEN calendar_change');
        await client.query('LISTEN faktura_change');
        await client.query('LISTEN weroni_question');
        await client.query('LISTEN camera_event');
        await client.query('LISTEN wall_display_command');
        await client.query('LISTEN voicenote_change');
        entry.pgClient = client;
        console.log(`LISTEN ... + voicenote_change auf ${dbInfo.dbname}@${dbInfo.dbhost}:${dbInfo.dbport}`);

        // Kanaele, die als Named Event (addEventListener im Frontend) ankommen sollen.
        const namedEventChannels = new Set(['wall_display_command', 'voicenote_change']);

        client.on('notification', (msg) => {
            const data = namedEventChannels.has(msg.channel)
                ? `event: ${msg.channel}\ndata: ${msg.payload}\n\n`
                : `data: ${msg.payload}\n\n`;
            for (const res of entry.sseClients) {
                res.write(data);
            }
        });
    } catch (err) {
        console.error(`PG Connect Error (${dbInfo.dbname}):`, err.message);
        scheduleReconnect(dbInfo, entry);
    }
}

function scheduleReconnect(dbInfo, entry) {
    if (entry.reconnectTimer) return;
    entry.pgClient = null;
    entry.reconnectTimer = setTimeout(() => {
        entry.reconnectTimer = null;
        if (entry.sseClients.size > 0) {
            connectListener(dbInfo, entry);
        } else {
            listeners.delete(dbInfo.dbname);
        }
    }, 5000);
}

// --- Cookie-Parser ---

function parseCookies(cookieHeader) {
    const cookies = {};
    if (!cookieHeader) return cookies;
    for (const part of cookieHeader.split(';')) {
        const [name, ...rest] = part.trim().split('=');
        if (name) cookies[name.trim()] = decodeURIComponent(rest.join('='));
    }
    return cookies;
}

// --- HTTP SSE-Server ---

const server = http.createServer(async (req, res) => {
    // Health-Check
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('ok');
        return;
    }

    // SSE-Endpoint
    if (req.url === '/events') {
        // Session-Cookie lesen
        const cookies = parseCookies(req.headers.cookie);
        const sessionId = cookies[cookieName];
        console.log('SSE Request - Cookie-Header:', req.headers.cookie || '(leer)');
        console.log('SSE Request - Parsed cookies:', JSON.stringify(cookies));
        console.log('SSE Request - cookieName:', cookieName, '-> sessionId:', sessionId || '(nicht gefunden)');
        if (!sessionId) {
            res.writeHead(401, { 'Content-Type': 'text/plain' });
            res.end('Keine Session');
            return;
        }

        // Company-DB ueber Session ermitteln
        let dbInfo;
        try {
            dbInfo = await lookupCompanyDbBySession(sessionId);
        } catch (err) {
            console.error('Session-Lookup Fehler:', err.message);
            res.writeHead(500);
            res.end('DB-Fehler');
            return;
        }

        if (!dbInfo) {
            res.writeHead(401, { 'Content-Type': 'text/plain' });
            res.end('Ungueltige Session');
            return;
        }

        // Listener fuer diese Company-DB holen/erstellen
        const entry = await getOrCreateListener(dbInfo);

        res.writeHead(200, {
            'Content-Type': 'text/event-stream',
            'Cache-Control': 'no-cache',
            'Connection': 'keep-alive',
            'X-Accel-Buffering': 'no',
        });

        // Heartbeat alle 30s
        const heartbeat = setInterval(() => {
            res.write(': heartbeat\n\n');
        }, 30000);

        entry.sseClients.add(res);

        req.on('close', () => {
            clearInterval(heartbeat);
            entry.sseClients.delete(res);
            // Listener aufraumen wenn keine Clients mehr
            if (entry.sseClients.size === 0 && !entry.reconnectTimer) {
                if (entry.pgClient) entry.pgClient.end().catch(() => {});
                listeners.delete(dbInfo.dbname);
            }
        });

        return;
    }

    res.writeHead(404);
    res.end();
});

server.listen(SSE_PORT, '127.0.0.1', () => {
    console.log(`SSE-Server laeuft auf http://127.0.0.1:${SSE_PORT}`);
});

// Letzte Verteidigungslinie: ein unerwarteter Fehler darf den SSE-Server nicht
// killen. Als reiner Relay-Dienst ist Weiterlaufen+Loggen besser als ein Crash,
// der bis zum Neustart nur noch 503 produziert.
process.on('uncaughtException', (err) => {
    console.error('uncaughtException:', err);
});
process.on('unhandledRejection', (err) => {
    console.error('unhandledRejection:', err);
});

// --- Build-ID ueberwachen: bei Aenderung alle Clients benachrichtigen ---

const buildIdPath = resolve(__dirname, '../../dist/build-id.txt');
let lastBuildId = '';

function readBuildId() {
    try {
        if (existsSync(buildIdPath)) {
            return readFileSync(buildIdPath, 'utf-8').trim();
        }
    } catch { /* ignorieren */ }
    return '';
}

lastBuildId = readBuildId();

watchFile(buildIdPath, { interval: 2000 }, () => {
    const newBuildId = readBuildId();
    if (newBuildId && newBuildId !== lastBuildId) {
        lastBuildId = newBuildId;
        console.log(`Build-ID geaendert: ${newBuildId} — sende reload an alle Clients`);
        const data = `event: build_changed\ndata: ${JSON.stringify({ buildId: newBuildId })}\n\n`;
        for (const [, entry] of listeners) {
            for (const res of entry.sseClients) {
                res.write(data);
            }
        }
    }
});

// Graceful Shutdown
function shutdown() {
    console.log('Shutting down...');
    for (const [, entry] of listeners) {
        if (entry.reconnectTimer) clearTimeout(entry.reconnectTimer);
        if (entry.pgClient) entry.pgClient.end().catch(() => {});
        // SSE-Streams aktiv schliessen — sonst haelt server.close() den Port besetzt
        // bis alle Clients trennen (kann Sekunden dauern und EADDRINUSE bei PM2-Restart ausloesen)
        for (const res of entry.sseClients) res.destroy();
        entry.sseClients.clear();
    }
    authPool.end().catch(() => {});
    server.close(() => process.exit(0));
    // Harter Fallback nach 3s falls Verbindungen nicht sauber schliessen
    setTimeout(() => process.exit(0), 3000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
