<?php
if (php_sapi_name() !== 'cli' && !isset($_GET['confirm'])) {
    die('Add ?confirm=1 to run this migration.');
}

require_once 'includes/db.php';

// Check if table already exists
$tableExists = $conn->query("SHOW TABLES LIKE 'incident_reads'")->num_rows > 0;

if (!$tableExists) {
    $sql = "CREATE TABLE incident_reads (
        id INT PRIMARY KEY AUTO_INCREMENT,
        incident_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_read (incident_id, user_id),
        FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id),
        INDEX idx_incident_id (incident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($sql)) {
        echo "✓ incident_reads table created successfully";
    } else {
        echo "✗ Error creating table: " . $conn->error;
    }
} else {
    echo "✓ incident_reads table already exists";
}
