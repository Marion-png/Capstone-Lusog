{{-- ── School Form 2 (SF2) attendance grid ─────────────────────────────
     One month of feeding attendance as the DepEd SF2 lays it out: No. and
     Name, a column per weekday, males then females with a total per day
     under each, a combined total, the month's totals and Remarks.

     Drawn by the Record Attendance page, where the session's own column
     takes a mark (`days[].editable`), and by the Attendance tab's sheet,
     which only reads it. Data: App\Support\Sf2AttendanceForm::build().

     Needs: $rows, $days, $monthLabel, $readOnly. --}}
@php
	// SF2 keeps males and females as two blocks with a total under each. A
	// learner with no recorded sex gets a block of their own rather than being
	// filed under either.
	$blocks = collect([
		['sex' => 'Male', 'label' => 'Male'],
		['sex' => 'Female', 'label' => 'Female'],
		['sex' => '', 'label' => 'Sex not recorded'],
	])->map(fn (array $block): array => $block + ['rows' => $rows->where('sex', $block['sex'])->values()])
		->filter(fn (array $block): bool => $block['sex'] !== '' || $block['rows']->isNotEmpty())
		->values();

	$glyphs = [
		\App\Support\FeedingAttendanceMark::PRESENT => '✓',
		\App\Support\FeedingAttendanceMark::ABSENT => '✗',
		\App\Support\FeedingAttendanceMark::EXCUSED => 'E',
		\App\Support\FeedingAttendanceMark::UNCONFIRMED => '?',
	];
	$stateLabel = fn (string $state): string => $state === '' ? \App\Support\FeedingAttendanceMark::NOT_RECORDED : \App\Support\FeedingAttendanceMark::label($state);
	$count = fn ($list, string $state, ?string $date = null): int => $list->sum(
		fn (array $row): int => $date !== null
			? (int) (($row['marks'][$date] ?? '') === $state)
			: count(array_filter($row['marks'], fn (string $mark): bool => $mark === $state))
	);

	$columns = 2 + count($days) + 3 + 1;
