<?php
require_once __DIR__ . '/config/database.php';
$conn = getConnection();

$sql = file_get_contents(__DIR__ . '/setup.sql');
$queries = array_filter(array_map('trim', explode(';', $sql)));

$success = 0;
$errors = [];
foreach ($queries as $query) {
    if (empty($query)) continue;
    if ($conn->query($query)) {
        $success++;
    } else {
        $errors[] = $conn->error . ' | Query: ' . substr($query, 0, 80);
    }
}

echo '<h2>Setup Complete</h2>';
echo "<p>✅ $success queries ran successfully.</p>";
if ($errors) {
    echo '<h3>Errors:</h3><ul>';
    foreach ($errors as $e) echo "<li>$e</li>";
    echo '</ul>';
} else {
    echo '<p>🎉 All tables and sample data created! You can now delete this file.</p>';
}
?>
