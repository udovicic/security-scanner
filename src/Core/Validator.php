<?php

namespace SecurityScanner\Core;

class Validator
{
    private array $errors = [];
    private array $customRules = [];
    private array $customMessages = [];

    /**
     * Validate data against rules
     */
    public function validate(array $data, array $rules, array $messages = []): array
    {
        $this->errors = [];

        foreach ($rules as $field => $rule) {
            $fieldRules = is_string($rule) ? explode('|', $rule) : $rule;
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $singleRule) {
                $this->validateField($field, $value, $singleRule, $data, $messages);
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException('Validation failed', $this->errors);
        }

        return $data;
    }

    /**
     * Register a custom validation rule
     */
    public function extend(string $ruleName, callable $callback, string $message = null): void
    {
        $this->customRules[$ruleName] = $callback;
        if ($message) {
            $this->customMessages[$ruleName] = $message;
        }
    }

    /**
     * Set custom error messages
     */
    public function setCustomMessages(array $messages): void
    {
        $this->customMessages = array_merge($this->customMessages, $messages);
    }

    /**
     * Validate without throwing exception
     */
    public function check(array $data, array $rules, array $messages = []): bool
    {
        try {
            $this->validate($data, $rules, $messages);
            return true;
        } catch (ValidationException $e) {
            return false;
        }
    }

    /**
     * Validate a single field
     */
    private function validateField(string $field, $value, string $rule, array $data, array $messages): void
    {
        $ruleParts = explode(':', $rule, 2);
        $ruleName = $ruleParts[0];
        $parameters = isset($ruleParts[1]) ? explode(',', $ruleParts[1]) : [];

        // Check for custom rules first
        if (isset($this->customRules[$ruleName])) {
            $callback = $this->customRules[$ruleName];
            $result = $callback($value, $parameters, $data);

            if ($result !== true) {
                $message = is_string($result) ? $result :
                          ($messages["{$field}.{$ruleName}"] ??
                           $this->customMessages[$ruleName] ??
                           "The {$field} field is invalid.");
                $this->addError($field, $message);
            }
            return;
        }

        // Built-in validation rules
        switch ($ruleName) {
            case 'required':
                $this->validateRequired($field, $value, $messages);
                break;

            case 'required_if':
                $this->validateRequiredIf($field, $value, $parameters, $data, $messages);
                break;

            case 'required_unless':
                $this->validateRequiredUnless($field, $value, $parameters, $data, $messages);
                break;

            case 'nullable':
                // Skip validation if null - this is a modifier rule
                break;

            case 'email':
                $this->validateEmail($field, $value, $messages);
                break;

            case 'url':
                $this->validateUrl($field, $value, $messages);
                break;

            case 'ip':
                $this->validateIp($field, $value, $messages);
                break;

            case 'integer':
            case 'int':
                $this->validateInteger($field, $value, $messages);
                break;

            case 'numeric':
                $this->validateNumeric($field, $value, $messages);
                break;

            case 'float':
                $this->validateFloat($field, $value, $messages);
                break;

            case 'boolean':
            case 'bool':
                $this->validateBoolean($field, $value, $messages);
                break;

            case 'string':
                $this->validateString($field, $value, $messages);
                break;

            case 'array':
                $this->validateArray($field, $value, $messages);
                break;

            case 'date':
                $this->validateDate($field, $value, $parameters, $messages);
                break;

            case 'date_format':
                $this->validateDateFormat($field, $value, $parameters, $messages);
                break;

            case 'after':
                $this->validateAfter($field, $value, $parameters, $data, $messages);
                break;

            case 'before':
                $this->validateBefore($field, $value, $parameters, $data, $messages);
                break;

            case 'min':
                $this->validateMin($field, $value, $parameters, $messages);
                break;

            case 'max':
                $this->validateMax($field, $value, $parameters, $messages);
                break;

            case 'between':
                $this->validateBetween($field, $value, $parameters, $messages);
                break;

            case 'size':
                $this->validateSize($field, $value, $parameters, $messages);
                break;

            case 'in':
                $this->validateIn($field, $value, $parameters, $messages);
                break;

            case 'not_in':
                $this->validateNotIn($field, $value, $parameters, $messages);
                break;

            case 'unique':
                $this->validateUnique($field, $value, $parameters, $messages);
                break;

            case 'exists':
                $this->validateExists($field, $value, $parameters, $messages);
                break;

            case 'confirmed':
                $this->validateConfirmed($field, $value, $data, $messages);
                break;

            case 'different':
                $this->validateDifferent($field, $value, $parameters, $data, $messages);
                break;

            case 'same':
                $this->validateSame($field, $value, $parameters, $data, $messages);
                break;

            case 'regex':
                $this->validateRegex($field, $value, $parameters, $messages);
                break;

            case 'not_regex':
                $this->validateNotRegex($field, $value, $parameters, $messages);
                break;

            case 'alpha':
                $this->validateAlpha($field, $value, $messages);
                break;

            case 'alpha_num':
                $this->validateAlphaNum($field, $value, $messages);
                break;

            case 'alpha_dash':
                $this->validateAlphaDash($field, $value, $messages);
                break;

            case 'json':
                $this->validateJson($field, $value, $messages);
                break;

            case 'file':
                $this->validateFile($field, $value, $messages);
                break;

            case 'image':
                $this->validateImage($field, $value, $messages);
                break;

            case 'mimes':
                $this->validateMimes($field, $value, $parameters, $messages);
                break;

            case 'max_file_size':
                $this->validateMaxFileSize($field, $value, $parameters, $messages);
                break;

            default:
                // Unknown rule - could be a custom rule that wasn't registered
                break;
        }
    }

