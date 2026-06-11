@props([
    'name' => 'school_name',
    'label' => 'School',
    'placeholder' => 'Search schools...',
    'schools' => [],
    'readonly' => false,
])

<div class="school-dropdown-field">
    <label @if($readonly) style="opacity: 0.6;" @endif>
        {{ $label }}
    </label>

    <div class="school-dropdown-container">
        <!-- Selected Value Display -->
        <div class="school-display">
            <span class="school-display-name">Select school</span>
            <button type="button" class="school-display-toggle">▼</button>
        </div>

        <!-- Search Input (visible when dropdown open) -->
        <input
            type="text"
            class="school-search-input"
            placeholder="{{ $placeholder }}"
            @if($readonly) disabled @endif
            style="display: none;"
        />

        <!-- Dropdown -->
        <div class="school-dropdown-list" style="display: none;">
            <!-- Options will be populated here -->
        </div>
    </div>

    <!-- Hidden Input -->
    <input type="hidden" name="{{ $name }}" class="school-hidden-input" value="{{ old($name, '') }}" />

    <!-- Error Message -->
    @error($name)
        <div class="err">{{ $message }}</div>
    @enderror
</div>

<style>
    .school-dropdown-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .school-dropdown-field label {
        font-size: 0.7rem;
        color: #5b7b68;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        font-weight: 700;
    }

    .school-dropdown-container {
        position: relative;
        width: 100%;
    }

    .school-display {
        height: 42px;
        border: 1px solid #dbe9df;
        border-radius: 10px;
        padding: 0 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff;
        cursor: pointer;
        transition: border-color 0.2s;
        font-size: 0.86rem;
    }

    .school-display:hover {
        border-color: #22c55e;
    }

    .school-display-name {
        color: #0f2f1b;
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .school-display-toggle {
        background: none;
        border: none;
        color: #5b7b68;
        cursor: pointer;
        font-size: 0.7rem;
        padding: 0 0 0 8px;
        transition: transform 0.2s;
    }

    .school-display.open .school-display-toggle {
        transform: rotate(180deg);
    }

    .school-search-input {
        width: 100%;
        height: 42px;
        border: 1px solid #dbe9df;
        border-radius: 10px;
        padding: 0 12px;
        font: inherit;
        color: #0f2f1b;
        background: #fff;
        font-size: 0.86rem;
    }

    .school-search-input:focus {
        outline: 2px solid #bbf7d0;
        border-color: #22c55e;
    }

    .school-search-input:disabled {
        background-color: #f5f5f5;
        color: #999;
        cursor: not-allowed;
    }

    .school-dropdown-list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        margin-top: 4px;
        background: white;
        border: 1px solid #dbe9df;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(20, 83, 45, 0.1);
        z-index: 10;
        max-height: 300px;
        overflow-y: auto;
    }

    .school-option {
        padding: 10px 12px;
        cursor: pointer;
        font-size: 0.86rem;
        transition: background-color 0.2s;
        border-bottom: 1px solid #f0f0f0;
    }

    .school-option:last-child {
        border-bottom: none;
    }

    .school-option:hover,
    .school-option.focused {
        background-color: #f0f5f2;
    }

    .school-option.selected {
        background-color: #dcfce7;
        color: #15803d;
        font-weight: 500;
    }

    .school-option-empty {
        color: #5b7b68;
        text-align: center;
        padding: 16px 12px;
        font-style: italic;
    }

    .err {
        margin-top: 4px;
        font-size: 0.74rem;
        color: #b91c1c;
    }

    @media (max-width: 720px) {
        .school-dropdown-list {
            max-height: 250px;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.school-dropdown-field').forEach(function(field) {
        const display = field.querySelector('.school-display');
        const displayName = field.querySelector('.school-display-name');
        const displayToggle = field.querySelector('.school-display-toggle');
        const searchInput = field.querySelector('.school-search-input');
        const dropdown = field.querySelector('.school-dropdown-list');
        const hiddenInput = field.querySelector('.school-hidden-input');
        const readonly = searchInput.disabled;
        
        const schools = @json($schools);
        let isOpen = false;
        let focusedIndex = -1;

        // Load pre-selected value
        if (hiddenInput.value) {
            const found = schools.find(s => s === hiddenInput.value);
            if (found) {
                displayName.textContent = found;
                display.style.display = 'flex';
            }
        }

        // Toggle dropdown on display click
        display.addEventListener('click', function() {
            if (!readonly) {
                toggleDropdown();
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!field.contains(e.target)) {
                closeDropdown();
            }
        });

        // Search filtering
        searchInput.addEventListener('input', function() {
            filterAndRender();
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusedIndex++;
                const options = dropdown.querySelectorAll('.school-option');
                if (focusedIndex >= options.length) {
                    focusedIndex = 0;
                }
                updateFocusedOption();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusedIndex--;
                const options = dropdown.querySelectorAll('.school-option');
                if (focusedIndex < 0) {
                    focusedIndex = options.length - 1;
                }
                updateFocusedOption();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const options = dropdown.querySelectorAll('.school-option');
                if (focusedIndex >= 0 && options[focusedIndex]) {
                    selectSchool(options[focusedIndex].textContent);
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        function toggleDropdown() {
            if (isOpen) {
                closeDropdown();
            } else {
                openDropdown();
            }
        }

        function openDropdown() {
            isOpen = true;
            display.classList.add('open');
            searchInput.style.display = 'block';
            dropdown.style.display = 'block';
            filterAndRender();
            setTimeout(() => {
                searchInput.focus();
            }, 0);
        }

        function closeDropdown() {
            isOpen = false;
            display.classList.remove('open');
            searchInput.style.display = 'none';
            dropdown.style.display = 'none';
            searchInput.value = '';
            focusedIndex = -1;
        }

        function filterAndRender() {
            const search = searchInput.value.toLowerCase();
            const filtered = schools.filter(school =>
                school.toLowerCase().includes(search)
            );

            dropdown.innerHTML = '';
            focusedIndex = -1;

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'school-option school-option-empty';
                empty.textContent = 'No schools found';
                dropdown.appendChild(empty);
                return;
            }

            filtered.forEach((school, index) => {
                const option = document.createElement('div');
                option.className = 'school-option';
                if (hiddenInput.value === school) {
                    option.classList.add('selected');
                }
                option.textContent = school;
                
                option.addEventListener('click', () => {
                    selectSchool(school);
                });
                
                option.addEventListener('mouseover', () => {
                    focusedIndex = index;
                    updateFocusedOption();
                });

                dropdown.appendChild(option);
            });
        }

        function updateFocusedOption() {
            const options = dropdown.querySelectorAll('.school-option');
            options.forEach((option, index) => {
                if (index === focusedIndex) {
                    option.classList.add('focused');
                    option.scrollIntoView({ block: 'nearest' });
                } else {
                    option.classList.remove('focused');
                }
            });
        }

        function selectSchool(school) {
            hiddenInput.value = school;
            displayName.textContent = school;
            display.style.display = 'flex';
            closeDropdown();
        }

        // Show display initially if a value is set
        if (hiddenInput.value) {
            const found = schools.find(s => s === hiddenInput.value);
            if (found) {
                displayName.textContent = found;
            }
        }
    });
});
</script>

