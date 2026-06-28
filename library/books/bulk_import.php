<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once '../../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Heal older tenant DBs missing newer library_books columns before importing.
ensureLibraryBooksColumns($db);

// ── Importable book fields (mirrors the library_books schema & create.php form) ──
$book_fields = [
    'title'            => ['label' => 'Book Title',            'required' => true],
    'author'           => ['label' => 'Author',               'required' => true],
    'isbn'             => ['label' => 'ISBN',                  'required' => false],
    'category'         => ['label' => 'Category',             'required' => false],
    'copies_available' => ['label' => 'Copies Available',     'required' => true],
    'total_copies'     => ['label' => 'Total Copies',         'required' => false],
    'publisher'        => ['label' => 'Publisher',            'required' => false],
    'publication_year' => ['label' => 'Publication Year',     'required' => false],
    'language'         => ['label' => 'Language',             'required' => false],
    'location'         => ['label' => 'Library Location',     'required' => false],
    'description'      => ['label' => 'Description',          'required' => false],
];

// Reference lists (same as the manual Add Book form).
$categories = [
    'Fiction', 'Non-Fiction', 'Science', 'Mathematics', 'History', 'Geography',
    'Literature', 'Biography', 'Reference', 'Textbook', 'Children', 'Young Adult',
    'Technology', 'Arts', 'Philosophy', 'Religion', 'Health', 'Sports', 'Other'
];
$languages = ['English', 'Spanish', 'French', 'German', 'Chinese', 'Arabic', 'Other'];

// ── Sample CSV template download ────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=library_books_import_template.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($book_fields));
    fputcsv($out, [
        'To Kill a Mockingbird', 'Harper Lee', '9780061120084', 'Fiction', '5', '5',
        'J. B. Lippincott & Co.', '1960', 'English', 'Section A, Shelf 3',
        'A classic novel of race and justice in the American South.',
    ]);
    fputcsv($out, [
        'A Brief History of Time', 'Stephen Hawking', '9780553380163', 'Science', '3', '3',
        'Bantam Books', '1988', 'English', 'Section C, Shelf 1',
        'An exploration of cosmology for the general reader.',
    ]);
    fclose($out);
    exit();
}

$success_count = 0;
$error_count = 0;
$errors = [];

