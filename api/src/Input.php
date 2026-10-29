<?php
declare(strict_types=1);

/** ตรวจสอบและทำความสะอาดข้อมูลจากฟอร์ม เก็บ error ไว้แล้วค่อยแจ้งทีเดียวด้วย check() */
final class Input
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function str(string $key, int $max = 255, bool $required = false, string $label = ''): ?string
    {
        $value = $this->data[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        $value = is_string($value) ? trim($value) : null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$key] = 'กรุณากรอก' . ($label ?: $key);
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->errors[$key] = ($label ?: $key) . ' ยาวเกิน ' . $max . ' ตัวอักษร';
            return null;
        }
        return $value;
    }

    public function enum(string $key, array $options, bool $required = false, string $label = '', ?string $default = null): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$key] = 'กรุณาเลือก' . ($label ?: $key);
            }
            return $default;
        }
        if (!in_array($value, $options, true)) {
            $this->errors[$key] = ($label ?: $key) . ' ไม่ถูกต้อง';
            return $default;
        }
        return $value;
    }

    /** รายการที่เลือกได้หลายค่า */
    public function set(string $key, array $options, bool $required = false, string $label = ''): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value)) {
            $value = [];
        }
        $value = array_values(array_unique(array_filter($value, fn($v) => in_array($v, $options, true))));
        if ($required && !$value) {
            $this->errors[$key] = 'กรุณาเลือก' . ($label ?: $key) . 'อย่างน้อย 1 รายการ';
        }
        return $value;
    }

    public function int(string $key, int $min = 0, int $max = PHP_INT_MAX, bool $required = false, string $label = ''): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$key] = 'กรุณากรอก' . ($label ?: $key);
            }
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) {
            $this->errors[$key] = ($label ?: $key) . ' ไม่ถูกต้อง';
            return null;
        }
        return (int)$value;
    }

    public function date(string $key, bool $required = false, string $label = ''): ?string
    {
        $value = $this->str($key, 10, $required, $label);
        if ($value !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $value !== null && strtotime($value) === false) {
            $this->errors[$key] = ($label ?: $key) . ' ไม่ถูกต้อง';
            return null;
        }
        return $value;
    }

    public function datetime(string $key, bool $required = false, string $label = ''): ?string
    {
        $value = $this->str($key, 19, $required, $label);
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $value) || ($ts = strtotime($value)) === false) {
            $this->errors[$key] = ($label ?: $key) . ' ไม่ถูกต้อง';
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /** A bounded list; callers validate each row's fields separately. */
    public function rows(string $key, int $max, string $label = ''): array
    {
        $value=$this->data[$key]??null;
        if (!is_array($value) || !array_is_list($value) || count($value)<1 || count($value)>$max) {
            $this->error($key,($label?:$key).' ต้องมีตั้งแต่ 1 ถึง '.$max.' รายการ');
            return [];
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return array_key_exists($key, $this->data) ? filter_var($this->data[$key], FILTER_VALIDATE_BOOL) : $default;
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    public function check(): void
    {
        if ($this->errors) {
            fail('ข้อมูลไม่ครบหรือไม่ถูกต้อง', 422, $this->errors);
        }
    }
}
