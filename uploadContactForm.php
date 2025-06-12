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
// EMAIL SERVICE - SIMPLIFIED (SMTP REMOVED)
// =============================================================================
class EmailService {
    private string $provider;
    private array $config;

    public function __construct() {
        $this->provider = $_ENV['EMAIL_PROVIDER'] ?? getenv('EMAIL_PROVIDER') ?? 'disabled';
        $this->config = $this->getConfig();
    }

    public function sendContactEmail(array $contactData): bool {
        if ($this->provider === 'disabled') {
            error_log("[EMAIL] Email provider is disabled");
            return false;
        }

        switch ($this->provider) {
            case 'emailjs':
                return $this->sendViaEmailJS($contactData);
            default:
                error_log("[EMAIL] Unsupported email provider: {$this->provider}");
                return false;
        }
    }

    private function sendViaEmailJS(array $contactData): bool {
        $url = 'https://api.emailjs.com/api/v1.0/email/send';
        
        $payload = [
           'service_id' => $this->config['service_id'],
            'template_id' => $this->config['template_id'],
            'user_id' => $this->config['public_key'],
            'accessToken' => $this->config['private_key'],
            'template_params' => [
                'user_name' => $contactData['name'],
                'user_email' => $contactData['email'],
                'message' => $contactData['message'],
                'to_email' => 'alexmerlo23@gmail.com',
                'reply_to' => $contactData['email'],
                'subject' => "New Contact Form Message from {$contactData['name']}"
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Portfolio-Backend/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
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
            return true;
        }

        error_log("[EMAIL] EmailJS returned HTTP {$httpCode}: {$response}");
        return false;
    }

    private function getConfig(): array {
        if ($this->provider === 'emailjs') {
            return [
                'service_id' => $_ENV['EMAILJS_SERVICE_ID'] ?? getenv('EMAILJS_SERVICE_ID') ?? '',
                'template_id' => $_ENV['EMAILJS_TEMPLATE_ID'] ?? getenv('EMAILJS_TEMPLATE_ID') ?? '',
                'public_key' => $_ENV['EMAILJS_PUBLIC_KEY'] ?? getenv('EMAILJS_PUBLIC_KEY') ?? '',
                'private_key' => $_ENV['EMAILJS_PRIVATE_KEY'] ?? getenv('EMAILJS_PRIVATE_KEY') ?? ''
            ];
        }
        
        return [];
    }

    public function isConfigured(): bool {
        if ($this->provider === 'disabled') {
            return false;
        }

        $requiredKeys = $this->getRequiredConfigKeys();
        
        foreach ($requiredKeys as $key) {
            if (empty($this->config[$key])) {
                return false;
            }
        }
        
        return true;
    }

    private function getRequiredConfigKeys(): array {
        if ($this->provider === 'emailjs') {
            return ['service_id', 'template_id', 'public_key', 'private_key'];
        }
        
        return [];
    }
}

// =============================================================================
// CONTACT FORM HANDLER - UPDATED FOR YOUR SCHEMA
// =============================================================================
class ContactFormHandler {
    private DatabaseConnection $db;
    private EmailService $emailService;

    public function __construct() {
        $this->db = DatabaseConnection::getInstance();
        $this->emailService = new EmailService();
        // Removed createTableIfNotExists since you have your own schema
    }

