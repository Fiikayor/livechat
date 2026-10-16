<?php
declare(strict_types=1);

namespace LiveChat\Standalone;

use PDO;

// Logins and passwords. This lives here and not in the core because only the
// standalone version has its own login. The Laravel version will use the
// app's existing users instead.
final class AgentRepository
{
    public function __construct(private PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM agents WHERE email = ?');
        $stmt->execute([self::normalize($email)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(string $name, string $email, string $password, bool $isAdmin = false): int
    {
        $this->pdo
            ->prepare('INSERT INTO agents (name, email, password_hash, is_admin) VALUES (?, ?, ?, ?)')
            ->execute([$name, self::normalize($email), password_hash($password, PASSWORD_DEFAULT), $isAdmin ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    // PHP raises the default hashing cost over time, so upgrade old hashes at login.
    public function updateHash(int $id, string $password): void
    {
        $this->pdo
            ->prepare('UPDATE agents SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
