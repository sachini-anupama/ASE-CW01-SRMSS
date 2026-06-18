<?php
declare(strict_types=1);

// ── AUTH GUARD ────────────────────────────────────────────────────────────────
// Include this ONE LINE at the very top of index.php (after opening <?php):
//   require __DIR__ . '/auth_check.php';
//
// It will redirect to login.php if not logged in.
// After this file runs, you can use $currentUser anywhere in the page.
// ─────────────────────────────────────────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['srmss_user'])) {
    header('Location: login.php');
    exit;
}

// ── Logged-in user data ───────────────────────────────────────────────────────
$currentUser = $_SESSION['srmss_user'];
// $currentUser['id']    → UserID
// $currentUser['name']  → FullName  (e.g. "Kamal Perera")
// $currentUser['email'] → Email
// $currentUser['role']  → RoleName  (Admin / Supervisor / Driver / Clerk)

// Avatar initials (first letter of first + last name)
$nameParts = explode(' ', $currentUser['name']);
$avatarInitials = strtoupper(
    substr($nameParts[0], 0, 1) .
    (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : '')
);
