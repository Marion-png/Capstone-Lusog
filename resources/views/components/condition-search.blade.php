@props([
    'name' => 'condition_id',
    'label' => 'Condition',
    'placeholder' => 'Search or type a condition...',
    'category' => null,
    'readonly' => false,
    'multiple' => false,
])

<div class="condition-search-field" id="condition-search-{{ uniqid() }}">
    <label @if($readonly) style="opacity: 0.6;" @endif>
        {{ $label }}
    </label>

    <div class="condition-search-container">
        <!-- Search Input -->
        <input
            type="text"
            class="condition-search-input"
            placeholder="{{ $placeholder }}"
            @if($readonly) disabled @endif
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
        />

        <!-- Loading Spinner -->
        <div class="condition-spinner" style="display: none;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 6v6l4 2"/>
            </svg>
        </div>

        <!-- Dropdown Results -->
        <div
            class="condition-dropdown"
            role="listbox"
            style="display: none;"
        >
            <!-- Results will be populated here -->
        </div>
    </div>

    <!-- Selected Tags -->
    <div class="condition-tags" style="display: none;">
        <!-- Tags will be populated here -->
    </div>

    <!-- Hidden Input(s) -->
    @if(!$multiple)
        <input type="hidden" name="{{ $name }}" class="condition-hidden-input" value="" />
    @endif

    <!-- Error Message -->
    @error($name)
        <div class="err">{{ $message }}</div>
    @enderror
</div>

