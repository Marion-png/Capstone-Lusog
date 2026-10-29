{{-- One row of consent-forms._paper-copy: the printed words, what was
     written on the line beside them (only when something was), and the pill.
     A service row carries the service marker, so a service is never mistaken
     for one of the parent's own boxes; the marker sits inside the label so it
     never wraps away from the words it marks.
     $label, $pill ([class, words]), $line, $isService. --}}
<li class="pcopy-row is-{{ $pill[0] }}{{ $isService ? ' is-service' : '' }}"><span class="pcopy-label">@if ($isService)<span class="pcopy-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 6v12M6 12h12"/></svg></span>@endif<span class="pcopy-text" lang="ceb">{{ $label }}@if ($line !== '')<span class="pcopy-written">{{ $line }}</span>@endif</span></span><span class="pcopy-std is-{{ $pill[0] }}">{{ $pill[1] }}</span></li>
