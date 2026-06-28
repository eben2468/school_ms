<?php
// Load deployment-specific secrets (DB credentials, APP_DEBUG) from an
// untracked file when present, so credentials are not hardcoded in this
// version-controlled, web-served file. See config/secrets.sample.php.
$__secrets = __DIR__ . '/secrets.php';
if (is_file($__secrets)) {
    require_once $__secrets;
}

// Database configuration constants (fallback defaults for local dev).
if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }
if (!defined('DB_NAME')) { define('DB_NAME', 'school_ms'); }
if (!defined('DB_USER')) { define('DB_USER', 'root'); }
if (!defined('DB_PASS')) { define('DB_PASS', ''); }

// Production-safe error handling: log errors, never render them to the browser
// (PHP internals/SQL can leak sensitive details). Set APP_DEBUG=true in
// config/secrets.php during development to see errors on screen.
if (!defined('APP_DEBUG')) { define('APP_DEBUG', false); }
@ini_set('log_errors', '1');
@ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    public $conn;

    public function getConnection() {
        $this->conn = null;
        $dbName = $this->db_name;
        
        // Dynamic tenant switching
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['school_db_name']) && !empty($_SESSION['school_db_name'])) {
            $dbName = $_SESSION['school_db_name'];
        }
        
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $dbName, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // Log error instead of echoing to prevent HTML output in API responses
            error_log("Database connection error: " . $exception->getMessage());
        }
        return $this->conn;
    }

    /**
     * Prefix used for student IDs in the active database.
     * - Central directory: 'STU' (legacy).
     * - Tenant: the first three letters of the school's name (e.g. Dream
     *   Academy -> DRE, Cambridge -> CAM), so IDs never collide across schools.
     * @return string three uppercase letters
     */
    public function studentIdPrefix() {
        $dbName = $_SESSION['school_db_name'] ?? null;
        if (empty($dbName) || $dbName === DB_NAME) {
            return 'STU'; // operating on the central directory
        }

        // Persistent, globally-unique prefix lives on central schools. Resolve
        // (and lazily assign) it via the school id so two schools can never
        // share a prefix, even with similar names. Cached per request so a bulk
        // import doesn't reopen the central connection for every row.
        static $prefixCache = [];
        $schoolId = $_SESSION['school_id'] ?? null;
        if (!empty($schoolId)) {
            if (isset($prefixCache[$schoolId])) {
                return $prefixCache[$schoolId];
            }
            try {
                require_once __DIR__ . '/../includes/school_prefix.php';
                $central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $prefix = assignSchoolPrefix($central, $schoolId, $_SESSION['school_name'] ?? null);
                return $prefixCache[$schoolId] = $prefix;
            } catch (Exception $e) {
                error_log("studentIdPrefix: " . $e->getMessage());
            }
        }

        // Last-resort fallback (no school id in session): derive from the name.
        $name = (string)($_SESSION['school_name'] ?? '');
        if ($name === '') {
            try {
                $name = (string)$this->getConnection()->query("SELECT school_name FROM school_settings LIMIT 1")->fetchColumn();
            } catch (Exception $e) {
                $name = '';
            }
        }
        return studentIdPrefixFromName($name);
    }

    /**
     * Generate unique student ID. Format: {PREFIX}{YEAR}{0000}, e.g. DRE20250001
     * (central uses STU). The prefix is school-scoped to keep IDs unique across
     * tenants.
     * @return string
     */
    public function generateStudentId() {
        $year = date('Y');
        $prefix = $this->studentIdPrefix() . $year;

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
        // Date-based defaults, used only when the school has no academic data.
        $defaults = [
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

        try {
            $conn = $this->getConnection();

            // Read the configured current year/term ids directly (the previous
            // single-query/LIMIT 1 approach was order-dependent and frequently
            // resolved to NULL, forcing the date-based fallback).
            $settings = [];
            $s = $conn->query("SELECT setting_key, setting_value FROM academic_settings
                               WHERE setting_key IN ('current_academic_year_id','current_academic_term_id')");
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            $year_id = $settings['current_academic_year_id'] ?? null;
            $term_id = $settings['current_academic_term_id'] ?? null;

            // If no current year is configured, prefer an active year, else the
            // most recent one.
            if (empty($year_id)) {
                $year_id = $conn->query("SELECT id FROM academic_years
                                         ORDER BY (status = 'active') DESC, year_name DESC LIMIT 1")->fetchColumn();
            }
            if (empty($year_id)) {
                return $defaults; // no academic years exist for this school
            }

            $yStmt = $conn->prepare("SELECT id, year_name, start_date, end_date, status
                                     FROM academic_years WHERE id = :id");
            $yStmt->execute([':id' => $year_id]);
            $year = $yStmt->fetch(PDO::FETCH_ASSOC);
            if (!$year) {
                return $defaults;
            }

            // Resolve the term: the configured one if it belongs to this year,
            // otherwise the active/first term within the year.
            $term = null;
            if (!empty($term_id)) {
                $tStmt = $conn->prepare("SELECT id, term_number, term_name, start_date, end_date, status
                                         FROM academic_terms WHERE id = :id AND academic_year_id = :yid");
                $tStmt->execute([':id' => $term_id, ':yid' => $year['id']]);
                $term = $tStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$term) {
                $tStmt = $conn->prepare("SELECT id, term_number, term_name, start_date, end_date, status
                                         FROM academic_terms WHERE academic_year_id = :yid
                                         ORDER BY (status = 'active') DESC, term_number ASC LIMIT 1");
                $tStmt->execute([':yid' => $year['id']]);
                $term = $tStmt->fetch(PDO::FETCH_ASSOC);
            }

            return [
                'year_id'     => $year['id'],
                'year_name'   => $year['year_name'],
                'year_start'  => $year['start_date'],
                'year_end'    => $year['end_date'],
                'year_status' => $year['status'],
                'term_id'     => $term['id'] ?? null,
                'term_number' => $term['term_number'] ?? '1',
                'term_name'   => $term['term_name'] ?? 'First Term',
                'term_start'  => $term['start_date'] ?? null,
                'term_end'    => $term['end_date'] ?? null,
                'term_status' => $term['status'] ?? 'active',
            ];
        } catch (PDOException $e) {
            // If academic tables don't exist yet, return default values.
            return $defaults;
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

if (!function_exists('studentIdPrefixFromName')) {
    /**
     * Three-letter, uppercase student-ID prefix derived from a school name
     * (e.g. "Dream Academy" -> "DRE", "Cambridge International" -> "CAM").
     * Falls back to the legacy 'STU' when a usable name is unavailable.
     *
     * @param string $name school name
     * @return string three uppercase letters
     */
    function studentIdPrefixFromName($name) {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$name));
        return (strlen($letters) >= 3) ? substr($letters, 0, 3) : 'STU';
    }
}

if (!function_exists('formatRoleName')) {
    /**
     * Human-readable display label for a user-role slug.
     *
     * The internal slug for the head of school remains 'principal' for backward
     * compatibility (database values, code comparisons, CSV import, etc.), but it
     * is presented to users as "Headmaster/Headmistress" everywhere in the UI and
     * on printed/PDF documents. All role displays should route through this so the
     * label stays consistent system-wide.
     *
     * @param string $role role slug (e.g. 'principal', 'school_admin')
     * @return string display label
     */
    function formatRoleName($role) {
        $role = (string)$role;
        $labels = [
            'principal'         => 'Headmaster/Headmistress',
            'super_admin'       => 'Super Admin',
            'school_admin'      => 'School Admin',
            'hr'                => 'Human Resource (HR)',
            'transport_officer' => 'Transport Officer',
            'hostel_warden'     => 'Hostel Warden',
            'canteen_manager'   => 'Canteen Manager',
        ];
        if (isset($labels[$role])) {
            return $labels[$role];
        }
        return ucwords(str_replace('_', ' ', $role));
    }
}

// Determine database name dynamically if session has it
$active_db_name = DB_NAME;
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (isset($_SESSION['school_db_name']) && !empty($_SESSION['school_db_name'])) {
    $active_db_name = $_SESSION['school_db_name'];
}

// Create mysqli connection for files that need it
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, $active_db_name);

// Check connection
if ($conn->connect_error) {
    error_log("MySQLi connection failed: " . $conn->connect_error);
    // Don't die() in API contexts, just log the error
}

// Set charset
$conn->set_charset("utf8");

// Create PDO connection for files that need it
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $active_db_name, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $exception) {
    error_log("PDO connection error: " . $exception->getMessage());
    $pdo = null;
}

// System-wide automatic activity capture for admin/logs.php.
// Registers a shutdown hook that records every state-changing request by a
// logged-in user into the active (tenant-aware) audit_logs table. Fail-safe.
require_once __DIR__ . '/../includes/activity_logger.php';
?>