{{--
    Sheet 2 as the nurse filled it — F. body systems through J. summary, in the
    clinic's own layout.

    One renderer, both student profiles. `App\Support\Sheet2Review` is the one
    structure; this is the one *reading* of it in a browser, exposed as
    `window.Sheet2Render`. The nurse's profile had this inline and the adviser's
    had nothing at all, so a learner the nurse had examined still read "No
    systems review was recorded" on the adviser's desk. Copying the layout over
    would have given the clinic's sheet a second place to drift.

    Display only: it writes nothing. Built from DOM nodes, because every value
    is something a person typed about a child.
--}}
@php $sheet2Css = resource_path('css/sheet2-review.css'); @endphp
@if (file_exists($sheet2Css))
    <style>{!! file_get_contents($sheet2Css) !!}</style>
@endif
<script>
window.Sheet2Render = (() => {
    const SHEET2_SYSTEMS = @json(\App\Support\Sheet2Review::SYSTEMS);

    const renderSheet2 = (host, sheet) => {
        host.textContent = '';
        const text = (v) => (v === null || v === undefined) ? '' : String(v);
        const dash = (v) => text(v).trim() !== '' ? text(v) : '—';

        const section = (title) => {
            const h = document.createElement('div');
            h.className = 'sp-review-title s2-section';
            h.textContent = title;
            host.appendChild(h);
        };
        const kvGrid = (pairs) => {
            const grid = document.createElement('div');
            grid.className = 'student-profile-grid';
            pairs.forEach(([label, value]) => {
                const cell = document.createElement('div');
                const k = document.createElement('span');
                k.textContent = label + ':';
                const v = document.createElement('b');
                v.textContent = dash(value);
                cell.append(k, v);
                grid.appendChild(cell);
            });
            host.appendChild(grid);
        };

        section('F. Evaluation of Body Systems');
        const table = document.createElement('table');
        table.className = 's2-table';
        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        ['Body System', 'Findings', 'Notes / Details'].forEach((t) => {
            const th = document.createElement('th');
            th.textContent = t;
            hr.appendChild(th);
        });
        thead.appendChild(hr);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        const systems = (sheet && sheet.systems) || {};
        Object.entries(SHEET2_SYSTEMS).forEach(([key, label]) => {
            const row = systems[key] || {};
            const tr = document.createElement('tr');
            const th = document.createElement('th');
            th.scope = 'row';
            th.textContent = label;
            const finding = document.createElement('td');
            finding.textContent = dash(row.finding);
            if (text(row.finding) === 'Abnormal') finding.className = 'is-abnormal';
            const notes = document.createElement('td');
            notes.textContent = text(row.notes);
            tr.append(th, finding, notes);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        host.appendChild(table);

        const vision = (sheet && sheet.vision) || {};
        const hearing = (sheet && sheet.hearing) || {};
        section('G. Vision and Hearing Screening');
        kvGrid([['Right Eye', vision.right], ['Left Eye', vision.left], ['Vision Result', vision.result], ['Hearing Result', hearing.result]]);

        const oral = (sheet && sheet.oral) || {};
        section('H. Oral Health Examination');
        kvGrid([['Teeth Condition', oral.teeth], ['Last Dental Visit', oral.last_visit || 'N/A'], ['Referral', oral.referral]]);

        const imm = (sheet && sheet.immunization) || {};
        section('I. Immunization Status');
        kvGrid([['Status', imm.status], ['Missing / Needed Vaccines', imm.missing || 'None'], ['Date Record Reviewed', imm.reviewed_at]]);

        const summary = (sheet && sheet.summary) || {};
        section('J. Assessment Summary and Recommendations');
        kvGrid([['Summary of Findings', summary.findings], ['Recommendations / Referrals', summary.recommendations], ['Examiner', summary.examiner], ['Date', summary.date]]);
    };

    /** Whether a saved sheet has anything in it. */
    const isFilled = (sheet) => Boolean(
        sheet && typeof sheet === 'object' && Object.keys(sheet).length > 0
    );

    /** The nurse's sheet off a record's examination column, or null. */
    const fromExamination = (examination) => (
        examination && typeof examination === 'object' ? examination.sheet2 : null
    );

    return { into: renderSheet2, isFilled, fromExamination };
})();
</script>
