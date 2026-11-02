{{-- The programme's headline figures — the Feeding Coordinator's own cards,
     the very partial their Beneficiaries tab draws, fed from the same
     FeedingBeneficiarySummary::tally(). So the nurse and the coordinator read
     one programme size, one wasting count and one turnout, never two.

     The Clinic Teacher's tally carries no flag (it is never computed for
     them), and the partial leaves the At Risk card out. --}}
@include('feedingcor-dashboard.partials.beneficiary-cards', ['beneficiarySummary' => $beneficiarySummary ?? []])
