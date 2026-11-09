<?php
// one buyer: tries to reserve $argv[1] of variant $argv[2]; prints OK/FAIL
require __DIR__ . '/bootstrap_stub.php';
[$_, $qty, $vid, $n] = $argv;
$r = res_submit([(int)$vid => (int)$qty], [
  'full_name' => "Racer $n", 'relationship' => 'self', 'student_no' => "RACE-$n", 'student_name' => '',
  'contact_no' => sprintf('0917222%04d', $n), 'email' => '', 'education_level_id' => 0, 'course_id' => 0,
  'year_level' => 'Grade 1', 'address' => '', 'notes' => '']);
echo $r['ok'] ? "OK {$r['ref']}\n" : 'FAIL ' . implode('|', $r['problems']) . ($r['error'] ?? '') . "\n";
