<?php
declare(strict_types=1);

namespace App\Models;

use Config\Database;
use PDO;
use Exception;

class Contact
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO contact_messages (name, email, subject, message, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['subject'],
            $data['message'],
            $data['ip_address'],
            $data['user_agent'],
            date('Y-m-d H:i:s')
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT id, name, email, subject, message, ip_address, user_agent, created_at 
                FROM contact_messages 
                ORDER BY created_at DESC 
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$offset, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT id, name, email, subject, message, ip_address, user_agent, created_at 
                FROM contact_messages 
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) as total FROM contact_messages";
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['total'] ?? 0);
    }

    public function getStats(): array
    {
        $stats = [
            'total_messages' => $this->count(),
            'messages_today' => $this->getCountByDate('today'),
            'messages_this_week' => $this->getCountByDate('week'),
            'messages_this_month' => $this->getCountByDate('month'),
            'top_domains' => $this->getTopEmailDomains(),
            'recent_messages' => $this->getRecentMessages(5)
        ];

        return $stats;
    }

    private function getCountByDate(string $period): int
    {
        $dateConditions = [
            'today' => "created_at >= CAST(GETDATE() AS DATE)",
            'week' => "created_at >= DATEADD(week, -1, GETDATE())",
            'month' => "created_at >= DATEADD(month, -1, GETDATE())"
        ];

        if (!isset($dateConditions[$period])) {
            return 0;
        }

        $sql = "SELECT COUNT(*) as count FROM contact_messages WHERE " . $dateConditions[$period];
        $stmt = $this->db->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['count'] ?? 0);
    }

    private function getTopEmailDomains(int $limit = 5): array
    {
        $sql = "SELECT 
                    SUBSTRING(email, CHARINDEX('@', email) + 1, LEN(email)) as domain,
                    COUNT(*) as count
                FROM contact_messages 
                WHERE email IS NOT NULL AND email != ''
                GROUP BY SUBSTRING(email, CHARINDEX('@', email) + 1, LEN(email))
                ORDER BY count DESC
                OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getRecentMessages(int $limit): array
    {
        $sql = "SELECT name, email, subject, created_at 
                FROM contact_messages 
                ORDER BY created_at DESC 
                OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM contact_messages WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        
        return $stmt->rowCount() > 0;
    }

    public function deleteOld(int $daysOld = 365): int
    {
        $sql = "DELETE FROM contact_messages WHERE created_at < DATEADD(day, -?, GETDATE())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$daysOld]);
        
        return $stmt->rowCount();
    }

    private function createTableIfNotExists(): void
    {
        $sql = "
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='contact_messages' AND xtype='U')
        CREATE TABLE contact_messages (
            id int IDENTITY(1,1) PRIMARY KEY,
            name nvarchar(255) NOT NULL,
            email nvarchar(255) NOT NULL,
            subject nvarchar(500) NOT NULL,
            message ntext NOT NULL,
            ip_address nvarchar(45) NULL,
            user_agent nvarchar(500) NULL,
            created_at datetime2 NOT NULL DEFAULT GETDATE(),
            INDEX idx_created_at (created_at),
            INDEX idx_email (email)
        )";

        try {
            $this->db->exec($sql);
        } catch (Exception $e) {
            error_log("Failed to create contact_messages table: " . $e->getMessage());
            throw new Exception("Database table creation failed");
        }
    }

    public function validateData(array $data): array
    {
        $errors = [];

        // Required fields
        $required = ['name', 'email', 'subject', 'message'];
        foreach ($required as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                $errors[] = "Field '{$field}' is required and must be a string";
            }
        }

        if (!empty($errors)) {
            return $errors;
        }

        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }

        // Length validations
        if (strlen($data['name']) > 255) {
            $errors[] = "Name must be less than 255 characters";
        }

        if (strlen($data['email']) > 255) {
            $errors[] = "Email must be less than 255 characters";
        }

        if (strlen($data['subject']) > 500) {
            $errors[] = "Subject must be less than 500 characters";
        }

        if (strlen($data['message']) > 5000) {
            $errors[] = "Message must be less than 5000 characters";
        }

        // Minimum length validations
        if (strlen(trim($data['name'])) < 2) {
            $errors[] = "Name must be at least 2 characters long";
        }

        if (strlen(trim($data['subject'])) < 3) {
            $errors[] = "Subject must be at least 3 characters long";
        }

        if (strlen(trim($data['message'])) < 10) {
            $errors[] = "Message must be at least 10 characters long";
        }

        return $errors;
    }
}