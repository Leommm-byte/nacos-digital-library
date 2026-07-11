<?php
require_once __DIR__ . '/../includes/db.php';

// secure_session_start() is called in db.php via helpers/session.php
secure_session_start();

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Phase 5: CSRF Verification
    csrf_verify();

    $fullname       = trim($_POST['fullname']);
    $matric_number  = strtoupper(trim($_POST['matric_number']));
    $department     = trim($_POST['department']);
    $level          = $_POST['level'];
    $programme      = $_POST['programme'];
    $password       = $_POST['password'];
    $confirm_pw     = $_POST['confirm_password'];

    // Device fingerprint (injected by client-side JS). SHA-256 hex string expected.
    $device_fp = isset($_POST['device_fp']) ? trim($_POST['device_fp']) : '';

    if (strlen($fullname) < 3) {
        $errors[] = "Full name must be at least 3 characters.";
    }

    // Phase 2: Matric Number Validation
    if (!validate_matric_number($matric_number)) {
        $errors[] = "Invalid Matric Number format. Expected format: ND/2023/CS/1234 or HND/2023/CS/1234";
    }

    if ($password !== $confirm_pw) {
        $errors[] = "Passwords do not match.";
    }

    // Phase 3: Password Complexity
    if (!is_password_strong($password)) {
        $errors[] = "Password must be 8-12 characters long and include uppercase, lowercase, number, and special character.";
    }

    // Validate device fingerprint format if present
    if ($device_fp !== '' && !preg_match('/^[0-9a-f]{64}$/i', $device_fp)) {
        $errors[] = "Unable to verify device fingerprint.";
    }

    if (empty($errors)) {

        // Soft device restriction: do not allow multiple accounts from same device_fp
        if ($device_fp !== '') {
            $check = $conn->prepare("SELECT id FROM users WHERE device_fp = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $device_fp);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $errors[] = "An account has already been created from this device.";
                }
                $check->close();
            }
        }

        if (empty($errors)) {
            $uuid = bin2hex(random_bytes(16));

            $password_hash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "INSERT INTO users 
            (uuid, fullname, matric_number, department, level, programme, password_hash, device_fp) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param(
                    "ssssssss",
                    $uuid,
                    $fullname,
                    $matric_number,
                    $department,
                    $level,
                    $programme,
                    $password_hash,
                    $device_fp
                );

                if ($stmt->execute()) {
                    $success = "Account created successfully. <a href='" . $BASE_URL . "auth/login.php'>Login Now</a>";
                    // Phase 13: Audit Logging
                    log_audit($conn, "user_registration", "users", $conn->insert_id, ["matric_number" => $matric_number]);
                } else {
                    if ($stmt->errno == 1062) {
                        $errors[] = "Matric Number already exists.";
                    } else {
                        $errors[] = "Database error: " . $stmt->error;
                    }
                }

                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create an account on NACOS Digital Library to access course materials, books and student resources.">
    <meta name="author" content="NACOS Digital Library">
    <meta name="robots" content="index,follow">
    <meta name="theme-color" content="#0a74da">
    <meta property="og:title" content="NACOS Digital Library — Sign Up">
    <meta property="og:description" content="Register for the NACOS Digital Library to browse and read protected resources for students.">
    <meta property="og:image" content="<?= $BASE_URL ?>assets/images/YCT_LOGO.png">
    <meta name="twitter:card" content="summary_large_image">
    <title>NACOS Digital Library | Signup</title>
    <link rel="stylesheet" href="<?= $BASE_URL ?>assets/css/auth.css">
    <link rel="icon" href="<?= $BASE_URL ?>assets/images/NACOS_LOGO.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
</head>

<body>

    <div class="auth-wrapper">
        <div class="auth-box">

            <img src="<?= $BASE_URL ?>assets/images/NACOS_LOGO.png" alt="NACOS Logo" class="auth-logo">

            <h2>Create Account</h2>

            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <?php foreach ($errors as $e) echo "<p>" . safe_output($e) . "</p>"; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert success">
                    <?= $success // Link is safe as it's hardcoded ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="signupForm">
                <!-- Phase 5: CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <input type="text" name="fullname" placeholder="Full Name" value="<?= isset($_POST['fullname']) ? safe_output($_POST['fullname']) : '' ?>" required>

                <input type="text" name="matric_number" placeholder="Matric Number (e.g. ND/2023/CS/1234)" value="<?= isset($_POST['matric_number']) ? safe_output($_POST['matric_number']) : '' ?>" required>

                <select name="department" required>
                    <option value="">Select Department</option>
                    <option value="computer-science" <?= isset($_POST['department']) && $_POST['department'] == 'computer-science' ? 'selected' : '' ?>>Computer Science</option>
                </select>

                <select name="level" required>
                    <option value="">Select Level</option>
                    <option value="ND1" <?= isset($_POST['level']) && $_POST['level'] == 'ND1' ? 'selected' : '' ?>>ND1</option>
                    <option value="ND2" <?= isset($_POST['level']) && $_POST['level'] == 'ND2' ? 'selected' : '' ?>>ND2</option>
                    <option value="ND3" <?= isset($_POST['level']) && $_POST['level'] == 'ND3' ? 'selected' : '' ?>>ND3</option>
                    <option value="HND1" <?= isset($_POST['level']) && $_POST['level'] == 'HND1' ? 'selected' : '' ?>>HND1</option>
                    <option value="HND2" <?= isset($_POST['level']) && $_POST['level'] == 'HND2' ? 'selected' : '' ?>>HND2</option>
                    <option value="HND3" <?= isset($_POST['level']) && $_POST['level'] == 'HND3' ? 'selected' : '' ?>>HND3</option>
                </select>

                <select name="programme" required>
                    <option value="">Select Programme</option>
                    <option value="Full-time" <?= isset($_POST['programme']) && $_POST['programme'] == 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                    <option value="Part-time" <?= isset($_POST['programme']) && $_POST['programme'] == 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                    <option value="CODFEL" <?= isset($_POST['programme']) && $_POST['programme'] == 'CODFEL' ? 'selected' : '' ?>>CODFEL</option>
                </select>

                <div class="password-wrapper">
                    <input type="password" name="password" id="password" placeholder="Password" required>
                    <i class="ri-eye-line toggle-eye" data-target="password"></i>
                </div>

                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                    <i class="ri-eye-line toggle-eye" data-target="confirm_password"></i>
                </div>

                <button type="submit">Sign Up</button>

            </form>

            <p class="switch-link">
                Already have an account? <a href="<?= $BASE_URL ?>auth/login.php">Login</a>
            </p>

        </div>
    </div>

    <script src="<?= $BASE_URL ?>assets/js/auth.js"></script>
</body>

</html>