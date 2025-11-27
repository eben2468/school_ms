<?php
// Database configuration constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'school_ms');
define('DB_USER', 'root');
define('DB_PASS', '');

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // Log error instead of echoing to prevent HTML output in API responses
            error_log("Database connection error: " . $exception->getMessage());
        }
        return $this->conn;
    }

    /**
     * Generate unique student ID in format STU20254927
     * @return string
     */
    public function generateStudentId() {
        $year = date('Y');
        $prefix = 'STU' . $year;

        // Get the highest existing student ID for current year
        $query = "SELECT student_id FROM student_profiles WHERE student_id LIKE :pattern ORDER BY student_id DESC LIMIT 1";
        $stmt = $this->getConnection()->prepare($query);
        $pattern = $prefix . '%';
        $stmt->bindParam(':pattern', $pattern);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // Extract the number part and increment
            $lastId = $result['student_id'];
            $numberPart = (int)substr($lastId, strlen($prefix));
            $nextNumber = $numberPart + 1;
        } else {
            // First student for this year
            $nextNumber = 1;
        }

        // Format with 4 digits
        $studentId = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        // Double-check uniqueness (in case of concurrent requests)
        $checkQuery = "SELECT id FROM student_profiles WHERE student_id = :student_id";
        $checkStmt = $this->getConnection()->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $studentId);
        $checkStmt->execute();

        // If ID exists, try next number
        while ($checkStmt->fetch()) {
            $nextNumber++;
            $studentId = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            $checkStmt->bindParam(':student_id', $studentId);
            $checkStmt->execute();
        }

        return $studentId;
    }

    /**
     * Get current academic year and term information
     * @return array
     */
    public function getCurrentAcademicContext() {
        try {
            $sql = "SELECT
                ay.id as year_id, ay.year_name, ay.start_date as year_start, ay.end_date as year_end, ay.status as year_status,
                at.id as term_id, at.term_number, at.term_name, at.start_date as term_start, at.end_date as term_end, at.status as term_status
            FROM academic_settings ays
            LEFT JOIN academic_years ay ON ay.id = ays.setting_value AND ays.setting_key = 'current_academic_year_id'
            LEFT JOIN academic_settings ats ON ats.setting_key = 'current_academic_term_id'
            LEFT JOIN academic_terms at ON at.id = ats.setting_value
            LIMIT 1";

            $stmt = $this->getConnection()->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result || !$result['year_id']) {
                // Return default values if no academic context is set
                return [
                    'year_id' => null,
                    'year_name' => date('Y') . '-' . (date('Y') + 1),
                    'year_start' => date('Y') . '-09-01',
                    'year_end' => (date('Y') + 1) . '-06-30',
                    'year_status' => 'active',
                    'term_id' => null,
                    'term_number' => '1',
                    'term_name' => 'First Term',
                    'term_start' => date('Y') . '-09-01',
                    'term_end' => date('Y') . '-12-15',
                    'term_status' => 'active'
                ];
            }

            return $result;
        } catch (PDOException $e) {
            // If academic tables don't exist yet, return default values
            return [
                'year_id' => null,
                'year_name' => date('Y') . '-' . (date('Y') + 1),
                'year_start' => date('Y') . '-09-01',
                'year_end' => (date('Y') + 1) . '-06-30',
                'year_status' => 'active',
                'term_id' => null,
                'term_number' => '1',
                'term_name' => 'First Term',
                'term_start' => date('Y') . '-09-01',
                'term_end' => date('Y') . '-12-15',
                'term_status' => 'active'
            ];
        }
    }

    /**
     * Get academic year by ID
     * @param int $year_id
     * @return array|false
     */
    public function getAcademicYear($year_id) {
        try {
            $sql = "SELECT * FROM academic_years WHERE id = :id";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindParam(':id', $year_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get academic term by ID
     * @param int $term_id
     * @return array|false
     */
    public function getAcademicTerm($term_id) {
        try {
            $sql = "SELECT * FROM academic_terms WHERE id = :id";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindParam(':id', $term_id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }
}

// Create mysqli connection for files that need it
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    error_log("MySQLi connection failed: " . $conn->connect_error);
    // Don't die() in API contexts, just log the error
}

// Set charset
$conn->set_charset("utf8");

// Create PDO connection for files that need it
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $exception) {
    error_log("PDO connection error: " . $exception->getMessage());
    $pdo = null;
}
?>