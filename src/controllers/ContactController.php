<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Contact;
use App\Services\EmailService;
use Exception;

class ContactController
{
    private Contact $contactModel;
    private EmailService $emailService;

    public function __construct()
    {
        $this->contactModel = new Contact();
        $this->emailService = new EmailService();
    }

    public function create(): array
    {
        try {
            // Get request data
            $request = $this->getRequestData();
            $contactData = $request['body'];

            // Validate input data
            $validationErrors = $this->contactModel->validateData($contactData);
            if (!empty($validationErrors)) {
                http_response_code(400);
                return [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors
                ];
            }

            // Prepare data for database
            $dbData = [
                'name' => trim($contactData['name']),
                'email' => trim(strtolower($contactData['email'])),
                'subject' => trim($contactData['subject']),
                'message' => trim($contactData['message']),
                'ip_address' => $request['ip'],
                'user_agent' => $request['user_agent']
            ];

            // Save to database
            $contactId = $this->contactModel->create($dbData);

            // Send email notification
            $emailSent = false;
            try {
                $emailSent = $this->emailService->sendContactEmail($dbData);
            } catch (Exception $e) {
                error_log(sprintf(
                    '[%s] [CONTACT] Email sending failed for contact ID %d: %s',
                    date('c'),
                    $contactId,
                    $e->getMessage()
                ));
            }

            // Log the contact
            error_log(sprintf(
                '[%s] [CONTACT] New contact message from %s (%s) - ID: %d, Email sent: %s',
                date('c'),
                $dbData['name'],
                $dbData['email'],
                $contactId,
                $emailSent ? 'Yes' : 'No'
            ));

            http_response_code(201);
            return [
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => [
                    'id' => $contactId,
                    'email_sent' => $emailSent,
                    'timestamp' => date('c')
                ]
            ];

        } catch (Exception $e) {
            error_log(sprintf(
                '[%s] [CONTACT] Error creating contact: %s',
                date('c'),
                $e->getMessage()
            ));

            http_response_code(500);
            return [
                'success' => false,
                'message' => 'Failed to process contact message',
                'error' => $_ENV['APP_ENV'] === 'development' ? $e->getMessage() : 'Internal server error'
            ];
        }
    }

    public function getMessages(): array
    {
        try {
            $request = $this->getRequestData();
            $query = $request['query'];

            $page = max(1, (int)($query['page'] ?? 1));
            $limit = min(100, max(1, (int)($query['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $messages = $this->contactModel->findAll($limit, $offset);
            $total = $this->contactModel->count();
            $totalPages = ceil($total / $limit);

            return [
                'success' => true,
                'data' => $messages,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                    'has_prev' => $page > 1
                ]
            ];

        } catch (Exception $e) {
            error_log(sprintf(
                '[%s] [CONTACT] Error retrieving messages: %s',
                date('c'),
                $e->getMessage()
            ));

            http_response_code(500);
            return [
                'success' => false,
                'message' => 'Failed to retrieve messages',
                'error' => $_ENV['APP_ENV'] === 'development' ? $e->getMessage() : 'Internal server error'
            ];
        }
    }

    public function getStats(): array
    {
        try {
            $stats = $this->contactModel->getStats();

            return [
                'success' => true,
                'data' => $stats,
                'timestamp' => date('c')
            ];

        } catch (Exception $e) {
            error_log(sprintf(
                '[%s] [CONTACT] Error retrieving stats: %s',
                date('c'),
                $e->getMessage()
            ));

            http_response_code(500);
            return [
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $_ENV['APP_ENV'] === 'development' ? $e->getMessage() : 'Internal server error'
            ];
        }
    }

    public function testEmail(): array
    {
        try {
            $result = $this->emailService->testEmailConfiguration();

            http_response_code($result['test_successful'] ? 200 : 500);
            
            return [
                'success' => $result['test_successful'],
                'message' => $result['message'],
                'data' => [
                    'provider' => $result['provider'],
                    'configured' => $result['configured'],
                    'test_successful' => $result['test_successful']
                ],
                'timestamp' => date('c')
            ];

        } catch (Exception $e) {
            error_log(sprintf(
                '[%s] [CONTACT] Email test error: %s',
                date('c'),
                $e->getMessage()
            ));

            http_response_code(500);
            return [
                'success' => false,
                'message' => 'Email test failed',
                'error' => $_ENV['APP_ENV'] === 'development' ? $e->getMessage() : 'Internal server error'
            ];
        }
    }

    public function getMessage(string $id): array