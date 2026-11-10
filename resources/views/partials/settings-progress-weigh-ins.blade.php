{{--
    Progress weigh-ins — the Feeding Program Coordinator's school setting
    (App\Support\FeedingWeighIns). OFF by default: baseline and endline stay
    the only required weighings. ON, the coordinator may record optional
    weigh-ins around feeding days 30, 60 and 90 on each Beneficiary Record.
    Turning it off hides them and keeps what was recorded.

    Expects $progressWeighIns (SettingsController::index).
--}}
<section class="card set-panel" id="progress-weigh-ins" aria-labelledby="progressWeighInsTitle">
    <div class="card-head">
        <div>
            <div class="card-title" id="progressWeighInsTitle">Progress weigh-ins</div>
            <div class="card-sub">Optional weigh-ins around feeding days 30, 60 and 90, between the baseline and the endline.</div>
        </div>
    </div>

    @if (! $progressWeighIns['supported'])
        <div class="flash info set-blocked">The database has not been updated for this setting yet.</div>
    @else
        <form method="POST" action="{{ route('settings.progress-weigh-ins') }}" class="set-form">
            @csrf
            <input type="hidden" name="enabled" value="0">
            <ul class="set-switches">
                <li>
                    <label class="set-switch">
                        <input type="checkbox" name="enabled" value="1" role="switch" @checked($progressWeighIns['enabled'])>
                        <span class="set-switch-track" aria-hidden="true"></span>
                        <span class="set-switch-label">Record progress weigh-ins</span>
                    </label>
                </li>
            </ul>
            <div class="set-actions">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    @endif
</section>
