{{--
    Photographs on a consultation — the clinic's dialog.

    A cut, a rash, a swelling: taken so an injury can be seen rather than
    described. Sharing with the learner's class adviser is a decision the
    nurse makes per photo, and the default is not shared — consultation
    detail otherwise stops at the clinic (App\Support\ConsultationVisibility),
    and a photograph of a child's injury is more revealing than the text
    beside it, not less.

    The photo is the dialog. It takes the whole left of the panel (the top on
    a phone); beside it sit the comment on the photo being looked at, every
    photo on the visit with its own comment, and — out of the photo's way —
    sharing, removing and adding.

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
        <button type="button" class="cphoto-close" id="cphotoClose" aria-label="Close">&times;</button>

        {{-- The photo itself. It opens on the newest one for this visit, and
             every state is drawn here rather than left as a blank box. --}}
        <section class="cphoto-stage" id="cphotoStage" aria-label="Photo viewer">
            <div class="cphoto-stage-frame">
                <img class="cphoto-stage-img" id="cphotoStageImg" alt="" hidden>
                <div class="cphoto-stage-state" id="cphotoStageLoading" role="status">
                    <span class="cphoto-spinner" aria-hidden="true"></span>
                    <span>Loading photo…</span>
                </div>
                <div class="cphoto-stage-state" id="cphotoStageEmpty" hidden>
                    <span>No photo uploaded for this consultation.</span>
                </div>
                <div class="cphoto-stage-state is-error" id="cphotoStageError" role="alert" hidden>
                    <strong id="cphotoStageErrorTitle">This photo could not be shown.</strong>
                    <span id="cphotoStageErrorText">Check your connection and try again.</span>
                    <div class="cphoto-stage-actions">
                        <button type="button" class="cphoto-stage-btn" id="cphotoRetry">Try again</button>
                        <a class="cphoto-stage-btn" id="cphotoDownload" href="#" download hidden>Download</a>
                    </div>
                </div>
                <button type="button" class="cphoto-nav is-prev" id="cphotoPrev" aria-label="Previous photo" hidden>&lsaquo;</button>
                <button type="button" class="cphoto-nav is-next" id="cphotoNext" aria-label="Next photo" hidden>&rsaquo;</button>
                <span class="cphoto-stage-count" id="cphotoStageCount" hidden></span>
            </div>
        </section>

        <aside class="cphoto-side">
            <div class="cphoto-head">
                <div class="cphoto-eyebrow">Consultation</div>
                <h3 id="cphotoTitle">Injury Photos</h3>
                <p class="cphoto-sub" id="cphotoSub">&nbsp;</p>
            </div>

            <div class="cphoto-side-body">
                {{-- The comment on the photo on screen, and what can be done
                     with that photo. --}}
                <section class="cphoto-detail" id="cphotoDetail" hidden>
                    <div class="cphoto-label">Comment</div>
                    <p class="cphoto-comment" id="cphotoStageCaption"></p>
                    <div class="cphoto-meta" id="cphotoDetailMeta"></div>
                    <label class="cphoto-share">
                        <input type="checkbox" id="cphotoDetailShare">
                        <span>Shared with class adviser</span>
                    </label>
                    <div class="cphoto-detail-actions">
                        <a class="cphoto-link" id="cphotoOpenFull" href="#" target="_blank" rel="noopener">Open full size</a>
                        <button type="button" class="cphoto-remove" id="cphotoDetailRemove">Remove photo</button>
                    </div>
                </section>

                {{-- Every photo on the visit with its own comment. Choosing one
                     puts it on the stage. --}}
                <section class="cphoto-all" id="cphotoAll" hidden>
                    <div class="cphoto-label">All photos <span class="cphoto-all-count" id="cphotoAllCount"></span></div>
                    <div id="cphotoList" class="cphoto-list"></div>
                </section>

                <details class="cphoto-add" id="cphotoAdd">
                    <summary>Add a photo</summary>
                    <div class="cphoto-upload">
                        <label class="cphoto-field">
                            <span>Photo</span>
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
                        <button type="button" class="btn btn-primary" id="cphotoUpload">Upload Photo</button>
                    </div>
                </details>

                <div class="cphoto-privacy">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>Photos stay in the clinic unless you share them. Share one only when the learner's class adviser needs to know about the injury.</span>
                </div>
            </div>
        </aside>
    </div>
</div>
