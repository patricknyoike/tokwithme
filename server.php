<?php
// Simple HTTP chat server - no WebSocket needed
// Works perfectly on Render's free tier

$request = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Use file-based storage (no database needed)
$dataFile = __DIR__ . '/chat_data.json';

if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([
        'users' => [],
        'messages' => [],
        'waiting' => []
    ]));
}

$data = json_decode(file_get_contents($dataFile), true);

// Clean up old users (older than 30 seconds)
$now = time();
$data['users'] = array_filter($data['users'], function($user) use ($now) {
    return ($now - $user['lastSeen']) < 30;
});

// Route handling
if ($request === '/register' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['userId'];
    $userName = $input['name'];
    
    // Remove old entry
    $data['users'] = array_filter($data['users'], function($user) use ($userId) {
        return $user['id'] !== $userId;
    });
    
    $data['users'][] = [
        'id' => $userId,
        'name' => $userName,
        'lastSeen' => time()
    ];
    
    file_put_contents($dataFile, json_encode($data));
    echo json_encode(['success' => true]);
    
} elseif ($request === '/find_partner' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['userId'];
    
    if (count($data['waiting']) > 0) {
        $partnerId = array_shift($data['waiting']);
        $partner = null;
        foreach ($data['users'] as $user) {
            if ($user['id'] === $partnerId) {
                $partner = $user;
                break;
            }
        }
        
        file_put_contents($dataFile, json_encode($data));
        echo json_encode([
            'success' => true,
            'partnerId' => $partnerId,
            'partnerName' => $partner['name'] ?? 'Stranger'
        ]);
    } else {
        if (!in_array($userId, $data['waiting'])) {
            $data['waiting'][] = $userId;
            file_put_contents($dataFile, json_encode($data));
        }
        echo json_encode(['success' => false, 'waiting' => true]);
    }
    
} elseif ($request === '/get_users' && $method === 'GET') {
    $userList = array_values(array_map(function($user) {
        return ['id' => $user['id'], 'name' => $user['name']];
    }, $data['users']));
    
    echo json_encode(['users' => $userList]);
    
} elseif ($request === '/send_message' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $data['messages'][] = [
        'from' => $input['from'],
        'to' => $input['to'],
        'message' => $input['message'],
        'timestamp' => $input['timestamp']
    ];
    
    // Keep only last 100 messages
    if (count($data['messages']) > 100) {
        array_shift($data['messages']);
    }
    
    file_put_contents($dataFile, json_encode($data));
    echo json_encode(['success' => true]);
    
} elseif ($request === '/get_messages' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['userId'];
    
    $newMessages = [];
    foreach ($data['messages'] as $msg) {
        if ($msg['to'] === $userId) {
            $newMessages[] = $msg;
        }
    }
    
    // Clear delivered messages
    $data['messages'] = array_filter($data['messages'], function($msg) use ($userId) {
        return $msg['to'] !== $userId;
    });
    file_put_contents($dataFile, json_encode($data));
    
    echo json_encode(['messages' => $newMessages]);
    
} elseif ($request === '/' || $request === '/index.html') {
    header('Content-Type: text/html');
    readfile('index.html');
    
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
