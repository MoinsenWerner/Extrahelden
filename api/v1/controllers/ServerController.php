<?php
/**
 * Server Controller - Minecraft server status endpoints
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ServerController
{
    private PDO $pdo;
    private const STATUS_TTL_SECONDS = 30;
    private const LIVE_TTL_SECONDS = 1;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * GET /servers/status
     * Public endpoint - returns all enabled server statuses
     */
    public function status(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $live = isset($queryParams['live']) && $queryParams['live'] === '1';
        $ttl = $live ? self::LIVE_TTL_SECONDS : self::STATUS_TTL_SECONDS;

        try {
            $servers = $this->pdo->query("
                SELECT id, name, host, port
                FROM minecraft_servers
                WHERE enabled = 1
                ORDER BY sort_order, name
            ")->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($servers as $server) {
                $serverId = (int) $server['id'];
                $host = (string) $server['host'];
                $port = (int) $server['port'];
                
                $status = $this->getServerStatusCached($serverId, $host, $port, $ttl);
                
                $result[] = [
                    'id' => $serverId,
                    'name' => $server['name'],
                    'host' => $host,
                    'port' => $port,
                    'online' => !empty($status['online']),
                    'players_online' => $status['players_online'] ?? null,
                    'players_max' => $status['players_max'] ?? null,
                    'version' => $status['version'] ?? null,
                    'latency_ms' => $status['latency_ms'] ?? null,
                    'cached' => !empty($status['cached']),
                ];
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'servers' => $result,
                    'ttl' => $ttl,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => 'Fehler beim Abrufen des Serverstatus.',
                ],
            ], 500);
        }
    }

    /**
     * GET /servers/{id}/status
     * Get status for a specific server
     */
    public function singleStatus(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("
            SELECT id, name, host, port, enabled
            FROM minecraft_servers
            WHERE id = ?
        ");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$server) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Server nicht gefunden.',
                ],
            ], 404);
        }

        $status = $this->getServerStatusCached(
            (int) $server['id'],
            $server['host'],
            (int) $server['port'],
            self::LIVE_TTL_SECONDS
        );

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => (int) $server['id'],
                'name' => $server['name'],
                'host' => $server['host'],
                'port' => (int) $server['port'],
                'enabled' => (bool) $server['enabled'],
                'online' => !empty($status['online']),
                'players_online' => $status['players_online'] ?? null,
                'players_max' => $status['players_max'] ?? null,
                'version' => $status['version'] ?? null,
                'latency_ms' => $status['latency_ms'] ?? null,
                'cached' => !empty($status['cached']),
            ],
        ]);
    }

    /**
     * Minecraft Server Ping (1.7+ Server List Ping Protocol)
     */
    private function mcPingRaw(string $host, int $port = 25565, float $timeout = 1.5): array
    {
        $start = microtime(true);
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        
        if (!$fp) {
            return ['online' => false, 'error' => "connect: $errstr ($errno)"];
        }

        stream_set_timeout($fp, (int) ceil($timeout), (int) ((($timeout - floor($timeout)) * 1e6)));

        try {
            $protocol = 47;
            $serverAddress = $host;

            // Handshake packet
            $data = "\x00"
                . $this->writeVarInt($protocol)
                . $this->writeVarInt(strlen($serverAddress)) . $serverAddress
                . pack('n', $port)
                . $this->writeVarInt(1);
            $packet = $this->writeVarInt(strlen($data)) . $data;
            fwrite($fp, $packet);

            // Status request
            fwrite($fp, "\x01\x00");

            // Read response
            $length = $this->readVarInt($fp);
            $packetId = $this->readVarInt($fp);
            
            if ($packetId !== 0x00) {
                fclose($fp);
                return ['online' => false, 'error' => 'invalid packet id'];
            }

            $jsonLen = $this->readVarInt($fp);
            $json = '';
            while (strlen($json) < $jsonLen) {
                $chunk = fread($fp, $jsonLen - strlen($json));
                if ($chunk === '' || $chunk === false) break;
                $json .= $chunk;
            }
            fclose($fp);

            $arr = json_decode($json, true);
            if (!is_array($arr)) {
                return ['online' => false, 'error' => 'invalid json'];
            }

            $latency = (microtime(true) - $start) * 1000.0;

            return [
                'online' => true,
                'players_online' => (int) ($arr['players']['online'] ?? 0),
                'players_max' => (int) ($arr['players']['max'] ?? 0),
                'version' => (string) ($arr['version']['name'] ?? ''),
                'latency_ms' => round($latency, 1),
                'raw' => $arr,
            ];
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    private function writeVarInt(int $value): string
    {
        $out = '';
        while (true) {
            $temp = $value & 0x7F;
            $value >>= 7;
            if ($value !== 0) $temp |= 0x80;
            $out .= chr($temp);
            if ($value === 0) break;
        }
        return $out;
    }

    private function readVarInt($fp): int
    {
        $numRead = 0;
        $result = 0;
        do {
            $b = fread($fp, 1);
            if ($b === '' || $b === false) {
                throw new \RuntimeException('read fail');
            }
            $byte = ord($b);
            $value = ($byte & 0x7F);
            $result |= ($value << (7 * $numRead));
            $numRead++;
            if ($numRead > 5) throw new \RuntimeException('VarInt too big');
        } while (($byte & 0x80) !== 0);
        return $result;
    }

    /**
     * Get server status with caching
     */
    private function getServerStatusCached(int $serverId, string $host, int $port, int $ttlSec): array
    {
        // Check cache
        $stmt = $this->pdo->prepare("
            SELECT online, players_online, players_max, version, latency_ms, raw_json, checked_at
            FROM server_status_cache 
            WHERE server_id = ?
        ");
        $stmt->execute([$serverId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        $isFresh = false;
        
        if ($row) {
            $checked = strtotime($row['checked_at'] ?? '1970-01-01 00:00:00');
            $isFresh = ($now - $checked) <= $ttlSec;
        }

        if ($row && $isFresh) {
            return [
                'online' => (int) $row['online'] === 1,
                'players_online' => isset($row['players_online']) ? (int) $row['players_online'] : null,
                'players_max' => isset($row['players_max']) ? (int) $row['players_max'] : null,
                'version' => $row['version'] ?? null,
                'latency_ms' => isset($row['latency_ms']) ? (float) $row['latency_ms'] : null,
                'raw' => $row['raw_json'] ? json_decode($row['raw_json'], true) : null,
                'cached' => true,
            ];
        }

        // Live ping
        try {
            $status = $this->mcPingRaw($host, $port, 1.5);
        } catch (\Throwable $e) {
            $status = ['online' => false];
        }

        // Update cache
        $this->pdo->prepare("
            INSERT INTO server_status_cache (server_id, online, players_online, players_max, version, latency_ms, raw_json, checked_at)
            VALUES (:id, :onl, :pon, :pmx, :ver, :lat, :raw, datetime('now'))
            ON CONFLICT(server_id) DO UPDATE SET
                online = excluded.online,
                players_online = excluded.players_online,
                players_max = excluded.players_max,
                version = excluded.version,
                latency_ms = excluded.latency_ms,
                raw_json = excluded.raw_json,
                checked_at = excluded.checked_at
        ")->execute([
            ':id' => $serverId,
            ':onl' => !empty($status['online']) ? 1 : 0,
            ':pon' => $status['players_online'] ?? null,
            ':pmx' => $status['players_max'] ?? null,
            ':ver' => $status['version'] ?? null,
            ':lat' => $status['latency_ms'] ?? null,
            ':raw' => isset($status['raw']) ? json_encode($status['raw']) : null,
        ]);

        $status['cached'] = false;
        return $status;
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
