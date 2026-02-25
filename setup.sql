-- USERS TABLE
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    first_name  VARCHAR(50) NOT NULL,
    last_name   VARCHAR(50) NOT NULL,
    email       VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('student', 'teacher', 'admin') DEFAULT 'student',
    school_id   VARCHAR(20),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- MODULES TABLE
CREATE TABLE IF NOT EXISTS modules (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(100) NOT NULL,
    description TEXT,
    teacher_id  INT NOT NULL,
    emoji       VARCHAR(10) DEFAULT NULL,
    color       VARCHAR(20) DEFAULT 'blue',
    status      ENUM('active', 'inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ENROLLMENTS TABLE
CREATE TABLE IF NOT EXISTS enrollments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    module_id   INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (student_id, module_id)
);

-- LESSONS TABLE
CREATE TABLE IF NOT EXISTS lessons (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    module_id   INT NOT NULL,
    title       VARCHAR(150) NOT NULL,
    content     LONGTEXT,
    duration    INT DEFAULT 45,
    order_num   INT DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
);

-- LESSON PROGRESS TABLE
CREATE TABLE IF NOT EXISTS lesson_progress (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    lesson_id   INT NOT NULL,
    completed   TINYINT(1) DEFAULT 0,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id)  REFERENCES lessons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (student_id, lesson_id)
);

-- ASSIGNMENTS TABLE
CREATE TABLE IF NOT EXISTS assignments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    module_id   INT NOT NULL,
    title       VARCHAR(150) NOT NULL,
    description TEXT,
    due_date    DATETIME,
    max_score   INT DEFAULT 100,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
);

-- SUBMISSIONS TABLE
CREATE TABLE IF NOT EXISTS submissions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_id    INT NOT NULL,
    content       TEXT,
    file_path     VARCHAR(255),
    score         INT DEFAULT NULL,
    feedback      TEXT,
    submitted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id)    REFERENCES users(id) ON DELETE CASCADE
);

-- GRADES TABLE
CREATE TABLE IF NOT EXISTS grades (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    module_id   INT NOT NULL,
    score       DECIMAL(5,2),
    remarks     VARCHAR(100),
    graded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE CASCADE
);

-- ATTENDANCE TABLE
CREATE TABLE IF NOT EXISTS attendance (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    module_id   INT NOT NULL,
    date        DATE NOT NULL,
    status      ENUM('present', 'absent', 'late', 'excused') DEFAULT 'present',
    noted_by    INT,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id)  REFERENCES modules(id) ON DELETE CASCADE
);

-- ANNOUNCEMENTS TABLE
CREATE TABLE IF NOT EXISTS announcements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    message     TEXT NOT NULL,
    created_by  INT NOT NULL,
    target_role ENUM('all', 'student', 'teacher') DEFAULT 'all',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- =========================================
-- SAMPLE DATA
-- =========================================

-- Default Admin Account (password: admin123)
INSERT INTO users (first_name, last_name, email, password, role, school_id) VALUES
('Admin', 'EBM', 'admin@ebmlms.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'ADMIN-001');

-- Sample Teacher (password: teacher123)
INSERT INTO users (first_name, last_name, email, password, role, school_id) VALUES
('Maria', 'Santos', 'teacher@ebmlms.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'TCH-001');

-- Sample Student (password: student123)
INSERT INTO users (first_name, last_name, email, password, role, school_id) VALUES
('Juan', 'Dela Cruz', 'student@ebmlms.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', '2024-00123');

-- Sample Module
INSERT INTO modules (title, description, teacher_id, color) VALUES
('Mathematics 101', 'Covers algebra, geometry, and trigonometry fundamentals.', 2, 'blue'),
('Science & Technology', 'Explore science through experiments and modern technology.', 2, 'cyan'),
('Filipino Literature', 'A deep dive into Filipino literary works.', 2, 'gold');

-- Enroll student in all modules
INSERT INTO enrollments (student_id, module_id) VALUES (3, 1), (3, 2), (3, 3);