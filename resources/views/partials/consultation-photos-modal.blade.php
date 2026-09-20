{{--
    Photographs on a consultation — the clinic's dialog.

    A cut, a rash, a swelling: taken so an injury can be seen rather than
    described. Sharing with the learner's class adviser is a decision the
    nurse makes per photo, and the default is not shared — consultation
    detail otherwise stops at the clinic (App\Support\ConsultationVisibility),
    and a photograph of a child's injury is more revealing than the text
    beside it, not less.

    Opened by any control carrying data-photos-open="<consultation id>".
    Carries its own sheet: it is opened from the Consultation Log and from a
    learner's profile, and those pages load different stylesheets.
--}}
@php $cphotoCss = resource_path('css/consultation-photos.css'); @endphp
@if (file_exists($cphotoCss))
    <style>{!! file_get_contents($cphotoCss) !!}</style>
@endif
<div class="cphoto-backdrop" id="cphotoBackdrop" hidden>
    <div class="cphoto-panel" role="dialog" aria-modal="true" aria-labelledby="cphotoTitle">
        <div class="cphoto-head">
            <div>
                <div class="cphoto-eyebrow">Consultation</div>
                <h3 id="cphotoTitle">Injury Photos</h3>
                <p class="cphoto-sub" id="cphotoSub">&nbsp;</p>
            </div>
            <button type="button" class="cphoto-close" id="cphotoClose" aria-label="Close">&times;</button>
        </div>

        <div class="cphoto-body">
            <div class="cphoto-privacy">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Photos stay in the clinic unless you share them. Share one only when the learner's class adviser needs to know about the injury.</span>
            </div>

            <div id="cphotoList" class="cphoto-list"></div>

            <div class="cphoto-upload">
                <label class="cphoto-field">
                    <span>Add a photo</span>
                    <input type="file" id="cphotoFile" accept="image/jpeg,image/png,image/webp,image/heic">
                </label>
                <label class="cphoto-field">
                    <span>What it shows (optional)</span>
                    <input type="text" id="cphotoCaption" maxlength="500" placeholder="e.g. Graze to the left knee, cleaned and dressed" autocomplete="off">
                </label>
                <label class="cphoto-check">
                    <input type="checkbox" id="cphotoShare">
                    <span>Share with the learner's class adviser</span>
                </label>
                <div class="cphoto-error" id="cphotoError" hidden></div>
            </div>
        </div>

        <div class="cphoto-foot">
            <button type="button" class="btn btn-secondary" data-photos-close>Close</button>
            <button type="button" class="btn" id="cphotoUpload">Upload Photo</button>
        </div>
    </div>
</div>
