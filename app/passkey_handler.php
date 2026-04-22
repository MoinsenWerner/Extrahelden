<?php
declare(strict_types=1);

require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Hilfsfunktion: Base64URL Dekodierung
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

try {
    switch ($action) {
        case 'get_reg_options':
            if (empty($_SESSION['user_id'])) {
                throw new Exception('Nicht angemeldet.');
            }

            // Challenge erstellen (muss kryptografisch sicher sein)
            $challenge = random_bytes(32);
            $_SESSION['pk_challenge'] = base64_encode($challenge);

            echo json_encode([
                'challenge' => base64_encode($challenge),
                'rp' => [
                    'name' => 'Meine PHP App',
                    'id'   => $_SERVER['HTTP_HOST']
                ],
                'user' => [
                    'id'          => base64_encode((string)$_SESSION['user_id']),
                    'name'        => $_SESSION['username'],
                    'displayName' => $_SESSION['username']
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7], // ES256
                    ['type' => 'public-key', 'alg' => -257] // RS256
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => [
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred'
                ]
            ]);
            break;

        case 'verify_reg':
            if (empty($_SESSION['user_id'])) {
                throw new Exception('Nicht autorisiert.');
            }

            $postData = json_decode(file_get_contents('php://input'), true);
            if (!$postData) {
                throw new Exception('Ungültige Daten.');
            }

            // In einer echten Produktionsumgebung müssten hier:
            // 1. clientDataJSON dekodiert und die Challenge geprüft werden.
            // 2. Das attestationObject (CBOR) geparst werden, um den Public Key zu extrahieren.
            
            // Da PHP kein natives CBOR/COSE unterstützt, speichern wir hier
            // die vom Client gelieferten Identifikatoren. 
            // HINWEIS: Für maximale Sicherheit sollte eine Library wie "web-auth/webauthn-lib" 
            // die Signaturprüfung übernehmen.
            
            $stmt = db()->prepare('
                INSERT INTO passkeys (user_id, credential_id, public_key) 
                VALUES (?, ?, ?)
            ');
            
            $stmt->execute([
                $_SESSION['user_id'],
                $postData['id'],
                $postData['response']['attestationObject'] // Vereinfachte Speicherung
            ]);

            echo json_encode(['success' => true]);
            break;

        case 'get_login_options':
            $challenge = random_bytes(32);
            $_SESSION['pk_challenge'] = base64_encode($challenge);

            echo json_encode([
                'challenge' => base64_encode($challenge),
                'timeout' => 60000,
                'rpId' => $_SERVER['HTTP_HOST'],
                'userVerification' => 'preferred'
            ]);
            break;

        case 'verify_login':
            $postData = json_decode(file_get_contents('php://input'), true);
            $credentialId = $postData['id'] ?? '';

            // Suchen des Users anhand der Credential ID
            $stmt = db()->prepare('
                SELECT u.id, u.username, u.is_admin 
                FROM users u 
                JOIN passkeys p ON u.id = p.user_id 
                WHERE p.credential_id = ? 
                LIMIT 1
            ');
            $stmt->execute([$credentialId]);
            $user = $stmt->fetch();

            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_id']  = (int)$user['id'];
                $_SESSION['username'] = (string)$user['username'];
                $_SESSION['is_admin'] = (int)$user['is_admin'];
                echo json_encode(['success' => true]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Passkey nicht erkannt.']);
            }
            break;

        default:
            throw new Exception('Unbekannte Aktion.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}