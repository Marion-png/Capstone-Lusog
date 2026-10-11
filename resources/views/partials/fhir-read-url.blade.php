{{--
    The GET that reads one resource back from the receiving server, with the
    id the server assigned picked out of it; a long URL wraps at its
    slashes, never inside a word. $outcome is one entry of
    FhirTransmission::serverOutcomes() (or patientOutcome()); $compact is the
    table-row size, without the Open action.
--}}
@php $compact = $compact ?? false; @endphp
<div class="fx-request{{ $compact ? ' is-compact' : '' }}">
    <span class="fx-method">GET</span>
    <code class="fx-url" title="{{ $outcome['location'] }}"><span class="fx-url-base">{!! str_replace('/', '/<wbr>', e($outcome['url_prefix'])) !!}</span><mark class="fx-url-id">{{ $outcome['id'] }}</mark></code>
    @if ($compact && $outcome['version'] !== '')
        <span class="fx-ver">v{{ $outcome['version'] }}</span>
    @endif
    <span class="fx-request-actions">
        <button type="button" class="fx-copy" data-copy-text="{{ $outcome['url'] }}">{{ $compact ? 'Copy' : 'Copy URL' }}</button>
        @unless ($compact)
            <a class="fx-copy" href="{{ $outcome['url'] }}" target="_blank" rel="noopener noreferrer">
                Open
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            </a>
        @endunless
    </span>
</div>
