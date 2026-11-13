<?php

namespace SegmentHoldings\SsgPublish\Tests;

use RuntimeException;

class LiveHookServer
{
    private function __construct(
        private string $directory,
        private $process,
        private int $port,
        private string $caBundle,
    ) {
    }

    public static function start(): self
    {
        foreach (['openssl', 'python3'] as $binary) {
            if (! self::binary($binary)) {
                throw new RuntimeException("Live hook tests need {$binary} on PATH.");
            }
        }

        $directory = sys_get_temp_dir().'/ssg-publish-live-'.bin2hex(random_bytes(4));
        if (! mkdir($directory) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create a directory for the live hook server.');
        }

        self::command($directory, [
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', 'ca.key', '-out', 'ca.pem', '-days', '2', '-nodes',
            '-subj', '/CN=SSG Publish Test CA',
        ]);

        file_put_contents($directory.'/san.cnf', "subjectAltName=IP:127.0.0.1,DNS:localhost\nextendedKeyUsage=serverAuth\n");

        self::command($directory, [
            'openssl', 'req', '-newkey', 'rsa:2048',
            '-keyout', 'server.key', '-out', 'server.csr', '-nodes',
            '-subj', '/CN=127.0.0.1',
        ]);

        self::command($directory, [
            'openssl', 'x509', '-req', '-in', 'server.csr',
            '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial',
            '-out', 'server.crt', '-days', '2', '-extfile', 'san.cnf',
        ]);

        $system = self::systemBundle();
        $bundle = $directory.'/bundle.pem';
        file_put_contents($bundle, $system.PHP_EOL.file_get_contents($directory.'/ca.pem'));

        file_put_contents($directory.'/server.py', <<<'PY'
import json, ssl
from http.server import BaseHTTPRequestHandler, HTTPServer

class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0") or 0)
        body = self.rfile.read(length) if length else b""
        record = {
            "method": self.command,
            "path": self.path.split("?", 1)[0],
            "headers": {key: value for key, value in self.headers.items()},
            "body": body.decode("utf-8", "replace"),
        }
        with open("requests.jsonl", "a", encoding="utf-8") as handle:
            handle.write(json.dumps(record) + "\n")

        if self.path.split("?", 1)[0] == "/deploy/redirect":
            self.send_response(302)
            location = "https://127.0.0.1:%d/deploy/secret-path" % self.server.server_address[1]
            self.send_header("Location", location)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return

        payload = b'{"ok":true,"message":"accepted"}'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, fmt, *args):
        return

httpd = HTTPServer(("127.0.0.1", 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain("server.crt", "server.key")
httpd.socket = context.wrap_socket(httpd.socket, server_side=True)
with open("port", "w", encoding="utf-8") as handle:
    handle.write(str(httpd.server_address[1]))
httpd.serve_forever()
PY);

        $pipes = [];
        $process = proc_open(
            ['python3', $directory.'/server.py'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $directory.'/server.log', 'a'],
                2 => ['file', $directory.'/server.log', 'a'],
            ],
            $pipes,
            $directory
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the live hook server.');
        }

        $port = null;
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (is_file($directory.'/port')) {
                $port = (int) trim((string) file_get_contents($directory.'/port'));
                if ($port > 0) {
                    break;
                }
            }
            usleep(50000);
        }

        if (! $port) {
            $log = is_file($directory.'/server.log') ? file_get_contents($directory.'/server.log') : '';
            proc_terminate($process);
            throw new RuntimeException("The live hook server did not bind a port. {$log}");
        }

        return new self($directory, $process, $port, $bundle);
    }

    public function url(string $path): string
    {
        return 'https://127.0.0.1:'.$this->port.$path;
    }

    public function caBundle(): string
    {
        return $this->caBundle;
    }

    public function reset(): void
    {
        $log = $this->directory.'/requests.jsonl';
        if (is_file($log)) {
            unlink($log);
        }
    }

    public function requests(): array
    {
        $log = $this->directory.'/requests.jsonl';
        if (! is_file($log)) {
            return [];
        }

        $rows = [];
        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private static function binary(string $name): bool
    {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $path !== '';
    }

    private static function systemBundle(): string
    {
        foreach ([
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
        ] as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return '';
    }

    private static function command(string $cwd, array $command): void
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not run '.implode(' ', $command));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new RuntimeException(trim($command[0].' failed: '.$stderr.' '.$stdout));
        }
    }
}
