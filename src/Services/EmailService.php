<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;

class EmailService
{
    private Client $httpClient;
    private string $provider;
    private array $config;

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 10
        ]);
        
        $this->provider = $_ENV['EMAIL_PROVIDER'] ?? 'emailjs';
        $this->config = $this->getConfig();
    }

    public function sendContactEmail(array $contactData): bool
    {
        switch ($this->provider) {
            case 'emailjs':
                return $this->sendViaEmailJS($contactData);
            case 'smtp':
                return $this->sendViaSMTP($contactData);
            default:
                throw new Exception("Unsupported email provider: {$this->provider}");
        }
    }

    private function sendViaEmailJS(array $contactData): bool
    {
        $url = 'https://api.emailjs.com/api/v1.0/email/send';
        
        $payload = [
            'service_id' => $this->config['service_id'],
            'template_id' => $this->config['template_id'],
            'user_id' => $this->config['public_key'],
            'accessToken' => $this->config['private_key'],
            'template_params' => [
                'from_name' => $contactData['name'],
                'from_email' => $contactData['email'],
                'subject' => $contactData['subject'],
                'message' => $contactData['message'],
                'to_name' => 'Portfolio Owner',
                'reply_to' => $contactData['email']
            ]
        ];

        try {
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Portfolio-Backend/1.0'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                error_log(sprintf(
                    '[%s] [EMAIL] Successfully sent email via EmailJS for: %s',
                    date('c'),
                    $contactData['email']
                ));
                return true;
            }

            error_log(sprintf(
                '[%s] [EMAIL] EmailJS returned status %d: %s',
                date('c'),
                $statusCode,
                $response->getBody()->getContents()
            ));
            
            return false;

        } catch (RequestException $e) {
            error_log(sprintf(
                '[%s] [EMAIL] EmailJS request failed: %s',
                date('c'),
                $e->getMessage()
            ));
            return false;
        } catch (Exception $e) {
            error_log(sprintf(
                '[%s] [EMAIL] EmailJS error: %s',
                date('c'),
                $e->getMessage()
            ));
            return false;
        }
    }

    private function sendViaSMTP(array $contactData): bool
    {
        // Basic SMTP implementation
        // In production, you'd want to use a proper SMTP library like PHPMailer or SwiftMailer
        
        $to = $this->config['to_email'];
        $subject = "Portfolio Contact: " . $contactData['subject'];
        
        $message = "New contact form submission:\n\n";
        $message .= "Name: " . $contactData['name'] . "\n";
        $message .= "Email: " . $contactData['email'] . "\n";
        $message .= "Subject: " . $contactData['subject'] . "\n\n";
        $message .= "Message:\n" . $contactData['message'] . "\n\n";
        $message .= "---\n";
        $message .= "Sent from Portfolio Contact Form\n";
        $message .= "IP: " . ($contactData['ip_address'] ?? 'Unknown') . "\n";
        $message .= "Time: " . date('c') . "\n";

        $headers = [
            "From: " . $this->config['from_email'],
            "Reply-To: " . $contactData['email'],
            "X-Mailer: Portfolio-Backend-PHP/1.0",
            "Content-Type: text/plain; charset=UTF-8"
        ];

        $success = mail($to, $subject, $message, implode("\r\n", $headers));
        
        if ($success) {
            error_log(sprintf(
                '[%s] [EMAIL] Successfully sent email via SMTP for: %s',
                date('c'),
                $contactData['email']
            ));
        } else {
            error_log(sprintf(
                '[%s] [EMAIL] SMTP mail() function failed for: %s',
                date('c'),
                $contactData['email']
            ));
        }

        return $success;
    }

    public function testEmailConfiguration(): array
    {
        $result = [
            'provider' => $this->provider,
            'configured' => false,
            'test_successful' => false,
            'message' => ''
        ];

        try {
            // Check if configuration is complete
            $requiredKeys = $this->getRequiredConfigKeys();
            $missingKeys = [];
            
            foreach ($requiredKeys as $key) {
                if (empty($this->config[$key])) {
                    $missingKeys[] = $key;
                }
            }

            if (!empty($missingKeys)) {
                $result['message'] = 'Missing configuration: ' . implode(', ', $missingKeys);
                return $result;
            }

            $result['configured'] = true;

            // Send test email
            $testData = [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'subject' => 'Email Configuration Test',
                'message' => 'This is a test email to verify the email configuration is working properly.',
                'ip_address' => '127.0.0.1'
            ];

            $result['test_successful'] = $this->sendContactEmail($testData);
            $result['message'] = $result['test_successful'] 
                ? 'Email configuration test successful'
                : 'Email configuration test failed - check logs for details';

        } catch (Exception $e) {
            $result['message'] = 'Email test error: ' . $e->getMessage();
        }

        return $result;
    }

    private function getConfig(): array
    {
        switch ($this->provider) {
            case 'emailjs':
                return [
                    'service_id' => $_ENV['EMAILJS_SERVICE_ID'] ?? '',
                    'template_id' => $_ENV['EMAILJS_TEMPLATE_ID'] ?? '',
                    'public_key' => $_ENV['EMAILJS_PUBLIC_KEY'] ?? '',
                    'private_key' => $_ENV['EMAILJS_PRIVATE_KEY'] ?? ''
                ];
            
            case 'smtp':
                return [
                    'host' => $_ENV['SMTP_HOST'] ?? '',
                    'port' => $_ENV['SMTP_PORT'] ?? '587',
                    'username' => $_ENV['SMTP_USERNAME'] ?? '',
                    'password' => $_ENV['SMTP_PASSWORD'] ?? '',
                    'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
                    'to_email' => $_ENV['SMTP_TO_EMAIL'] ?? ''
                ];
            
            default:
                return [];
        }
    }

    private function getRequiredConfigKeys(): array
    {
        switch ($this->provider) {
            case 'emailjs':
                return ['service_id', 'template_id', 'public_key', 'private_key'];
            
            case 'smtp':
                return ['host', 'port', 'username', 'password', 'from_email', 'to_email'];
            
            default:
                return [];
        }
    }

    public function isConfigured(): bool
    {
        $requiredKeys = $this->getRequiredConfigKeys();
        
        foreach ($requiredKeys as $key) {
            if (empty($this->config[$key])) {
                return false;
            }
        }
        
        return true;
    }
}