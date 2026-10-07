<?php
declare(strict_types=1);

function xendit_api_request(string $method, string $path, ?array $body = null, array $headers = []): array
{
    $secretKey = (string) app_env('XENDIT_SECRET_KEY');
    if ($secretKey === '') {
        throw new RuntimeException('Xendit is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Xendit.');
    }

    $url = 'https://api.xendit.co' . $path;
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
        $requestHeaders[] = 'Content-Type: application/json';
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_USERPWD => $secretKey . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($encodedBody !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedBody);
    }

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('Xendit API transport error: ' . $curlError);
        throw new RuntimeException('Could not reach Xendit. Please try again.');
    }

    $response = json_decode((string) $responseBody, true);
    if (!is_array($response)) {
        error_log('Xendit returned a non-JSON response (HTTP ' . $httpStatus . ').');
        throw new RuntimeException('Xendit returned an unexpected response.');
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errorCode = preg_replace('/[^A-Z0-9_]/', '', (string) ($response['error_code'] ?? ''));
        error_log('Xendit API request failed (HTTP ' . $httpStatus . ', ' . ($errorCode ?: 'UNKNOWN') . ').');
        throw new RuntimeException('Xendit could not start checkout. Please try again.');
    }

    return $response;
}

function xendit_create_checkout_session(array $payment, array $owner, string $gymName): array
{
    $referenceId = (string) ($payment['xendit_reference_id'] ?? '');
    $amount = round((float) ($payment['amount'] ?? 0), 2);
    if ($referenceId === '' || $amount <= 0) {
        throw new RuntimeException('Invalid subscription payment details.');
    }

    $billingCycle = (string) ($payment['billing_cycle'] ?? 'monthly');
    $description = 'FitTrack ' . (string) ($payment['plan_name'] ?? 'subscription') . ' plan for ' . $gymName
        . ' (' . ($billingCycle === 'yearly' ? 'annual' : 'monthly') . ')';
    $payload = [
        'reference_id' => $referenceId,
        'session_type' => 'PAY',
        'mode' => 'PAYMENT_LINK',
        'amount' => $amount,
        'currency' => 'PHP',
        'country' => 'PH',
        'locale' => 'en',
        'description' => mb_substr($description, 0, 1000),
    ];

    $appUrl = app_base_url();
    if (str_starts_with(strtolower($appUrl), 'https://')) {
        $successUrl = $appUrl . '?' . http_build_query([
            'page' => 'gym_subscription',
            'checkout' => 'returned',
            'ref' => $referenceId,
        ]);
        $cancelUrl = $appUrl . '?' . http_build_query([
            'page' => 'gym_subscription',
            'checkout' => 'cancelled',
            'ref' => $referenceId,
        ]);
        $payload['success_return_url'] = $successUrl;
        $payload['cancel_return_url'] = $cancelUrl;
    }

    $session = xendit_api_request('POST', '/sessions', $payload);
    $sessionId = trim((string) ($session['payment_session_id'] ?? ''));
    $checkoutUrl = trim((string) ($session['payment_link_url'] ?? ''));
    $checkoutHost = strtolower((string) parse_url($checkoutUrl, PHP_URL_HOST));
    $validHost = $checkoutHost === 'xen.to'
        || str_ends_with($checkoutHost, '.xen.to')
        || str_ends_with($checkoutHost, '.xendit.co');

    if ($sessionId === '' || !str_starts_with(strtolower($checkoutUrl), 'https://') || !$validHost) {
        error_log('Xendit created a checkout session with an invalid redirect URL.');
        throw new RuntimeException('Xendit returned an invalid checkout link.');
    }

    return [
        'payment_session_id' => $sessionId,
        'payment_link_url' => $checkoutUrl,
    ];
}

