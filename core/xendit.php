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
        error_log('Xendit returned a non-JSON response (HTTP ' . $httpStatus . '). Body: ' . substr((string)$responseBody, 0, 500));
        throw new RuntimeException('Xendit returned an unexpected response (HTTP ' . $httpStatus . ').');
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errorCode = preg_replace('/[^A-Z0-9_]/', '', (string) ($response['error_code'] ?? ''));
        $rawMessage = (string) ($response['message'] ?? '');
        $detailMsg = $rawMessage;
        if (!empty($response['errors']) && is_array($response['errors'])) {
            $detailMsg .= ' - ' . json_encode($response['errors'], JSON_UNESCAPED_SLASHES);
        }
        error_log('Xendit API request failed (HTTP ' . $httpStatus . ', ' . ($errorCode ?: 'UNKNOWN') . '): ' . $detailMsg);
        throw new RuntimeException('Xendit error (' . ($errorCode ?: 'HTTP_' . $httpStatus) . '): ' . ($detailMsg ?: 'Request rejected'));
    }

    return $response;
}

function xendit_find_customer_id(string $referenceId, array $headers = []): ?string
{
    if ($referenceId === '') {
        return null;
    }

    // 1. Check local users table if cached
    if (str_starts_with($referenceId, 'user-')) {
        $userId = (int) substr($referenceId, 5);
        if ($userId > 0) {
            try {
                $stmt = db()->prepare('SELECT xendit_customer_id FROM users WHERE user_id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $cached = trim((string) $stmt->fetchColumn());
                if ($cached !== '') {
                    return $cached;
                }
            } catch (Throwable) {
                // Column might not exist yet on all environments
            }
        }
    }

    // 2. Query Xendit Customers API
    try {
        $res = xendit_api_request(
            'GET',
            '/customers?reference_id=' . rawurlencode($referenceId),
            null,
            array_merge(['api-version: 2020-10-31'], $headers)
        );
        $list = $res['data'] ?? $res;
        if (is_array($list)) {
            foreach ($list as $item) {
                if (is_array($item) && !empty($item['id'])) {
                    $foundId = (string) $item['id'];
                    if (str_starts_with($referenceId, 'user-')) {
                        $userId = (int) substr($referenceId, 5);
                        if ($userId > 0) {
                            try {
                                db()->prepare('UPDATE users SET xendit_customer_id = ? WHERE user_id = ? AND (xendit_customer_id IS NULL OR xendit_customer_id = "")')
                                    ->execute([$foundId, $userId]);
                            } catch (Throwable) {
                            }
                        }
                    }
                    return $foundId;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Xendit customer lookup note: ' . $e->getMessage());
    }

    return null;
}

function xendit_create_checkout_session(
    array $payment,
    array $customer,
    string $merchantName,
    ?string $forUserId = null,
    string $returnPage = 'gym_subscription'
): array
{
    $referenceId = (string) ($payment['xendit_reference_id'] ?? '');
    $amount = round((float) ($payment['amount'] ?? 0), 2);
    if ($referenceId === '' || $amount <= 0) {
        throw new RuntimeException('Invalid subscription payment details.');
    }

    $billingCycle = (string) ($payment['billing_cycle'] ?? 'monthly');
    $description = 'FitTrack ' . (string) ($payment['plan_name'] ?? 'subscription') . ' plan for ' . $merchantName
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
            'page' => $returnPage,
            'checkout' => 'returned',
            'ref' => $referenceId,
        ]);
        $cancelUrl = $appUrl . '?' . http_build_query([
            'page' => $returnPage,
            'checkout' => 'cancelled',
            'ref' => $referenceId,
        ]);
        $payload['success_return_url'] = $successUrl;
        $payload['cancel_return_url'] = $cancelUrl;
    }

    $requestHeaders = $forUserId !== null ? ['for-user-id: ' . $forUserId] : [];
    $customerEmail = trim((string)($customer['email'] ?? ''));
    $userId = (int)($customer['user_id'] ?? 0);
    $customerRef = 'user-' . $userId;

    if ($customerEmail !== '') {
        $existingCustId = $userId > 0 ? xendit_find_customer_id($customerRef, $requestHeaders) : null;
        if ($existingCustId !== null) {
            $payload['customer_id'] = $existingCustId;
        } else {
            $givenNames = trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string)($customer['first_name'] ?? 'Member')) ?: 'Member');
            $surname = trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string)($customer['last_name'] ?? '')));
            $individualDetail = [
                'given_names' => $givenNames,
            ];
            if ($surname !== '') {
                $individualDetail['surname'] = $surname;
            }

            $payload['customer'] = [
                'reference_id' => $customerRef,
                'type' => 'INDIVIDUAL',
                'email' => $customerEmail,
                'individual_detail' => $individualDetail,
            ];
        }
    }

    try {
        $session = xendit_api_request('POST', '/sessions', $payload, $requestHeaders);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        // If Xendit reports that the customer reference_id already exists, recover automatically
        if (str_contains($msg, 'DUPLICATE_ERROR') && str_contains(strtolower($msg), 'customer')) {
            error_log('Xendit reported duplicate customer reference_id. Attempting automatic recovery...');
            $fallbackCustId = null;
            try {
                $rawRes = xendit_api_request('GET', '/customers?reference_id=' . rawurlencode($customerRef), null, $requestHeaders);
                $rawList = $rawRes['data'] ?? $rawRes;
                if (is_array($rawList) && !empty($rawList)) {
                    $first = reset($rawList);
                    if (is_array($first) && !empty($first['id'])) {
                        $fallbackCustId = (string)$first['id'];
                    }
                }
            } catch (Throwable) {
            }

            if ($fallbackCustId !== null) {
                unset($payload['customer']);
                $payload['customer_id'] = $fallbackCustId;
                if ($userId > 0) {
                    try {
                        db()->prepare('UPDATE users SET xendit_customer_id = ? WHERE user_id = ?')->execute([$fallbackCustId, $userId]);
                    } catch (Throwable) {
                    }
                }
                $session = xendit_api_request('POST', '/sessions', $payload, $requestHeaders);
            } elseif (isset($payload['customer'])) {
                // Ensure customer reference_id is unique per session if lookup could not find existing ID
                $payload['customer']['reference_id'] = $customerRef . '-' . time() . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $session = xendit_api_request('POST', '/sessions', $payload, $requestHeaders);
            } else {
                throw $e;
            }
        } else {
            throw $e;
        }
    }

    // Cache customer ID if returned in session response
    $returnedCustId = trim((string)($session['customer_id'] ?? ''));
    if ($returnedCustId !== '' && $userId > 0) {
        try {
            db()->prepare('UPDATE users SET xendit_customer_id = ? WHERE user_id = ? AND (xendit_customer_id IS NULL OR xendit_customer_id = "")')
                ->execute([$returnedCustId, $userId]);
        } catch (Throwable) {
        }
    }

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
        $lookup = $pdo->prepare('SELECT p.*, u.email AS owner_email, u.first_name AS owner_first_name, u.last_name AS owner_last_name, g.name AS gym_name
            FROM gym_subscription_payments p
            JOIN users u ON u.user_id = p.owner_user_id
            JOIN gyms g ON g.gym_id = p.gym_id
            WHERE p.xendit_session_id = ? LIMIT 1');
        $lookup->execute([$sessionId]);
        $payment = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            $memberLookup = $pdo->prepare('SELECT p.*, m.user_id AS member_user_id, m.start_date AS membership_start_date, m.end_date AS membership_end_date, mp.plan_name, mp.gym_id, u.email AS member_email, u.first_name AS member_first_name, u.last_name AS member_last_name, g.name AS gym_name, g.owner_user_id, owner.email AS owner_email, owner.first_name AS owner_first_name, owner.last_name AS owner_last_name, g.xendit_subaccount_id
                FROM payments p
                JOIN memberships m ON m.membership_id = p.membership_id
                JOIN membership_plans mp ON mp.plan_id = m.plan_id
                JOIN users u ON u.user_id = m.user_id
                JOIN gyms g ON g.gym_id = mp.gym_id
                JOIN users owner ON owner.user_id = g.owner_user_id
                WHERE p.xendit_session_id = ? LIMIT 1');
            $memberLookup->execute([$sessionId]);
            $memberPayment = $memberLookup->fetch(PDO::FETCH_ASSOC);
            if ($memberPayment) {
                xendit_handle_membership_payment_webhook($pdo, $memberPayment, $event, $data, $sessionId);
                return;
            }
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

        $gymStmt = $pdo->prepare('SELECT subscription_plan, subscription_status, subscription_renewal_date FROM gyms WHERE gym_id = ? FOR UPDATE');
        $gymStmt->execute([(int) $lockedPayment['gym_id']]);
        $gym = $gymStmt->fetch(PDO::FETCH_ASSOC);
        if (!$gym) {
            throw new RuntimeException('Subscription gym no longer exists.');
        }

        $today = new DateTimeImmutable(date('Y-m-d'));
        $start = $today;
        $currentPlan = strtolower(trim((string)($gym['subscription_plan'] ?? '')));
        $newPlan = strtolower(trim((string)$lockedPayment['plan_name']));
        $billingCycle = (string)$lockedPayment['billing_cycle'];

        // Only extend from existing renewal date if renewing the exact same plan AND within a reasonable upcoming window (not a dummy far-future test date)
        if (($gym['subscription_status'] ?? '') === 'active' && !empty($gym['subscription_renewal_date']) && $currentPlan === $newPlan) {
            $renewal = new DateTimeImmutable((string) $gym['subscription_renewal_date']);
            $maxReasonableWindow = $today->modify($billingCycle === 'yearly' ? '+366 days' : '+35 days');
            if ($renewal > $start && $renewal <= $maxReasonableWindow) {
                $start = $renewal;
            }
        }
        $end = xendit_add_billing_period($start, $billingCycle);
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

        try {
            $emailSent = Emails::sendGymSubscriptionReceipt(
                (string)$payment['owner_email'],
                trim((string)$payment['owner_first_name'] . ' ' . (string)$payment['owner_last_name']),
                (string)$payment['gym_name'],
                (string)$lockedPayment['plan_name'],
                (string)$lockedPayment['billing_cycle'],
                (float)$lockedPayment['amount'],
                $receiptNumber,
                $paymentId,
                $startDate,
                $endDate
            );
            if (!$emailSent) {
                error_log('Xendit subscription receipt email could not be sent.');
            }
        } catch (Throwable $emailError) {
            error_log('Could not queue Xendit subscription receipt email: ' . $emailError->getMessage());
        }

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

function xendit_handle_membership_payment_webhook(PDO $pdo, array $payment, string $event, array $data, string $sessionId): void
{
    if ($payment['status'] === 'paid') {
        http_response_code(200);
        echo json_encode(['success' => true, 'duplicate' => true]);
        return;
    }

    if ($event === 'payment_session.expired') {
        $pdo->prepare('UPDATE payments SET status = "overdue" WHERE payment_id = ? AND status = "pending"')
            ->execute([(int)$payment['payment_id']]);
        $pdo->prepare('UPDATE memberships SET status = "cancelled" WHERE membership_id = ? AND status = "pending"')
            ->execute([(int)$payment['membership_id']]);
        http_response_code(200);
        echo json_encode(['success' => true]);
        return;
    }

    $referenceId = trim((string)($data['reference_id'] ?? ''));
    $subaccountId = trim((string)($payment['xendit_subaccount_id'] ?? ''));
    if ($referenceId === '' || !hash_equals((string)$payment['xendit_reference_id'], $referenceId) || $subaccountId === '') {
        throw new RuntimeException('Xendit membership payment details did not match the pending payment.');
    }

    $verifiedSession = xendit_api_request(
        'GET',
        '/sessions/' . rawurlencode($sessionId),
        null,
        ['for-user-id: ' . $subaccountId]
    );
    if ((string)($verifiedSession['status'] ?? '') !== 'COMPLETED'
        || (string)($verifiedSession['payment_session_id'] ?? '') !== $sessionId
        || (string)($verifiedSession['reference_id'] ?? '') !== $referenceId
        || strtoupper((string)($verifiedSession['currency'] ?? '')) !== 'PHP'
        || round((float)($verifiedSession['amount'] ?? -1), 2) !== round((float)$payment['amount'], 2)) {
        throw new RuntimeException('Xendit membership payment status or amount did not match.');
    }

    $xenditPaymentId = trim((string)($data['payment_id'] ?? $verifiedSession['payment_id'] ?? ''));
    if ($xenditPaymentId === '') {
        throw new RuntimeException('Xendit did not include a completed membership payment ID.');
    }

    $pdo->beginTransaction();
    $lockPayment = $pdo->prepare('SELECT * FROM payments WHERE payment_id = ? FOR UPDATE');
    $lockPayment->execute([(int)$payment['payment_id']]);
    $lockedPayment = $lockPayment->fetch(PDO::FETCH_ASSOC);
    if (!$lockedPayment || $lockedPayment['status'] !== 'pending') {
        $pdo->commit();
        http_response_code(200);
        echo json_encode(['success' => true, 'duplicate' => true]);
        return;
    }

    $membershipStmt = $pdo->prepare('SELECT * FROM memberships WHERE membership_id = ? FOR UPDATE');
    $membershipStmt->execute([(int)$lockedPayment['membership_id']]);
    $membership = $membershipStmt->fetch(PDO::FETCH_ASSOC);
    if (!$membership) {
        throw new RuntimeException('The pending membership no longer exists.');
    }

    if ($membership['status'] === 'pending') {
        $pdo->prepare('UPDATE memberships SET status = "cancelled" WHERE user_id = ? AND membership_id <> ? AND status = "active" AND plan_id IN (SELECT plan_id FROM membership_plans WHERE gym_id = ?)')
            ->execute([(int)$payment['member_user_id'], (int)$membership['membership_id'], (int)$payment['gym_id']]);
        $pdo->prepare('UPDATE memberships SET status = "active" WHERE membership_id = ?')
            ->execute([(int)$membership['membership_id']]);
    }
    $pdo->prepare('INSERT IGNORE INTO gym_members (gym_id, user_id) VALUES (?, ?)')
        ->execute([(int)$payment['gym_id'], (int)$payment['member_user_id']]);

    $receiptNumber = 'MEM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $pdo->prepare('UPDATE payments SET status = "paid", payment_method = "online", payment_date = CURDATE(), receipt_number = ?, xendit_payment_id = ? WHERE payment_id = ? AND status = "pending"')
        ->execute([$receiptNumber, $xenditPaymentId, (int)$lockedPayment['payment_id']]);
    $pdo->commit();

    try {
        process_trainer_commission((int)$lockedPayment['payment_id'], (float)$lockedPayment['amount']);
    } catch (Throwable $e) {
        error_log('Could not process Xendit member payment commission: ' . $e->getMessage());
    }

    try {
        notify_user((int)$payment['member_user_id'], 'system', 'Membership Payment Confirmed', 'Payment confirmed for ' . $payment['plan_name'] . ' at ' . $payment['gym_name'] . '. Receipt: ' . $receiptNumber . '.');
        notify_user((int)$payment['owner_user_id'], 'system', 'Member Payment Received', $payment['member_first_name'] . ' ' . $payment['member_last_name'] . ' paid ' . money((float)$payment['amount']) . ' for ' . $payment['plan_name'] . '. Receipt: ' . $receiptNumber . '.');
    } catch (Throwable $notificationError) {
        error_log('Could not create Xendit membership payment notifications: ' . $notificationError->getMessage());
    }

    try {
        $memberEmailSent = Emails::sendMembershipPaymentReceipt(
            (string)$payment['member_email'],
            trim((string)$payment['member_first_name'] . ' ' . (string)$payment['member_last_name']),
            (string)$payment['gym_name'],
            (string)$payment['plan_name'],
            (float)$payment['amount'],
            $receiptNumber,
            $xenditPaymentId,
            (string)$payment['membership_start_date'],
            (string)$payment['membership_end_date']
        );
        $ownerEmailSent = Emails::sendGymMemberPaymentNotice(
            (string)$payment['owner_email'],
            trim((string)$payment['owner_first_name'] . ' ' . (string)$payment['owner_last_name']),
            (string)$payment['gym_name'],
            trim((string)$payment['member_first_name'] . ' ' . (string)$payment['member_last_name']),
            (string)$payment['plan_name'],
            (float)$payment['amount'],
            $receiptNumber,
            $xenditPaymentId
        );
        if (!$memberEmailSent || !$ownerEmailSent) {
            error_log('One or more Xendit membership payment emails could not be sent.');
        }
    } catch (Throwable $emailError) {
        error_log('Could not send Xendit membership payment emails: ' . $emailError->getMessage());
    }

    try {
        audit_log((int)$payment['member_user_id'], 'membership_payment', 'payment', (string)$lockedPayment['payment_id'], json_encode([
            'gym_id' => (int)$payment['gym_id'],
            'amount' => (float)$payment['amount'],
            'receipt' => $receiptNumber,
            'provider' => 'xendit',
        ]));
    } catch (Throwable $auditError) {
        error_log('Could not audit Xendit membership payment: ' . $auditError->getMessage());
    }

    http_response_code(200);
    echo json_encode(['success' => true]);
}

function xendit_add_billing_period(DateTimeImmutable $start, string $billingCycle): DateTimeImmutable
{
    $monthsToAdd = $billingCycle === 'yearly' ? 12 : 1;
    $targetMonth = $start->modify('first day of this month')->modify('+' . $monthsToAdd . ' months');
    $day = min((int) $start->format('j'), (int) $targetMonth->format('t'));
    return $targetMonth->setDate((int) $targetMonth->format('Y'), (int) $targetMonth->format('n'), $day);
}
