<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

error_log("TokWithMe Server Starting...");

$port = getenv('PORT') ?: 5008;
$host = '0.0.0.0';

error_log("Attempting to bind to $host:$port");

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

stream_set_blocking($server, false);

while (true) {
    $read = array_merge([$server], $clients);
    $write = null;
    $except = null;
    
    if (stream_select($read, $write, $except, 0, 50000) > 0) {
        if (in_array($server, $read)) {
            if ($client = @stream_socket_accept($server, 0)) {
                $clients[] = $client;
                error_log("New TCP connection accepted, waiting for handshake...");
            }
            unset($read[array_search($server, $read)]);
        }
        
        foreach ($read as $client) {
            $headers = @fread($client, 1024);
            if ($headers === false || $headers === '') {
                // Client disconnected without handshake
                $index = array_search($client, $clients);
                if ($index !== false) {
                    unset($clients[$index]);
                }
                @fclose($client);
                error_log("Client disconnected before handshake");
                continue;
            }
            
            // Check if this is a WebSocket upgrade request
            if (preg_match('/Sec-WebSocket-Key: (.*?)\r\n/', $headers, $match)) {
                // Perform WebSocket handshake
                $key = $match[1];
                $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                           "Upgrade: websocket\r\n" .
                           "Connection: Upgrade\r\n" .
                           "Sec-WebSocket-Accept: $accept\r\n\r\n";
                @fwrite($client, $upgrade);
                error_log("WebSocket handshake completed");
                
                // Store this client as a WebSocket client
                $clients[$client] = true; // Mark as WebSocket client
            } else {
                // Not a WebSocket request - send HTTP response for browser
                $response = "HTTP/1.1 200 OK\r\n" .
                           "Content-Type: text/html\r\n" .
                           "Content-Length: " . strlen($headers) . "\r\n\r\n" .
                           $headers;
                @fwrite($client, $response);
                @fclose($client);
                $index = array_search($client, $clients);
                if ($index !== false) {
                    unset($clients[$index]);
                }
            }
        }
    }
    usleep(10000);
}
?>
