<?php
// api/config/database.php
function getDBConnection() {
    try {
        // MySQL configuration
        $host = 'localhost';
        $dbname = 'baleeg_db';
        $username = 'root';
        $password = '';
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Create tables if they don't exist
function initializeDatabase($db) {
    if (!$db) return;
    
    try {
        // Create members table
        $db->exec("
            CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                accountNumber VARCHAR(20) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                phone VARCHAR(20),
                membershipType VARCHAR(50) DEFAULT 'Standard',
                joinDate DATE,
                status VARCHAR(20) DEFAULT 'Active',
                balance DECIMAL(10,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create transactions table
        $db->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                memberId INT NOT NULL,
                memberName VARCHAR(100),
                accountNumber VARCHAR(20),
                type VARCHAR(50) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                date DATE,
                status VARCHAR(20) DEFAULT 'pending',
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (memberId) REFERENCES members(id) ON DELETE CASCADE
            )
        ");
        
        // Create admins table
        $db->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT 'admin',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Insert default admin if not exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO admins (username, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute(['admin', 'admin@baleeg.com', $hashedPassword, 'administrator']);
        }
        
    } catch (PDOException $e) {
        error_log("Failed to initialize database: " . $e->getMessage());
    }
}

// Initialize database on first run
$db = getDBConnection();
if ($db) {
    initializeDatabase($db);
}
?>