    /**
     * Add validation error
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Validate required field
     */
    private function validateRequired(string $field, $value, array $messages): void
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $message = $messages["{$field}.required"] ?? "The {$field} field is required.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate required if another field has a specific value
     */
    private function validateRequiredIf(string $field, $value, array $parameters, array $data, array $messages): void
    {
        if (count($parameters) < 2) return;

        $otherField = $parameters[0];
        $expectedValue = $parameters[1];

        if (isset($data[$otherField]) && $data[$otherField] == $expectedValue) {
            $this->validateRequired($field, $value, $messages);
        }
    }

    /**
     * Validate required unless another field has a specific value
     */
    private function validateRequiredUnless(string $field, $value, array $parameters, array $data, array $messages): void
    {
        if (count($parameters) < 2) return;

        $otherField = $parameters[0];
        $expectedValue = $parameters[1];

        if (!isset($data[$otherField]) || $data[$otherField] != $expectedValue) {
            $this->validateRequired($field, $value, $messages);
        }
    }

    /**
     * Validate email format
     */
    private function validateEmail(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $message = $messages["{$field}.email"] ?? "The {$field} must be a valid email address.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate URL format
     */
    private function validateUrl(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $message = $messages["{$field}.url"] ?? "The {$field} must be a valid URL.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate IP address
     */
    private function validateIp(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_IP)) {
            $message = $messages["{$field}.ip"] ?? "The {$field} must be a valid IP address.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate integer
     */
    private function validateInteger(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
            $message = $messages["{$field}.integer"] ?? "The {$field} must be an integer.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate numeric value
     */
    private function validateNumeric(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $message = $messages["{$field}.numeric"] ?? "The {$field} must be numeric.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate float
     */
    private function validateFloat(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_FLOAT)) {
            $message = $messages["{$field}.float"] ?? "The {$field} must be a valid float.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate boolean
     */
    private function validateBoolean(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '') {
            $validBooleans = [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'];
            if (!in_array($value, $validBooleans, true)) {
                $message = $messages["{$field}.boolean"] ?? "The {$field} must be true or false.";
                $this->addError($field, $message);
            }
        }
    }

    /**
     * Validate string
     */
    private function validateString(string $field, $value, array $messages): void
    {
        if ($value !== null && !is_string($value)) {
            $message = $messages["{$field}.string"] ?? "The {$field} must be a string.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate array
     */
    private function validateArray(string $field, $value, array $messages): void
    {
        if ($value !== null && !is_array($value)) {
            $message = $messages["{$field}.array"] ?? "The {$field} must be an array.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate date
     */
    private function validateDate(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '') return;

        $format = $parameters[0] ?? 'Y-m-d H:i:s';
        $date = \DateTime::createFromFormat($format, $value);

        if (!$date || $date->format($format) !== $value) {
            $message = $messages["{$field}.date"] ?? "The {$field} is not a valid date.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate date format
     */
    private function validateDateFormat(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '') return;
        if (empty($parameters)) return;

        $format = $parameters[0];
        $date = \DateTime::createFromFormat($format, $value);

        if (!$date || $date->format($format) !== $value) {
            $message = $messages["{$field}.date_format"] ?? "The {$field} does not match the format {$format}.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate after date
     */
    private function validateAfter(string $field, $value, array $parameters, array $data, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $afterField = $parameters[0];
        $afterValue = $data[$afterField] ?? $parameters[0];

        $date = strtotime($value);
        $afterDate = strtotime($afterValue);

        if ($date === false || $afterDate === false || $date <= $afterDate) {
            $message = $messages["{$field}.after"] ?? "The {$field} must be a date after {$afterField}.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate before date
     */
    private function validateBefore(string $field, $value, array $parameters, array $data, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $beforeField = $parameters[0];
        $beforeValue = $data[$beforeField] ?? $parameters[0];

        $date = strtotime($value);
        $beforeDate = strtotime($beforeValue);

        if ($date === false || $beforeDate === false || $date >= $beforeDate) {
            $message = $messages["{$field}.before"] ?? "The {$field} must be a date before {$beforeField}.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate minimum value/length
     */
    private function validateMin(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $min = (int)$parameters[0];

        if (is_numeric($value)) {
            if ((float)$value < $min) {
                $message = $messages["{$field}.min"] ?? "The {$field} must be at least {$min}.";
                $this->addError($field, $message);
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) < $min) {
                $message = $messages["{$field}.min"] ?? "The {$field} must be at least {$min} characters.";
                $this->addError($field, $message);
            }
        } elseif (is_array($value)) {
            if (count($value) < $min) {
                $message = $messages["{$field}.min"] ?? "The {$field} must have at least {$min} items.";
                $this->addError($field, $message);
            }
        }
    }

    /**
     * Validate maximum value/length
     */
    private function validateMax(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $max = (int)$parameters[0];

        if (is_numeric($value)) {
            if ((float)$value > $max) {
                $message = $messages["{$field}.max"] ?? "The {$field} may not be greater than {$max}.";
                $this->addError($field, $message);
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) > $max) {
                $message = $messages["{$field}.max"] ?? "The {$field} may not be greater than {$max} characters.";
                $this->addError($field, $message);
            }
        } elseif (is_array($value)) {
            if (count($value) > $max) {
                $message = $messages["{$field}.max"] ?? "The {$field} may not have more than {$max} items.";
                $this->addError($field, $message);
            }
        }
    }

    /**
     * Validate value is between min and max
     */
    private function validateBetween(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || count($parameters) < 2) return;

        $min = (int)$parameters[0];
        $max = (int)$parameters[1];

        if (is_numeric($value)) {
            $numValue = (float)$value;
            if ($numValue < $min || $numValue > $max) {
                $message = $messages["{$field}.between"] ?? "The {$field} must be between {$min} and {$max}.";
                $this->addError($field, $message);
            }
        } elseif (is_string($value)) {
            $length = mb_strlen($value);
            if ($length < $min || $length > $max) {
                $message = $messages["{$field}.between"] ?? "The {$field} must be between {$min} and {$max} characters.";
                $this->addError($field, $message);
            }
        }
    }

    /**
     * Validate exact size
     */
    private function validateSize(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $size = (int)$parameters[0];

        if (is_numeric($value)) {
            if ((float)$value != $size) {
                $message = $messages["{$field}.size"] ?? "The {$field} must be {$size}.";
                $this->addError($field, $message);
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) != $size) {
                $message = $messages["{$field}.size"] ?? "The {$field} must be {$size} characters.";
                $this->addError($field, $message);
            }
        } elseif (is_array($value)) {
            if (count($value) != $size) {
                $message = $messages["{$field}.size"] ?? "The {$field} must contain {$size} items.";
                $this->addError($field, $message);
            }
        }
    }

    /**
     * Validate value is in list
     */
    private function validateIn(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        if (!in_array($value, $parameters, true)) {
            $message = $messages["{$field}.in"] ?? "The selected {$field} is invalid.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate value is not in list
     */
    private function validateNotIn(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        if (in_array($value, $parameters, true)) {
            $message = $messages["{$field}.not_in"] ?? "The selected {$field} is invalid.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate unique value in database
     */
    private function validateUnique(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '') return;

        // This would require database connection - placeholder implementation
        $message = $messages["{$field}.unique"] ?? "The {$field} has already been taken.";
        // TODO: Implement database check when database layer is available
    }

    /**
     * Validate value exists in database
     */
    private function validateExists(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '') return;

        // This would require database connection - placeholder implementation
        $message = $messages["{$field}.exists"] ?? "The selected {$field} is invalid.";
        // TODO: Implement database check when database layer is available
    }

    /**
     * Validate confirmed field matches
     */
    private function validateConfirmed(string $field, $value, array $data, array $messages): void
    {
        $confirmField = $field . '_confirmation';
        $confirmValue = $data[$confirmField] ?? null;

        if ($value !== $confirmValue) {
            $message = $messages["{$field}.confirmed"] ?? "The {$field} confirmation does not match.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate field is different from another
     */
    private function validateDifferent(string $field, $value, array $parameters, array $data, array $messages): void
    {
        if (empty($parameters)) return;

        $otherField = $parameters[0];
        $otherValue = $data[$otherField] ?? null;

        if ($value === $otherValue) {
            $message = $messages["{$field}.different"] ?? "The {$field} and {$otherField} must be different.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate field is same as another
     */
    private function validateSame(string $field, $value, array $parameters, array $data, array $messages): void
    {
        if (empty($parameters)) return;

        $otherField = $parameters[0];
        $otherValue = $data[$otherField] ?? null;

        if ($value !== $otherValue) {
            $message = $messages["{$field}.same"] ?? "The {$field} and {$otherField} must match.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate regex pattern
     */
    private function validateRegex(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $pattern = $parameters[0];

        if (!preg_match($pattern, $value)) {
            $message = $messages["{$field}.regex"] ?? "The {$field} format is invalid.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate not matching regex pattern
     */
    private function validateNotRegex(string $field, $value, array $parameters, array $messages): void
    {
        if ($value === null || $value === '' || empty($parameters)) return;

        $pattern = $parameters[0];

        if (preg_match($pattern, $value)) {
            $message = $messages["{$field}.not_regex"] ?? "The {$field} format is invalid.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate alphabetic characters only
     */
    private function validateAlpha(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !ctype_alpha($value)) {
            $message = $messages["{$field}.alpha"] ?? "The {$field} may only contain letters.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate alphanumeric characters only
     */
    private function validateAlphaNum(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !ctype_alnum($value)) {
            $message = $messages["{$field}.alpha_num"] ?? "The {$field} may only contain letters and numbers.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate alphanumeric characters, dashes, and underscores
     */
    private function validateAlphaDash(string $field, $value, array $messages): void
    {
        if ($value !== null && $value !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            $message = $messages["{$field}.alpha_dash"] ?? "The {$field} may only contain letters, numbers, dashes and underscores.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate JSON format
     */
    private function validateJson(string $field, $value, array $messages): void
    {
        if ($value === null || $value === '') return;

        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $message = $messages["{$field}.json"] ?? "The {$field} must be a valid JSON string.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate file upload
     */
    private function validateFile(string $field, $value, array $messages): void
    {
        if (!($value instanceof UploadedFile)) {
            $message = $messages["{$field}.file"] ?? "The {$field} must be a file.";
            $this->addError($field, $message);
            return;
        }

        if (!$value->isValid()) {
            $message = $messages["{$field}.file"] ?? "The {$field} upload failed.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate image file
     */
    private function validateImage(string $field, $value, array $messages): void
    {
        $this->validateFile($field, $value, $messages);

        if ($value instanceof UploadedFile && $value->isValid()) {
            $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/svg+xml'];
            if (!in_array($value->getType(), $imageTypes)) {
                $message = $messages["{$field}.image"] ?? "The {$field} must be an image.";
                $this->addError($field, $message);
            }
        }
    }

    /**
     * Validate file MIME types
     */
    private function validateMimes(string $field, $value, array $parameters, array $messages): void
    {
        if (!($value instanceof UploadedFile) || !$value->isValid() || empty($parameters)) return;

        if (!in_array($value->getType(), $parameters)) {
            $allowed = implode(', ', $parameters);
            $message = $messages["{$field}.mimes"] ?? "The {$field} must be a file of type: {$allowed}.";
            $this->addError($field, $message);
        }
    }

    /**
     * Validate maximum file size
     */
    private function validateMaxFileSize(string $field, $value, array $parameters, array $messages): void
    {
        if (!($value instanceof UploadedFile) || !$value->isValid() || empty($parameters)) return;

        $maxSize = (int)$parameters[0];
        if ($value->getSize() > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 2);
            $message = $messages["{$field}.max_file_size"] ?? "The {$field} may not be greater than {$maxSizeMB}MB.";
            $this->addError($field, $message);
        }
    }
}

class ValidationException extends \Exception
{
    private array $errors;

    public function __construct(string $message, array $errors)
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}