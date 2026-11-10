<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The Feeding Coordinator's narrative report, rendered to a PDF.
 *
 * The narrative is prose, not a grid, so it leaves the app as a document
 * rather than a workbook: the same letterhead, title line, five headed
 * sections and Prepared by / Noted by block the SBFP Forms page prints, set
 * on A4 by `feedingcor-dashboard/narrative-pdf`.
 *
 * Rendered by dompdf with remote loading off and every resource inline, so a
 * report can never reach out to the network while it is being drawn. The two
 * seals are PNGs with transparency, which dompdf can only read through PHP's
 * GD extension; where GD is not installed the seal boxes print empty — the
 * same box the printed form holds for a seal a school has not dropped in —
 * rather than failing the download.
 */
final class NarrativeReportPdf
{
    /** The report's sections, field suffix => heading, in the order the form sets them. */
    public const SECTIONS = [
        'introduction' => 'Introduction',
        'background' => 'Background and Rationale',
        'implementation' => 'Implementation',
        'results' => 'Results and Impact',
        'conclusion' => 'Conclusion and Recommendation',
    ];

    /**
     * @param  array{letterhead: array<string, string>, funder: string, sections: array<string, string>, preparedName: string, notedName: string, notedRole: string}  $report
     */
    public static function render(array $report): string
    {
        $html = view('feedingcor-dashboard.narrative-pdf', $report + [
            'headings' => self::SECTIONS,
            'seals' => self::seals(),
        ])->render();

        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        // DejaVu carries the whole of Filipino and English typography (ñ, the
        // en and em dashes, curly quotes); the PDF core fonts do not.
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');
        $options->setTempDir(sys_get_temp_dir());
        if (is_dir($cache) && is_writable($cache)) {
            $options->setFontCache($cache);
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The seals as inline images, or null where they cannot be drawn: the
     * file is missing, or GD — which dompdf needs for any PNG — is not loaded.
     *
     * @return array{deped: ?string, school: ?string}
     */
    private static function seals(): array
    {
        $inline = static function (?string $file): ?string {
            if ($file === null || ! function_exists('imagecreatefrompng')) {
                return null;
            }

            $bytes = @file_get_contents($file);

            return $bytes === false ? null : 'data:image/png;base64,'.base64_encode($bytes);
        };

        $files = SchoolLetterhead::sealFiles();

        return ['deped' => $inline($files['deped']), 'school' => $inline($files['school'])];
    }
}
