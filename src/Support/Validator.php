<?php

declare(strict_types=1);

namespace PitchRooms\Support;

use PitchRooms\Core\HttpException;
use PitchRooms\Core\Request;

/**
 * Tiny rule validator. Collects every failing field, then throws one
 * VALIDATION_FAILED response so the frontend can highlight all of them.
 *
 *   Validator::make($request, [
 *       'email'    => 'required|email',
 *       'password' => 'required|min:6',
 *       'role'     => 'required|in:agency,brand',
 *   ]);
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];

    private function __construct(private array $data, private array $rules)
    {
    }

    public static function make(Request|array $input, array $rules): array
    {
        $data = $input instanceof Request ? $input->all() : $input;
        $validator = new self($data, $rules);

        return $validator->run();
    }

    private function run(): array
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $isRequired = in_array('required', $rules, true);

            if ($value === null || $value === '') {
                if ($isRequired) {
                    $this->errors[$field] = $this->label($field) . ' is required.';
                }
                if (!$isRequired && array_key_exists($field, $this->data)) {
                    $this->validated[$field] = $value;
                }
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === '' || $rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                $this->applyRule($field, $value, $rule);
                if (isset($this->errors[$field])) {
                    break;
                }
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }

        return $this->validated;
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $argument = null;
        if (str_contains($rule, ':')) {
            [$rule, $argument] = explode(':', $rule, 2);
        }

        $label = $this->label($field);

        switch ($rule) {
            case 'string':
                if (!is_string($value)) {
                    $this->errors[$field] = $label . ' must be text.';
                }
                break;

            case 'email':
                if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field] = 'Enter a valid email address.';
                }
                break;

            case 'url':
                if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
                    $this->errors[$field] = $label . ' must be a valid URL.';
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->errors[$field] = $label . ' must be a number.';
                }
                break;

            case 'integer':
                if (!is_numeric($value) || (int) $value != $value) {
                    $this->errors[$field] = $label . ' must be a whole number.';
                }
                break;

            case 'boolean':
                if (!is_bool($value) && !in_array((string) $value, ['0', '1', 'true', 'false'], true)) {
                    $this->errors[$field] = $label . ' must be true or false.';
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    $this->errors[$field] = $label . ' must be a list.';
                }
                break;

            case 'min':
                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value < (float) $argument) {
                        $this->errors[$field] = $label . ' must be at least ' . $argument . '.';
                    }
                } elseif (is_array($value)) {
                    if (count($value) < (int) $argument) {
                        $this->errors[$field] = $label . ' needs at least ' . $argument . ' item(s).';
                    }
                } elseif (mb_strlen((string) $value) < (int) $argument) {
                    $this->errors[$field] = $label . ' must be at least ' . $argument . ' characters.';
                }
                break;

            case 'max':
                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value > (float) $argument) {
                        $this->errors[$field] = $label . ' must not exceed ' . $argument . '.';
                    }
                } elseif (is_array($value)) {
                    if (count($value) > (int) $argument) {
                        $this->errors[$field] = $label . ' allows at most ' . $argument . ' item(s).';
                    }
                } elseif (mb_strlen((string) $value) > (int) $argument) {
                    $this->errors[$field] = $label . ' must not exceed ' . $argument . ' characters.';
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $argument);
                if (!in_array((string) $value, $allowed, true)) {
                    $this->errors[$field] = $label . ' must be one of: ' . implode(', ', $allowed) . '.';
                }
                break;

            case 'date':
                if (strtotime((string) $value) === false) {
                    $this->errors[$field] = $label . ' must be a valid date.';
                }
                break;

            case 'future':
                $timestamp = strtotime((string) $value);
                if ($timestamp === false || $timestamp <= time()) {
                    $this->errors[$field] = $label . ' must be in the future.';
                }
                break;

            case 'phone':
                if (!preg_match('/^[+]?[0-9 ()-]{7,20}$/', (string) $value)) {
                    $this->errors[$field] = 'Enter a valid phone number.';
                }
                break;

            case 'regex':
                if (!preg_match((string) $argument, (string) $value)) {
                    $this->errors[$field] = $label . ' has an invalid format.';
                }
                break;
        }
    }

    private function label(string $field): string
    {
        $words = preg_split('/(?=[A-Z])|_/', $field) ?: [$field];
        $label = ucfirst(strtolower(trim(implode(' ', array_filter($words)))));

        return $label === '' ? $field : $label;
    }
}
