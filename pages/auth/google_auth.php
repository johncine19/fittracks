<?php

declare(strict_types=1);

function handle_google_gym_auth(): void
{
    handle_google_auth('gym_owner');
}

function handle_google_auth(?string $requiredRole = null): void
{
    $entryPage = $requiredRole === 'gym_owner' ? 'gym_register' : 'login';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
        flash('Invalid Google authentication request.', 'danger');
        redirect($entryPage);
    }

    $idToken = trim((string) $_POST['credential']);
    $expectedClientId = (string) app_env('GOOGLE_CLIENT_ID', '');

    if (empty($expectedClientId)) {
        flash('Google authentication is not properly configured on this server.', 'danger');
        redirect($entryPage);
    }

    // Call Google's tokeninfo API to verify token validity and signature
    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $verifyUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('Google Auth verification failed. HTTP: ' . $httpCode . ' Error: ' . $curlError . ' Response: ' . (string) $response);
        flash('Failed to verify Google account credentials. Please try again.', 'danger');
        redirect($entryPage);
    }

    $payload = json_decode((string) $response, true);
    if (!is_array($payload) || empty($payload['sub']) || empty($payload['email'])) {
        flash('Invalid account payload received from Google.', 'danger');
        redirect($entryPage);
    }

    // Verify token audience matches our Google Client ID
    if (empty($payload['aud']) || $payload['aud'] !== $expectedClientId) {
        error_log('Google Auth AUD mismatch. Expected: ' . $expectedClientId . ', Got: ' . ($payload['aud'] ?? 'none'));
        flash('Security check failed: Google client ID mismatch.', 'danger');
        redirect($entryPage);
    }

    $googleId = (string) $payload['sub'];
    $email = strtolower(trim((string) $payload['email']));
    $firstName = !empty($payload['given_name']) ? (string) $payload['given_name'] : 'Google';
    $lastName = !empty($payload['family_name']) ? (string) $payload['family_name'] : 'Member';

    // 1. Try to find user by google_id
    $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    // 2. If not linked yet, check if an account with this email already exists
    if (!$user) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if ($requiredRole !== null && ($user['role'] ?? '') !== $requiredRole) {
                flash('This Google account is already registered with a different FitTrack account type. Use that account or register with another Google account.', 'danger');
                redirect($entryPage);
            }

            // Link existing account with Google and mark email as verified
            $update = db()->prepare('
                UPDATE users 
                SET google_id = ?, 
                    email_verified_at = COALESCE(email_verified_at, NOW()) 
                WHERE user_id = ?
            ');
            $update->execute([$googleId, $user['user_id']]);

            // Re-fetch updated user
            $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ?');
            $stmt->execute([$user['user_id']]);
            $user = $stmt->fetch();
        }
    }

    if ($user && $requiredRole !== null && ($user['role'] ?? '') !== $requiredRole) {
        flash('This Google account is already registered with a different FitTrack account type. Use that account or register with another Google account.', 'danger');
        redirect($entryPage);
    }

    // 3. If account does not exist, create the account type required by this signup route.
    if (!$user) {
        $newUserRole = $requiredRole === 'gym_owner' ? 'gym_owner' : 'member';
        $insert = db()->prepare('
            INSERT INTO users (google_id, role, first_name, last_name, email, password_hash, status, email_verified_at, created_at)
            VALUES (?, ?, ?, ?, ?, NULL, "active", NOW(), NOW())
        ');
        $insert->execute([$googleId, $newUserRole, $firstName, $lastName, $email]);
        $newUserId = (int) db()->lastInsertId();

        $stmt = db()->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([$newUserId]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        flash('Unable to sign in with Google. Please try again.', 'danger');
        redirect($entryPage);
    }

    // 4. Verify account status
    if ($user['status'] === 'suspended' || $user['status'] === 'inactive') {
        flash('Your account has been deactivated or suspended. Please contact support.', 'danger');
        redirect($entryPage);
    }

    // 5. Establish authenticated session
    RateLimiter::clear($email);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['user_id'];
    unset($_SESSION['pending_verify_uid']);

    // Check if welcome flash should be shown
    $shouldFlashWelcome = true;
    if ($user['role'] === 'gym_owner') {
        $gymCheck = db()->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int) $user['user_id'])->fetch();
        if (!$gymCheck || in_array($gymCheck['status'], ['pending', 'rejected'], true)) {
            $shouldFlashWelcome = false;
        }
    } elseif ($user['role'] === 'member') {
        $profCheck = function_exists('member_profile') ? member_profile((int) $user['user_id']) : null;
        if (!$profCheck || empty($profCheck['height_cm']) || empty($profCheck['primary_goal'])) {
            $shouldFlashWelcome = false;
        }
    }

    if ($shouldFlashWelcome) {
        flash('Welcome back, ' . htmlspecialchars($user['first_name']) . '!', 'success');
    } else {
        flash('Signed in with Google as ' . htmlspecialchars($user['email']), 'info');
    }

    redirect('dashboard');
}
