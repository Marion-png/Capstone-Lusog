<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ImageCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageMargin;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The SBFP Forms page's forms, written into a workbook laid out as the form.
 *
 * "Download Excel" on that page hands over the document on screen, not a dump
 * of its figures: the same letterhead with a seal either side, the same banner
 * and school-year line, the BMI grid with its two-row head merged exactly as
 * the printed table spans it, the master list ruled to the form's twenty
 * lines, and the boxed Prepared / Attested / Noted block. Every merge, border,
 * width and row height is set here rather than left to Excel, so the file
 * opens as a form that can be printed and signed instead of a table someone
 * has to rebuild first.
 *
 * Only the layout lives here. The figures come from BmiAssessmentReport and
 * the rows from the coordinator's roster, so the downloaded grid and the one
 * on screen are the same numbers by construction. The School Head's report
 * export writes its grid through here too: one layout, so the two files
 * cannot drift apart.
 *
 * The BMI report carries its charts too: one clustered column chart per grid,
 * as the form draws them, written as native Excel charts that read the grid's
 * own cells (XlsxCharts — OpenSpout has no charts, so they are added to the
 * file after it closes), then the insight beneath them. Each part of the
 * report starts a new printed page when it would not fit on the current one,
 * so no grid or chart is cut in two.
 */
final class SbfpFormWorkbook
{
    /** The forms' body face. Arial prints the same wherever the file is opened. */
    private const FONT = 'Arial';

    private const INK = '0F172A';

    /** --lg-emerald-deep: the banner the form opens its report under. */
    private const BANNER = '126B3A';

    /** The ruled table's head fill and the read-only cells', as the form prints them. */
    private const HEAD_FILL = 'F3F4F6';

    /** --lg-mint: the grid's Sex column. */
    private const SEX_FILL = 'E7F5EC';

    /** Each seal's box, in pixels: the form draws them at 72px. */
    private const SEAL_PX = 72;

    /** Width, in Excel character units, of a column a seal sits in (~82px). */
    private const SEAL_COLUMN = 11;

    /** The print-out's margins, in inches: the page's own @page sets 10mm. */
    private const MARGIN = 0.4;

    /** The rows a chart is drawn over, at 15pt each (~300px tall): two to a printed page. */
    private const CHART_ROWS = 15;

    /**
     * Printable height of a landscape A4 page in points, less a margin of
     * safety: 8.27in less the 0.4in margins, at 72pt an inch, is ~537pt.
     */
    private const LANDSCAPE_PAGE_PT = 530;

    private int $row = 0;

    private int $sheet;

    /** @var array<int, float> row => height in points, as written */
    private array $heights = [];

    /** @var list<int> first row of each part a printed page must not split */
    private array $blocks = [];

    /** @var list<array<string, mixed>> the grids written, for their charts */
    private array $grids = [];

    /** @var list<array<string, mixed>> the charts to draw, see XlsxCharts::chartXml() */
    private array $charts = [];

    /**
     * What each open writer still owes its file once OpenSpout has closed it:
     * the charts and page breaks, keyed by spl_object_id() of the writer.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private static array $pending = [];

    /**
     * @param  int  $last  the last column, 0-based
     */
    private function __construct(private readonly XlsxWriter $writer, private readonly int $last)
    {
        $this->sheet = $writer->getCurrentSheet()->getIndex();

        // A form, not a spreadsheet: no gridlines between the ruled parts.
        // Only the first sheet opens selected — two selected tabs would open
        // grouped, and a change typed into one would land on both.
        $writer->getCurrentSheet()->setSheetView(new SheetView(
            showGridLines: false,
            tabSelected: $this->sheet === 0,
        ));
    }

    /**
     * A writer for one of these forms: A4, one page wide, as many long as the
     * roll needs. The BMI report is landscape, as the page prints it; the
     * master lists are portrait.
     */
    public static function writer(bool $landscape): XlsxWriter
    {
        return new XlsxWriter(new Options(
            FALLBACK_STYLE: new Style(fontSize: 10, fontName: self::FONT),
            pageMargin: new PageMargin(self::MARGIN, self::MARGIN, self::MARGIN, self::MARGIN, 0.2, 0.2),
            pageSetup: new PageSetup(
                $landscape ? PageOrientation::LANDSCAPE : PageOrientation::PORTRAIT,
                PaperSize::A4,
                fitToHeight: 0,
                fitToWidth: 1,
            ),
        ));
    }

