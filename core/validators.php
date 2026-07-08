<?php
declare(strict_types=1);

/**
 * Lightweight server-side validator. Rules are pipe-separated strings, e.g.
 * 'required|email', 'required|min:8', 'required|digits:11', 'numeric|max:300'.
 *
 * Usage:
 *   $v = new Validator();
 *   if (!$v->validate($_POST, [
 *       'email'    => 'required|email',
 *       'password' => 'required|min:8',
 *   ])) {
 *       flash(implode(' ', $v->errors()), 'danger');
 *       redirect_back_or('register');
 *   }
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     */
    public function validate(array $data, array $rules): bool
    {
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $rules2 = explode('|', $ruleString);

            foreach ($rules2 as $rule) {
                if ($rule === '') {
                    continue;
                }

                if ($rule === 'required') {
                    if ($value === null || $value === '') {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . ' is required.');
                        break;
                    }
                    continue;
                }

                // Skip remaining rules for empty optional fields.
                if ($value === null || $value === '') {
                    continue;
                }

                if ($rule === 'email') {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->fail($field, 'Enter a valid email address.');
                    }
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (mb_strlen((string) $value) < $min) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . " must be at least $min characters.");
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (mb_strlen((string) $value) > $max) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . " must be at most $max characters.");
                    }
                } elseif (str_starts_with($rule, 'digits:')) {
                    $len = (int) substr($rule, 7);
                    if (!preg_match('/^\d{' . $len . '}$/', (string) $value)) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . " must be exactly $len digits.");
                    }
                } elseif ($rule === 'numeric') {
                    if (!is_numeric($value)) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . ' must be a number.');
                    }
                } elseif (str_starts_with($rule, 'min_num:')) {
                    $min = (float) substr($rule, 8);
                    if ((float)$value < $min) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . " must be at least $min.");
                    }
                } elseif (str_starts_with($rule, 'max_num:')) {
                    $max = (float) substr($rule, 8);
                    if ((float)$value > $max) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . " must be at most $max.");
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array((string) $value, $allowed, true)) {
                        $this->fail($field, ucfirst(str_replace('_', ' ', $field)) . ' is not a valid value.');
                    }
                }
            }
        }

        return empty($this->errors);
    }

    private function fail(string $field, string $message): void
    {
        // Keep only the first error per field so messages stay short.
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return $this->errors === [] ? '' : reset($this->errors);
    }
}

/**
 * Server-side password strength check, used in addition to (not instead of)
 * the existing client-side `minlength="8"` hints. Requires at least 8
 * characters with a mix of letters and numbers, and rejects an extended
 * common-password blocklist. (We follow modern NIST 800-63B guidance of
 * favoring length + a blocklist over forced special-character complexity,
 * which research shows pushes people toward predictable patterns like
 * "Password1!" rather than meaningfully stronger passwords.)
 */
function is_acceptable_password(string $password): bool
{
    if (strlen($password) < 8) {
        return false;
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return false;
    }

    $common = [
        'password', '12345678', 'qwertyui', 'admin123', 'password1',
        'letmein1', '11111111', 'iloveyou', 'qwerty123', 'abc12345',
        'password123', 'fittracks',
    ];
    if (in_array(strtolower($password), $common, true)) {
        return false;
    }

    return true;
}