<style>
    .condition-search-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .condition-search-field label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #3d5c47;
    }

    .condition-search-container {
        position: relative;
        width: 100%;
    }

    .condition-search-input {
        width: 100%;
        border: 1px solid #e4ece7;
        border-radius: 10px;
        padding: 10px 12px;
        font: inherit;
        font-size: 0.86rem;
    }

    .condition-search-input:focus {
        outline: none;
        border-color: #166534;
        box-shadow: 0 0 0 3px rgba(22, 101, 52, 0.1);
    }

    .condition-search-input:disabled {
        background-color: #f5f5f5;
        color: #999;
        cursor: not-allowed;
    }

    .condition-spinner {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
    }

    .condition-spinner svg {
        width: 100%;
        height: 100%;
        color: #166534;
    }

    @keyframes spin {
        from { transform: translateY(-50%) rotate(0deg); }
        to { transform: translateY(-50%) rotate(360deg); }
    }

    .condition-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        margin-top: 4px;
        background: white;
        border: 1px solid #e4ece7;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        z-index: 10;
        max-height: 300px;
        overflow-y: auto;
    }

    .condition-option {
        padding: 10px 12px;
        cursor: pointer;
        font-size: 0.86rem;
        transition: background-color 0.2s;
    }

    .condition-option:hover,
    .condition-option.focused {
        background-color: #f0f5f2;
    }

    .condition-option-add {
        color: #166534;
        font-weight: 500;
        border-top: 1px solid #e4ece7;
    }

    .condition-option-add .add-icon {
        margin-right: 6px;
        font-weight: bold;
    }

    .condition-option-empty {
        color: #7a9e87;
        text-align: center;
        padding: 16px 12px;
        font-style: italic;
    }

    .condition-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }

    .condition-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #e8f3ed;
        color: #166534;
        padding: 6px 10px;
        border-radius: 20px;
        font-size: 0.84rem;
        font-weight: 500;
    }

    .tag-remove {
        background: none;
        border: none;
        color: #166534;
        cursor: pointer;
        font-size: 1.2rem;
        padding: 0;
        line-height: 1;
        transition: color 0.2s;
    }

    .tag-remove:hover:not(:disabled) {
        color: #0d4620;
    }

    .tag-remove:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .err {
        margin-top: 4px;
        font-size: 0.74rem;
        color: #b91c1c;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.condition-search-field').forEach(function(field) {
        const input = field.querySelector('.condition-search-input');
        const dropdown = field.querySelector('.condition-dropdown');
        const spinner = field.querySelector('.condition-spinner');
        const tagsContainer = field.querySelector('.condition-tags');
        const hiddenInput = field.querySelector('.condition-hidden-input');
        const readonly = input.disabled;
        
        const category = @js($category);
        let selectedConditions = [];
        let filteredResults = [];
        let focusedIndex = -1;
        let debounceTimeout = null;

        input.addEventListener('input', function() {
            clearTimeout(debounceTimeout);
            
            if (input.value.length === 0) {
                dropdown.style.display = 'none';
                filteredResults = [];
                focusedIndex = -1;
                return;
            }

            spinner.style.display = 'block';
            debounceTimeout = setTimeout(() => {
                fetchConditions(input.value);
            }, 300);
        });

        input.addEventListener('focus', function() {
            if (input.value.length > 0) {
                dropdown.style.display = 'block';
            }
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                selectFocused();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusedIndex++;
                if (focusedIndex >= filteredResults.length + (input.value.length > 0 && !hasExactMatch() ? 1 : 0)) {
                    focusedIndex = 0;
                }
                updateFocusedOption();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusedIndex--;
                if (focusedIndex < 0) {
                    focusedIndex = filteredResults.length + (input.value.length > 0 && !hasExactMatch() ? 1 : 0) - 1;
                }
                updateFocusedOption();
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
                input.value = '';
                focusedIndex = -1;
                filteredResults = [];
            }
        });

        document.addEventListener('click', function(e) {
            if (!field.contains(e.target) && e.target !== input) {
                dropdown.style.display = 'none';
                input.value = '';
                focusedIndex = -1;
            }
        });

        function fetchConditions(search) {
            const params = new URLSearchParams({
                search: search,
            });

            if (category) {
                params.append('category', category);
            }

            fetch(`/api/conditions?${params}`)
                .then(response => response.json())
                .then(data => {
                    filteredResults = data.filter(
                        item => !selectedConditions.some(selected => selected.id === item.id)
                    );
                    spinner.style.display = 'none';
                    renderDropdown();
                })
                .catch(error => {
                    console.error('Error fetching conditions:', error);
                    spinner.style.display = 'none';
                });
        }

        function renderDropdown() {
            dropdown.innerHTML = '';

            if (filteredResults.length === 0 && (input.value.length === 0 || hasExactMatch())) {
                if (input.value.length > 0 && hasExactMatch()) {
                    dropdown.style.display = 'none';
                }
                return;
            }

            filteredResults.forEach((result, index) => {
                const option = document.createElement('div');
                option.className = 'condition-option' + (focusedIndex === index ? ' focused' : '');
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', focusedIndex === index);
                option.textContent = result.name;
                
                option.addEventListener('click', () => {
                    selectCondition(result);
                });
                
                option.addEventListener('mouseover', () => {
                    focusedIndex = index;
                    updateFocusedOption();
                });

                dropdown.appendChild(option);
            });

            if (input.value.length > 0 && !hasExactMatch()) {
                const addOption = document.createElement('div');
                addOption.className = 'condition-option condition-option-add' + (focusedIndex === filteredResults.length ? ' focused' : '');
                addOption.setAttribute('role', 'option');
                addOption.setAttribute('aria-selected', focusedIndex === filteredResults.length);
                addOption.innerHTML = '<span class="add-icon">+</span> Add "<span>' + escapeHtml(input.value) + '</span>"';
                
                addOption.addEventListener('click', () => {
                    addNewCondition();
                });
                
                addOption.addEventListener('mouseover', () => {
                    focusedIndex = filteredResults.length;
                    updateFocusedOption();
                });

                dropdown.appendChild(addOption);
            }

            if (filteredResults.length === 0 && input.value.length > 0 && !hasExactMatch()) {
                const empty = document.createElement('div');
                empty.className = 'condition-option condition-option-empty';
                empty.textContent = 'No conditions found. Type to add a new one.';
                dropdown.appendChild(empty);
            }

            if (dropdown.children.length > 0) {
                dropdown.style.display = 'block';
            }
        }

        function updateFocusedOption() {
            Array.from(dropdown.querySelectorAll('.condition-option')).forEach((option, index) => {
                if (index === focusedIndex) {
                    option.classList.add('focused');
                    option.setAttribute('aria-selected', 'true');
                } else {
                    option.classList.remove('focused');
                    option.setAttribute('aria-selected', 'false');
                }
            });
        }

        function selectCondition(condition) {
            selectedConditions.push(condition);
            renderTags();
            updateHiddenInput();
            dropdown.style.display = 'none';
            input.value = '';
            focusedIndex = -1;
            filteredResults = [];
        }

        function hasExactMatch() {
            return filteredResults.some(
                item => item.name.toLowerCase() === input.value.toLowerCase()
            ) || selectedConditions.some(
                item => item.name.toLowerCase() === input.value.toLowerCase()
            );
        }

        function addNewCondition() {
            if (!input.value.trim()) {
                return;
            }

            spinner.style.display = 'block';

            fetch('/api/conditions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    name: input.value,
                    category: category || null,
                }),
            })
                .then(response => {
                    if (response.status === 409) {
                        return response.json().then(data => {
                            if (data.id) {
                                return { id: data.id, name: input.value };
                            }
                            throw new Error('Condition creation failed');
                        });
                    }
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(condition => {
                    selectCondition(condition);
                    spinner.style.display = 'none';
                })
                .catch(error => {
                    console.error('Error creating condition:', error);
                    spinner.style.display = 'none';
                });
        }

        function selectFocused() {
            if (focusedIndex === -1) {
                return;
            }

            if (focusedIndex < filteredResults.length) {
                selectCondition(filteredResults[focusedIndex]);
            } else if (input.value.length > 0 && !hasExactMatch()) {
                addNewCondition();
            }
        }

        function renderTags() {
            tagsContainer.innerHTML = '';

            if (selectedConditions.length === 0) {
                tagsContainer.style.display = 'none';
                return;
            }

            tagsContainer.style.display = 'flex';

            selectedConditions.forEach(condition => {
                const tag = document.createElement('div');
                tag.className = 'condition-tag';
                
                const nameSpan = document.createElement('span');
                nameSpan.textContent = condition.name;
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'tag-remove';
                removeBtn.textContent = '×';
                removeBtn.setAttribute('aria-label', `Remove ${condition.name}`);
                if (readonly) {
                    removeBtn.disabled = true;
                }
                
                removeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    removeCondition(condition.id);
                });

                tag.appendChild(nameSpan);
                tag.appendChild(removeBtn);
                tagsContainer.appendChild(tag);
            });
        }

        function removeCondition(id) {
            selectedConditions = selectedConditions.filter(c => c.id !== id);
            renderTags();
            updateHiddenInput();
        }

        function updateHiddenInput() {
            if (hiddenInput) {
                hiddenInput.value = selectedConditions.length > 0 ? selectedConditions[0].id : '';
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    });
});
</script>

