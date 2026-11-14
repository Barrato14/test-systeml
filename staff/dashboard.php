<?php
// Backward-compatible entry point for Staff dashboard URLs.
// The shared dashboard enforces the user's role and permissions.
header('Location: ../admin/dashboard.php');
exit;