function handle_xendit_subscription_webhook(): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        return;
    }

    $expectedToken = (string) app_env('XENDIT_WEBHOOK_TOKEN');
    $providedToken = (string) ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '');
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid webhook token.']);
        return;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid webhook payload.']);
        return;
    }

    $event = (string) ($payload['event'] ?? '');
    $data = $payload['data'];
    if (!in_array($event, ['payment_session.completed', 'payment_session.expired'], true)) {
        http_response_code(200);
        echo json_encode(['success' => true, 'ignored' => true]);
        return;
    }

    // Xendit's dashboard test payloads may call the session identifier "id".
    $sessionId = trim((string) ($data['payment_session_id'] ?? $data['id'] ?? ''));
    if ($sessionId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing payment session ID.']);
        return;
    }

    $pdo = db();
    try {
        $lookup = $pdo->prepare('SELECT * FROM gym_subscription_payments WHERE xendit_session_id = ? LIMIT 1');
        $lookup->execute([$sessionId]);
        $payment = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            error_log('Xendit webhook referenced an unknown subscription session.');
            http_response_code(200);
            echo json_encode(['success' => true, 'ignored' => true]);
            return;
        }

        if ($payment['status'] === 'paid') {
            http_response_code(200);
            echo json_encode(['success' => true, 'duplicate' => true]);
            return;
        }

        if ($event === 'payment_session.expired') {
            $pdo->prepare('UPDATE gym_subscription_payments SET status = "failed" WHERE id = ? AND status = "pending"')
                ->execute([(int) $payment['id']]);
            http_response_code(200);
            echo json_encode(['success' => true]);
            return;
        }

        $referenceId = (string) ($data['reference_id'] ?? '');
        $paymentRequestId = trim((string) ($data['payment_request_id'] ?? ''));
        if ($referenceId === '' || !hash_equals((string) $payment['xendit_reference_id'], $referenceId) || $paymentRequestId === '') {
            throw new RuntimeException('Xendit session reference did not match the pending subscription.');
        }

        $verifiedPayment = xendit_api_request(
            'GET',
            '/v3/payment_requests/' . rawurlencode($paymentRequestId),
            null,
            ['api-version: 2024-11-11']
        );
        if ((string) ($verifiedPayment['status'] ?? '') !== 'SUCCEEDED'
            || (string) ($verifiedPayment['reference_id'] ?? '') !== $referenceId
            || strtoupper((string) ($verifiedPayment['currency'] ?? '')) !== 'PHP'
            || round((float) ($verifiedPayment['request_amount'] ?? -1), 2) !== round((float) $payment['amount'], 2)) {
            throw new RuntimeException('Xendit payment status or amount did not match the pending subscription.');
        }

        $paymentId = trim((string) ($data['payment_id'] ?? $verifiedPayment['latest_payment_id'] ?? ''));
        if ($paymentId === '') {
            throw new RuntimeException('Xendit did not include a completed payment ID.');
        }

        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare('SELECT * FROM gym_subscription_payments WHERE id = ? FOR UPDATE');
        $lockStmt->execute([(int) $payment['id']]);
        $lockedPayment = $lockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedPayment || $lockedPayment['status'] !== 'pending') {
            $pdo->commit();
            http_response_code(200);
            echo json_encode(['success' => true, 'duplicate' => true]);
            return;
        }

        $gymStmt = $pdo->prepare('SELECT subscription_status, subscription_renewal_date FROM gyms WHERE gym_id = ? FOR UPDATE');
        $gymStmt->execute([(int) $lockedPayment['gym_id']]);
        $gym = $gymStmt->fetch(PDO::FETCH_ASSOC);
        if (!$gym) {
            throw new RuntimeException('Subscription gym no longer exists.');
        }

        $today = new DateTimeImmutable(date('Y-m-d'));
        $start = $today;
        if (($gym['subscription_status'] ?? '') === 'active' && !empty($gym['subscription_renewal_date'])) {
            $renewal = new DateTimeImmutable((string) $gym['subscription_renewal_date']);
            if ($renewal > $start) {
                $start = $renewal;
            }
        }
        $end = xendit_add_billing_period($start, (string) $lockedPayment['billing_cycle']);
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $receiptNumber = 'SUB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        $pdo->prepare('UPDATE gym_subscription_payments SET status = "paid", payment_method = "online", payment_date = NOW(), receipt_number = ?, xendit_payment_id = ?, start_date = ?, end_date = ? WHERE id = ? AND status = "pending"')
            ->execute([$receiptNumber, $paymentId, $startDate, $endDate, (int) $lockedPayment['id']]);
        $pdo->prepare('UPDATE gyms SET subscription_plan = ?, subscription_status = "active", subscription_renewal_date = ? WHERE gym_id = ?')
            ->execute([$lockedPayment['plan_name'], $endDate, (int) $lockedPayment['gym_id']]);
        $pdo->commit();

        audit_log(
            (int) $lockedPayment['owner_user_id'],
            'subscribe_plan',
            'gym_subscription',
            (string) $lockedPayment['gym_id'],
            json_encode([
                'plan' => $lockedPayment['plan_name'],
                'billing_cycle' => $lockedPayment['billing_cycle'],
                'amount' => (float) $lockedPayment['amount'],
                'receipt' => $receiptNumber,
                'provider' => 'xendit',
            ])
        );
        notify_admins(
            'system',
            'New Subscription Payment',
            "A gym subscribed to the {$lockedPayment['plan_name']} Plan (" . money((float) $lockedPayment['amount']) . ' / ' . ($lockedPayment['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly') . ') via XENDIT.'
        );
        notify_user(
            (int) $lockedPayment['owner_user_id'],
            'system',
            'Subscription Activated',
            "Your {$lockedPayment['plan_name']} plan (" . ($lockedPayment['billing_cycle'] === 'yearly' ? 'Annual' : 'Monthly') . ") is active until " . date('M j, Y', strtotime($endDate)) . ". Receipt: {$receiptNumber}."
        );

        http_response_code(200);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Xendit subscription webhook processing failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Webhook processing failed.']);
    }
}

function xendit_add_billing_period(DateTimeImmutable $start, string $billingCycle): DateTimeImmutable
{
    $monthsToAdd = $billingCycle === 'yearly' ? 12 : 1;
    $targetMonth = $start->modify('first day of this month')->modify('+' . $monthsToAdd . ' months');
    $day = min((int) $start->format('j'), (int) $targetMonth->format('t'));
    return $targetMonth->setDate((int) $targetMonth->format('Y'), (int) $targetMonth->format('n'), $day);
}
