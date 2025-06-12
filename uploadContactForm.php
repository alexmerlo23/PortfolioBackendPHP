<?php
declare(strict_types=1);

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Turn off for production

// Set JSON header early
header('Content-Type: application/json; charset=utf-8');

// =============================================================================
// CORS CONFIGURATION
// =============================================================================
class CorsHandler {
    private array $allowedOrigins;
    private array $allowedMethods = ['GET', 'POST', 'OPTIONS'];
    private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'];

    public function __construct() {
        // Get environment safely
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        $allowedOriginsEnv = $_ENV['ALLOWED_ORIGINS'] ?? getenv('ALLOWED_ORIGINS') ?: '';
        
        // Parse allowed origins
        $this->allowedOrigins = array_map(
            fn($origin) => rtrim(trim($origin), '/'),
            array_filter(
                explode(',', $allowedOriginsEnv),
                fn($origin) => !empty(trim($origin))
            )
        );
        
        // Add localhost origins for development
        if ($appEnv === 'development') {
            $this->allowedOrigins = array_merge($this->allowedOrigins, [
                'http://localhost:3000',
                'http://localhost:5173',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:5173'
            ]);
        }
        
        // Default allowed origins if none specified
        if (empty($this->allowedOrigins)) {
            $this->allowedOrigins = [
                'https://polite-sea-0cee84310.6.azurestaticapps.net'
            ];
        }
        
        $this->allowedOrigins = array_unique($this->allowedOrigins);
    }

    public function handleCors(): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (!empty($origin)) {
            $origin = rtrim($origin, '/');
        }
        
        // Allow requests with no origin (mobile apps, curl, etc.)
        if (empty($origin)) {
            $this->setBasicCorsHeaders();
            return true;
        }

        // Check if origin is allowed
        if ($this->isOriginAllowed($origin)) {
            $this->setCorsHeaders($origin);
            error_log("[CORS] Allowed request from: {$origin}");
            return true;
        } else {
            error_log("[CORS] Blocked request from unauthorized origin: {$origin}");
            
            // For production, be more lenient for now
            $appEnv = $_ENV['APP_ENV'] ?? 'production';
            if ($appEnv === 'production') {
                $this->setCorsHeaders($origin);
                error_log("[CORS] WARNING: Allowing unauthorized origin in production: {$origin}");
                return true;
            }
            
            http_response_code(403);
            echo json_encode([
                'error' => 'CORS: Origin not allowed',
                'origin' => $origin,
                'allowed_origins' => $this->allowedOrigins
            ]);
            return false;
        }
    }

    public function handlePreflight(): void {
        http_response_code(200);
        echo json_encode([
            'message' => 'CORS preflight successful',
            'allowed_methods' => $this->allowedMethods,
            'allowed_headers' => $this->allowedHeaders
        ]);
        exit;
    }

    private function isOriginAllowed(string $origin): bool {
        return in_array($origin, $this->allowedOrigins, true);
    }

    private function setCorsHeaders(string $origin): void {
        if (!headers_sent()) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
            header('Access-Control-Max-Age: 86400');
            header('Vary: Origin');
        }
    }

    private function setBasicCorsHeaders(): void {
        if (!headers_sent()) {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
            header('Access-Control-Max-Age: 86400');
        }
    }
}

// =============================================================================
// DATABASE CONFIGURATION
// =============================================================================
class DatabaseConnection {
    private static ?DatabaseConnection $instance = null;
    private ?PDO $connection = null;
    private array $config;

