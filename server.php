<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\WebSocket\WsServer;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $users;
    protected $waitingUsers;
    protected $partners;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->waitingUsers = [];
        $this->partners = [];
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;
        
        switch($data['type']) {
            case 'register':
                $userId = $data['userId'];
                $this->users[$userId] = [
                    'conn' => $from,
                    'name' => $data['name'],
                    'resourceId' => $from->resourceId
                ];
                $this->broadcastUserList();
                echo "User registered: {$data['name']}\n";
                break;
                
            case 'find_partner':
                $userId = $this->getUserId($from);
                if (count($this->waitingUsers) > 0) {
                    $partnerId = array_shift($this->waitingUsers);
                    $partner = $this->users[$partnerId] ?? null;
                    
                    if ($partner) {
                        $this->partners[$userId] = $partnerId;
                        $this->partners[$partnerId] = $userId;
                        
                        $from->send(json_encode([
                            'type' => 'pair',
                            'partnerId' => $partnerId,
                            'partnerName' => $partner['name']
                        ]));
                        
                        $partner['conn']->send(json_encode([
                            'type' => 'pair',
                            'partnerId' => $userId,
                            'partnerName' => $this->users[$userId]['name']
                        ]));
                        
                        echo "Paired users!\n";
                    }
                } else {
                    $this->waitingUsers[] = $userId;
                }
                break;
                
            case 'message':
                $fromId = $this->getUserId($from);
                $toId = $data['to'];
                $partnerId = $this->partners[$toId] ?? null;
                
                if ($partnerId === $fromId) {
                    $partner = $this->users[$toId] ?? null;
                    if ($partner) {
                        $partner['conn']->send(json_encode([
                            'type' => 'message',
                            'from' => $fromId,
                            'message' => $data['message'],
                            'timestamp' => $data['timestamp']
                        ]));
                    }
                }
                break;
                
            case 'get_users':
                $this->broadcastUserList();
                break;
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $userId = $this->getUserId($conn);
        
        if ($userId) {
            $index = array_search($userId, $this->waitingUsers);
            if ($index !== false) unset($this->waitingUsers[$index]);
            
            if (isset($this->partners[$userId])) {
                $partnerId = $this->partners[$userId];
                $partner = $this->users[$partnerId] ?? null;
                if ($partner) {
                    $partner['conn']->send(json_encode([
                        'type' => 'partner_disconnected',
                        'partnerId' => $userId
                    ]));
                    unset($this->partners[$partnerId]);
                }
                unset($this->partners[$userId]);
            }
            
            unset($this->users[$userId]);
            $this->broadcastUserList();
        }
        
        $this->clients->detach($conn);
        echo "Connection disconnected\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
    
    private function getUserId(ConnectionInterface $conn) {
        foreach ($this->users as $id => $user) {
            if ($user['conn'] === $conn) {
                return $id;
            }
        }
        return null;
    }
    
    private function broadcastUserList() {
        $userList = [];
        foreach ($this->users as $id => $user) {
            $userList[] = [
                'id' => $id,
                'name' => $user['name']
            ];
        }
        
        foreach ($this->users as $user) {
            $user['conn']->send(json_encode([
                'type' => 'user_list',
                'users' => $userList
            ]));
        }
    }
}

// Run the server
$port = getenv('PORT') ?: 8080;
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ChatServer()
        )
    ),
    $port
);

echo "✅ TokWithMe Chat Server running on port $port\n";
$server->run();
