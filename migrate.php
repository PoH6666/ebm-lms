<!DOCTYPE html>
<html>
<head>
    <title>EBM LMS — Database Migration</title>
    <style>
        body { font-family: monospace; background: #0d0d1a; color: #fff; padding: 40px; }
        h2   { color: #6c63ff; }
        .ok  { color: #4ecdc4; }
        .err { color: #ff6363; }
        .skip{ color: #ffce54; }
    </style>
</head>
<body>
<h2>EBM LMS — Database Migration</h2>
<p>Renaming <b>modules</b> to <b>subjects</b> and cleaning up columns...</p>
<hr style="border-color:#333; margin: 20px 0;">

<?php
// Suppress session/header warnings
error_reporting(E_ERROR);
ini_set('display_errors', 0);
ob_start();

define('DB_HOST', getenv('MYSQLHOST'));
define('DB_PORT', getenv('MYSQLPORT') ?: 3306);
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));
define('DB_NAME', getenv('MYSQLDATABASE'));

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($conn->connect_error) {
    ob_end_clean();
    die("<p class='err'>Connection failed: " . $conn->connect_error . "</p>");
}
ob_end_clean();

function runStep($conn, $sql, $label) {
    $result = $conn->query($sql);
    if ($result) {
        echo "<p class='ok'>✅ {$label}</p>";
        return 'ok';
    } else {
        $err = $conn->error;
        $skipKeywords = ["Duplicate", "Unknown column", "already exists",
                         "doesn't exist", "Can't DROP", "check that column",
                         "Table", "doesn't have"];
        foreach ($skipKeywords as $kw) {
            if (stripos($err, $kw) !== false) {
                echo "<p class='skip'>⚠️ Skipped (already done): {$label}</p>";
                return 'skip';
            }
        }
        echo "<p class='err'>❌ Failed: {$label} — {$err}</p>";
        return 'fail';
    }
}

$ok = $skip = $fail = 0;

$steps = [
    // Drop emoji and color columns
    ["ALTER TABLE modules DROP COLUMN emoji",        "Drop 'emoji' column from modules"],
    ["ALTER TABLE modules DROP COLUMN color",        "Drop 'color' column from modules"],

    // Rename table modules → subjects
    ["RENAME TABLE modules TO subjects",             "Rename table: modules → subjects"],

    // Rename title → subject_name
    ["ALTER TABLE subjects CHANGE COLUMN title subject_name VARCHAR(255) NOT NULL",
                                                     "Rename column: title → subject_name in subjects"],

    // enrollments: module_id → subject_id
    ["ALTER TABLE enrollments CHANGE COLUMN module_id subject_id INT NOT NULL",
                                                     "Rename column: module_id → subject_id in enrollments"],

    // lessons: module_id → subject_id
    ["ALTER TABLE lessons CHANGE COLUMN module_id subject_id INT NOT NULL",
                                                     "Rename column: module_id → subject_id in lessons"],

    // assignments: module_id → subject_id
    ["ALTER TABLE assignments CHANGE COLUMN module_id subject_id INT NOT NULL",
                                                     "Rename column: module_id → subject_id in assignments"],

    // lesson_progress: module_id → subject_id (may not exist)
    ["ALTER TABLE lesson_progress CHANGE COLUMN module_id subject_id INT NOT NULL",
                                                     "Rename column: module_id → subject_id in lesson_progress"],
];

foreach ($steps as [$sql, $label]) {
    $r = runStep($conn, $sql, $label);
    if ($r === 'ok')   $ok++;
    if ($r === 'skip') $skip++;
    if ($r === 'fail') $fail++;
}

echo "<hr style='border-color:#333;margin:20px 0;'>";
echo "<p><b>Done!</b> &nbsp; ✅ {$ok} succeeded &nbsp; ⚠️ {$skip} skipped &nbsp; ❌ {$fail} failed</p>";

if ($fail === 0) {
    echo "<p class='ok' style='font-size:1.1rem;'>🎉 Migration complete! Delete this file from GitHub when done.</p>";
} else {
    echo "<p class='err'>Some steps failed — check errors above.</p>";
}
$conn->close();
?>
</body>
</html>
