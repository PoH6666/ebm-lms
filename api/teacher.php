<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
ob_start();

try {
    if (session_status() === PHP_SESSION_NONE) session_start();
} catch (Exception $e) {}

try {
    require_once __DIR__ . '/../config/database.php';
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status'=>'error','message'=>'Config load failed: '.$e->getMessage()]);
    exit();
}

ob_end_clean();
header('Content-Type: application/json');

function respond($status, $message, $data = null) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status'=>$status,'message'=>$message,'data'=>$data]);
    exit();
}

try {
    $conn = getConnection();
} catch (Exception $e) {
    respond('error', 'DB connection failed: ' . $e->getMessage());
}
if (!$conn) respond('error', 'DB connection returned null.');

// Auto-migrate silently
$cols = [
    "ALTER TABLE subjects ADD COLUMN subject_code VARCHAR(50) NOT NULL DEFAULT ''",
    "ALTER TABLE subjects ADD COLUMN description TEXT",
    "ALTER TABLE subjects ADD COLUMN teacher_id INT DEFAULT NULL",
    "ALTER TABLE subjects ADD COLUMN created_at DATETIME DEFAULT NULL",
    "ALTER TABLE lessons ADD COLUMN subject_id INT DEFAULT NULL",
    "ALTER TABLE lessons ADD COLUMN video_url VARCHAR(500) DEFAULT ''",
    "ALTER TABLE lessons ADD COLUMN duration INT DEFAULT 0",
    "ALTER TABLE lessons ADD COLUMN lesson_order INT DEFAULT 0",
    "ALTER TABLE lessons ADD COLUMN created_at DATETIME DEFAULT NULL",
    "ALTER TABLE assignments ADD COLUMN subject_id INT DEFAULT NULL",
    "ALTER TABLE assignments ADD COLUMN type VARCHAR(20) DEFAULT 'quiz'",
    "ALTER TABLE assignments ADD COLUMN time_limit INT DEFAULT 30",
    "ALTER TABLE assignments ADD COLUMN passing_score INT DEFAULT 75",
    "ALTER TABLE assignments ADD COLUMN instructions TEXT",
    "ALTER TABLE assignments ADD COLUMN exam_date DATE",
    "ALTER TABLE assignments ADD COLUMN total_points INT DEFAULT 0",
    "ALTER TABLE assignments ADD COLUMN questions LONGTEXT",
    "ALTER TABLE enrollments ADD COLUMN subject_id INT DEFAULT NULL",
    "ALTER TABLE enrollments ADD COLUMN enrolled_at DATETIME DEFAULT NULL",
];
foreach ($cols as $sql) { @$conn->query($sql); }

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
if (!$action) respond('error', 'No action. PHP is working! teacher_id received: '.($_POST['teacher_id']??'none'));

if ($action === 'create_subject') {
    $teacher_id   = intval($_POST['teacher_id'] ?? 0);
    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
    $description  = trim($_POST['description'] ?? '');
    if (!$teacher_id)   respond('error', 'Teacher ID required.');
    if (!$subject_name) respond('error', 'Subject name required.');
    if (!$subject_code) respond('error', 'Subject code required.');
    $chk = $conn->prepare("SELECT id FROM subjects WHERE subject_code=? AND teacher_id=?");
    if (!$chk) respond('error', 'Prepare failed: '.$conn->error);
    $chk->bind_param("si",$subject_code,$teacher_id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) respond('error','Subject code already exists.');
    $stmt = $conn->prepare("INSERT INTO subjects (subject_name,subject_code,description,teacher_id,created_at) VALUES (?,?,?,?,NOW())");
    if (!$stmt) respond('error','Prepare failed: '.$conn->error);
    $stmt->bind_param("sssi",$subject_name,$subject_code,$description,$teacher_id);
    if ($stmt->execute()) respond('success','Subject created!',['id'=>$conn->insert_id]);
    respond('error','Insert failed: '.$stmt->error);
}

if ($action === 'get_my_subjects') {
    $teacher_id = intval($_POST['teacher_id'] ?? $_GET['teacher_id'] ?? 0);
    if (!$teacher_id) respond('error','Teacher ID required.');
    $colCheck = $conn->query("SHOW COLUMNS FROM enrollments LIKE 'subject_id'");
    $hasEnrSubId = $colCheck && $colCheck->num_rows > 0;
    $joinEnr = $hasEnrSubId ? "LEFT JOIN enrollments e ON e.subject_id = s.id" : "";
    $countEnr = $hasEnrSubId ? "COUNT(DISTINCT e.id)" : "0";
    $colCheck2 = $conn->query("SHOW COLUMNS FROM lessons LIKE 'subject_id'");
    $hasLesSubId = $colCheck2 && $colCheck2->num_rows > 0;
    $joinLes = $hasLesSubId ? "LEFT JOIN lessons l ON l.subject_id = s.id" : "";
    $countLes = $hasLesSubId ? "COUNT(DISTINCT l.id)" : "0";
    $sql = "SELECT s.id, s.subject_name, s.subject_code, s.description,
            $countEnr AS student_count, $countLes AS lesson_count
            FROM subjects s $joinEnr $joinLes
            WHERE s.teacher_id=?
            GROUP BY s.id ORDER BY s.created_at DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) respond('error','Prepare failed: '.$conn->error);
    $stmt->bind_param("i",$teacher_id);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    respond('success','OK',$rows);
}

