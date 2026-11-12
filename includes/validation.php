<?php
/**
 * FIELD RULES - Janet's Quality Catering System
 * The server side of the name, Philippine mobile number and postal code rules.
 * static/js/field-rules.js applies the same rules in the browser.
 */

if (defined('JQ_VALIDATION_LOADED')) {
    return;
}
define('JQ_VALIDATION_LOADED', true);

const JQ_PHONE_MESSAGE  = 'Enter exactly 10 digits after +63, starting with 9. Numbers only, for example +63 9123456789.';
const JQ_POSTAL_MESSAGE = 'Postal code must be exactly 4 digits, for example 1100.';

/**
 * A Philippine mobile number is stored as +639XXXXXXXXX.
 * Accepts +639XXXXXXXXX, +63 9XXXXXXXXX, or the 10 digits alone (the forms show +63 beside the box).
 * @return string|null|false the stored form, null when empty, false when invalid
 */
function jq_normalize_phone(?string $raw)
{
    $value = trim((string)$raw);
    if ($value === '') {
        return null;
    }
    return preg_match('/^(?:\+63\s*)?(9\d{9})$/D', $value, $m) ? '+63' . $m[1] : false;
}

/** How a stored number is shown: +63 9123456789. */
function jq_phone_display(?string $stored): string
{
    $stored = trim((string)$stored);
    return preg_match('/^\+63(9\d{9})$/', $stored, $m) ? '+63 ' . $m[1] : $stored;
}

/** Trims a name and reduces repeated spaces to one. */
function jq_clean_name(?string $raw): string
{
    return trim((string)preg_replace('/\s+/u', ' ', (string)$raw));
}

/**
 * The letters A to Z, upper or lower case, and single spaces between words.
 * @return string|null an error message, or null when the name is acceptable
 */
function jq_name_error(string $name, string $label = 'Name', bool $required = true, int $max = 100): ?string
{
    if ($name === '') {
        return $required ? $label . ' is required.' : null;
    }
    if (mb_strlen($name) > $max) {
        return $label . ' can be at most ' . $max . ' characters.';
    }
    if (!preg_match('/^[A-Za-z]+(?: [A-Za-z]+)*$/D', $name)) {
        return $label . ' can contain letters and spaces only. Numbers and symbols are not allowed.';
    }
    return null;
}

/**
 * The HTML attributes for a name or mobile number box, shared by every form that has one.
 * static/js/field-rules.js reads data-rule, and also adds the fixed +63 beside mobile number boxes.
 */
function jq_field_attrs(string $rule): string
{
    if ($rule === 'ph-mobile') {
        return 'data-rule="ph-mobile" pattern="9[0-9]{9}" maxlength="10" inputmode="numeric" title="10 digits starting with 9, for example 9123456789"';
    }
    return 'data-rule="name" pattern="[A-Za-z ]+" inputmode="text" title="Letters and spaces only"';
}

/** @return string|null an error message, or null when the postal code is acceptable */
function jq_postal_error(string $postal): ?string
{
    if ($postal === '') {
        return 'Postal code is required.';
    }
    return preg_match('/^\d{4}$/', $postal) ? null : JQ_POSTAL_MESSAGE;
}

/**
 * The ZIP code of a place in the address lists: the barangay's own ZIP where it has one (Metro Manila areas),
 * otherwise the city's or municipality's. Empty when the place is not in includes/ph_postal_codes.php.
 */
function jq_postal_for(?string $municipalityCode, ?string $barangayCode = null): string
{
    static $zips = null;
    $zips ??= require __DIR__ . '/ph_postal_codes.php';
    return (string)($zips['b'][(string)$barangayCode] ?? $zips['m'][(string)$municipalityCode] ?? '');
}
