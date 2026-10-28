<?php

/*
 * A fixed box reader for tests, as a separate process.
 *
 *   server <file>         listens on a free port, prints it, sends the file to the first client and closes
 *   client <port> <file>  connects to the port (retrying a few seconds), sends the file and closes
 *
 * The file holds exactly the bytes the reader sends.
 */

[, $mode] = $argv;

if ($mode === 'server') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error) ?: exit("server: {$error}\n");
    echo substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1)."\n";
    $client = stream_socket_accept($server, 30) ?: exit(1);
    send($client, (string) file_get_contents($argv[2]));
    exit(0);
}

for ($i = 0; $i < 100; $i++) {
    $client = @stream_socket_client('tcp://127.0.0.1:'.$argv[2], $errno, $error, 1);
    if ($client !== false) {
        send($client, (string) file_get_contents($argv[3]));
        exit(0);
    }
    usleep(100_000);
}
exit(1);

/** @param resource $client */
function send($client, string $bytes): void
{
    // In pieces, like a real reader: a read can come split across packets.
    foreach (str_split($bytes, 7) as $piece) {
        fwrite($client, $piece);
        usleep(5_000);
    }
    fclose($client);
}
