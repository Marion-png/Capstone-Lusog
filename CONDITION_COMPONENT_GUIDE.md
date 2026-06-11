# LUSOG Condition Search Component - Implementation Guide

## Overview
This document describes the new hybrid search-and-dropdown condition component for the LUSOG school clinic management system.

## API Endpoints

### GET /api/conditions
Retrieve filtered conditions.

**Query Parameters:**
- `search` (optional): Search term for condition name (case-insensitive)
- `category` (optional): Filter by category

**Example:**
```javascript
fetch('/api/conditions?search=fever&category=General')
  .then(r => r.json())
  .then(data => console.log(data))
```

**Response:**
```json
[
  { "id": 1, "name": "Fever", "category": "General" },
  { "id": 2, "name": "High Fever", "category": "General" }
]
```

### POST /api/conditions
Create a new condition (restricted).

**Requirements:**
- User role must be: `school_nurse` or `clinic_staff`
- Class advisers receive 403 Forbidden

**Request Body:**
```json
{
  "name": "New Condition Name",
  "category": "General"
}
```

**Response on Success (201):**
```json
{
  "id": 35,
  "name": "New Condition Name",
  "category": "General",
  "created_at": "2026-06-10T12:00:00Z",
  "updated_at": "2026-06-10T12:00:00Z"
}
```

**Response on Duplicate (409):**
```json
{
  "message": "A condition with this name already exists.",
  "id": 1
}
```

---

## Blade Component Usage

### Basic Usage
```blade
<x-condition-search
    name="condition_id"
    label="Condition"
/>
```

### With All Options
```blade
<x-condition-search
    name="condition_id"
    label="Select Medical Condition"
    placeholder="Search or type a condition..."
    category="Respiratory"
    readonly="false"
    multiple="false"
/>
```

### In Forms
```blade
<form method="POST" action="/dashboard/consultation-log">
    @csrf
    
    <input type="text" name="student_name" />
    
    <x-condition-search
        name="condition_id"
        label="Presenting Complaint"
        placeholder="Search conditions..."
    />
    
    <!-- condition_id hidden input automatically created -->
    
    <button type="submit">Save</button>
</form>
```

### Component Props
| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `name` | string | `condition_id` | HTML input name attribute |
| `label` | string | `Condition` | Field label text |
| `placeholder` | string | `Search or type a condition...` | Input placeholder |
| `category` | string | null | Pre-filter by category |
| `readonly` | boolean | false | Disable editing/adding |
| `multiple` | boolean | false | Allow multiple selections (future) |

---

## Behavior

### Search & Discovery
1. User types in the input field
2. After 300ms of inactivity, component fetches matching conditions from API
3. Matching conditions displayed in dropdown list
4. If user's input doesn't match exactly, "Add '[text]'" option appears

### Creating New Conditions
1. User types condition name not in database
2. Clicks "Add '[name]'" option or presses Enter
3. Component POSTs to `/api/conditions` with name and category
4. On success (201): New condition auto-selected as a tag
5. On duplicate (409): Existing condition auto-selected
6. On error (403): Shows authorization error

### Selected Conditions
- Displayed as removable tags below the input
- Click × to remove
- Hidden input maintains condition_id for form submission
- Tags use accessible button elements

### Keyboard Navigation
- **Arrow Down**: Move focus to next option
- **Arrow Up**: Move focus to previous option
- **Enter**: Select focused option or create new
- **Escape**: Close dropdown

---

## Existing Conditions (Seeded)

34 conditions are pre-seeded with categories:

**Eye Conditions:**
- Inflamed eye/stye
- Eye irritation
- Conjunctivitis

**ENT (Ear/Nose/Throat):**
- Ear Problem
- Nose Bleeding
- Sinusistis/Acute Rhinitis

**Respiratory:**
- Sore throat
- Tonsilitis
- Cough
- Cold

**Oral:**
- Inflamed Gum
- Toothache

**Gastrointestinal:**
- Hyperacidity
- Diarrhea/LBM
- Abdominal Pain
- Nausea/Vomitting

**Neurological:**
- Headache
- Fainting
- Dizziness

**Reproductive:**
- Dysmenorrhea

**Injury:**
- Lacerated Wound
- Punctured Wound
- Incised Wound
- Abrasion
- Contusion
- Burn

**Skin:**
- Ulcer (Skin)
- Thea Flava
- Ringworm
- Boil

**Allergy:**
- Skin allergy

**General:**
- Fever

**Other:**
- Others

---

## Database Schema

### conditions table
```sql
CREATE TABLE conditions (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) UNIQUE NOT NULL,
  category VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);
```

### consultations table (updated)
```sql
ALTER TABLE consultations ADD COLUMN condition_id BIGINT UNSIGNED NULL AFTER condition;
ALTER TABLE consultations ADD FOREIGN KEY (condition_id) REFERENCES conditions(id) ON DELETE SET NULL;
```

---

## Role-Based Access

### Can Add New Conditions
- School Nurse
- Clinic Staff

### Can Only View/Search
- Class Adviser
- School Head
- Other roles

---

## Testing

Run the test suite:
```bash
php artisan test tests/Feature/ConditionControllerTest.php
php artisan test tests/Feature/ConsultationTest.php
```

All 22 tests pass with zero regressions.

---

## Browser Support
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- No IE support (uses modern ES6+ JavaScript)

---

## Troubleshooting

**Component not showing:**
- Ensure `<x-condition-search>` is in a Blade file
- Check that component path is `resources/views/components/condition-search.blade.php`

**API returns 403:**
- Verify user role is `school_nurse` or `clinic_staff`
- Check session contains `active_role` key

**Duplicate conditions not working:**
- Ensure conditions table is populated with ConditionSeeder
- Check database for unique constraint on conditions.name

**Hidden input not submitting:**
- Verify form method is POST
- Ensure component `name` prop matches expected input name in controller

---

## Future Enhancements
- [ ] Multi-select support (set `multiple="true"`)
- [ ] Category selection in component
- [ ] Condition editing/deletion for admins
- [ ] Bulk import conditions from CSV
- [ ] Condition usage statistics
- [ ] Custom category creation
