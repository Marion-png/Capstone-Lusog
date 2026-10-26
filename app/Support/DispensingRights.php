<?php

namespace App\Support;

use App\Models\Medicine;
use Illuminate\Support\Collection;

/**
 * Who may hand a medicine over, and which medicines.
 *
 * Two answers, and they are not the same answer:
 *
 *  - the **School Nurse** dispenses from the whole shelf. They are the
 *    licensed clinician in the building and the stock list is theirs;
 *  - **Clinic Staff** and the **Clinic Teacher** dispense **paracetamol and
 *    nothing else**. Somebody logging a visit can give a child something for a
 *    fever, and the school wants that recorded rather than kept in a notebook
 *    — but choosing an antibiotic or an antihistamine for a learner is a
 *    clinical decision neither role is making.
 *
 * This class is the only place that is written down, because the restriction
 * has to hold in four places at once — the field the dialog draws, the options
 * it enables, the validation rule and the write itself — and a rule copied
 * into four places is a rule that disagrees with itself the first time
 * somebody edits one copy. In particular the dialog's disabled options are
 * **not** the restriction: a disabled `<option>` is a hint to whoever is
 * looking at the screen, and anyone can post a medicine id anyway.
 * `ConsultationController` asks this class again before it writes, inside the
 * transaction, so a crafted request is refused and nothing — neither the
 * consultation nor the stock decrement — is recorded.
 *
 * Paracetamol is matched on the **name**, not on an id or an exact catalogue
 * string. The DepEd catalogue carries it twice already ("Paracetamol 500mg
 * tablet" and "Paracetamol 250mg/5ml suspension"), a school may hold a third
 * off-catalogue spelling, and every one of them is the medicine these roles
 * are permitted. An allow-list of ids would silently narrow to whichever rows
 * happened to exist when it was written.
 */
class DispensingRights
{
    public const NURSE = 'school_nurse';

    public const CLINIC_STAFF = 'clinic_staff';

    public const CLINIC_TEACHER = 'clinic_teacher';

    /** The roles admitted to the dispensing path at all. */
    private const DISPENSING_ROLES = [self::NURSE, self::CLINIC_STAFF, self::CLINIC_TEACHER];

    /** The roles admitted to it for paracetamol only. */
    private const RESTRICTED_ROLES = [self::CLINIC_STAFF, self::CLINIC_TEACHER];

    /**
     * The one medicine a restricted role may give, matched case-insensitively
     * anywhere in the item's name.
     */
    public const RESTRICTED_TO = 'paracetamol';

    /** How that medicine is named to a person. */
    public const RESTRICTED_TO_LABEL = 'Paracetamol';

    /** May this role dispense anything at all? */
    public static function mayDispense(?string $role): bool
    {
        return in_array((string) $role, self::DISPENSING_ROLES, true);
    }

    /**
     * May this role dispense only some of the shelf?
     *
     * True for Clinic Staff and the Clinic Teacher. False for the nurse (the
     * whole shelf) and false for a role that may not dispense at all —
     * "restricted" here means "admitted, but not to everything", so a role
     * with no access is not a narrower case of it.
     */
    public static function isRestricted(?string $role): bool
    {
        return in_array((string) $role, self::RESTRICTED_ROLES, true);
    }

    /** Is this the medicine a restricted role may give? */
    public static function isRestrictedMedicine(?string $medicineName): bool
    {
        return str_contains(strtolower((string) $medicineName), self::RESTRICTED_TO);
    }

    /** Is this particular medicine one this role may give? */
    public static function allows(?string $role, ?string $medicineName): bool
    {
        if (! self::mayDispense($role)) {
            return false;
        }

        if (! self::isRestricted($role)) {
            return true;
        }

        return self::isRestrictedMedicine($medicineName);
    }

    /**
     * The shelf in the order the medicine field lists it: paracetamol first,
     * for every role — it is what the clinic gives most, and the one item every
     * dispensing role may choose — then the rest by name.
     *
     * @param  iterable<Medicine>  $medicines
     * @return Collection<int, Medicine>
     */
    public static function ordered(iterable $medicines): Collection
    {
        return collect($medicines)
            ->sortBy(fn (Medicine $medicine): array => [
                self::isRestrictedMedicine($medicine->name) ? 0 : 1,
                mb_strtolower((string) $medicine->name),
            ])
            ->values();
    }

    /**
     * The line printed under the medicine field, or '' where the role has the
     * whole shelf.
     *
     * The restriction is stated before it is enforced: a greyed-out option
     * with no explanation reads as a broken form.
     */
    public static function restrictionNotice(?string $role): string
    {
        if (! self::isRestricted($role)) {
            return '';
        }

        return 'This role may dispense '.self::RESTRICTED_TO_LABEL.' only. '
            .'Other medicines are listed so you can see what the clinic holds, '
            .'but the School Nurse dispenses them.';
    }

    /** What the server says when it refuses the write. */
    public static function refusalMessage(?string $role, ?string $medicineName): string
    {
        $name = trim((string) $medicineName);

        if (! self::mayDispense($role)) {
            return 'Your role cannot dispense medicine. Record the consultation and refer the learner to the School Nurse.';
        }

        return ($name !== '' ? $name.' is not a medicine this role may dispense. ' : '')
            .'A '.AccountSettings::roleLabel($role).' may dispense '
            .self::RESTRICTED_TO_LABEL.' only; the School Nurse dispenses the rest.';
    }
}