// ── Handle file upload and import ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    csrf_require('bulk_import.php');
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload failed. Please try again.";
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = "Please upload a .csv file.";
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $error = "The file is too large. Maximum allowed size is 5 MB.";
    } elseif (($handle = fopen($file['tmp_name'], 'r')) === FALSE) {
        $error = "Could not read the uploaded file.";
    } else {
        $header = fgetcsv($handle);
        if ($header === false) {
            $error = "The CSV file is empty.";
            fclose($handle);
        } else {
            // Build a normalised column-name => index map (flexible column order).
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            $col = [];
            foreach ($header as $i => $h) {
                $key = strtolower(trim(str_replace([' ', '-'], '_', (string)$h)));
                if ($key !== '') $col[$key] = $i;
            }
            $get = function ($row, $key) use ($col) {
                if (!isset($col[$key])) return '';
                return trim((string)($row[$col[$key]] ?? ''));
            };

            $missing = [];
            foreach (['title', 'author'] as $req) {
                if (!isset($col[$req])) $missing[] = $req;
            }
            if (!empty($missing)) {
                $error = "The CSV is missing required columns: " . implode(', ', $missing)
                       . ". Download the template for the correct format.";
                fclose($handle);
            } else {
                $seen_isbn = [];
                $row_number = 1; // header is row 1

                while (($data = fgetcsv($handle)) !== FALSE) {
                    $row_number++;
                    // Skip completely blank lines.
                    if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                        continue;
                    }

                    $title  = $get($data, 'title');
                    $author = $get($data, 'author');
                    $isbn   = $get($data, 'isbn');
                    $category = $get($data, 'category');
                    $copies_available = $get($data, 'copies_available');
                    $total_copies = $get($data, 'total_copies');
                    $publisher = $get($data, 'publisher');
                    $publication_year = $get($data, 'publication_year');
                    $language = $get($data, 'language');
                    $location = $get($data, 'location');
                    $description = $get($data, 'description');

                    // ── Validation ──
                    if ($title === '' || $author === '') {
                        $errors[] = "Row $row_number: Title and author are required."; $error_count++; continue;
                    }
                    // Copies: default to 1 when blank, must be a non-negative integer.
                    if ($copies_available === '') {
                        $copies_available = 1;
                    } elseif (!ctype_digit($copies_available)) {
                        $errors[] = "Row $row_number: Copies available must be a whole number."; $error_count++; continue;
                    } else {
                        $copies_available = (int)$copies_available;
                    }
                    // Total copies defaults to copies available; never less than it.
                    if ($total_copies === '' || !ctype_digit($total_copies)) {
                        $total_copies = $copies_available;
                    } else {
                        $total_copies = max((int)$total_copies, $copies_available);
                    }
                    // Publication year: validate range if provided.
                    if ($publication_year !== '') {
                        if (!ctype_digit($publication_year) || (int)$publication_year < 1000 || (int)$publication_year > (int)date('Y')) {
                            $errors[] = "Row $row_number: Publication year '" . htmlspecialchars($publication_year) . "' is invalid."; $error_count++; continue;
                        }
                        $publication_year = (int)$publication_year;
                    } else {
                        $publication_year = null;
                    }
                    // Duplicate ISBN handling (within the file and against the DB).
                    if ($isbn !== '') {
                        if (isset($seen_isbn[$isbn])) {
                            $errors[] = "Row $row_number: Duplicate ISBN '$isbn' within the file — skipped."; $error_count++; continue;
                        }
                        $chk = $db->prepare("SELECT COUNT(*) FROM library_books WHERE isbn = :isbn");
                        $chk->execute([':isbn' => $isbn]);
                        if ($chk->fetchColumn() > 0) {
                            $errors[] = "Row $row_number: A book with ISBN '$isbn' already exists — skipped."; $error_count++; continue;
                        }
                    }

                    try {
                        $db->beginTransaction();
                        $stmt = $db->prepare("INSERT INTO library_books
                            (title, author, isbn, category, copies_available, total_copies,
                             description, publisher, publication_year, language, location)
                            VALUES
                            (:title, :author, :isbn, :category, :copies_available, :total_copies,
                             :description, :publisher, :publication_year, :language, :location)");
                        $stmt->execute([
                            ':title' => $title,
                            ':author' => $author,
                            ':isbn' => $isbn ?: null,
                            ':category' => $category ?: null,
                            ':copies_available' => $copies_available,
                            ':total_copies' => $total_copies,
                            ':description' => $description ?: null,
                            ':publisher' => $publisher ?: null,
                            ':publication_year' => $publication_year,
                            ':language' => $language ?: null,
                            ':location' => $location ?: null,
                        ]);
                        $db->commit();

                        if ($isbn !== '') $seen_isbn[$isbn] = true;
                        $success_count++;
                    } catch (PDOException $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        if ($e->getCode() == 23000) {
                            $errors[] = "Row $row_number: A book with ISBN '$isbn' already exists — skipped.";
                        } else {
                            $errors[] = "Row $row_number: Database error - " . $e->getMessage();
                        }
                        $error_count++;
                    }
                }
                fclose($handle);
            }
        }
    }
}

