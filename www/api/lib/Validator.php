<?php

class Validator {
    /** @throws InvalidArgumentException if any required field is missing/empty */
    public static function requireFields(array $data, array $fields): void {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || trim((string) $data[$field]) === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    /** Keeps only whitelisted keys -- prevents unexpected/extra fields reaching a SQL statement. */
    public static function pick(array $data, array $allowedFields): array {
        return array_intersect_key($data, array_flip($allowedFields));
    }

    public static function isPositiveInt($value): bool {
        return is_numeric($value) && (int) $value == $value && (int) $value > 0;
    }
}
