<?php
/**
 * Session 8 - customer and student lookup, used by walk-in prefill and the
 * customer history page. Everything is prefixed cust_. Read-only.
 * Depends on includes/orders.php (ord_* helpers) and includes/rentals.php.
 */

/** Student by exact student no. (or LRN), with level and course names, or null. */
function cust_find_student(string $student_no): ?array
{
    $student_no = clean_str($student_no);
    if ($student_no === '') {
        return null;
    }
    return ord_one(
        'SELECT s.student_id, s.student_no, s.full_name, s.education_level_id, s.course_id, s.year_level, s.created_at,
                el.name AS level_name, co.code AS course_code
           FROM students s
           LEFT JOIN education_levels el ON el.education_level_id = s.education_level_id
           LEFT JOIN courses co ON co.course_id = s.course_id
          WHERE s.student_no = ?',
        [$student_no]
    );
}

/** Students whose number or name contains the text (exact number first), at most $limit. */
function cust_search_students(string $q, int $limit = 10): array
{
    $q = clean_str($q);
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60);
    if ($q === '') {
        return [];
    }
    $like = '%' . addcslashes($q, '\\%_') . '%';
    return ord_all(
        'SELECT s.student_id, s.student_no, s.full_name, s.year_level, el.name AS level_name, co.code AS course_code,
                (SELECT COUNT(*) FROM orders o WHERE o.student_id = s.student_id) AS order_count
           FROM students s
           LEFT JOIN education_levels el ON el.education_level_id = s.education_level_id
           LEFT JOIN courses co ON co.course_id = s.course_id
          WHERE s.student_no = ? OR s.student_no LIKE ? OR s.full_name LIKE ?
          ORDER BY (s.student_no = ?) DESC, s.full_name
          LIMIT ' . max(1, min(50, $limit)),
        [$q, $like, $like, $q]
    );
}

/** The customer (person ordering) on the student's most recent order, or null. */
function cust_last_customer(int $student_id): ?array
{
    return ord_one(
        'SELECT c.full_name, c.contact_no, c.email, c.address, c.relationship
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
          WHERE o.student_id = ? ORDER BY o.created_at DESC, o.order_id DESC LIMIT 1',
        [$student_id]
    );
}

/** Orders of one student, newest first, with a one-line item summary. */
function cust_orders(int $student_id, int $limit = 100): array
{
    return ord_all(
        "SELECT o.order_id, o.order_ref, o.order_no, o.order_type, o.status, o.source, o.total_amount,
                o.created_at, o.deleted_at, c.full_name AS customer_name, c.contact_no,
                (SELECT GROUP_CONCAT(CONCAT(oi.item_name, ' (', oi.size_label, ') x', oi.quantity) ORDER BY oi.order_item_id SEPARATOR '; ')
                   FROM order_items oi WHERE oi.order_id = o.order_id) AS items_text
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
          WHERE o.student_id = ?
          ORDER BY o.created_at DESC, o.order_id DESC
          LIMIT " . max(1, min(500, $limit)),
        [$student_id]
    );
}

/** Distinct people who have ordered for this student (parents, guardians, the student). */
function cust_contacts(int $student_id): array
{
    return ord_all(
        'SELECT c.full_name, c.contact_no, c.email, c.relationship, COUNT(*) AS orders, MAX(o.created_at) AS last_order
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
          WHERE o.student_id = ?
          GROUP BY c.full_name, c.contact_no, c.email, c.relationship
          ORDER BY last_order DESC',
        [$student_id]
    );
}

/** Everything the history page shows for one student. */
function cust_history(int $student_id): array
{
    return [
        'orders'   => cust_orders($student_id),
        'rentals'  => rent_for_student($student_id),
        'contacts' => cust_contacts($student_id),
    ];
}
