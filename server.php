const WebSocket = require('ws');
const http = require('http');
const fs = require('fs');
const path = require('path');

const port = process.env.PORT || 8080;

// Create HTTP server
const server = http.createServer((req, res) => {
    // Only serve index.html for root path
    if (req.url === '/' || req.url === '/index.html') {
        fs.readFile(path.join(__dirname, 'index.html'), (err, data) => {
            if (err) {
                res.writeHead(500);
                res.end('Error loading index.html');
            } else {
                res.writeHead(200, { 'Content-Type': 'text/html' });
                res.end(data);
            }
        });
    } else {
        res.writeHead(404);
        res.end('Not found');
    }
});

// Attach WebSocket server to the same HTTP server
const wss = new WebSocket.Server({ server });

// Your WebSocket logic
let users = new Map();
let waitingUsers = [];

wss.on('connection', (ws, req) => {
    console.log(`New WebSocket connection from ${req.socket.remoteAddress}`);
    let userId = null;
    let userName = null;
    let currentPartner = null;
    
    ws.on('message', (data) => {
        try {
            const msg = JSON.parse(data);
            console.log(`Received: ${msg.type} from ${userId || 'unknown'}`);
            
            switch(msg.type) {
                case 'register':
                    userId = msg.userId;
                    userName = msg.name;
                    users.set(userId, { ws, name: userName, online: true });
                    broadcastUserList();
                    console.log(`✅ User registered: ${userName} (${userId})`);
                    break;
                    
                case 'find_partner':
                    userId = msg.userId;
                    userName = users.get(userId)?.name || 'Unknown';
                    if (waitingUsers.length > 0) {
                        const partnerId = waitingUsers.shift();
                        const partner = users.get(partnerId);
                        
                        if (partner && partner.ws.readyState === WebSocket.OPEN) {
                            currentPartner = partnerId;
                            users.get(userId).partner = partnerId;
                            users.get(partnerId).partner = userId;
                            
                            ws.send(JSON.stringify({
                                type: 'pair',
                                partnerId: partnerId,
                                partnerName: partner.name
                            }));
                            
                            partner.ws.send(JSON.stringify({
                                type: 'pair',
                                partnerId: userId,
                                partnerName: userName
                            }));
                            
                            console.log(`🎉 Paired ${userName} with ${partner.name}`);
                        }
                    } else {
                        waitingUsers.push(userId);
                        ws.send(JSON.stringify({ type: 'waiting' }));
                        console.log(`⏳ ${userName} is waiting for a partner`);
                    }
                    break;
                    
                case 'initiate_chat':
                    userId = msg.fromId;
                    userName = msg.fromName;
                    const target = users.get(msg.toId);
                    if (target && target.ws.readyState === WebSocket.OPEN && !target.partner) {
                        currentPartner = msg.toId;
                        target.partner = userId;
                        users.get(userId).partner = msg.toId;
                        
                        ws.send(JSON.stringify({
                            type: 'pair',
                            partnerId: msg.toId,
                            partnerName: msg.toName
                        }));
                        
                        target.ws.send(JSON.stringify({
                            type: 'pair',
                            partnerId: userId,
                            partnerName: msg.fromName
                        }));
                        
                        console.log(`💬 ${userName} initiated chat with ${msg.toName}`);
                    } else {
                        ws.send(JSON.stringify({
                            type: 'error',
                            message: 'User is not available'
                        }));
                    }
                    break;
                    
                case 'message':
                    if (currentPartner) {
                        const partner = users.get(currentPartner);
                        if (partner && partner.ws.readyState === WebSocket.OPEN) {
                            partner.ws.send(JSON.stringify({
                                type: 'message',
                                from: userId,
                                messageId: msg.messageId,
                                message: msg.message,
                                timestamp: msg.timestamp
                            }));
                            console.log(`💬 Message from ${userName} to partner`);
                        }
                    }
                    break;
                    
                case 'typing':
                    if (currentPartner) {
                        const partner = users.get(currentPartner);
                        if (partner && partner.ws.readyState === WebSocket.OPEN) {
                            partner.ws.send(JSON.stringify({
                                type: 'typing',
                                from: userId,
                                isTyping: msg.isTyping
                            }));
                        }
                    }
                    break;
                    
                case 'disconnect':
                    if (currentPartner) {
                        const partner = users.get(currentPartner);
                        if (partner && partner.ws.readyState === WebSocket.OPEN) {
                            partner.ws.send(JSON.stringify({
                                type: 'partner_disconnected',
                                partnerId: userId
                            }));
                            partner.partner = null;
                        }
                        currentPartner = null;
                    }
                    break;
                    
                case 'get_users':
                    broadcastUserList();
                    break;
            }
        } catch(e) {
            console.error('Error processing message:', e);
        }
    });
    
    ws.on('close', () => {
        if (userId) {
            const index = waitingUsers.indexOf(userId);
            if (index !== -1) waitingUsers.splice(index, 1);
            
            if (currentPartner) {
                const partner = users.get(currentPartner);
                if (partner && partner.ws.readyState === WebSocket.OPEN) {
                    partner.ws.send(JSON.stringify({
                        type: 'partner_disconnected',
                        partnerId: userId
                    }));
                    partner.partner = null;
                }
            }
            
            users.delete(userId);
            broadcastUserList();
            console.log(`❌ User ${userName} disconnected`);
        }
    });
    
    ws.on('error', (error) => {
        console.error(`WebSocket error: ${error}`);
    });
});

function broadcastUserList() {
    const userList = Array.from(users.entries()).map(([id, user]) => ({
        id: id,
        name: user.name
    }));
    
    console.log(`📡 Broadcasting ${userList.length} online users`);
    
    users.forEach((user) => {
        if (user.ws && user.ws.readyState === WebSocket.OPEN) {
            user.ws.send(JSON.stringify({
                type: 'user_list',
                users: userList
            }));
        }
    });
}

// Start the server
server.listen(port, () => {
    console.log(`🚀 TokWithMe Server running on port ${port}`);
    console.log(`📱 WebSocket server: ws://localhost:${port}`);
    console.log(`🌐 HTTP server: http://localhost:${port}`);
});

// Handle server errors
server.on('error', (error) => {
    console.error(`Server error: ${error}`);
});
