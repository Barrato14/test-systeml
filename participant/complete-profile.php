<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role']) !== 'participant') {
    header("Location: ../login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course = $_POST['course'] ?? '';
    $section = $_POST['section'] ?? '';

    if (!empty($course) && !empty($section)) {
        // Update profile in Supabase
        $payload = [
            'course' => $course,
            'section' => $section
        ];
        
        $response = supabaseRequest("profiles?id=eq.{$userId}", 'PATCH', $payload);

        if ($response['code'] >= 200 && $response['code'] < 300) {
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Failed to update profile. Please try again.";
        }
    } else {
        $error = "Please select both your course and section.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Profile - EMIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center participant-page" style="min-height: 100vh;">
    <div class="card shadow-sm p-4" style="max-width: 450px; width: 100%;">
        <h3 class="mb-3 text-center">Complete Your Profile</h3>
        <p class="text-muted small text-center mb-4">Please select your course and section to continue to your dashboard.</p>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label for="course" class="form-label fw-bold">Course</label>
                <select name="course" id="course" class="form-select" required>
                    <option value="">Select Course</option>
                    <option value="ACT">ACT (Associate in Computer Technology)</option>
                    <option value="HM">HM (Hotel Management)</option>
                    <option value="CT">CT</option>
                </select>
            </div>

            <div class="mb-4">
                <label for="section" class="form-label fw-bold">Year & Section</label>
                <select name="section" id="section" class="form-select" required>
                    <option value="">Select Section</option>
                    <optgroup label="Year 1">
                        <option value="1A">1A</option>
                        <option value="1B">1B</option>
                        <option value="1C">1C</option>
                    </optgroup>
                    <optgroup label="Year 2">
                        <option value="2A">2A</option>
                        <option value="2B">2B</option>
                        <option value="2C">2C</option>
                    </optgroup>
                </select>
            </div>

            <button type="submit" class="btn btn-primary w-100">Save & Continue</button>
        </form>
    </div>
</body>
</html>