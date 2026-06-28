<?php
/**
 * exam_access.php
 * Shared helpers for teacher-scoped access to exams.
 * A teacher "owns" an exam only when they teach that exam's class AND subject
 * (i.e. a matching row exists in class_teachers).
 */

if (!function_exists('teacherTeachesClassSubject')) {
    /** True if the teacher teaches the given class + subject combination. */
    function teacherTeachesClassSubject($db, $teacher_id, $class_id, $subject_id) {
        $stmt = $db->prepare("SELECT 1 FROM class_teachers
                              WHERE teacher_id = :tid AND class_id = :cid AND subject_id = :sid LIMIT 1");
        $stmt->execute([':tid' => $teacher_id, ':cid' => $class_id, ':sid' => $subject_id]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('teacherOwnsExam')) {
    /** True if the teacher teaches the class + subject of the given exam id. */
    function teacherOwnsExam($db, $teacher_id, $exam_id) {
        $stmt = $db->prepare("SELECT 1 FROM exams e
                              JOIN class_teachers ct ON ct.class_id = e.class_id AND ct.subject_id = e.subject_id
                              WHERE e.id = :eid AND ct.teacher_id = :tid LIMIT 1");
        $stmt->execute([':eid' => $exam_id, ':tid' => $teacher_id]);
        return (bool)$stmt->fetchColumn();
    }
}