    private function __construct() {
        $this->config = [
            'host' => $_ENV['DB_SERVER'] ?? getenv('DB_SERVER'),
            'port' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '1433',
            'database' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'),
            'username' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME'),
            'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD'),
        ];
    }

    public static function getInstance(): DatabaseConnection {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    private function connect(): void {
        try {
            $dsn = sprintf(
                'sqlsrv:Server=%s,%s;Database=%s;TrustServerCertificate=1;ConnectionPooling=0',
                $this->config['host'],
                $this->config['port'],
                $this->config['database']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 30,
            ];

            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            error_log("[DB] Connected to Azure SQL Database: {$this->config['database']}");

        } catch (PDOException $e) {
            error_log("[DB] Connection failed: " . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    public function isConfigured(): bool {
        return !empty($this->config['host']) && 
               !empty($this->config['database']) && 
               !empty($this->config['username']);
    }
}

// =============================================================================
// EMAIL SERVICE - FIXED TO MATCH NODE.JS VERSION
// =============================================================================
class EmailService {
    private array $config;

    public function __construct() {
        $this->config = [
            'service_id' => $_ENV['EMAILJS_SERVICE_ID'] ?? getenv('EMAILJS_SERVICE_ID') ?? '',
            'template_id' => $_ENV['EMAILJS_TEMPLATE_ID'] ?? getenv('EMAILJS_TEMPLATE_ID') ?? '',
            'public_key' => $_ENV['EMAILJS_PUBLIC_KEY'] ?? getenv('EMAILJS_PUBLIC_KEY') ?? '',
            'private_key' => $_ENV['EMAILJS_PRIVATE_KEY'] ?? getenv('EMAILJS_PRIVATE_KEY') ?? ''
        ];
    }

    public function sendContactEmail(array $contactData): bool {
        $url = 'https://api.emailjs.com/api/v1.0/email/send';
        
        // FIXED: Use the exact same template parameters as Node.js version
        $payload = [
            'service_id' => $this->config['service_id'],
            'template_id' => $this->config['template_id'],
            'user_id' => $this->config['public_key'],
            'accessToken' => $this->config['private_key'],
            'template_params' => [
                // Match Node.js template parameter names exactly
                'user_name' => $contactData['name'],
                'user_email' => $contactData['email'],
                'message' => $contactData['message'],
                'to_email' => 'alexmerlo23@gmail.com', // Same hardcoded email as Node.js
                'reply_to' => $contactData['email'],
                'subject' => "New Contact Form Message from {$contactData['name']}"
            ]
        ];

        error_log("[EMAIL] Sending email with template params: " . json_encode($payload['template_params']));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Portfolio-Backend-PHP/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[EMAIL] cURL error: {$error}");
            return false;
        }

        if ($httpCode === 200) {
            error_log("[EMAIL] Successfully sent email via EmailJS for: {$contactData['email']}");
            error_log("[EMAIL] EmailJS response: {$response}");
            return true;
        }

        error_log("[EMAIL] EmailJS returned HTTP {$httpCode}: {$response}");
        return false;
    }

    public function isConfigured(): bool {
        $requiredKeys = ['service_id', 'template_id', 'public_key', 'private_key'];
        
        foreach ($requiredKeys as $key) {
            if (empty($this->config[$key])) {
                error_log("[EMAIL] Missing configuration key: {$key}");
                return false;
            }
        }
        
        return true;
    }
}

// =============================================================================
// CONTACT FORM HANDLER - FIXED TO MATCH NODE.JS VERSION
// =============================================================================
class ContactFormHandler {
    private DatabaseConnection $db;
    private EmailService $emailService;

    public function __construct() {
        $this->db = DatabaseConnection::getInstance();
        $this->emailService = new EmailService();
        $this->createTableIfNotExists();
    }

    public function handleRequest(): array {
        try {
            // Get request data
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                $input = $_POST;
            }
            
            error_log("[CONTACT] 🚀 Contact form submission started - " . json_encode([
                'hasName' => !empty($input['name']),
                'hasEmail' => !empty($input['email']),
                'messageLength' => isset($input['message']) ? strlen($input['message']) : 0
            ]));
            
            // Validate required fields
            $validationErrors = $this->validateInput($input);
            if (!empty($validationErrors)) {
                http_response_code(400);
                return [
                    'success' => false,
                    'error' => 'Validation failed',
                    'details' => $validationErrors
                ];
            }

            // Prepare contact data - FIXED: Only store name, email, message like Node.js
            $contactData = [
                'name' => trim($input['name']),
                'email' => trim($input['email']),
                'message' => trim($input['message'])
            ];

            error_log("[EMAIL] EmailJS Configuration Status: " . json_encode([
                'fullyConfigured' => $this->emailService->isConfigured(),
                'hasServiceId' => !empty($_ENV['EMAILJS_SERVICE_ID'] ?? getenv('EMAILJS_SERVICE_ID')),
                'hasTemplateId' => !empty($_ENV['EMAILJS_TEMPLATE_ID'] ?? getenv('EMAILJS_TEMPLATE_ID')),
                'initialized' => $this->emailService->isConfigured()
            ]));

            // Execute database insert and EmailJS simultaneously like Node.js
            $dbPromise = null;
            $emailPromise = null;
            
            // Database operation
            if ($this->db->isConfigured()) {
                try {
                    $dbPromise = $this->saveToDatabase($contactData);
                } catch (Exception $e) {
                    error_log("[DB] Database operation failed: " . $e->getMessage());
                    $dbPromise = null;
                }
            }

            // Email operation
            if ($this->emailService->isConfigured()) {
                try {
                    error_log("[INFO] Attempting to send email via EmailJS (server-side)");
                    $emailPromise = $this->emailService->sendContactEmail($contactData);
                } catch (Exception $e) {
                    error_log("[EMAIL] EmailJS send failed: " . $e->getMessage());
                    $emailPromise = false;
                }
            } else {
                error_log("[INFO] Email sending skipped - configuration incomplete");
                $emailPromise = false;
            }

            // Check results
            $dbSuccess = $dbPromise !== null;
            $emailSuccess = $emailPromise === true;

            error_log("[INFO] Operation Results: " . json_encode([
                'databaseSuccess' => $dbSuccess,
                'emailSuccess' => $emailSuccess,
                'emailSkipped' => !$this->emailService->isConfigured()
            ]));

            if ($dbSuccess) {
                error_log("[INFO] Contact message saved to database - ID: {$dbPromise}");
            }

            if ($emailSuccess) {
                error_log("[INFO] Email sent successfully via EmailJS");
            }

            // Return success response if database worked (matching Node.js logic)
            if ($dbSuccess) {
                http_response_code(201);
                return [
                    'success' => true,
                    'message' => 'Contact message sent successfully',
                    'id' => $dbPromise,
                    'timestamp' => date('c'),
                    'emailSent' => $emailSuccess,
                    'emailConfigured' => $this->emailService->isConfigured(),
                    // Debug info - remove in production
                    'debug' => [
                        'emailJSInitialized' => $this->emailService->isConfigured(),
                        'emailResult' => [
                            'status' => $emailSuccess ? 'fulfilled' : ($this->emailService->isConfigured() ? 'rejected' : 'skipped'),
                            'wasSkipped' => !$this->emailService->isConfigured()
                        ]
                    ]
                ];
            } else {
                throw new Exception('Database operation failed');
            }

        } catch (Exception $e) {
            error_log("[CONTACT] Error: " . $e->getMessage());
            
            http_response_code(500);
            return [
                'success' => false,
                'error' => 'Failed to process contact message',
                'message' => $e->getMessage()
            ];
        }
    }

    private function validateInput(array $input): array {
        $errors = [];

        // Required fields
        if (empty($input['name']) || !is_string($input['name'])) {
            $errors[] = 'Name is required';
        }

        if (empty($input['email']) || !is_string($input['email'])) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        if (empty($input['message']) || !is_string($input['message'])) {
            $errors[] = 'Message is required';
        }

        // Length validations
        if (!empty($input['name']) && strlen(trim($input['name'])) < 2) {
            $errors[] = 'Name must be at least 2 characters long';
        }

        if (!empty($input['message']) && strlen(trim($input['message'])) < 10) {
            $errors[] = 'Message must be at least 10 characters long';
        }

        if (!empty($input['name']) && strlen($input['name']) > 255) {
            $errors[] = 'Name must be less than 255 characters';
        }

        if (!empty($input['email']) && strlen($input['email']) > 255) {
            $errors[] = 'Email must be less than 255 characters';
        }

        if (!empty($input['message']) && strlen($input['message']) > 5000) {
            $errors[] = 'Message must be less than 5000 characters';
        }

        return $errors;
    }

    private function saveToDatabase(array $contactData): ?int {
        try {
            $pdo = $this->db->getConnection();
            
            // FIXED: Match Node.js database structure exactly - only Name, Email, Message
            $stmt = $pdo->prepare("
                INSERT INTO ContactMessages (Name, Email, Message) 
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([
                $contactData['name'],
                $contactData['email'],
                $contactData['message']
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['ID'] : null;

        } catch (Exception $e) {
            error_log("[DB] Failed to save contact message: " . $e->getMessage());
            throw $e;
        }
    }

    private function createTableIfNotExists(): void {
        if (!$this->db->isConfigured()) {
            return;
        }

        try {
            $pdo = $this->db->getConnection();
            
            // FIXED: Match the Node.js table structure exactly
            $sql = "
            IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='ContactMessages' AND xtype='U')
            CREATE TABLE ContactMessages (
                ID int IDENTITY(1,1) PRIMARY KEY,
                Name nvarchar(100) NOT NULL,
                Email nvarchar(255) NOT NULL,
                Message nvarchar(max) NOT NULL,
                CreatedAt datetime2 NOT NULL DEFAULT GETDATE(),
                INDEX idx_created_at (CreatedAt),
                INDEX idx_email (Email)
            )";

            $pdo->exec($sql);
            
        } catch (Exception $e) {
            error_log("[DB] Failed to create ContactMessages table: " . $e->getMessage());
        }
    }
}

// =============================================================================
// MAIN EXECUTION
// =============================================================================

try {
    // Load environment variables if .env file exists
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                
                if (!empty($key) && !isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }

    // Initialize CORS handler
    $corsHandler = new CorsHandler();
    
    // Handle CORS
    if (!$corsHandler->handleCors()) {
        exit; // CORS check failed
    }

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $corsHandler->handlePreflight();
    }

    // Only allow POST requests for actual form submission
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'error' => 'Method not allowed',
            'allowed_methods' => ['POST', 'OPTIONS'],
            'message' => 'This endpoint only accepts POST requests for contact form submissions'
        ]);
        exit;
    }

    // Process contact form
    $contactHandler = new ContactFormHandler();
    $response = $contactHandler->handleRequest();
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    
    $response = [
        'success' => false,
        'error' => 'Server error occurred',
        'message' => $e->getMessage(),
        'timestamp' => date('c')
    ];
    
    // Add debug info in development
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    if ($appEnv !== 'production') {
        $response['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
    error_log("[ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}
?>