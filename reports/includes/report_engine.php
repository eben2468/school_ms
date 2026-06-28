<?php
/**
 * Custom Report Engine
 * --------------------
 * A safe, whitelist-based query builder for the custom report tools
 * (builder, saved reports, scheduled reports).
 *
 * Users never supply raw SQL. They choose a predefined "source", a subset
 * of that source's allowed columns, and values for that source's allowed
 * filters. Column/filter KEYS are validated against the definition and only
 * their predefined SQL fragments are used; all VALUES are bound parameters.
 */

if (!function_exists('report_engine_install')) {

    /** Create the persistence tables if they do not exist. */
    function report_engine_install(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS custom_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) DEFAULT NULL,
            source VARCHAR(50) NOT NULL,
            config TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS report_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
            day_of_week TINYINT DEFAULT NULL,
            day_of_month TINYINT DEFAULT NULL,
            run_time TIME NOT NULL DEFAULT '08:00:00',
            recipients VARCHAR(1000) DEFAULT NULL,
            format VARCHAR(10) NOT NULL DEFAULT 'csv',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_run_at DATETIME DEFAULT NULL,
            next_run_at DATETIME DEFAULT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Full catalogue of report sources. Each source is a fixed, safe query
     * surface. Filters' SQL placeholders must match their array key.
     */
    function report_engine_sources(): array
    {
        return [
            'students' => [
                'label' => 'Students Directory',
                'icon' => 'fa-user-graduate',
                'roles' => ['super_admin', 'school_admin', 'principal', 'accountant'],
                'from' => "FROM users u
                    JOIN student_profiles sp ON u.id = sp.user_id
                    LEFT JOIN (SELECT student_id, MIN(class_id) AS class_id FROM student_classes WHERE status='active' GROUP BY student_id) sc ON u.id = sc.student_id
                    LEFT JOIN classes c ON sc.class_id = c.id",
                'base_where' => "u.role = 'student'",
                'columns' => [
                    'name' => ['label' => 'Student Name', 'select' => 'u.name'],
                    'student_id' => ['label' => 'Student ID', 'select' => 'sp.student_id'],
                    'email' => ['label' => 'Email', 'select' => 'u.email'],
                    'gender' => ['label' => 'Gender', 'select' => 'sp.gender'],
                    'class_name' => ['label' => 'Class', 'select' => 'c.name'],
                    'grade_level' => ['label' => 'Grade', 'select' => 'c.grade_level'],
                    'status' => ['label' => 'Status', 'select' => 'u.status'],
                    'admission_date' => ['label' => 'Admission Date', 'select' => 'sp.admission_date'],
                ],
                'filters' => [
                    'class_id' => ['label' => 'Class', 'type' => 'class', 'sql' => 'sc.class_id = :class_id'],
                    'gender' => ['label' => 'Gender', 'type' => 'select', 'options' => ['male' => 'Male', 'female' => 'Female'], 'sql' => 'sp.gender = :gender'],
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'sql' => 'u.status = :status'],
                ],
                'default_columns' => ['name', 'student_id', 'class_name', 'status'],
                'order' => 'u.name ASC',
            ],

            'attendance' => [
                'label' => 'Attendance Records',
                'icon' => 'fa-calendar-check',
                'roles' => ['super_admin', 'school_admin', 'principal'],
                'from' => "FROM attendance a
                    JOIN users u ON a.student_id = u.id
                    JOIN classes c ON a.class_id = c.id",
                'base_where' => "1=1",
                'columns' => [
                    'student_name' => ['label' => 'Student', 'select' => 'u.name'],
                    'class_name' => ['label' => 'Class', 'select' => 'c.name'],
                    'date' => ['label' => 'Date', 'select' => 'a.date'],
                    'status' => ['label' => 'Status', 'select' => 'a.status'],
                    'time_in' => ['label' => 'Time In', 'select' => 'a.time_in'],
                    'remarks' => ['label' => 'Remarks', 'select' => 'a.remarks'],
                ],
                'filters' => [
                    'class_id' => ['label' => 'Class', 'type' => 'class', 'sql' => 'a.class_id = :class_id'],
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late'], 'sql' => 'a.status = :status'],
                    'date_from' => ['label' => 'From Date', 'type' => 'date', 'sql' => 'a.date >= :date_from'],
                    'date_to' => ['label' => 'To Date', 'type' => 'date', 'sql' => 'a.date <= :date_to'],
                ],
                'default_columns' => ['student_name', 'class_name', 'date', 'status'],
                'order' => 'a.date DESC',
            ],

            'academic' => [
                'label' => 'Academic Records',
                'icon' => 'fa-graduation-cap',
                'roles' => ['super_admin', 'school_admin', 'principal'],
                'from' => "FROM student_academic_records sar
                    JOIN users u ON sar.student_id = u.id
                    JOIN subjects s ON sar.subject_id = s.id
                    LEFT JOIN classes c ON sar.class_id = c.id
                    JOIN academic_terms at ON sar.academic_term_id = at.id",
                'base_where' => "1=1",
                'columns' => [
                    'student_name' => ['label' => 'Student', 'select' => 'u.name'],
                    'class_name' => ['label' => 'Class', 'select' => 'c.name'],
                    'subject_name' => ['label' => 'Subject', 'select' => 's.name'],
                    'term_name' => ['label' => 'Term', 'select' => 'at.term_name'],
                    'total_score' => ['label' => 'Total Score', 'select' => 'sar.total_score'],
                    'grade' => ['label' => 'Grade', 'select' => 'sar.grade'],
                ],
                'filters' => [
                    'class_id' => ['label' => 'Class', 'type' => 'class', 'sql' => 'sar.class_id = :class_id'],
                    'year_id' => ['label' => 'Academic Year', 'type' => 'year', 'sql' => 'sar.academic_year_id = :year_id'],
                    'term_number' => ['label' => 'Term', 'type' => 'select', 'options' => ['1' => 'First Term', '2' => 'Second Term', '3' => 'Third Term'], 'sql' => 'at.term_number = :term_number'],
                ],
                'default_columns' => ['student_name', 'class_name', 'subject_name', 'total_score', 'grade'],
                'order' => 'u.name ASC, s.name ASC',
            ],

            'finance' => [
                'label' => 'Fee Invoices',
                'icon' => 'fa-file-invoice-dollar',
                'roles' => ['super_admin', 'school_admin', 'principal', 'accountant'],
                'from' => "FROM finance_invoices i
                    JOIN users u ON i.student_id = u.id",
                'base_where' => "i.status <> 'cancelled'",
                'columns' => [
                    'invoice_number' => ['label' => 'Invoice #', 'select' => 'i.invoice_number'],
                    'student_name' => ['label' => 'Student', 'select' => 'u.name'],
                    'total_amount' => ['label' => 'Total', 'select' => 'i.total_amount'],
                    'amount_paid' => ['label' => 'Paid', 'select' => 'i.amount_paid'],
                    'balance' => ['label' => 'Balance', 'select' => '(i.total_amount + i.penalty_amount - i.discount_amount - i.amount_paid)'],
                    'status' => ['label' => 'Status', 'select' => 'i.status'],
                    'due_date' => ['label' => 'Due Date', 'select' => 'i.due_date'],
                ],
                'filters' => [
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['pending' => 'Pending', 'partially_paid' => 'Partially Paid', 'paid' => 'Paid', 'overdue' => 'Overdue'], 'sql' => 'i.status = :status'],
                    'year_id' => ['label' => 'Academic Year', 'type' => 'year', 'sql' => 'i.academic_year_id = :year_id'],
                ],
                'default_columns' => ['invoice_number', 'student_name', 'total_amount', 'amount_paid', 'balance', 'status'],
                'order' => 'i.created_at DESC',
            ],

            'library' => [
                'label' => 'Library Loans',
                'icon' => 'fa-book',
                'roles' => ['super_admin', 'school_admin', 'principal', 'teacher', 'librarian'],
                'from' => "FROM book_loans bl
                    JOIN library_books b ON bl.book_id = b.id
                    JOIN users u ON bl.user_id = u.id",
                'base_where' => "1=1",
                'columns' => [
                    'title' => ['label' => 'Book Title', 'select' => 'b.title'],
                    'category' => ['label' => 'Category', 'select' => 'b.category'],
                    'borrower_name' => ['label' => 'Borrower', 'select' => 'u.name'],
                    'borrowed_date' => ['label' => 'Borrowed', 'select' => 'bl.borrowed_date'],
                    'due_date' => ['label' => 'Due', 'select' => 'bl.due_date'],
                    'returned_date' => ['label' => 'Returned', 'select' => 'bl.returned_date'],
                    'status' => ['label' => 'Status', 'select' => 'bl.status'],
                ],
                'filters' => [
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['borrowed' => 'Borrowed', 'returned' => 'Returned', 'overdue' => 'Overdue'], 'sql' => 'bl.status = :status'],
                    'date_from' => ['label' => 'Borrowed From', 'type' => 'date', 'sql' => 'bl.borrowed_date >= :date_from'],
                    'date_to' => ['label' => 'Borrowed To', 'type' => 'date', 'sql' => 'bl.borrowed_date <= :date_to'],
                ],
                'default_columns' => ['title', 'borrower_name', 'borrowed_date', 'due_date', 'status'],
                'order' => 'bl.borrowed_date DESC',
            ],
        ];
    }

    /** Sources available to a given role. */
    function report_engine_sources_for_role(string $role): array
    {
        $out = [];
        foreach (report_engine_sources() as $key => $def) {
            if (in_array($role, $def['roles'])) { $out[$key] = $def; }
        }
        return $out;
    }

    /** Can this role use this source? */
    function report_engine_can_use(string $role, string $source): bool
    {
        $sources = report_engine_sources();
        return isset($sources[$source]) && in_array($role, $sources[$source]['roles']);
    }

    /**
     * Build a safe, parameterised query from a validated config.
     * @return array{sql:string, params:array, keys:array, labels:array}
     */
    function report_engine_build(array $def, array $columns, array $filters): array
    {
        // Whitelist columns
        $selected = [];
        foreach ($columns as $ck) {
            if (isset($def['columns'][$ck])) { $selected[$ck] = $def['columns'][$ck]; }
        }
        if (empty($selected)) {
            foreach ($def['default_columns'] as $ck) {
                if (isset($def['columns'][$ck])) { $selected[$ck] = $def['columns'][$ck]; }
            }
        }

        $select_sql = [];
        foreach ($selected as $ck => $c) {
            $select_sql[] = $c['select'] . ' AS `' . $ck . '`';
        }

        $where = [$def['base_where']];
        $params = [];
        foreach ($filters as $fk => $val) {
            if ($val === '' || $val === null || !isset($def['filters'][$fk])) { continue; }
            $where[] = $def['filters'][$fk]['sql'];
            $params[':' . $fk] = $val;
        }

        $sql = 'SELECT ' . implode(', ', $select_sql) . ' ' . $def['from']
            . ' WHERE ' . implode(' AND ', $where);
        if (!empty($def['order'])) { $sql .= ' ORDER BY ' . $def['order']; }
        $sql .= ' LIMIT 2000';

        return [
            'sql' => $sql,
            'params' => $params,
            'keys' => array_keys($selected),
            'labels' => array_map(fn($c) => $c['label'], $selected),
        ];
    }

    /**
     * Run a saved/builder config and return labels + rows.
     * @return array{labels:array, keys:array, rows:array}
     */
    function report_engine_run(PDO $db, string $source, array $config): array
    {
        $sources = report_engine_sources();
        if (!isset($sources[$source])) {
            return ['labels' => [], 'keys' => [], 'rows' => []];
        }
        $def = $sources[$source];
        $columns = $config['columns'] ?? [];
        $filters = $config['filters'] ?? [];
        $built = report_engine_build($def, (array)$columns, (array)$filters);
        $stmt = $db->prepare($built['sql']);
        $stmt->execute($built['params']);
        return [
            'labels' => $built['labels'],
            'keys' => $built['keys'],
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** Compute the next run datetime for a schedule. */
    function report_engine_next_run(string $frequency, ?int $dow, ?int $dom, string $time): string
    {
        $now = new DateTime();
        [$h, $m] = array_pad(explode(':', $time), 2, '0');
        if ($frequency === 'daily') {
            $next = (clone $now)->setTime((int)$h, (int)$m, 0);
            if ($next <= $now) { $next->modify('+1 day'); }
        } elseif ($frequency === 'weekly') {
            $target = $dow ?? 1; // 0=Sun..6=Sat
            $next = (clone $now)->setTime((int)$h, (int)$m, 0);
            $cur = (int)$next->format('w');
            $delta = ($target - $cur + 7) % 7;
            if ($delta === 0 && $next <= $now) { $delta = 7; }
            $next->modify("+$delta day");
        } else { // monthly
            $target = max(1, min(28, $dom ?? 1));
            $next = (clone $now)->setTime((int)$h, (int)$m, 0)->setDate((int)$now->format('Y'), (int)$now->format('n'), $target);
            if ($next <= $now) { $next->modify('+1 month'); }
        }
        return $next->format('Y-m-d H:i:s');
    }

    /** Stream a result set to the browser as CSV and exit. */
    function report_engine_csv(string $filename, array $labels, array $keys, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array_values($labels));
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $k) { $line[] = $row[$k] ?? ''; }
            fputcsv($out, $line);
        }
        fclose($out);
        exit();
    }

    /** Render a result set as an HTML table body string (escaped). */
    function report_engine_render_table(array $labels, array $keys, array $rows): string
    {
        $html = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">';
        $html .= '<thead class="bg-gray-50 dark:bg-gray-750"><tr>';
        foreach ($labels as $label) {
            $html .= '<th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">' . htmlspecialchars($label) . '</th>';
        }
        $html .= '</tr></thead><tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">';
        if (empty($rows)) {
            $html .= '<tr><td colspan="' . max(1, count($labels)) . '" class="px-4 py-8 text-center text-gray-400">No rows match this report.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $html .= '<tr class="hover:bg-gray-50 dark:hover:bg-gray-750">';
                foreach ($keys as $k) {
                    $val = $row[$k];
                    $html .= '<td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">' . ($val === null || $val === '' ? '<span class="text-gray-300 dark:text-gray-600">—</span>' : htmlspecialchars((string)$val)) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}
