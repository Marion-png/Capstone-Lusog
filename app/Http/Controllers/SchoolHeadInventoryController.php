<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\SchoolHeadHealthOverview;
use App\Support\SchoolHeadOverview;
use App\Support\SchoolHeadPulse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The School Head's Inventory Overview — can the clinic still dispense.
 *
 * A stock level and a reorder standing, and nothing else. The head has
 * visibility of the inventory but not control of it: receiving stock and
 * dispensing it are the clinic's, so this tab renders no receipt form, no
 * dispensing action and no adjustment — and RestrictSchoolHeadWrites refuses
 * those endpoints server-side regardless of what a page renders.
 *
 * The dispensing log is deliberately absent too. It names the learner a
 * medicine went to and why, which is clinical information about a child; a head
 * asking "are we running out of paracetamol" does not need it.
 *
 * The reorder line is each medicine's own `minimum_threshold`, set by the
 * clinic, never a constant here.
 */
class SchoolHeadInventoryController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can open the inventory overview.');
        }

        return view('schoolhead-dashboard.inventory', $this->build($request));
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->isSchoolHead($request)) {
            return redirect()->route('login')->with('error', 'Only the School Head can export the inventory.');
        }

        $data = $this->build($request);

        $reserved = tempnam(sys_get_temp_dir(), 'sh-inventory-');
        $path = $reserved.'.xlsx';
        @unlink($reserved);

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(['MEDICINE INVENTORY — OVERVIEW']));
        $writer->addRow(Row::fromValues([(string) $data['schoolName']]));
        $writer->addRow(Row::fromValues(['Generated '.now()->format('Y-m-d')]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['NO.', 'MEDICINE', 'STOCK', 'UNIT', 'REORDER AT', 'STATUS']));

        $number = 0;
        foreach ($data['rows'] as $row) {
            $number++;
            $writer->addRow(Row::fromValues([
                $number,
                $row['name'],
                $row['stock'],
                $row['unit'],
                $row['threshold'],
                $row['label'],
            ]));
        }

        $writer->close();

        $filename = 'Medicine-Inventory-'.now()->format('Ymd').'.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId);

        $schoolYear = trim((string) $request->query('school_year', ''));
        if (! in_array($schoolYear, $years, true)) {
            $schoolYear = $years[0] ?? StudentHealthRecord::currentSchoolYear();
        }

        // The inventory is not a per-learner reading, so it needs no roster
        // scope — an empty collection keeps the shared class honest about that.
        $health = SchoolHeadHealthOverview::for($institutionId, $schoolYear, collect());
        $inventory = $health->inventory();

        $state = trim((string) $request->query('state', ''));
        if (! in_array($state, SchoolHeadHealthOverview::STOCK_STATES, true)) {
            $state = '';
        }

        $rows = collect($inventory['rows'])
            ->when($state !== '', fn ($list) => $list->where('state', $state))
            ->values();

        return [
            'schoolName' => $request->session()->get('active_school_name', 'School'),
            'schoolYear' => $schoolYear,
            'schoolYears' => $years,
            'todayLabel' => now()->format('j F Y'),
            'filters' => ['school_year' => $schoolYear, 'state' => $state],
            'states' => SchoolHeadHealthOverview::STOCK_STATES,
            'inventory' => $inventory,
            'rows' => $rows,
            'shown' => $rows->count(),
            'stamp' => SchoolHeadPulse::stamp($institutionId),
        ];
    }

    private function isSchoolHead(Request $request): bool
    {
        return strtolower(trim((string) $request->session()->get('active_role', ''))) === 'school_head';
    }
}