    /**
     * One weighing's Nutritional Assessment (BMI) report, as one sheet.
     *
     * Twelve columns, the grid's own: Sex, the five BMI-for-age columns and
     * their Total, the four height-for-age columns and theirs. The two seals
     * sit in the first and last of them, which is why both are as wide as a
     * seal.
     *
     * @param  array<string, string>  $letterhead  SchoolLetterhead::for(), address as typed on the form
     * @param  'baseline'|'endline'  $phase
     * @param  array<string, int|string>  $values  one BmiAssessmentReport::values() set
     * @param  list<string>  $gridKeys  the grids on screen, in order ("g7" … "overall")
     * @param  list<string>  $insight  the sentences under the charts
     * @param  list<array{label: string, name: string, role: string}>  $signatories
     */
    public static function bmiReport(
        XlsxWriter $writer,
        string $sheetName,
        array $letterhead,
        string $phase,
        array $values,
        array $gridKeys,
        string $schoolYear,
        string $scope,
        array $insight,
        string $insightScope,
        array $signatories,
    ): void {
        $writer->getCurrentSheet()->setName($sheetName);
        $form = new self($writer, 11);

        $sheet = $writer->getCurrentSheet();
        $sheet->setColumnWidth(self::SEAL_COLUMN, 1);
        $sheet->setColumnWidth(10.5, 2, 3, 4, 5, 6);
        $sheet->setColumnWidth(8, 7);
        $sheet->setColumnWidth(10.5, 8, 9, 10, 11);
        $sheet->setColumnWidth(self::SEAL_COLUMN, 12);

        $form->block();
        $form->letterhead($letterhead);
        $form->banner($phase === 'endline'
            ? 'Final Nutritional Assessment (BMI) Report'
            : 'Baseline Nutritional Assessment (BMI) Report');
        $form->centred('S.Y. '.$schoolYear, $form->style(bold: true, size: 10), 15);
        if ($scope !== '') {
            $form->centred($scope, $form->style(bold: true, size: 9), 14);
        }
        $form->headRule();
        $form->spacer(6);

        $prefix = BmiAssessmentReport::prefixFor($phase);
        foreach ($gridKeys as $gradeKey) {
            $form->block();
            $form->bmiGrid($prefix.'_'.$gradeKey, BmiAssessmentReport::gridTitle($gradeKey), $values);
        }

        // The form's charts, in its order: one per grid on screen, each a
        // picture of the grid it follows, read from that grid's own cells.
        $form->block();
        $form->across(
            'DATA VISUALIZATION — '.($phase === 'endline' ? 'ENDLINE' : 'BASELINE').' NUTRITIONAL ASSESSMENT',
            $form->style(bold: true, size: 10),
            18,
        );
        foreach ($form->grids as $index => $grid) {
            if ($index > 0) {
                $form->block();
            }
            $form->chart($grid, $sheetName, $schoolYear);
        }

        if ($insight !== []) {
            $form->block();
            $form->insight($insight, $insightScope);
        }

        $form->block();
        $form->spacer(8);
        // Three blocks across twelve columns, four columns each.
        $form->signatures($signatories, [[0, 3], [4, 7], [8, 11]]);

        self::$pending[spl_object_id($writer)][] = [
            'sheet' => $form->sheet,
            'charts' => $form->charts,
            'breaks' => $form->pageBreaks(self::LANDSCAPE_PAGE_PT),
        ];
    }

    /**
     * Closes the writer and finishes the file: the charts and the page breaks
     * OpenSpout cannot write are added to the closed workbook (XlsxCharts).
     * Every caller closes a form's writer through here, never directly.
     */
    public static function close(XlsxWriter $writer, string $path): void
    {
        $writer->close();

        $sheets = self::$pending[spl_object_id($writer)] ?? [];
        unset(self::$pending[spl_object_id($writer)]);

        if ($sheets !== []) {
            XlsxCharts::addTo($path, $sheets);
        }
    }

    /**
     * Where a printed page may break: before any part that would not fit on
     * what is left of the page, so a grid, a chart or the signature block is
     * never cut in two. A part taller than a page is left to Excel.
     *
     * @return list<int> the 1-based rows a page ends on
     */
    private function pageBreaks(float $pageHeight): array
    {
        $breaks = [];
        $used = 0.0;
        $starts = array_values(array_unique($this->blocks));

        foreach ($starts as $index => $start) {
            $end = ($starts[$index + 1] ?? $this->row + 1) - 1;
            $height = 0.0;
            for ($row = $start; $row <= $end; $row++) {
                $height += $this->heights[$row] ?? 13.0;
            }

            if ($used > 0 && $used + $height > $pageHeight) {
                $breaks[] = $start - 1;
                $used = 0.0;
            }
            $used += $height;
        }

        return $breaks;
    }

