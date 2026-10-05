<section class="card set-panel set-assignment" aria-labelledby="assignmentTitle">
    <div class="card-head">
        <div>
            <div class="card-title" id="assignmentTitle">Teaching assignment</div>
            <div class="card-sub">Update your grade and section when your new school-year assignment takes effect.</div>
        </div>
    </div>

    @if (! $profile['can_change_assignment'])
        <div class="flash info set-blocked">Sign in with a stored teacher account to update its assignment.</div>
    @else
        @php
            $assignmentGrade = old('assigned_grade_level', $profile['assigned_grade_level']);
            $assignmentSection = old('assigned_section', $profile['assigned_section']);
        @endphp

        <p class="set-note" id="assignmentHelp">
            Save this change once your new assignment starts. It applies immediately to the current school year.
            Existing student records stay in their original grades, sections and school years.
            @if ($profile['role'] === 'class_adviser')
                Your student list will show your newly assigned class.
            @else
                Your clinic access continues to cover your school.
            @endif
        </p>

        <form method="POST" action="{{ route('settings.assignment') }}" class="set-form" id="assignmentForm" aria-describedby="assignmentHelp">
            @csrf

            @if ($errors->assignment->any())
                <div class="flash err" role="alert">
                    @foreach ($errors->assignment->all() as $assignmentError)
                        <div>{{ $assignmentError }}</div>
                    @endforeach
                </div>
            @endif

            <div class="set-assignment-fields">
                <div class="set-field">
                    <label class="field-label" for="assignmentYear">Current school year</label>
                    <input class="input" id="assignmentYear" name="school_year" value="{{ $assignmentSchoolYear }}" readonly>
                </div>

                <div class="set-field">
                    <label class="field-label" for="assignmentGrade">New grade level</label>
                    @if ($assignmentCatalog !== [])
                        <select class="input" id="assignmentGrade" name="assigned_grade_level" required>
                            <option value="">Select grade level</option>
                            @foreach ($assignmentCatalog as $grade => $sections)
                                <option value="{{ $grade }}" @selected($assignmentGrade === $grade)>{{ $grade }}</option>
                            @endforeach
                        </select>
                    @else
                        <input class="input" id="assignmentGrade" name="assigned_grade_level" value="{{ $assignmentGrade }}"
                               maxlength="50" placeholder="e.g. Grade 8" required>
                    @endif
                </div>

                <div class="set-field">
                    <label class="field-label" for="assignmentSection">New section</label>
                    @if ($assignmentCatalog !== [])
                        <select class="input" id="assignmentSection" name="assigned_section" required>
                            <option value="">Select section</option>
                            @foreach ($assignmentCatalog as $grade => $sections)
                                <optgroup label="{{ $grade }}">
                                    @foreach ($sections as $section)
                                        <option value="{{ $section }}" @selected($assignmentGrade === $grade && $assignmentSection === $section)>{{ $section }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    @else
                        <input class="input" id="assignmentSection" name="assigned_section" value="{{ $assignmentSection }}"
                               maxlength="100" placeholder="Enter your assigned section" required>
                    @endif
                </div>
            </div>

            <label class="set-confirm">
                <input type="checkbox" name="confirm_assignment" value="1" required>
                <span>This is my assigned grade and section, and the change should take effect now.</span>
            </label>

            <div class="set-actions">
                <button type="submit" class="btn btn-primary">Update teaching assignment</button>
            </div>
        </form>

        @if ($assignmentCatalog !== [])
            <script>
            (() => {
                const catalog = @json($assignmentCatalog);
                const grade = document.getElementById('assignmentGrade');
                const section = document.getElementById('assignmentSection');
                const previousSection = @json($assignmentSection);

                const renderSections = (selected = '') => {
                    const names = catalog[grade.value] || [];
                    section.replaceChildren(new Option(names.length ? 'Select section' : 'Select a grade level first', ''));
                    names.forEach((name) => section.add(new Option(name, name, false, name === selected)));
                };

                grade.addEventListener('change', () => renderSections());
                renderSections(previousSection);
            })();
            </script>
        @endif
    @endif
</section>