$title = "Bulk Import Books";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Library Management', 'url' => 'index.php'],
    ['title' => 'Bulk Import']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-5xl mx-auto">

                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-purple-600 via-blue-600 to-indigo-600 rounded-xl p-6 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Bulk Import Books</h1>
                                <p class="text-purple-100 text-lg">Add many books to the library at once from a CSV file</p>
                                <a href="index.php" class="inline-flex items-center mt-4 text-sm text-white hover:text-purple-200 transition">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Books Management
                                </a>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-import text-5xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <?php if ($success_count > 0 || $error_count > 0): ?>
                <div class="mb-6">
                    <?php if ($success_count > 0): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4">
                        <i class="fas fa-check-circle mr-2"></i>Successfully imported <?php echo $success_count; ?> book<?php echo $success_count === 1 ? '' : 's'; ?>.
                    </div>
                    <?php endif; ?>
                    <?php if ($error_count > 0): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-lg mb-4">
                        <p class="font-medium"><i class="fas fa-list-ul mr-2"></i><?php echo $error_count; ?> row<?php echo $error_count === 1 ? '' : 's'; ?> skipped:</p>
                        <ul class="mt-2 list-disc list-inside text-sm max-h-64 overflow-y-auto">
                            <?php foreach (array_slice($errors, 0, 50) as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($errors) > 50): ?>
                            <li>... and <?php echo count($errors) - 50; ?> more.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Instructions -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6 mb-6">
                    <h2 class="text-lg font-semibold text-blue-900 dark:text-blue-300 mb-3">Import Instructions</h2>
                    <div class="text-blue-800 dark:text-blue-300 space-y-2 text-sm">
                        <p>1. Download the CSV template below and fill in your books (one book per row).</p>
                        <p>2. The first row must be the header row using the exact column names shown below.</p>
                        <p>3. Required fields: <strong>title</strong> and <strong>author</strong>.</p>
                        <p>4. <strong>copies_available</strong> defaults to 1 when blank; <strong>total_copies</strong> defaults to copies available.</p>
                        <p>5. <strong>publication_year</strong> must be a 4-digit year no later than <?php echo date('Y'); ?>.</p>
                        <p>6. ISBNs are optional, but rows whose ISBN already exists (or repeats in the file) are skipped.</p>
                        <p><strong>Note:</strong> Cover images cannot be set during import — add them later by editing a book.</p>
                    </div>
                </div>

                <!-- Download Template -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Download Template</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">The template includes every importable column with two example rows.</p>
                    <a href="bulk_import.php?download=template" class="inline-flex items-center bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i>Download CSV Template
                    </a>
                </div>

                <!-- CSV Columns Reference -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">CSV Columns</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Columns marked <span class="text-red-600 font-semibold">*</span> are required. Order is flexible.</p>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <?php foreach ($book_fields as $key => $meta): ?>
                        <span class="px-2.5 py-1 rounded-md text-xs font-mono <?php echo $meta['required']
                            ? 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800'
                            : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'; ?>"
                            title="<?php echo htmlspecialchars($meta['label']); ?>">
                            <?php echo $key; ?><?php echo $meta['required'] ? ' *' : ''; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Suggested categories</p>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($categories as $cat): ?>
                                <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs"><?php echo $cat; ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Suggested languages</p>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($languages as $lang): ?>
                                <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs"><?php echo $lang; ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Upload CSV File</h2>
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label for="csv_file" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select CSV File</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                                class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Only CSV files are allowed. Maximum file size: 5MB</p>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="confirm" name="confirm" required class="mr-2">
                            <label for="confirm" class="text-sm text-gray-700 dark:text-gray-300">
                                I confirm that the data is accurate and I want to proceed with the import
                            </label>
                        </div>
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-upload mr-2"></i>Import Books
                        </button>
                    </form>
                </div>

                <!-- Sample Data Format -->
                <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Sample Data Format</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm whitespace-nowrap">
                            <thead>
                                <tr class="bg-gray-200 dark:bg-gray-700">
                                    <?php foreach (array_keys($book_fields) as $key): ?>
                                    <th class="px-3 py-2 text-left font-mono text-xs"><?php echo $key; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700 dark:text-gray-300">
                                <tr class="border-b dark:border-gray-700">
                                    <td class="px-3 py-2">To Kill a Mockingbird</td><td class="px-3 py-2">Harper Lee</td>
                                    <td class="px-3 py-2">9780061120084</td><td class="px-3 py-2">Fiction</td>
                                    <td class="px-3 py-2">5</td><td class="px-3 py-2">5</td>
                                    <td class="px-3 py-2">J. B. Lippincott</td><td class="px-3 py-2">1960</td>
                                    <td class="px-3 py-2">English</td><td class="px-3 py-2">Section A, Shelf 3</td>
                                    <td class="px-3 py-2">A classic novel.</td>
                                </tr>
                                <tr>
                                    <td class="px-3 py-2">A Brief History of Time</td><td class="px-3 py-2">Stephen Hawking</td>
                                    <td class="px-3 py-2">9780553380163</td><td class="px-3 py-2">Science</td>
                                    <td class="px-3 py-2">3</td><td class="px-3 py-2">3</td>
                                    <td class="px-3 py-2">Bantam Books</td><td class="px-3 py-2">1988</td>
                                    <td class="px-3 py-2">English</td><td class="px-3 py-2">Section C, Shelf 1</td>
                                    <td class="px-3 py-2">Cosmology for everyone.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
