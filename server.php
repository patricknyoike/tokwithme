<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Log startup
error_log("TokWithMe Server Starting...");

// Get port from Render environment (VERY IMPORTANT!)
$port = getenv('PORT');
if (!$port) {
    $port = 8080; // Fallback port
}

$host = '0.0.0.0';

error_log("Attempting to bind to $host:$port");

// Create server socket
$server = @stream_socket_server("tcp://$host:$port", $errno, $errstr);

if (!$server) {
    error_log("ERROR: Failed to create socket: $errstr ($errno)");
    exit(1);
}

error_log("✅ WebSocket server running on ws://$host:$port");

$clients = [];
$users = [];
$waitingUsers = [];
$partners = [];

// Set socket to non-blocking
stream_set_blocking($server, false);

// Main loop
while (true) {
    $read = array_merge([$server], $clients);
    $write = null;
    $except = null;
    
    if (stream_select($read, $write, $except, 0, 50000) > 0) {
        // New connection
        if (in_array($server, $read)) {
            if ($client = @stream_socket_accept($server, 0)) {
                $clients[] = $client;
                performHandshake($client);
                error_log("New client connected. Total clients: " . count($clients));
            }
            unset($read[array_search($server, $read)]);
        }
        
        // Handle client messages
        foreach ($read as $client) {
            $data = @fread($client, 1024);
            if ($data === false || $data === '') {
                // Client disconnected
                $index = array_search($client, $clients);
                if ($index !== false) {
                    unset($clients[$index]);
                    $userId = array_search($client, $users);
                    if ($userId) {
                        unset($users[$userId]);
                        if (isset($partners[$userId])) {
                            $partnerId = $partners[$userId];
                            if (isset($users[$partnerId])) {
                                sendMessage($users[$partnerId], json_encode([
                                    'type' => 'partner_disconnected',
                                    'partnerId' => $userId
                                ]));
                            }
                            unset($partners[$partnerId]);
                            unset($partners[$userId]);
                        }
                        $index = array_search($userId, $waitingUsers);
                        if ($index !== false) unset($waitingUsers[$index]);
                        broadcastUserList($clients, $users);
                    }
                    error_log("Client disconnected. Total clients: " . count($clients));
                }
                @fclose($client);
                continue;
            }
            
            $message = unmask($data);
            handleMessage($client, $message, $users, $waitingUsers, $partners, $clients);
        }
    }
    
    // Small sleep to prevent CPU hogging
    usleep(10000);
}

function performHandshake($client) {
    $headers = @fread($client, 1024);
    if (preg_match('/Sec-WebSocket-Key: (.*?)\r\n/', $headers, $match)) {
        $key = $match[1];
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Accept: $accept\r\n\r\n";
        @fwrite($client, $upgrade);
        return true;
    }
    return false;
}

function unmask($data) {
    if (strlen($data) < 2) return '';
    
    $length = ord($data[1]) & 127;
    if ($length == 126) {
        $masks = substr($data, 4, 4);
        $data = substr($data, 8);
    } elseif ($length == 127) {
        $masks = substr($data, 10, 4);
        $data = substr($data, 14);
    } else {
        $masks = substr($data, 2, 4);
        $data = substr($data, 6);
    }
    
    $text = '';
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

function encode($data) {
    $b1 = 0x81;
    $length = strlen($data);
    if ($length <= 125) {
        $header = pack('CC', $b1, $length);
    } elseif ($length <= 65535) {
        $header = pack('CCn', $b1, 126, $length);
    } else {
        $header = pack('CCNN', $b1, 127, $length);
    }
    return $header . $data;
}

function sendMessage($client, $message) {
    @fwrite($client, encode($message));
}

function handleMessage($client, $message, &$users, &$waitingUsers, &$partners, $clients) {
    $data = json_decode($message, true);
    if (!$data) return;
    
    switch($data['type']) {
        case 'register':
            $userId = $data['userId'];
            $users[$userId] = $client;
            broadcastUserList($clients, $users);
            error_log("User registered: $userId");
            break;
            
        case 'find_partner':
            $userId = $data['userId'];
            if (count($waitingUsers) > 0) {
                $partnerId = array_shift($waitingUsers);
                if (isset($users[$partnerId])) {
                    $partners[$userId] = $partnerId;
                    $partners[$partnerId] = $userId;
                    
                    sendMessage($client, json_encode([
                        'type' => 'pair',
                        'partnerId' => $partnerId,
                        'partnerName' => 'Stranger'
                    ]));
                    sendMessage($users[$partnerId], json_encode([
                        'type' => 'pair',
                        'partnerId' => $userId,
                        'partnerName' => 'Stranger'
                    ]));
                    error_log("Users paired: $userId with $partnerId");
                }
            } else {
                $waitingUsers[] = $userId;
                error_log("User waiting: $userId");
            }
            break;
            
        case 'message':
            $toId = $data['to'];
            if (isset($partners[$toId]) && isset($users[$toId])) {
                sendMessage($users[$toId], json_encode([
                    'type' => 'message',
                    'from' => $data['from'],
                    'message' => $data['message'],
                    'timestamp' => $data['timestamp']
                ]));
            }
            break;
            
        case 'get_users':
            broadcastUserList($clients, $users);
            break;
    }
}

function broadcastUserList($clients, $users) {
    $userList = [];
    foreach ($users as $id => $conn) {
        $userList[] = ['id' => $id, 'name' => 'User_' . substr($id, -6)];
    }
    
    $message = json_encode(['type' => 'user_list', 'users' => $userList]);
    foreach ($users as $conn) {
        sendMessage($conn, $message);
    }
}
?>
