<?php
echo "TokWithMe Server Starting...\n";

// Simple WebSocket server using built-in PHP sockets
$host = '0.0.0.0';
$port = getenv('PORT') ?: 8080;

$server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$server) {
    die("Error: $errstr ($errno)\n");
}

echo "✅ WebSocket server running on ws://$host:$port\n";

$clients = [];
$users = [];
$waitingUsers = [];
$partners = [];

while (true) {
    $read = array_merge([$server], $clients);
    $write = null;
    $except = null;
    
    if (stream_select($read, $write, $except, 0, 50000)) {
        if (in_array($server, $read)) {
            $client = stream_socket_accept($server);
            if ($client) {
                $clients[] = $client;
                performHandshake($client);
                echo "New client connected\n";
            }
            unset($read[array_search($server, $read)]);
        }
        
        foreach ($read as $client) {
            $data = fread($client, 1024);
            if ($data === false || $data === '') {
                // Client disconnected
                $index = array_search($client, $clients);
                if ($index !== false) {
                    unset($clients[$index]);
                    $userId = array_search($client, $users);
                    if ($userId) {
                        unset($users[$userId]);
                        // Notify partner
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
                        // Remove from waiting list
                        $index = array_search($userId, $waitingUsers);
                        if ($index !== false) unset($waitingUsers[$index]);
                        broadcastUserList($clients, $users);
                    }
                    echo "Client disconnected\n";
                }
                fclose($client);
                continue;
            }
            
            $message = unmask($data);
            handleMessage($client, $message, $users, $waitingUsers, $partners, $clients);
        }
    }
}

function performHandshake($client) {
    $headers = fread($client, 1024);
    preg_match('/Sec-WebSocket-Key: (.*?)\r\n/', $headers, $match);
    $key = $match[1];
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
               "Upgrade: websocket\r\n" .
               "Connection: Upgrade\r\n" .
               "Sec-WebSocket-Accept: $accept\r\n\r\n";
    fwrite($client, $upgrade);
}

function unmask($data) {
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
    
    $userId = array_search($client, $users);
    
    switch($data['type']) {
        case 'register':
            $userId = $data['userId'];
            $users[$userId] = $client;
            broadcastUserList($clients, $users);
            break;
            
        case 'find_partner':
            $userId = $data['userId'];
            if (count($waitingUsers) > 0) {
                $partnerId = array_shift($waitingUsers);
                $partner = $users[$partnerId] ?? null;
                if ($partner) {
                    $partners[$userId] = $partnerId;
                    $partners[$partnerId] = $userId;
                    
                    sendMessage($client, json_encode([
                        'type' => 'pair',
                        'partnerId' => $partnerId,
                        'partnerName' => 'Stranger'
                    ]));
                    sendMessage($partner, json_encode([
                        'type' => 'pair',
                        'partnerId' => $userId,
                        'partnerName' => 'Stranger'
                    ]));
                }
            } else {
                $waitingUsers[] = $userId;
            }
            break;
            
        case 'message':
            $toId = $data['to'];
            $partnerId = $partners[$toId] ?? null;
            if ($partnerId && isset($users[$toId])) {
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