if ($action === 'create_lesson') {
    $teacher_id=$_POST['teacher_id']??0; $subject_id=$_POST['subject_id']??0;
    $title=trim($_POST['title']??''); $content=trim($_POST['content']??'');
    $video_url=trim($_POST['video_url']??''); $duration=intval($_POST['duration']??0);
    $lesson_order=intval($_POST['lesson_order']??1);
    if (!$teacher_id||!$subject_id||!$title) respond('error','Teacher, subject, title required.');
    $own=$conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $own->bind_param("ii",$subject_id,$teacher_id); $own->execute();
    if ($own->get_result()->num_rows===0) respond('error','You do not own this subject.');
    $stmt=$conn->prepare("INSERT INTO lessons (subject_id,title,content,video_url,duration,lesson_order,created_at) VALUES (?,?,?,?,?,?,NOW())");
    if (!$stmt) respond('error','Prepare failed: '.$conn->error);
    $stmt->bind_param("isssii",$subject_id,$title,$content,$video_url,$duration,$lesson_order);
    if ($stmt->execute()) respond('success','Lesson created!',['id'=>$conn->insert_id]);
    respond('error','Failed: '.$stmt->error);
}

if ($action === 'create_quiz') {
    $teacher_id=intval($_POST['teacher_id']??0); $subject_id=intval($_POST['subject_id']??0);
    $title=trim($_POST['title']??''); $instructions=trim($_POST['instructions']??'');
    $time_limit=intval($_POST['time_limit']??30); $passing_score=intval($_POST['passing_score']??75);
    $questions=$_POST['questions']??'[]';
    if (!$teacher_id||!$subject_id||!$title) respond('error','Required fields missing.');
    $own=$conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $own->bind_param("ii",$subject_id,$teacher_id); $own->execute();
    if ($own->get_result()->num_rows===0) respond('error','You do not own this subject.');
    $type='quiz';
    $stmt=$conn->prepare("INSERT INTO assignments (subject_id,title,instructions,type,time_limit,passing_score,questions,created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    if (!$stmt) respond('error','Prepare failed: '.$conn->error);
    $stmt->bind_param("isssiis",$subject_id,$title,$instructions,$type,$time_limit,$passing_score,$questions);
    if ($stmt->execute()) respond('success','Quiz created!',['id'=>$conn->insert_id]);
    respond('error','Failed: '.$stmt->error);
}

if ($action === 'create_exam') {
    $teacher_id=intval($_POST['teacher_id']??0); $subject_id=intval($_POST['subject_id']??0);
    $title=trim($_POST['title']??''); $instructions=trim($_POST['instructions']??'');
    $time_limit=intval($_POST['time_limit']??60); $passing_score=intval($_POST['passing_score']??75);
    $exam_date=trim($_POST['exam_date']??''); $type=trim($_POST['exam_type']??'midterm');
    $questions=$_POST['questions']??'[]';
    if (!$teacher_id||!$subject_id||!$title) respond('error','Required fields missing.');
    $own=$conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $own->bind_param("ii",$subject_id,$teacher_id); $own->execute();
    if ($own->get_result()->num_rows===0) respond('error','You do not own this subject.');
    $stmt=$conn->prepare("INSERT INTO assignments (subject_id,title,instructions,type,time_limit,passing_score,exam_date,questions,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
    if (!$stmt) respond('error','Prepare failed: '.$conn->error);
    $stmt->bind_param("isssiiis",$subject_id,$title,$instructions,$type,$time_limit,$passing_score,$exam_date,$questions);
    if ($stmt->execute()) respond('success','Exam created!',['id'=>$conn->insert_id]);
    respond('error','Failed: '.$stmt->error);
}

if ($action === 'enroll_student') {
    $teacher_id=intval($_POST['teacher_id']??0); $subject_id=intval($_POST['subject_id']??0);
    $email=trim($_POST['student_email']??'');
    if (!$teacher_id||!$subject_id||!$email) respond('error','All fields required.');
    $own=$conn->prepare("SELECT id FROM subjects WHERE id=? AND teacher_id=?");
    $own->bind_param("ii",$subject_id,$teacher_id); $own->execute();
    if ($own->get_result()->num_rows===0) respond('error','You do not own this subject.');
    $usr=$conn->prepare("SELECT id,first_name,last_name FROM users WHERE email=? AND role='student'");
    $usr->bind_param("s",$email); $usr->execute();
    $student=$usr->get_result()->fetch_assoc();
    if (!$student) respond('error','No student found with that email.');
    $dup=$conn->prepare("SELECT id FROM enrollments WHERE student_id=? AND subject_id=?");
    $dup->bind_param("ii",$student['id'],$subject_id); $dup->execute();
    if ($dup->get_result()->num_rows>0) respond('error','Student already enrolled.');
    $enr=$conn->prepare("INSERT INTO enrollments (student_id,subject_id,enrolled_at) VALUES (?,?,NOW())");
    $enr->bind_param("ii",$student['id'],$subject_id);
    if ($enr->execute()) respond('success',"Enrolled {$student['first_name']} {$student['last_name']} successfully!");
    respond('error','Failed: '.$enr->error);
}

if ($action === 'get_enrolled_students') {
    $subject_id=intval($_POST['subject_id']??$_GET['subject_id']??0);
    if (!$subject_id) respond('error','Subject ID required.');
    $stmt=$conn->prepare("SELECT u.id,u.first_name,u.last_name,u.email,u.school_id,e.enrolled_at FROM enrollments e JOIN users u ON u.id=e.student_id WHERE e.subject_id=? ORDER BY u.last_name ASC");
    if (!$stmt) respond('error','Prepare failed: '.$conn->error);
    $stmt->bind_param("i",$subject_id); $stmt->execute();
    $rows=[]; $res=$stmt->get_result();
    while ($row=$res->fetch_assoc()) $rows[]=$row;
    respond('success','OK',$rows);
}

respond('error','Unknown action: '.htmlspecialchars($action));
