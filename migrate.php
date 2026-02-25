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
<p>Renaming <b>modules</b> → <b>subjects</b> and cleaning up columns...</p>
<hr style="border-color:#333; margin: 20px 0;">

<?php
require_once __DIR__ . '/config/database.php';
$conn = getConnection();

$steps = [

    // 1. Drop emoji & color columns from modules (if they exist)
    "ALTER TABLE modules DROP COLUMN IF EXISTS emoji" =>
        "Drop 'emoji' column from modules",

    "ALTER TABLE modules DROP COLUMN IF EXISTS color" =>
        "Drop 'color' column from modules",

    // 2. Drop subject_code if redundant (optional — keep if you want it)
    // "ALTER TABLE modules DROP COLUMN IF EXISTS subject_code" =>
    //     "Drop 'subject_code' column",

    // 3. Rename modules table to subjects
    "RENAME TABLE modules TO subjects" =>
        "Rename table: modules → subjects",

    // 4. Rename column: title → subject_name in subjects
    "ALTER TABLE subjects CHANGE COLUMN title subject_name VARCHAR(255) NOT NULL" =>
        "Rename column: title → subject_name in subjects",

    // 5. Update enrollments: module_id → subject_id
    "ALTER TABLE enrollments CHANGE COLUMN module_id subject_id INT NOT NULL" =>
        "Rename column: module_id → subject_id in enrollments",

    // 6. Update lessons: module_id → subject_id
    "ALTER TABLE lessons CHANGE COLUMN module_id subject_id INT NOT NULL" =>
        "Rename column: module_id → subject_id in lessons",

    // 7. Update assignments: module_id → subject_id
    "ALTER TABLE assignments CHANGE COLUMN module_id subject_id INT NOT NULL" =>
        "Rename column: module_id → subject_id in assignments",

    // 8. Update lesson_progress: module_id → subject_id (if exists)
    "ALTER TABLE lesson_progress CHANGE COLUMN module_id subject_id INT NOT NULL" =>
        "Rename column: module_id → subject_id in lesson_progress",
];

$success = 0;
$skipped = 0;
$failed  = 0;

foreach ($steps as $sql => $label) {
    $result = $conn->query($sql);
    if ($result) {
        echo "<p class='ok'>✅ {$label}</p>";
        $success++;
    } else {
        $err = $conn->error;
        // "Duplicate column", "Unknown column", "already exists" = safe to skip
        if (
            strpos($err, "Duplicate") !== false ||
            strpos($err, "Unknown column") !== false ||
            strpos($err, "already exists") !== false ||
            strpos($err, "doesn't exist") !== false ||
            strpos($err, "Can't DROP") !== false ||
            strpos($err, "check that column") !== false
        ) {
            echo "<p class='skip'>⚠️ Skipped (already done): {$label}</p>";
            $skipped++;
        } else {
            echo "<p class='err'>❌ Failed: {$label} — {$err}</p>";
            $failed++;
        }
    }
}

echo "<hr style='border-color:#333; margin:20px 0;'>";
echo "<p><b>Done!</b> ✅ {$success} succeeded &nbsp; ⚠️ {$skipped} skipped &nbsp; ❌ {$failed} failed</p>";

if ($failed === 0) {
    echo "<p class='ok' style='font-size:1.1rem;'>🎉 Migration complete! You can now delete this file from GitHub.</p>";
} else {
    echo "<p class='err'>Some steps failed. Check errors above.</p>";
}

$conn->close();
?>
</body>
</html>