    /** The next row starts a part a printed page must keep whole. */
    private function block(): void
    {
        $this->blocks[] = $this->row + 1;
    }

    /**
     * Reserves the rows one chart is drawn over and records what it reads:
     * the grid's column heads for its categories and the MALE, FEMALE and
     * TOTAL rows for its three series — the very cells above, so the chart
     * redraws if a figure in the grid is corrected.
     *
     * @param  array<string, mixed>  $grid
     */
    private function chart(array $grid, string $sheetName, string $schoolYear): void
    {
        $peak = 0;
        foreach ($grid['series'] as $series) {
            $peak = max($peak, ...array_map('intval', $series['values']));
        }

        $from = $this->row;
        for ($line = 0; $line < self::CHART_ROWS; $line++) {
            $this->spacer(15);
        }

        $this->charts[] = $grid + [
            'sheetName' => $sheetName,
            'chartTitle' => $grid['title'].'  ·  '.$schoolYear,
            'axis' => BmiAssessmentReport::axisScale($peak),
            'fromRow' => $from,
            'toRow' => $this->row,
            'toColumn' => $this->last + 1,
        ];

        $this->spacer(8);
    }

    /**
     * A master list as the forms page rules it: No. / Name / Grade / Section /
     * Class Adviser, never fewer than the form's twenty lines.
     *
     * Six columns for five on paper: the seals need a column each side, and
     * the right-hand one is the second half of Class Adviser, merged row by
     * row so the table still reads as five columns.
     *
     * @param  array<string, string>  $letterhead
     * @param  list<array{name: string, grade: string, section: string, adviser?: string}>  $rows
     * @param  list<array{label: string, name: string, role: string}>  $signatories
     */
    public static function masterlist(
        XlsxWriter $writer,
        string $sheetName,
        array $letterhead,
        string $title,
        string $schoolYear,
        string $scope,
        array $rows,
        array $signatories,
    ): void {
        $writer->getCurrentSheet()->setName($sheetName);
        $form = new self($writer, 5);

        $sheet = $writer->getCurrentSheet();
        $sheet->setColumnWidth(self::SEAL_COLUMN, 1);
        $sheet->setColumnWidth(32, 2);
        $sheet->setColumnWidth(9, 3);
        $sheet->setColumnWidth(14, 4);
        $sheet->setColumnWidth(18, 5);
        $sheet->setColumnWidth(self::SEAL_COLUMN, 6);

        $form->letterhead($letterhead);
        $form->title($title);
        $form->centred('S.Y. '.$schoolYear, $form->style(bold: true, size: 10), 15);
        if ($scope !== '') {
            $form->centred($scope, $form->style(bold: true, size: 9), 14);
        }
        $form->headRule();
        $form->spacer(6);

        $head = $form->style(bold: true, size: 9, align: CellAlignment::CENTER, fill: self::HEAD_FILL, box: true);
        $headRow = $form->add([
            Cell::fromValue('NO.', $head),
            Cell::fromValue('NAME', $head),
            Cell::fromValue('GRADE', $head),
            Cell::fromValue('SECTION', $head),
            Cell::fromValue('CLASS ADVISER', $head),
            Cell::fromValue('', $head),
        ], 18);
        $form->merge(4, $headRow, 5, $headRow);
        // A long roll runs onto a second page; its head goes with it. The
        // sheet's name is one word on purpose: OpenSpout writes it into the
        // print-title reference unquoted.
        $sheet->setPrintTitleRows('$'.$headRow.':$'.$headRow);

        $number = $form->style(bold: true, size: 9, align: CellAlignment::CENTER, box: true);
        $text = $form->style(size: 9, box: true, wrap: true);
        $centred = $form->style(size: 9, align: CellAlignment::CENTER, box: true);

        $rows = array_values($rows);
        $lines = max(MasterlistSheet::MIN_ROWS, count($rows));
        for ($line = 0; $line < $lines; $line++) {
            $entry = $rows[$line] ?? ['name' => '', 'grade' => '', 'section' => ''];
            $at = $form->add([
                Cell::fromValue($line + 1, $number),
                Cell::fromValue((string) $entry['name'], $text),
                Cell::fromValue((string) $entry['grade'], $centred),
                Cell::fromValue((string) $entry['section'], $text),
                Cell::fromValue((string) ($entry['adviser'] ?? ''), $text),
                Cell::fromValue('', $text),
            ], 16);
            $form->merge(4, $at, 5, $at);
        }

        $form->spacer(10);
        // Prepared by under No. and Name, Noted by under the rest.
        $form->signatures($signatories, [[0, 1], [2, 5]]);
    }

