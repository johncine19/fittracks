<?php
declare(strict_types=1);

function gym_onboarding_page(): void
{
    if (!defined('AUTH_PAGE')) define('AUTH_PAGE', true);
    
    $user = current_user();
    if (!$user || $user['role'] !== 'gym_owner') {
        redirect('login');
    }

    $pdo = db();
    // Check if they already have a gym
    $gym = $pdo->query('SELECT status FROM gyms WHERE owner_user_id = ' . (int)$user['user_id'])->fetch();
    if ($gym) {
        if ($gym['status'] === 'pending') {
            redirect('gym_pending');
        } elseif ($gym['status'] === 'rejected') {
            redirect('gym_rejected');
        } else {
            redirect('dashboard');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $gymName = trim((string) post('gym_name'));
        $gymAddress = trim((string) post('gym_address'));
        $gymContact = trim((string) post('gym_contact_info'));

        if (!$gymName || !$gymAddress || !$gymContact) {
            flash('All fields are required.', 'danger');
        } else {
            require_once __DIR__ . '/../../core/file_handler.php';
            
            try {
                $permitFilename = null;
                if (isset($_FILES['business_permit']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
                    $permitFilename = FileUpload::storeBusinessPermit($_FILES['business_permit'], (int)$user['user_id']);
                } else {
                    throw new Exception('Business permit is required.');
                }

                $validIdFilename = null;
                if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                    $validIdFilename = FileUpload::storeValidId($_FILES['valid_id'], (int)$user['user_id']);
                } else {
                    throw new Exception('Valid ID is required.');
                }

                $stmt = $pdo->prepare('INSERT INTO gyms (owner_user_id, name, address, contact_info, business_permit_url, valid_id_url, status) VALUES (?, ?, ?, ?, ?, ?, "pending")');
                $stmt->execute([
                    $user['user_id'],
                    $gymName,
                    $gymAddress,
                    $gymContact,
                    $permitFilename,
                    $validIdFilename
                ]);

                audit_log($user['user_id'], 'submit_application', 'gym_owner', (string)$user['user_id']);
                
                $ownerName = $user['first_name'] . ' ' . $user['last_name'];
                notify_admins('system', 'New Gym Application', "A new gym application for '{$gymName}' was submitted by {$ownerName}. Please review it in the Gym Applications dashboard.");
                
                Emails::sendNewGymApplication($gymName, $ownerName);
                Emails::sendGymApplicationSubmitted((string)$user['email'], $ownerName, $gymName);

                flash('Your gym application has been submitted successfully! We have sent a confirmation email to ' . htmlspecialchars((string)$user['email']) . '.', 'success');
                redirect('gym_pending');

            } catch (Exception $e) {
                flash($e->getMessage(), 'danger');
            }
        }
    }

    // Suppress conflicting "Welcome back" flash toast on onboarding page
    if (isset($_SESSION['flash']['message']) && str_starts_with((string)$_SESSION['flash']['message'], 'Welcome back')) {
        unset($_SESSION['flash']);
    }

    render_header('Gym Onboarding', $user);
    ?>
    <div class="split-login-viewport">
        <div class="split-login-frame onboarding-mode">
            <!-- Left Hero Showcase -->
            <div class="split-login-showcase">
                <!-- Decorative Dot Matrix SVG -->
                <svg class="showcase-decor-dots" width="70" height="70" viewBox="0 0 70 70" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="10" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="26" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="42" r="2.5" fill="#ffffff" />
                    <circle cx="10" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="26" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="42" cy="58" r="2.5" fill="#ffffff" />
                    <circle cx="58" cy="58" r="2.5" fill="#ffffff" />
                </svg>

                <!-- Decorative Diagonal Speed Stripes SVG -->
                <svg class="showcase-decor-stripes" viewBox="0 0 260 260" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polygon points="120,260 220,0 260,0 160,260" fill="url(#limeGradient1)" opacity="0.65" />
                    <polygon points="40,260 140,0 170,0 70,260" fill="url(#limeGradient2)" opacity="0.45" />
                    <polygon points="0,260 90,0 110,0 20,260" fill="url(#limeGradient1)" opacity="0.25" />
                    <defs>
                        <linearGradient id="limeGradient1" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#4d7c0f" />
                            <stop offset="50%" stop-color="#84cc16" />
                            <stop offset="100%" stop-color="#bef264" />
                        </linearGradient>
                        <linearGradient id="limeGradient2" x1="0%" y1="100%" x2="100%" y2="0%">
                            <stop offset="0%" stop-color="#3f6212" />
                            <stop offset="100%" stop-color="#a3e635" />
                        </linearGradient>
                    </defs>
                </svg>

                <div class="split-login-showcase-content">
                    <!-- Top Brand Header -->
                    <div class="showcase-brand">
                        <div class="showcase-brand-icon">
                            <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                                <path d="M6 8 L32 8 L29 14 L15 14 L13 18 L26 18 L23 24 L10 24 L5 34 L1 34 L6 8 Z" fill="#84cc16" />
                                <polygon points="12,5 36,5 34,9 10,9" fill="#a3e635" opacity="0.8" />
                                <polygon points="2,32 10,32 8,36 0,36" fill="#65a30d" />
                            </svg>
                        </div>
                        <div class="showcase-brand-text">
                            <div class="showcase-brand-name">FIT<span>TRACK</span></div>
                            <div class="showcase-brand-tagline">Manage. Engage. Grow.</div>
                        </div>
                    </div>

                    <!-- Middle Headline & Copy -->
                    <div class="showcase-hero-copy">
                        <h1 class="showcase-title">
                            Grow Your Facility.
                            <span class="highlight">Empower Your Members.</span>
                        </h1>
                        <p class="showcase-desc">
                            Set up your facility profile to unlock automated check-ins, member subscriptions, and operations management.
                        </p>
                    </div>

                    <!-- Three Feature Items -->
                    <div class="showcase-features">
                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Fast Verification</h4>
                                <p>Quick document review to activate your facility profile promptly.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Member Management</h4>
                                <p>Manage memberships, plans, and attendance with modern tools.</p>
                            </div>
                        </div>

                        <div class="showcase-feature-item">
                            <div class="showcase-feature-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="18" y1="20" x2="18" y2="10"></line>
                                    <line x1="12" y1="20" x2="12" y2="4"></line>
                                    <line x1="6" y1="20" x2="6" y2="14"></line>
                                </svg>
                            </div>
                            <div class="showcase-feature-body">
                                <h4>Actionable Analytics</h4>
                                <p>Gain real-time insights on attendance trends and revenue growth.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Side Elevated Card -->
            <div class="split-login-card-pane">
                <div class="split-login-card onboarding-card">
                    <div class="split-card-header">
                        <h2 class="split-card-title">Complete Setup</h2>
                        <p class="split-card-subtitle">Facility details for <span class="brand-highlight">FitTrack</span></p>
                    </div>

                    <!-- Mobile Step Tracker (Mobile-Only) -->
                    <div class="mobile-step-tracker step-mobile-only">
                        <button type="button" class="mobile-step-tab active" id="tab-onboarding-1" onclick="goToOnboardingStep(1)">
                            <span class="step-num">1</span>
                            <span>Facility Details</span>
                        </button>
                        <button type="button" class="mobile-step-tab" id="tab-onboarding-2" onclick="goToOnboardingStep(2)">
                            <span class="step-num">2</span>
                            <span>Documents</span>
                        </button>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="split-card-form" novalidate onsubmit="const btn = this.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; btn.innerHTML = '<svg class=\'fitness-loader mini\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\' style=\'margin-right:8px;\'><line x1=\'6\' y1=\'12\' x2=\'18\' y2=\'12\'></line><rect x=\'4\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'18\' y=\'8\' width=\'2\' height=\'8\' rx=\'1\'></rect><rect x=\'2\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect><rect x=\'20\' y=\'10\' width=\'2\' height=\'4\' rx=\'1\'></rect></svg> SUBMITTING...'; }">
                        <?= csrf_field() ?>

                        <!-- STEP 1: Facility Information -->
                        <div class="split-step-pane" id="onboarding-pane-1">
                            <!-- Gym Name & Contact Row (Paired on desktop) -->
                            <div class="split-form-row-2col">
                                <div class="split-form-group">
                                    <label>Gym / Facility Name</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 21h18M3 7v14M21 7v14M9 21V11M15 21V11M9 7l3-4 3 4"></path>
                                        </svg>
                                        <input type="text" name="gym_name" required placeholder="e.g. Iron Forge Gym"
                                               value="<?= h(post('gym_name')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter your gym name.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>

                                <div class="split-form-group">
                                    <label>Contact Info</label>
                                    <div class="split-input-wrap auth-input-group">
                                        <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                        </svg>
                                        <input type="text" name="gym_contact_info" required placeholder="Phone or business email"
                                               value="<?= h(post('gym_contact_info')) ?>"
                                               oninvalid="this.setCustomValidity('Please enter contact information.')"
                                               oninput="this.setCustomValidity('')">
                                    </div>
                                </div>
                            </div>

                            <!-- Gym Address -->
                            <div class="split-form-group">
                                <label>Complete Facility Address</label>
                                <div class="split-input-wrap auth-input-group">
                                    <svg class="split-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                    <input type="text" name="gym_address" required placeholder="Unit, Street, Barangay, City, Province"
                                           value="<?= h(post('gym_address')) ?>"
                                           oninvalid="this.setCustomValidity('Please enter the full address of your gym.')"
                                           oninput="this.setCustomValidity('')">
                                </div>
                            </div>

                            <!-- Mobile Step 1 Continue Button -->
                            <button type="button" class="split-submit-btn step-mobile-only" onclick="goToOnboardingStep(2)">
                                <span>Continue to Documents</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </div>

                        <!-- STEP 2: Verification Documents -->
                        <div class="split-step-pane step-hidden" id="onboarding-pane-2">
                            <!-- Styled File Upload Boxes (Paired on desktop) -->
                            <div class="split-form-row-2col">
                                <!-- Business Permit Upload Box -->
                                <div class="split-form-group">
                                    <label>Business Permit <span style="font-weight: 400; color: #94a3b8;">(Max 5MB)</span></label>
                                    <div class="split-file-upload-box" id="box-business-permit">
                                        <input type="file" name="business_permit" required accept=".pdf,.jpg,.jpeg,.png"
                                               onchange="handleFileChosen(this, 'filename-permit', 'box-business-permit')"
                                               oninvalid="this.setCustomValidity('Please upload your business permit.')"
                                               oninput="this.setCustomValidity('')">
                                        <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                            <polyline points="17 8 12 3 7 8"></polyline>
                                            <line x1="12" y1="3" x2="12" y2="15"></line>
                                        </svg>
                                        <span class="split-upload-title">Upload Permit</span>
                                        <span class="split-upload-desc">DTI, SEC or Mayor's Permit</span>
                                        <span class="split-upload-filename" id="filename-permit"></span>
                                    </div>
                                </div>

                                <!-- Valid ID Upload Box -->
                                <div class="split-form-group">
                                    <label>Valid Gov. ID <span style="font-weight: 400; color: #94a3b8;">(Max 5MB)</span></label>
                                    <div class="split-file-upload-box" id="box-valid-id">
                                        <input type="file" name="valid_id" required accept=".pdf,.jpg,.jpeg,.png"
                                               onchange="handleFileChosen(this, 'filename-id', 'box-valid-id')"
                                               oninvalid="this.setCustomValidity('Please upload a valid government ID.')"
                                               oninput="this.setCustomValidity('')">
                                        <svg class="split-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                                            <circle cx="9" cy="10" r="2"></circle>
                                            <line x1="15" y1="8" x2="17" y2="8"></line>
                                            <line x1="15" y1="12" x2="17" y2="12"></line>
                                            <line x1="7" y1="16" x2="17" y2="16"></line>
                                        </svg>
                                        <span class="split-upload-title">Upload ID</span>
                                        <span class="split-upload-desc">Passport, DL, or UMID</span>
                                        <span class="split-upload-filename" id="filename-id"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" class="split-submit-btn">
                                <span>Submit Application</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>

                            <!-- Mobile Back Button -->
                            <button type="button" class="split-step-back-btn step-mobile-only" onclick="goToOnboardingStep(1)">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                Back to Facility Details
                            </button>
                        </div>

                        <div class="split-card-footer">
                            <div>Need to finish later? <a href="index.php?page=logout" class="signup-link">Sign out</a></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentOnboardingStep = 1;

    function goToOnboardingStep(step) {
        if (window.innerWidth > 768) return; // Desktop displays both steps simultaneously

        const pane1 = document.getElementById('onboarding-pane-1');
        const pane2 = document.getElementById('onboarding-pane-2');
        const tab1  = document.getElementById('tab-onboarding-1');
        const tab2  = document.getElementById('tab-onboarding-2');

        if (step === 2) {
            const gn = document.querySelector('input[name="gym_name"]');
            const gc = document.querySelector('input[name="gym_contact_info"]');
            const ga = document.querySelector('input[name="gym_address"]');

            if (gn && !gn.value.trim()) {
                gn.focus();
                gn.reportValidity();
                return;
            }
            if (gc && !gc.value.trim()) {
                gc.focus();
                gc.reportValidity();
                return;
            }
            if (ga && !ga.value.trim()) {
                ga.focus();
                ga.reportValidity();
                return;
            }

            pane1.classList.add('step-hidden');
            pane2.classList.remove('step-hidden');
            if (tab1) tab1.classList.remove('active');
            if (tab2) tab2.classList.add('active');
            currentOnboardingStep = 2;
        } else {
            pane2.classList.add('step-hidden');
            pane1.classList.remove('step-hidden');
            if (tab2) tab2.classList.remove('active');
            if (tab1) tab1.classList.add('active');
            currentOnboardingStep = 1;
        }
    }

    function handleFileChosen(input, labelId, boxId) {
        const label = document.getElementById(labelId);
        const box   = document.getElementById(boxId);
        if (!input.files || !input.files[0]) {
            if (label) label.style.display = 'none';
            if (box) box.classList.remove('has-file');
            return;
        }
        const file = input.files[0];
        if (label) {
            label.textContent = '✓ ' + file.name;
            label.style.display = 'block';
        }
        if (box) {
            box.classList.add('has-file');
        }
    }

    window.addEventListener('resize', function() {
        const pane1 = document.getElementById('onboarding-pane-1');
        const pane2 = document.getElementById('onboarding-pane-2');
        if (!pane1 || !pane2) return;
        if (window.innerWidth > 768) {
            pane1.classList.remove('step-hidden');
            pane2.classList.remove('step-hidden');
        } else {
            if (currentOnboardingStep === 1) {
                pane1.classList.remove('step-hidden');
                pane2.classList.add('step-hidden');
            } else {
                pane1.classList.add('step-hidden');
                pane2.classList.remove('step-hidden');
            }
        }
    });
    </script>
    <?php
    render_footer();
}

