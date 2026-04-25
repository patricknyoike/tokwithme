<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$port = getenv('PORT') ?: 10000;
$host = '0.0.0.0';

$server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$server) {
    die("Error: $errstr\n");
}

echo "✅ Server running on http://$host:$port\n";

$clients = [];

while (true) {
    $read = [$server];
    foreach ($clients as $client) {
        if (is_resource($client)) $read[] = $client;
    }
    
    if (stream_select($read, $write, $except, 0, 50000) > 0) {
        if (in_array($server, $read)) {
            $client = stream_socket_accept($server);
            if ($client) {
                // Read the request
                $request = fread($client, 4096);
                
                // Check if it's a WebSocket upgrade request
                if (preg_match('/Sec-WebSocket-Key: (.*?)\r\n/', $request, $match)) {
                    // WebSocket handshake
                    $key = $match[1];
                    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                    $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
                               "Upgrade: websocket\r\n" .
                               "Connection: Upgrade\r\n" .
                               "Sec-WebSocket-Accept: $accept\r\n\r\n";
                    fwrite($client, $upgrade);
                    $clients[] = $client;
                    echo "WebSocket client connected\n";
                } else {
                    // HTTP request - serve the HTML file
                    $htmlFile = __DIR__ . '/index.html';
                    if (file_exists($htmlFile)) {
                        $html = file_get_contents($htmlFile);
                        $response = "HTTP/1.1 200 OK\r\n" .
                                   "Content-Type: text/html\r\n" .
                                   "Content-Length: " . strlen($html) . "\r\n" .
                                   "Connection: close\r\n\r\n" .
                                   $html;
                        fwrite($client, $response);
                        echo "Served index.html to browser\n";
                    } else {
                        // If index.html doesn't exist, create a simple version
                        $simpleHtml = '<!DOCTYPE html>
                        <html>
                        <head><title>TokWithMe</title><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
                        <body style="background:#0F0F0F;color:white;font-family:sans-serif;text-align:center;padding:50px;">
                            <h1>TokWithMe</h1>
                            <p>Real-time chat is loading...</p>
                            <button onclick="connect()" style="background:#FE2C55;border:none;padding:15px 30px;border-radius:50px;color:white;margin-top:20px;">Connect</button>
                            <script>
                                function connect() {
                                    const ws = new WebSocket("wss://' . $_SERVER["HTTP_HOST"] . '");
                                    ws.onopen = () => alert("Connected!");
                                    ws.onerror = () => alert("Connection error");
                                }
                            </script>
                        </body>
                        </html>';
                        $response = "HTTP/1.1 200 OK\r\n" .
                                   "Content-Type: text/html\r\n" .
                                   "Content-Length: " . strlen($simpleHtml) . "\r\n" .
                                   "Connection: close\r\n\r\n" .
                                   $simpleHtml;
                        fwrite($client, $response);
                        echo "Served emergency HTML\n";
                    }
                    fclose($client);
                }
            }
            unset($read[array_search($server, $read)]);
        }
        
        // Handle WebSocket messages
        foreach ($read as $client) {
            $data = fread($client, 1024);
            if ($data === '' || $data === false) {
                $index = array_search($client, $clients);
                if ($index !== false) unset($clients[$index]);
                fclose($client);
                echo "WebSocket client disconnected\n";
            }
        }
    }
}
?>
