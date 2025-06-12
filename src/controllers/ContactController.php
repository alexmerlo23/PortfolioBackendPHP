<?php
declare(strict_types=1);

namespace App\Controllers;

use Config\Database;
use PDO;
use Exception;

class ContactController
{
    public function create(): array
    {
        try {
            // Get request data
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                $input = $_POST;
            }
            
            $name = trim($input['name'] ?? '');
            $email = trim($input['email'] ?? '');
            $message = trim($input['message'] ?? '');
            
            // Basic validation
            if (empty($name) || empty($email) || empty($message)) {
                http_response_code(400);
                return [
                    'success' => false,
                    'error' => 'Name, email, and message are required'
                ];
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                return [
                    'success' => false,
                    'error' => 'Invalid email format'
                ];
            }
            
            // Get database connection
            $db = Database::getInstance();
            $pdo = $db->getConnection();
            
            // Insert into database
            $stmt = $pdo->prepare("
                INSERT INTO ContactMessages (Name, Email, Message) 
                OUTPUT INSERTED.ID, INSERTED.CreatedAt
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([$name, $email, $message]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception('Failed to insert contact message');
            }
            
            $contactId = $result['ID'];
            $createdAt = $result['CreatedAt'];
            
            // Send email using EmailJS (client-side will handle this)
            // Or implement server-side email sending here if needed
            
            // Log successful submission
            error_log(sprintf(
                '[%s] [CONTACT] New message from %s (%s) - ID: %d',
                date('c'),
                $name,
                $email,
                $contactId
            ));
            
            http_response_code(201);
            return [
                'success' => true,
                'message' => 'Contact message sent successfully',
                'id' => $contactId,
                'timestamp' => $createdAt
            ];
            
        } catch (Exception $e) {
            error_log(sprintf(
                '[%s] [CONTACT] Error: %s',
                date('c'),
                $e->getMessage()
            ));
            
            http_response_code(500);
            return [
                'success' => false,
                'error' => 'Failed to save contact message'
            ];
        }
    }
}