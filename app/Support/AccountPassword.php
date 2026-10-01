<?php

namespace App\Support;

/**
 * One declaration of what this app accepts as a password.
 *
 * It is asked for in two places — the registration request a System Admin
 * approves, and a person changing their own on the Settings page — and a rule
 * typed twice is a rule the two screens can disagree about. A password
 * accepted at registration and then refused on a change reads as a broken
 * form, and the reverse is worse: a change path with looser rules than
 * registration is a way around them.
 */
class AccountPassword
{
    public const MIN_LENGTH = 8;

    /**
     * bcrypt hashes the first 72 bytes and silently ignores the rest, so
     * anything longer is not the password it would be checked against.
     */
    public const MAX_LENGTH = 72;

    /** At least one letter and one digit, so "password" and "12345678" are both refused. */
    public const COMPOSITION = 'regex:/^(?=.*[\pL])(?=.*\d).+$/u';

    public const COMPOSITION_MESSAGE = 'Passwords must contain at least one letter and one number.';

    /**
     * The rule set, with or without the `confirmed` pairing.
     *
     * Registration and a change both ask twice, so both pass true; the
     * argument exists because a rule set is a worse single source of truth if
     * callers have to edit it to drop one entry.
     *
     * @return array<int, string>
     */
    public static function rules(bool $confirmed = true): array
    {
        return array_values(array_filter([
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            $confirmed ? 'confirmed' : null,
            self::COMPOSITION,
        ]));
    }

    /**
     * The one human sentence for the composition rule, keyed for a field name.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'password'): array
    {
        return [$field.'.regex' => self::COMPOSITION_MESSAGE];
    }

    /** What the form prints under the field, so the rule is stated before it is enforced. */
    public static function requirementHint(): string
    {
        return 'At least '.self::MIN_LENGTH.' characters, including one letter and one number.';
    }
}
