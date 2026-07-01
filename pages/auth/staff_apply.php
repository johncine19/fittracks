<?php
declare(strict_types=1);

function handle_staff_apply(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $firstName = trim((string) post('first_name'));
        $lastName = trim((string) post('last_name'));
        $email = trim((string) post('email'));
        $phone = trim((string) post('phone'));
        $message = trim((string) post('message'));

        if ($firstName === '' || $lastName === '' || $email === '' || $message === '') {
            flash('Please fill in all required fields.', 'danger');
            redirect('staff_apply');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Please enter a valid email address.', 'danger');
            redirect('staff_apply');
        }

        if ($phone !== '') {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) !== 11) {
                flash('Phone number must be exactly 11 digits.', 'danger');
                redirect('staff_apply');
            }
        }

        $summary = $firstName . ' ' . $lastName . ' (' . $email . ')';
        if ($phone !== '') {
            $summary .= ' · ' . $phone;
        }
        notify_staff('system', 'Staff application received', $summary . ': ' . mb_substr($message, 0, 400));

        flash('Thank you! Your application has been submitted. We will contact you soon.', 'success');
        redirect('login');
    }

    render_header('Apply as Staff');
    ?>
    <section style="padding: 40px 0;">
        <div class="gilded-container">
            <div class="gilded-header">
                <h1 class="gilded-title"><?= h(APP_NAME) ?></h1>
                <p class="gilded-subtitle">Apply to join our team</p>
            </div>
            <form method="post" class="gilded-form">
                <?= csrf_field() ?>
                <div class="gilded-row">
                    <div class="gilded-field">
                        <label>FIRST NAME</label>
                        <div class="gilded-input-group">
                            <input name="first_name" required placeholder="First name">
                        </div>
                    </div>
                    <div class="gilded-field">
                        <label>LAST NAME</label>
                        <div class="gilded-input-group">
                            <input name="last_name" required placeholder="Last name">
                        </div>
                    </div>
                </div>
                <div class="gilded-field">
                    <label>EMAIL ADDRESS</label>
                    <div class="gilded-input-group">
                        <input type="email" name="email" required placeholder="Enter your email">
                    </div>
                </div>
                <div class="gilded-field">
                    <label>PHONE NUMBER <small style="font-weight:400;text-transform:none">(optional)</small></label>
                    <div class="gilded-input-group">
                        <input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789">
                    </div>
                </div>
                <div class="gilded-field">
                    <label>WHY DO YOU WANT TO JOIN?</label>
                    <div class="gilded-input-group">
                        <textarea name="message" required rows="4" placeholder="Tell us about your experience and interest..." style="resize:vertical;min-height:100px"></textarea>
                    </div>
                </div>
                <button type="submit" class="gilded-btn">SUBMIT APPLICATION</button>
                <div class="gilded-footer">
                    Already have an account? <a href="index.php?page=login">Sign In</a>
                </div>
            </form>
            <div class="corner corner-tl"></div>
            <div class="corner corner-tr"></div>
            <div class="corner corner-bl"></div>
            <div class="corner corner-br"></div>
        </div>
    </section>
    <?php
    render_footer();
}
