<?php
/**
 * Database Migration Script
 */

require_once 'config/db.php';

header('Content-Type: text/plain; charset=utf-8');

$db = get_db_connection();

// 1. Add session_type to appointments table
try {
    $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'session_type'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE appointments ADD COLUMN session_type ENUM('video', 'chat', 'group') NOT NULL DEFAULT 'video'");
        echo "Added 'session_type' column to 'appointments' table.\n";
    } else {
        echo "'session_type' column already exists in 'appointments' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'appointments' table: " . $e->getMessage() . "\n";
}

// 2. Add group_recommended to students_profile table
try {
    $stmt = $db->query("SHOW COLUMNS FROM students_profile LIKE 'group_recommended'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE students_profile ADD COLUMN group_recommended TINYINT(1) DEFAULT 0");
        echo "Added 'group_recommended' column to 'students_profile' table.\n";
    } else {
        echo "'group_recommended' column already exists in 'students_profile' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'students_profile' table: " . $e->getMessage() . "\n";
}

// 3. Add profile_picture to users table
try {
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER role");
        echo "Added 'profile_picture' column to 'users' table.\n";
    } else {
        echo "'profile_picture' column already exists in 'users' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'users' table: " . $e->getMessage() . "\n";
}

// 4. Create uploads/avatars directory
$uploadsDir = __DIR__ . '/uploads/avatars';
if (!is_dir($uploadsDir)) {
    if (mkdir($uploadsDir, 0755, true)) {
        echo "Created 'uploads/avatars/' directory.\n";
    } else {
        echo "Failed to create 'uploads/avatars/' directory.\n";
    }
} else {
    echo "'uploads/avatars/' directory already exists.\n";
}

// 5. Add assessment_type to self_assessments table
try {
    $stmt = $db->query("SHOW COLUMNS FROM self_assessments LIKE 'assessment_type'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE self_assessments ADD COLUMN assessment_type VARCHAR(50) NOT NULL DEFAULT 'anxiety' AFTER student_id");
        echo "Added 'assessment_type' column to 'self_assessments' table.\n";
    } else {
        echo "'assessment_type' column already exists in 'self_assessments' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'self_assessments' table: " . $e->getMessage() . "\n";
}

// 6. Add university to students_profile table
try {
    $stmt = $db->query("SHOW COLUMNS FROM students_profile LIKE 'university'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE students_profile ADD COLUMN university VARCHAR(150) DEFAULT NULL AFTER name");
        echo "Added 'university' column to 'students_profile' table.\n";
    } else {
        echo "'university' column already exists in 'students_profile' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'students_profile' table: " . $e->getMessage() . "\n";
}

// 7. Add university to admin table
try {
    $stmt = $db->query("SHOW COLUMNS FROM admin LIKE 'university'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE admin ADD COLUMN university VARCHAR(150) DEFAULT NULL AFTER name");
        echo "Added 'university' column to 'admin' table.\n";
    } else {
        echo "'university' column already exists in 'admin' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'admin' table: " . $e->getMessage() . "\n";
}

// 8. Add details to self_assessments table
try {
    $stmt = $db->query("SHOW COLUMNS FROM self_assessments LIKE 'details'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE self_assessments ADD COLUMN details JSON DEFAULT NULL AFTER category");
        echo "Added 'details' column to 'self_assessments' table.\n";
    } else {
        echo "'details' column already exists in 'self_assessments' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'self_assessments' table: " . $e->getMessage() . "\n";
}

// 9. Update appointments table status enum
try {
    $db->exec("ALTER TABLE appointments MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'completed', 'missing') DEFAULT 'pending'");
    echo "Updated 'status' column in 'appointments' table.\n";
} catch (Exception $e) {
    echo "Error updating status column in 'appointments' table: " . $e->getMessage() . "\n";
}

// 10. Add session_started to appointments table
try {
    $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'session_started'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE appointments ADD COLUMN session_started TINYINT(1) DEFAULT 0 AFTER session_type");
        echo "Added 'session_started' column to 'appointments' table.\n";
    } else {
        echo "'session_started' column already exists in 'appointments' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'appointments' table: " . $e->getMessage() . "\n";
}

// 11. Add started_at to appointments table
try {
    $stmt = $db->query("SHOW COLUMNS FROM appointments LIKE 'started_at'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE appointments ADD COLUMN started_at DATETIME DEFAULT NULL AFTER session_started");
        echo "Added 'started_at' column to 'appointments' table.\n";
    } else {
        echo "'started_at' column already exists in 'appointments' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'appointments' table: " . $e->getMessage() . "\n";
}

// 12. Add discussion_choice to students_profile table
try {
    $stmt = $db->query("SHOW COLUMNS FROM students_profile LIKE 'discussion_choice'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE students_profile ADD COLUMN discussion_choice ENUM('video', 'chat', 'none') DEFAULT 'none' AFTER group_recommended");
        echo "Added 'discussion_choice' column to 'students_profile' table.\n";
    } else {
        echo "'discussion_choice' column already exists in 'students_profile' table.\n";
    }
} catch (Exception $e) {
    echo "Error updating 'students_profile' table: " . $e->getMessage() . "\n";
}

echo "Migration script execution completed.\n";
