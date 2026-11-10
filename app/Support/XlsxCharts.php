<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Native Excel charts and page breaks, added to a workbook OpenSpout has
 * already written.
 *
 * OpenSpout writes cells, merges, styles and images but no charts, and the BMI
 * report is not the form without them. An .xlsx is a zip of XML parts, so the
 * charts are added the way Excel itself stores them: one chart part per chart
 * (`xl/charts/chartN.xml`), anchored over the rows the workbook reserved for
 * it in the sheet's drawing part — the same part OpenSpout put the seals in —
 * with each new part declared in `[Content_Types].xml`.
 *
 * Each chart's series are formulas over the grid's own cells, so Excel draws
 * the numbers in the table and redraws if one is corrected; the values are
 * cached beside the formulas too, so a viewer that does not recalculate
 * (a phone's preview, Google Sheets on import) still shows the bars.
 */
final class XlsxCharts
{
    /** MALE, FEMALE, TOTAL: the validated --sh-ob / --sh-w steps and the neutral sum, as the form draws them. */
    private const SERIES_COLOURS = ['male' => '2F6FB3', 'female' => 'D0741C', 'total' => '4B5A52'];

    private const NS_DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    private const NS_MAIN = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_CHART = 'http://schemas.openxmlformats.org/drawingml/2006/chart';

    private const NS_PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @param  list<array{sheet: int, charts: list<array<string, mixed>>, breaks: list<int>}>  $sheets
     */
    public static function addTo(string $path, array $sheets): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the workbook to add its charts.');
        }

        $types = (string) $zip->getFromName('[Content_Types].xml');
        $chartNumber = 0;

        foreach ($sheets as $entry) {
            // OpenSpout numbers its parts from 1 in sheet order.
            $id = $entry['sheet'] + 1;
            $sheetPath = 'xl/worksheets/sheet'.$id.'.xml';
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                continue;
            }

            if ($entry['breaks'] !== []) {
                $sheetXml = self::beforeDrawings($sheetXml, self::rowBreaks($entry['breaks']));
            }

            if ($entry['charts'] !== []) {
                $drawingPath = 'xl/drawings/drawing'.$id.'.xml';
                $drawingRelsPath = 'xl/drawings/_rels/drawing'.$id.'.xml.rels';
                $drawing = $zip->getFromName($drawingPath);
                $drawingRels = $zip->getFromName($drawingRelsPath);

                if ($drawing === false || $drawingRels === false) {
                    // No seal on this sheet, so no drawing part yet: start one
                    // and point the sheet at it.
                    $drawing = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                        .'<xdr:wsDr xmlns:xdr="'.self::NS_DRAWING.'" xmlns:a="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'"></xdr:wsDr>';
                    $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                        .'<Relationships xmlns="'.self::NS_PACKAGE_REL.'"></Relationships>';

                    $sheetXml = self::insertBefore($sheetXml, '<legacyDrawing', '<drawing r:id="rIdDrawing1"/>');
                    $sheetRelsPath = 'xl/worksheets/_rels/sheet'.$id.'.xml.rels';
                    $sheetRels = (string) $zip->getFromName($sheetRelsPath);
                    $sheetRels = self::insertBefore($sheetRels, '</Relationships>',
                        '<Relationship Id="rIdDrawing1" Type="'.self::NS_REL.'/drawing" Target="../drawings/drawing'.$id.'.xml"/>');
                    $zip->addFromString($sheetRelsPath, $sheetRels);
                    $types = self::insertBefore($types, '</Types>',
                        '<Override ContentType="application/vnd.openxmlformats-officedocument.drawing+xml" PartName="/'.$drawingPath.'"/>');
                }

                foreach ($entry['charts'] as $index => $chart) {
                    $chartNumber++;
                    $relId = 'rIdChart'.($index + 1);

                    $drawing = self::insertBefore($drawing, '</xdr:wsDr>', self::anchor($chart, $relId, 1000 + $index));
                    $drawingRels = self::insertBefore($drawingRels, '</Relationships>',
                        '<Relationship Id="'.$relId.'" Type="'.self::NS_REL.'/chart" Target="../charts/chart'.$chartNumber.'.xml"/>');
                    $zip->addFromString('xl/charts/chart'.$chartNumber.'.xml', self::chartXml($chart));
                    $types = self::insertBefore($types, '</Types>',
                        '<Override ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml" PartName="/xl/charts/chart'.$chartNumber.'.xml"/>');
                }

                $zip->addFromString($drawingPath, $drawing);
                $zip->addFromString($drawingRelsPath, $drawingRels);
            }

            $zip->addFromString($sheetPath, $sheetXml);
        }

        $zip->addFromString('[Content_Types].xml', $types);
        $zip->close();
    }

    /**
     * The chart's frame, stretched over the rows reserved for it and the full
     * width of the form.
     *
     * @param  array<string, mixed>  $chart
     */
    private static function anchor(array $chart, string $relId, int $shapeId): string
    {
        return '<xdr:twoCellAnchor editAs="oneCell">'
            .'<xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$chart['fromRow'].'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            .'<xdr:to><xdr:col>'.$chart['toColumn'].'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$chart['toRow'].'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            .'<xdr:graphicFrame macro="">'
            .'<xdr:nvGraphicFramePr><xdr:cNvPr id="'.$shapeId.'" name="'.self::e($chart['title']).' chart"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            .'<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
            .'<a:graphic><a:graphicData uri="'.self::NS_CHART.'">'
            .'<c:chart xmlns:c="'.self::NS_CHART.'" r:id="'.$relId.'"/>'
            .'</a:graphicData></a:graphic>'
            .'</xdr:graphicFrame>'
            .'<xdr:clientData/>'
            .'</xdr:twoCellAnchor>';
    }

    /**
     * One grid's clustered column chart: the eleven columns of the grid as
     * categories, MALE / FEMALE / TOTAL as series, each column's value over
     * it (a zero prints nothing, as on the form), the axis stepped as the
     * form steps it (BmiAssessmentReport::axisScale), legend below.
     *
     * @param  array<string, mixed>  $chart
     */
    public static function chartXml(array $chart): string
    {
        $sheet = "'".str_replace("'", "''", (string) $chart['sheetName'])."'";
        $labels = array_values($chart['labels']);
        $count = count($labels);
        $lastColumn = self::column($count); // B + 10 = L
        $catRef = $sheet.'!$B$'.$chart['labelRow'].':$'.$lastColumn.'$'.$chart['labelRow'];

        $strCache = static function (array $values): string {
            $xml = '<c:strCache><c:ptCount val="'.count($values).'"/>';
            foreach (array_values($values) as $i => $value) {
                $xml .= '<c:pt idx="'.$i.'"><c:v>'.self::e((string) $value).'</c:v></c:pt>';
            }

            return $xml.'</c:strCache>';
        };

        $series = '';
        foreach ($chart['series'] as $order => $line) {
            $row = $line['row'];
            $numCache = '<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="'.$count.'"/>';
            foreach (array_values($line['values']) as $i => $value) {
                $numCache .= '<c:pt idx="'.$i.'"><c:v>'.(int) $value.'</c:v></c:pt>';
            }
            $numCache .= '</c:numCache>';

            $series .= '<c:ser>'
                .'<c:idx val="'.$order.'"/><c:order val="'.$order.'"/>'
                .'<c:tx><c:strRef><c:f>'.self::e($sheet.'!$A$'.$row).'</c:f>'.$strCache([$line['label']]).'</c:strRef></c:tx>'
                .'<c:spPr><a:solidFill><a:srgbClr val="'.(self::SERIES_COLOURS[$line['key']] ?? '4B5A52').'"/></a:solidFill></c:spPr>'
                .'<c:invertIfNegative val="0"/>'
                .'<c:dLbls>'
                .'<c:numFmt formatCode="0;;;" sourceLinked="0"/>'
                .'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
                .self::textProperties(700, '334155')
                .'<c:dLblPos val="outEnd"/>'
                .'<c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/>'
                .'<c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/>'
                .'</c:dLbls>'
                .'<c:cat><c:strRef><c:f>'.self::e($catRef).'</c:f>'.$strCache($labels).'</c:strRef></c:cat>'
                .'<c:val><c:numRef><c:f>'.self::e($sheet.'!$B$'.$row.':$'.$lastColumn.'$'.$row).'</c:f>'.$numCache.'</c:numRef></c:val>'
                .'</c:ser>';
        }

        $axis = $chart['axis'];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<c:chartSpace xmlns:c="'.self::NS_CHART.'" xmlns:a="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">'
            .'<c:roundedCorners val="0"/>'
            .'<c:chart>'
            .'<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1000" b="1"/></a:pPr>'
            .'<a:r><a:rPr lang="en-US" sz="1000" b="1"><a:solidFill><a:srgbClr val="0F172A"/></a:solidFill><a:latin typeface="Arial"/></a:rPr>'
            .'<a:t>'.self::e((string) $chart['chartTitle']).'</a:t></a:r></a:p></c:rich></c:tx>'
            .'<c:overlay val="0"/></c:title>'
            .'<c:autoTitleDeleted val="0"/>'
            .'<c:plotArea><c:layout/>'
            .'<c:barChart><c:barDir val="col"/><c:grouping val="clustered"/><c:varyColors val="0"/>'
            .$series
            .'<c:gapWidth val="60"/><c:overlap val="0"/>'
            .'<c:axId val="50010"/><c:axId val="50020"/>'
            .'</c:barChart>'
            .'<c:catAx><c:axId val="50010"/><c:scaling><c:orientation val="minMax"/></c:scaling>'
            .'<c:delete val="0"/><c:axPos val="b"/><c:numFmt formatCode="General" sourceLinked="0"/>'
            .'<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            .'<c:spPr><a:ln w="9525"><a:solidFill><a:srgbClr val="94A3B8"/></a:solidFill></a:ln></c:spPr>'
            .self::textProperties(700, '334155')
            .'<c:crossAx val="50020"/><c:crosses val="autoZero"/><c:auto val="1"/>'
            .'<c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
            .'<c:valAx><c:axId val="50020"/>'
            .'<c:scaling><c:orientation val="minMax"/><c:max val="'.(int) $axis['max'].'"/><c:min val="0"/></c:scaling>'
            .'<c:delete val="0"/><c:axPos val="l"/>'
            .'<c:majorGridlines><c:spPr><a:ln w="6350"><a:solidFill><a:srgbClr val="E2E8F0"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>'
            .'<c:numFmt formatCode="0" sourceLinked="0"/>'
            .'<c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/>'
            .'<c:spPr><a:ln><a:noFill/></a:ln></c:spPr>'
            .self::textProperties(700, '334155')
            .'<c:crossAx val="50010"/><c:crosses val="autoZero"/><c:crossBetween val="between"/>'
            .'<c:majorUnit val="'.(int) $axis['step'].'"/></c:valAx>'
            .'<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>'
            .'</c:plotArea>'
            .'<c:legend><c:legendPos val="b"/><c:overlay val="0"/>'.self::textProperties(800, '0F172A').'</c:legend>'
            .'<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/>'
            .'</c:chart>'
            .'<c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln w="9525"><a:solidFill><a:srgbClr val="CBD5E1"/></a:solidFill></a:ln></c:spPr>'
            .'<c:printSettings><c:headerFooter/><c:pageMargins b="0.75" l="0.7" r="0.7" t="0.75" header="0.3" footer="0.3"/><c:pageSetup/></c:printSettings>'
            .'</c:chartSpace>';
    }

    /** Arial at a size in hundredths of a point, in one colour. */
    private static function textProperties(int $size, string $colour): string
    {
        return '<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr>'
            .'<a:defRPr sz="'.$size.'"><a:solidFill><a:srgbClr val="'.$colour.'"/></a:solidFill><a:latin typeface="Arial"/></a:defRPr>'
            .'</a:pPr><a:endParaRPr lang="en-US"/></a:p></c:txPr>';
    }

    /**
     * Manual page breaks after each listed row. Placed with the drawings,
     * which is where the sheet's schema puts them (after the page setup).
     *
     * @param  list<int>  $rows
     */
    private static function rowBreaks(array $rows): string
    {
        $xml = '<rowBreaks count="'.count($rows).'" manualBreakCount="'.count($rows).'">';
        foreach ($rows as $row) {
            $xml .= '<brk id="'.$row.'" max="16383" man="1"/>';
        }

        return $xml.'</rowBreaks>';
    }

    private static function beforeDrawings(string $sheetXml, string $fragment): string
    {
        $at = str_contains($sheetXml, '<drawing ') ? '<drawing ' : '<legacyDrawing';

        return self::insertBefore($sheetXml, $at, $fragment);
    }

    private static function insertBefore(string $xml, string $needle, string $fragment): string
    {
        $at = strrpos($xml, $needle);
        if ($at === false) {
            throw new RuntimeException('Unexpected workbook part: no '.$needle.' to write before.');
        }

        return substr($xml, 0, $at).$fragment.substr($xml, $at);
    }

    /** 1 → B, 11 → L: the sheet column letter for a 0-based index. */
    private static function column(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