    /** "BMI-Report-Baseline-2026-2027-20261110.xlsx" — a name a folder sorts. */
    public static function filename(string $name, string $schoolYear, string $extension = 'xlsx'): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-');

        return $slug.'-'.str_replace('/', '-', $schoolYear).'-'.SchoolClock::now()->format('Ymd').'.'.$extension;
    }

    // ── The parts every form shares ──────────────────────────────────────

    /**
     * The form's heading: the DepEd seal at the left, the school's at the
     * right, and between them the lines `partials/form-letterhead` sets, in
     * its order and weights. The first line spans the whole sheet so the seals
     * hang from the second, level with the middle of the block.
     *
     * @param  array<string, string>  $letterhead
     */
    private function letterhead(array $letterhead): void
    {
        $seals = SchoolLetterhead::sealFiles();
        $lines = [
            [$letterhead['republic'], $this->style(size: 9, align: CellAlignment::CENTER), 13],
            [$letterhead['department'], $this->style(bold: true, size: 11, align: CellAlignment::CENTER), 15],
            [$letterhead['region'], $this->style(size: 9, align: CellAlignment::CENTER), 13],
            [SchoolLetterhead::divisionLine($letterhead['division']), $this->style(size: 9, align: CellAlignment::CENTER), 13],
            [mb_strtoupper($letterhead['school']), $this->style(bold: true, size: 13, align: CellAlignment::CENTER), 19],
            // An address nobody holds is a line to write on, never a guess.
            [$letterhead['address'] !== '' ? $letterhead['address'] : ' ', $this->style(size: 9, italic: true, align: CellAlignment::CENTER), 14],
        ];

        foreach ($lines as $index => [$text, $style, $height]) {
            if ($index === 0) {
                $this->merge(0, $this->add([Cell::fromValue($text, $style)], $height), $this->last, $this->row);

                continue;
            }

            $cells = array_fill(0, $this->last + 1, Cell::fromValue(''));
            $cells[1] = Cell::fromValue($text, $style);
            if ($index === 1) {
                $cells[0] = $this->seal($seals['deped']);
                $cells[$this->last] = $this->seal($seals['school']);
            }
            $this->merge(1, $this->add($cells, $height), $this->last - 1, $this->row);
        }
    }

    /** A seal, or nothing where the school has not dropped one in. */
    private function seal(?string $file): Cell
    {
        return $file !== null ? new ImageCell($file, self::SEAL_PX, self::SEAL_PX) : Cell::fromValue('');
    }

    /** The report's banner: white on the brand's deep green, as on the form. */
    private function banner(string $text): void
    {
        $this->spacer(6);
        $style = $this->style(bold: true, size: 11, align: CellAlignment::CENTER, fill: self::BANNER, color: 'FFFFFF');
        $this->across(mb_strtoupper($text), $style, 21);
    }

    /** A master list's title, which may run to two lines. */
    private function title(string $text): void
    {
        $this->spacer(6);
        $style = $this->style(bold: true, size: 11, align: CellAlignment::CENTER, wrap: true);
        $this->across(mb_strtoupper($text), $style, mb_strlen($text) > 70 ? 30 : 17);
    }

    private function centred(string $text, Style $style, float $height): void
    {
        $this->across(mb_strtoupper($text), $style->withCellAlignment(CellAlignment::CENTER), $height);
    }

    /** The rule the form draws under its heading. */
    private function headRule(): void
    {
        $style = $this->style(borders: [BorderName::BOTTOM]);
        $cells = array_fill(0, $this->last + 1, Cell::fromValue('', $style));
        $cells[0] = Cell::fromValue(' ', $style);
        $this->add($cells, 5);
    }

    /**
     * One BMI grid: its underlined title, the two-row head merged as the
     * printed table spans it (Sex down both rows, Nutritional Status over six
     * columns, Height for Age over five), then MALE, FEMALE and TOTAL.
     *
     * @param  array<string, int|string>  $values
     */
    private function bmiGrid(string $prefix, string $title, array $values): void
    {
        $this->across($title, $this->style(bold: true, size: 11, underline: true, align: CellAlignment::CENTER), 18);

        $head = $this->style(bold: true, size: 9, align: CellAlignment::CENTER, fill: self::HEAD_FILL, box: true, wrap: true);
        $subhead = $this->style(bold: true, size: 8, align: CellAlignment::CENTER, fill: self::HEAD_FILL, box: true, wrap: true);
        $sex = $this->style(bold: true, size: 9, align: CellAlignment::CENTER, fill: self::SEX_FILL, box: true);
        $figure = $this->style(size: 9, align: CellAlignment::CENTER, fill: self::HEAD_FILL, box: true);

        $nsCount = count(BmiAssessmentReport::NS_COLUMNS);
        $hfaCount = count(BmiAssessmentReport::HFA_COLUMNS);
        $hfaFrom = 1 + $nsCount + 1;

        $groups = array_fill(0, $this->last + 1, Cell::fromValue('', $head));
        $groups[0] = Cell::fromValue('SEX', $head);
        $groups[1] = Cell::fromValue('NUTRITIONAL STATUS', $head);
        $groups[$hfaFrom] = Cell::fromValue('HEIGHT FOR AGE (HFA)', $head);
        $top = $this->add($groups, 18);

        $labels = array_merge(
            [''],
            array_values(BmiAssessmentReport::NS_COLUMNS),
            ['Total'],
            array_values(BmiAssessmentReport::HFA_COLUMNS),
            ['Total'],
        );
        $this->add(array_map(fn (string $label): Cell => Cell::fromValue($label, $subhead), $labels), 30);

        $this->merge(0, $top, 0, $top + 1);
        $this->merge(1, $top, $nsCount + 1, $top);
        $this->merge($hfaFrom, $top, $hfaFrom + $hfaCount, $top);

        $keys = array_merge(
            array_keys(BmiAssessmentReport::NS_COLUMNS),
            ['nst'],
            array_keys(BmiAssessmentReport::HFA_COLUMNS),
            ['hfat'],
        );

        $series = [];
        foreach (BmiAssessmentReport::SEX_ROWS as $sexKey => $sexLabel) {
            $cells = [Cell::fromValue($sexLabel, $sex)];
            $figures = [];
            foreach ($keys as $key) {
                $value = $this->count($values[$prefix.'_'.$sexKey.'_'.$key] ?? '');
                $figures[] = is_int($value) ? $value : 0;
                $cells[] = Cell::fromValue($value, $figure);
            }
            $series[] = ['key' => $sexKey, 'label' => $sexLabel, 'row' => $this->add($cells, 17), 'values' => $figures];
        }

        // What this grid's chart reads: its column heads and its three rows.
        $this->grids[] = [
            'title' => $title,
            'labelRow' => $top + 1,
            'labels' => array_slice($labels, 1),
            'series' => $series,
        ];

        $this->spacer(10);
    }

    /**
     * A grid cell as a number Excel can add up. Every cell is a count and
     * prints as one, zero included; anything else is left as written.
     */
    private function count(int|string|null $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) ? (int) $value : $value;
    }

    /**
     * The sentences the form prints under its charts, one per row, each
     * row tall enough for its own sentence: Excel does not grow a merged row
     * to fit its text.
     *
     * @param  list<string>  $lines
     */
    private function insight(array $lines, string $scope): void
    {
        $head = 'INSIGHT'.($scope !== '' ? ' — '.mb_strtoupper($scope) : '');
        $this->across($head, $this->style(bold: true, size: 9), 16);

        $style = $this->style(size: 9, wrap: true, vertical: CellVerticalAlignment::TOP);
        foreach ($lines as $line) {
            $text = '•  '.$line;
            $this->across($text, $style, 13 * max(1, (int) ceil(mb_strlen($text) / 140)) + 2);
        }
    }

    /**
     * The boxed signature block: one box per signatory, the label at its top
     * left, room to sign, the name centred on a line and the designation
     * under it — the form's `foot-grid`.
     *
     * @param  list<array{label: string, name: string, role: string}>  $signatories
     * @param  list<array{0: int, 1: int}>  $spans  first and last column of each box
     */
    private function signatures(array $signatories, array $spans): void
    {
        $part = function (int $column, string $kind) use ($spans): Style {
            [$from, $to] = $this->spanOf($column, $spans);
            $edges = [];
            if ($column === $from) {
                $edges[] = BorderName::LEFT;
            }
            if ($column === $to) {
                $edges[] = BorderName::RIGHT;
            }
            if ($kind === 'label') {
                $edges[] = BorderName::TOP;
            }
            // The name sits on the line it is signed on; the designation row
            // closes the box.
            if ($kind === 'name' || $kind === 'role') {
                $edges[] = BorderName::BOTTOM;
            }

            return match ($kind) {
                'label' => $this->style(bold: true, size: 9, borders: $edges),
                'name' => $this->style(bold: true, size: 10, align: CellAlignment::CENTER, borders: $edges),
                'role' => $this->style(bold: true, size: 9, align: CellAlignment::CENTER, borders: $edges, wrap: true),
                default => $this->style(size: 9, borders: $edges),
            };
        };

        $rows = [
            'label' => fn (array $who): string => mb_strtoupper($who['label']),
            'space' => fn (array $who): string => ' ',
            'name' => fn (array $who): string => mb_strtoupper($who['name']),
            'role' => fn (array $who): string => $who['role'],
        ];
        $heights = ['label' => 16, 'space' => 22, 'name' => 17, 'role' => 17];

        foreach ($rows as $kind => $value) {
            $cells = [];
            for ($column = 0; $column <= $this->last; $column++) {
                $cells[] = Cell::fromValue('', $part($column, $kind));
            }

            foreach ($spans as $index => [$from]) {
                $cells[$from] = Cell::fromValue($value($signatories[$index] ?? ['label' => '', 'name' => '', 'role' => '']), $part($from, $kind));
            }

            // A merged cell shows the borders of every cell it covers, which is
            // why each one above was written with its box edges.
            $at = $this->add($cells, $heights[$kind]);
            foreach ($spans as [$from, $to]) {
                $this->merge($from, $at, $to, $at);
            }
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $spans
     * @return array{0: int, 1: int}
     */
    private function spanOf(int $column, array $spans): array
    {
        foreach ($spans as $span) {
            if ($column >= $span[0] && $column <= $span[1]) {
                return $span;
            }
        }

        return [$column, $column];
    }

    // ── Rows, merges and styles ─────────────────────────────────────────

    /** One line of text across the whole sheet. */
    private function across(string $text, Style $style, float $height): void
    {
        $this->merge(0, $this->add([Cell::fromValue($text, $style)], $height), $this->last, $this->row);
    }

    /**
     * Breathing room between parts. OpenSpout drops a row of empty cells, and
     * its height with it, so the first cell holds a space.
     */
    private function spacer(float $height): void
    {
        $this->add([Cell::fromValue(' ')], $height);
    }

    /**
     * @param  list<Cell>  $cells
     * @return int the row just written, 1-based
     */
    private function add(array $cells, float $height = 0): int
    {
        $row = new Row(array_values($cells));
        $this->writer->addRow($height > 0 ? $row->withHeight($height) : $row);
        $this->heights[$this->row + 1] = $height > 0 ? $height : 13.0;

        return ++$this->row;
    }

    private function merge(int $fromColumn, int $fromRow, int $toColumn, int $toRow): void
    {
        $this->writer->getOptions()->mergeCells($fromColumn, $fromRow, $toColumn, $toRow, $this->sheet);
    }

    /**
     * @param  list<BorderName>  $borders  edges to rule; $box rules all four
     */
    private function style(
        bool $bold = false,
        int $size = 10,
        bool $italic = false,
        bool $underline = false,
        ?CellAlignment $align = null,
        ?CellVerticalAlignment $vertical = CellVerticalAlignment::CENTER,
        ?string $fill = null,
        string $color = self::INK,
        bool $box = false,
        array $borders = [],
        bool $wrap = false,
    ): Style {
        $style = (new Style(fontSize: $size, fontName: self::FONT))
            ->withFontBold($bold)
            ->withFontItalic($italic)
            ->withFontUnderline($underline)
            ->withFontColor($color);

        if ($align !== null) {
            $style = $style->withCellAlignment($align);
        }
        if ($vertical !== null) {
            $style = $style->withCellVerticalAlignment($vertical);
        }
        if ($fill !== null) {
            $style = $style->withBackgroundColor($fill);
        }
        if ($wrap) {
            $style = $style->withShouldWrapText(true);
        }

        $edges = $box ? [BorderName::TOP, BorderName::BOTTOM, BorderName::LEFT, BorderName::RIGHT] : $borders;
        if ($edges !== []) {
            $style = $style->withBorder(new Border(...array_map(
                static fn (BorderName $edge): BorderPart => new BorderPart($edge, '111827', BorderWidth::THIN),
                $edges
            )));
        }

        return $style;
    }
}