    public function handleRequest(): array {
        try {
            // Get request data
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                $input = $_POST;
            }
            
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

            // Prepare contact data - MATCH YOUR SCHEMA
            $contactData = [
                'name' => trim($input['name']),
                'email' => trim($input['email']),
                'message' => trim($input['message']),
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Save to database if configured
            $contactId = null;
            if ($this->db->isConfigured()) {
                $contactId = $this->saveToDatabase($contactData);
                error_log("[DB] Attempted to save contact message. Result ID: " . ($contactId ? $contactId : 'FAILED'));
            } else {
                error_log("[DB] Database not configured - skipping database save");
            }

            // TEMPORARILY DISABLE EMAIL - Check environment variable
            $emailSent = false;
            $emailProvider = $_ENV['EMAIL_PROVIDER'] ?? getenv('EMAIL_PROVIDER') ?? 'disabled';
            
            if ($emailProvider !== 'disabled' && $this->emailService->isConfigured()) {
                $emailSent = $this->emailService->sendContactEmail($contactData);
                error_log("[EMAIL] Email provider is: {$emailProvider}, Email sent: " . ($emailSent ? 'Yes' : 'No'));
            } else {
                error_log("[EMAIL] Email disabled or not configured. Provider: {$emailProvider}");
            }

            // Log the submission with more detail
            error_log(sprintf(
                "[CONTACT] New message from %s (%s) - DB ID: %s, Email sent: %s, DB configured: %s",
                $contactData['name'],
                $contactData['email'],
                $contactId ? $contactId : 'FAILED',
                $emailSent ? 'Yes' : 'No',
                $this->db->isConfigured() ? 'Yes' : 'No'
            ));

            http_response_code(201);
            return [
                'success' => true,
                'message' => 'Contact message received successfully',
                'id' => $contactId,
                'email_sent' => $emailSent,
                'database_saved' => $contactId !== null,
                'timestamp' => $contactData['created_at']
            ];

        } catch (Exception $e) {
            error_log("[CONTACT] Error: " . $e->getMessage());
            error_log("[CONTACT] Stack trace: " . $e->getTraceAsString());
            
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

        // Length validations - MATCH YOUR SCHEMA
        if (!empty($input['name']) && strlen(trim($input['name'])) < 2) {
            $errors[] = 'Name must be at least 2 characters long';
        }

        if (!empty($input['message']) && strlen(trim($input['message'])) < 2) {
            $errors[] = 'Message must be at least 2 characters long';
        }

        if (!empty($input['name']) && strlen($input['name']) > 100) {
            $errors[] = 'Name must be less than 100 characters'; // Your schema limit
        }

        if (!empty($input['email']) && strlen($input['email']) > 255) {
            $errors[] = 'Email must be less than 255 characters';
        }

        // No length limit for message since you're using NVARCHAR(MAX)

        return $errors;
    }

    private function saveToDatabase(array $contactData): ?int {
        try {
            $pdo = $this->db->getConnection();
            
            // Test connection first
            $testStmt = $pdo->query("SELECT 1");
            error_log("[DB] Connection test successful");
            
            // Check if table exists
            $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'ContactMessages'");
            $tableCheck->execute();
            $tableExists = $tableCheck->fetchColumn() > 0;
            error_log("[DB] ContactMessages table exists: " . ($tableExists ? 'Yes' : 'No'));
            
            if (!$tableExists) {
                throw new Exception("ContactMessages table does not exist");
            }
            
            // MATCH YOUR EXACT SCHEMA - Only Name, Email, Message
            $stmt = $pdo->prepare("
                INSERT INTO ContactMessages (Name, Email, Message) 
                OUTPUT INSERTED.ID
                VALUES (?, ?, ?)
            ");
            
            error_log("[DB] Executing insert with values: " . json_encode([
                'name' => $contactData['name'],
                'email' => $contactData['email'],
                'message_length' => strlen($contactData['message'])
            ]));
            
            $stmt->execute([
                $contactData['name'],
                $contactData['email'],
                $contactData['message']
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $insertedId = $result ? (int)$result['ID'] : null;
            
            error_log("[DB] Insert result: " . ($insertedId ? "Success, ID: {$insertedId}" : "Failed - no ID returned"));
            
            return $insertedId;

        } catch (Exception $e) {
            error_log("[DB] Failed to save contact message: " . $e->getMessage());
            error_log("[DB] Error details: " . $e->getTraceAsString());
            
            // Try to get more specific error information
            if (isset($pdo)) {
                $errorInfo = $pdo->errorInfo();
                error_log("[DB] PDO Error Info: " . json_encode($errorInfo));
            }
            
            return null;
        }
    }

    private function getClientIP(): string {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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