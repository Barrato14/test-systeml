<?php
session_start();
require_once 'config/database.php';

if (empty($_SESSION['user_id']) || strtolower((string)($_SESSION['user_role'] ?? '')) !== 'participant') {
    header('Location: login.php');
    exit;
}

$error = '';
$profileId = (string)$_SESSION['user_id'];
$email = (string)($_SESSION['user_email'] ?? '');
$csrfToken = (string)($_SESSION['google_profile_csrf'] ?? '');

if ($csrfToken === '') {
    $_SESSION['google_profile_csrf'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['google_profile_csrf'];
}

$profileResponse = supabaseRequest('profiles?id=eq.' . urlencode($profileId) . '&select=first_name,last_name,course,section,email', 'GET');
$profileRows = $profileResponse['data'] ?? [];
$profile = is_array($profileRows) && isset($profileRows[0]) ? $profileRows[0] : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $course = trim((string)($_POST['course'] ?? ''));
    $section = trim((string)($_POST['section'] ?? ''));

    if ($csrfToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Your session expired. Please refresh this page and try again.';
    } elseif ($firstName === '' || $lastName === '') {
        $error = 'Please enter your real first name and last name.';
    } elseif (!in_array($course, ['ACT', 'HM', 'CT'], true)) {
        $error = 'Please select a valid course.';
    } elseif (!in_array($section, ['1A', '1B', '1C', '2A', '2B', '2C'], true)) {
        $error = 'Please select a valid year and section.';
    } else {
        $existingProfileResponse = supabaseServiceRequest(
            'profiles?id=eq.' . urlencode($profileId) . '&select=id',
            'GET'
        );
        $existingProfiles = $existingProfileResponse['data'] ?? [];
        $profileExists = is_array($existingProfiles) && !empty($existingProfiles);

        $studentProfilePayload = [
            'id' => $profileId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'course' => $course,
            'section' => $section,
            'role' => 'Participant',
            'is_verified' => true,
            'verification_token' => null
        ];

        if ($profileExists) {
            unset($studentProfilePayload['id'], $studentProfilePayload['email'], $studentProfilePayload['role']);
            $updateResponse = supabaseServiceRequest(
                'profiles?id=eq.' . urlencode($profileId),
                'PATCH',
                $studentProfilePayload
            );
        } else {
            $updateResponse = supabaseServiceRequest('profiles', 'POST', $studentProfilePayload);
        }

        if (($updateResponse['code'] ?? 500) >= 300) {
            error_log('Google student profile save failed: ' . json_encode($updateResponse['data'] ?? []));
            $error = 'We could not save your student details. Please check the profile settings or contact the administrator.';
        } else {
            $_SESSION['user_name'] = $firstName . ' ' . $lastName;
            unset($_SESSION['google_profile_csrf']);
            header('Location: participant/dashboard.php');
            exit;
        }
    }

    $profile = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'course' => $course,
        'section' => $section,
        'email' => $email
    ];
}

$displayEmail = $profile['email'] ?? $email;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Student Profile - EMIS</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0B1020">
</head>
<body class="auth-register-page">
    <main class="auth-layout">
        <section class="auth-showcase">
            <div class="showcase-content">
                <div class="brand-logo"><i class="ph-fill ph-ticket"></i><span>Eventora</span></div>
                <div class="showcase-text">
                    <h1>One more step before you get started.</h1>
                    <p>Use your real student information so your event registrations and attendance records show the correct identity.</p>
                </div>
            </div>
        </section>
        <section class="auth-form-wrapper auth-register-wrapper">
            <div class="mobile-logo"><i class="ph-fill ph-ticket"></i><span>Eventora</span></div>
            <div class="auth-form-container auth-register-container">
                <header class="form-header">
                    <h2>Complete your student profile</h2>
                    <p>Your Google account is connected. Now enter your real name and course details.</p>
                </header>
                <?php if ($error): ?>
                    <div class="alert-error" role="alert"><i class="ph ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
                <?php endif; ?>
                <form method="POST" action="complete-google-profile.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="input-group">
                        <label for="first_name">Real First Name</label>
                        <div class="input-wrapper"><i class="ph ph-user input-icon"></i><input type="text" id="first_name" name="first_name" placeholder="Enter your real first name" autocomplete="given-name" maxlength="100" required value="<?= htmlspecialchars($profile['first_name'] ?? '') ?>"></div>
                    </div>
                    <div class="input-group">
                        <label for="last_name">Real Last Name</label>
                        <div class="input-wrapper"><i class="ph ph-user input-icon"></i><input type="text" id="last_name" name="last_name" placeholder="Enter your real last name" autocomplete="family-name" maxlength="100" required value="<?= htmlspecialchars($profile['last_name'] ?? '') ?>"></div>
                    </div>
                    <div class="input-group">
                        <label for="course">Course</label>
                        <div class="input-wrapper"><i class="ph ph-graduation-cap input-icon"></i>
                            <select id="course" name="course" required>
                                <option value="">Select your course</option>
                                <option value="ACT" <?= (($profile['course'] ?? '') === 'ACT') ? 'selected' : '' ?>>ACT (Associate in Computer Technology)</option>
                                <option value="HM" <?= (($profile['course'] ?? '') === 'HM') ? 'selected' : '' ?>>HM (Hotel Management)</option>
                                <option value="CT" <?= (($profile['course'] ?? '') === 'CT') ? 'selected' : '' ?>>CT</option>
                            </select>
                        </div>
                    </div>
                    <div class="input-group">
                        <label for="section">Year &amp; Section</label>
                        <div class="input-wrapper"><i class="ph ph-users-three input-icon"></i>
                            <select id="section" name="section" required>
                                <option value="">Select your year and section</option>
                                <?php foreach (['1A', '1B', '1C', '2A', '2B', '2C'] as $sectionOption): ?>
                                    <option value="<?= $sectionOption ?>" <?= (($profile['section'] ?? '') === $sectionOption) ? 'selected' : '' ?>><?= $sectionOption ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="input-group">
                        <label for="email">Google Account Email</label>
                        <div class="input-wrapper"><i class="ph ph-envelope-simple input-icon"></i><input type="email" id="email" value="<?= htmlspecialchars($displayEmail) ?>" readonly></div>
                    </div>
                    <button type="submit" class="btn-primary auth-register-submit"><span class="btn-text">Save Student Details</span><i class="ph-bold ph-arrow-right"></i></button>
                </form>
                <p class="register-prompt">Your Google account is used for sign-in. Your student name above is the name EMIS will display.</p>
            </div>
            <footer class="auth-footer"><p>&copy; <?= date('Y') ?> Eventora. All rights reserved.</p></footer>
        </section>
    </main>
</body>
</html>
