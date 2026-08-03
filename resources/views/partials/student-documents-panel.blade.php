{{--
    Medical Documents panel — one component, both student profiles.

    The class adviser and the school nurse/clinic staff file documents against
    the same learner and read the same list, so the markup, styling, and
    behaviour live here rather than being written twice. Include this inside a
    profile panel, then include partials/student-documents-script once and call
    StudentDocuments.init()/.load() from the host page.

    Exactly one instance per page: the ids below are fixed.
--}}
@php $studentDocumentsCss = resource_path('css/student-documents.css'); @endphp
@if (file_exists($studentDocumentsCss))
    <style>{!! file_get_contents($studentDocumentsCss) !!}</style>
@endif

<div class="sd-panel-head">
    <h4>Medical Documents</h4>
    <span class="sd-count" id="sdCount">0 files</span>
</div>

<div class="doc-drop" id="sdDrop" role="button" tabindex="0" aria-label="Upload a medical document">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.5 19a4.5 4.5 0 0 0 .5-8.97A6 6 0 0 0 6.2 9.2 4.4 4.4 0 0 0 6.5 19"/><polyline points="9 13 12 10 15 13"/><line x1="12" y1="10" x2="12" y2="19"/></svg>
    <p class="doc-drop-main">Drag and drop medical documents here, or click to browse</p>
    <p class="doc-drop-sub">Supported formats: PDF, JPG, PNG, DOC, XLS (Max 10MB)</p>
    <button type="button" class="doc-drop-btn" id="sdBrowse">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload Document
    </button>
</div>
{{-- Outside the drop zone: a click dispatched on the input would otherwise
     bubble back into the zone's own click handler and re-enter the picker. --}}
<input type="file" id="sdInput" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" hidden>

<p class="doc-feedback" id="sdFeedback" role="status" aria-live="polite"></p>

<div class="doc-list-head">
    <span>Uploaded Documents</span>
    <span class="sd-count" id="sdListCount">0 files</span>
</div>
<div id="sdList"></div>