@endphp
<div class="card sf2-card">
	<div class="sf2-scroll">
		<table class="sf2" id="sf2Table">
			<thead>
				<tr>
					<th class="sf2-no" rowspan="3" scope="col">No.</th>
					<th class="sf2-name" rowspan="3" scope="col">Name <span>(Last Name, First Name, Middle Name)</span></th>
					<th class="sf2-month-head" colspan="{{ count($days) }}" scope="colgroup">{{ $monthLabel }}</th>
					<th class="sf2-totals-head" colspan="3" rowspan="2" scope="colgroup">Total for the Month</th>
					<th class="sf2-remarks" rowspan="3" scope="col">Remarks</th>
				</tr>
				<tr>
					@foreach ($days as $day)
						<th scope="col" @class(['sf2-day', 'is-today' => $day['today'], 'is-selected' => $day['selected'], 'is-closed' => $day['closed'], 'is-future' => $day['future']])
							@if ($day['closed']) title="{{ $day['closed'] }}" @endif>{{ $day['day'] }}</th>
					@endforeach
				</tr>
				<tr>
					@foreach ($days as $day)
						<th scope="col" @class(['sf2-dow', 'is-today' => $day['today'], 'is-selected' => $day['selected'], 'is-closed' => $day['closed'], 'is-future' => $day['future']])>{{ $day['weekday'] }}</th>
					@endforeach
					<th class="sf2-tot" scope="col">Absent</th>
					<th class="sf2-tot" scope="col">Excused</th>
					<th class="sf2-tot" scope="col">Present</th>
				</tr>
			</thead>

			@if ($rows->isEmpty())
				<tbody>
					<tr><td colspan="{{ $columns }}" class="table-empty">{{ $emptyText ?? 'No beneficiaries are on file for this class.' }}</td></tr>
				</tbody>
			@endif

			@foreach ($rows->isEmpty() ? [] : $blocks as $block)
				<tbody class="sf2-block" data-block="{{ $block['sex'] }}">
					<tr class="sf2-group"><th colspan="{{ $columns }}" scope="rowgroup">{{ $block['label'] }}</th></tr>
					@forelse ($block['rows'] as $row)
						{{-- Name, grade and section are carried on the row so the
						     confirmation dialog reads them back from the form. --}}
						<tr data-search="{{ strtolower(trim($row['name'].' '.$row['grade'].' '.$row['section'])) }}"
							data-name="{{ $row['name'] }}"
							data-grade="{{ $row['grade'] }}"
							data-section="{{ $row['section'] ?: '—' }}"
							data-onfile="{{ $row['mark'] }}">
							<td class="sf2-no">{{ $loop->iteration }}.</td>
							<th class="sf2-name" scope="row">{{ $row['name'] }}</th>
							@foreach ($days as $day)
								@php($state = $row['marks'][$day['date']] ?? '')
								<td @class(['sf2-cell', 'is-today' => $day['today'], 'is-selected' => $day['selected'], 'is-closed' => $day['closed'], 'is-future' => $day['future']])
									data-date="{{ $day['date'] }}" data-state="{{ $day['editable'] ? $row['mark'] : $state }}">
									@if ($day['editable'])
										<button type="button" class="sf2-mark is-{{ $row['mark'] ?: 'blank' }}" data-cell
											aria-haspopup="dialog"
											aria-label="{{ $row['name'] }}, {{ \Illuminate\Support\Carbon::parse($day['date'])->format('M j') }}: {{ $stateLabel($row['mark']) }}">{{ $glyphs[$row['mark']] ?? '' }}</button>
										{{-- The form's real fields. The cell's pop-up sets them;
										     the confirmation dialog and the endpoint read them. --}}
										<span class="sf2-fields">
											<input type="radio" name="marks[{{ $row['id'] }}]" value="present" @checked($row['mark'] === 'present') tabindex="-1">
											<input type="radio" name="marks[{{ $row['id'] }}]" value="absent" @checked($row['mark'] === 'absent') tabindex="-1">
											<input type="radio" name="marks[{{ $row['id'] }}]" value="excused" @checked($row['mark'] === 'excused') tabindex="-1">
											<input type="text" class="ra-remark" name="remarks[{{ $row['id'] }}]" value="{{ $row['remarks'] }}" maxlength="255" tabindex="-1"
												@disabled(! in_array($row['mark'], ['absent', 'excused'], true))>
										</span>
									@elseif ($state !== '')
										<span class="sf2-mark is-{{ $state }}" title="{{ $stateLabel($state) }}">{{ $glyphs[$state] ?? '' }}</span>
									@endif
								</td>
							@endforeach
							<td class="sf2-tot" data-total="absent">{{ count(array_filter($row['marks'], fn ($m) => $m === 'absent')) }}</td>
							<td class="sf2-tot" data-total="excused">{{ count(array_filter($row['marks'], fn ($m) => $m === 'excused')) }}</td>
							<td class="sf2-tot" data-total="present">{{ count(array_filter($row['marks'], fn ($m) => $m === 'present')) }}</td>
							<td class="sf2-remarks" data-remark-cell>
								@if (! $readOnly && $row['remarks'] !== '')
									{{ $row['remarks'] }}
								@elseif ($readOnly && $row['latest_remark'] !== '')
									<span class="sf2-remark-date">{{ $row['latest_remark_date'] }}</span> {{ $row['latest_remark'] }}
								@endif
							</td>
						</tr>
					@empty
						<tr class="sf2-none"><td colspan="{{ $columns }}" class="table-empty">None in this class.</td></tr>
					@endforelse
					<tr class="sf2-total">
						<th class="sf2-total-label" colspan="2" scope="row">{{ $block['label'] }} &middot; Total per day</th>
						@foreach ($days as $day)
							<td @class(['sf2-cell', 'is-today' => $day['today'], 'is-selected' => $day['selected'], 'is-closed' => $day['closed']]) data-day-total="{{ $day['date'] }}">{{ $count($block['rows'], 'present', $day['date']) ?: '' }}</td>
						@endforeach
						<td class="sf2-tot" data-block-total="absent">{{ $count($block['rows'], 'absent') }}</td>
						<td class="sf2-tot" data-block-total="excused">{{ $count($block['rows'], 'excused') }}</td>
						<td class="sf2-tot" data-block-total="present">{{ $count($block['rows'], 'present') }}</td>
						<td class="sf2-remarks"></td>
					</tr>
				</tbody>
			@endforeach

			@if ($rows->isNotEmpty())
				<tfoot>
					<tr class="sf2-total sf2-combined" data-block="all">
						<th class="sf2-total-label" colspan="2" scope="row">Combined total per day</th>
						@foreach ($days as $day)
							<td @class(['sf2-cell', 'is-today' => $day['today'], 'is-selected' => $day['selected'], 'is-closed' => $day['closed']]) data-day-total="{{ $day['date'] }}">{{ $count($rows, 'present', $day['date']) ?: '' }}</td>
						@endforeach
						<td class="sf2-tot" data-block-total="absent">{{ $count($rows, 'absent') }}</td>
						<td class="sf2-tot" data-block-total="excused">{{ $count($rows, 'excused') }}</td>
						<td class="sf2-tot" data-block-total="present">{{ $count($rows, 'present') }}</td>
						<td class="sf2-remarks"></td>
					</tr>
				</tfoot>
			@endif
		</table>
	</div>

	<ul class="sf2-legend" aria-label="Codes for checking attendance">
		<li><span class="sf2-mark is-present">✓</span> Present</li>
		<li><span class="sf2-mark is-absent">✗</span> Absent</li>
		<li><span class="sf2-mark is-excused">E</span> Excused</li>
		<li><span class="sf2-mark is-unconfirmed">?</span> Unconfirmed</li>
		<li><span class="sf2-mark is-blank"></span> {{ \App\Support\FeedingAttendanceMark::NOT_RECORDED }}</li>
		<li><span class="sf2-swatch is-closed"></span> No session</li>
	</ul>
</div>
