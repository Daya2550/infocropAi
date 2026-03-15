<?php
/**
 * smart_download.php
 * Generates a MERGED Smart Reality Check PDF Advisory Report.
 * Combines the original 10-stage plan with the new reality check data.
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/fpdf.php';

$pdo = get_db();

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}
$user_id = (int)$_SESSION['user_id'];

// ── Load smart_data from session ──────────────────────────────────────────────
$smart = $_SESSION['smart_data'] ?? [];
if (empty($smart['updated_report'])) {
    die('No smart report data found. Please complete the Smart Reality Check first.');
}

// ── Fetch Baseline Data (if available) ────────────────────────────────────────
$base_data = [];
if (!empty($smart['base_report_id'])) {
    $stmt = $pdo->prepare("SELECT report_data FROM farm_reports WHERE id = ?");
    $stmt->execute([$smart['base_report_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $base_data = json_decode($row['report_data'], true) ?? [];
    }
}

// ── Site info ─────────────────────────────────────────────────────────────────
$sys = [];
foreach ($pdo->query("SELECT * FROM settings") as $s) {
    $sys[$s['setting_key']] = $s['setting_value'];
}
$site_name    = $sys['site_name']    ?? 'InfoCrop AI';
$phone = $sys['contact_phone'] ?? '+91 8010094034';
$email = $sys['contact_email'] ?? 'jagadledayanand2550@gmail.com';
$website = $sys['contact_website'] ?? 'infocropai.free.nf';
$contact_info = 'Phone: ' . $phone . ' | Email: ' . $email . ' | Web: ' . $website;

// ── Helper: clean text for FPDF (Latin-1) ────────────────────────────────────
function cp($text) {
    if (!is_string($text)) $text = (string)($text ?? '');
    if ($text === '') return '';

    // Preserve meaningful symbols before stripping
    $text = str_replace(['₹', 'â‚¹'], 'Rs.', $text);
    $text = str_replace(['★', '☆', '⭐'], '*', $text);
    $text = str_replace(['✔', '✓'], '[OK]', $text);
    $text = str_replace(['✖', '✗', '✘'], '[X]', $text);
    $text = str_replace(['→', '➔', '➜', '➡'], '->', $text);
    $text = str_replace(['←'], '<-', $text);
    $text = str_replace(['↑'], '^', $text);
    $text = str_replace(['↓'], 'v', $text);
    $text = str_replace(['•', '●', '◉'], chr(149), $text);
    $text = str_replace(['—', '–'], '-', $text);
    $text = str_replace(["\u{2018}", "\u{2019}", "\u{201A}"], "'", $text);
    $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}"], '"', $text);
    $text = str_replace("\u{2026}", '...', $text);

    // Strip ALL emoji & symbol Unicode blocks comprehensively
    $text = (string) preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text);  // All Supplementary Multilingual Plane emojis
    $text = (string) preg_replace('/[\x{2600}-\x{27BF}]/u', '', $text);    // Misc symbols, Dingbats
    $text = (string) preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $text);    // Variation selectors
    $text = (string) preg_replace('/[\x{200D}]/u', '', $text);             // Zero-width joiner
    $text = (string) preg_replace('/[\x{20E3}]/u', '', $text);             // Combining enclosing keycap
    $text = (string) preg_replace('/[\x{E0020}-\x{E007F}]/u', '', $text);  // Tags block
    $text = (string) preg_replace('/[\x{2300}-\x{23FF}]/u', '', $text);    // Misc technical
    $text = (string) preg_replace('/[\x{2B00}-\x{2BFF}]/u', '', $text);    // Misc symbols & arrows
    $text = (string) preg_replace('/[\x{3000}-\x{303F}]/u', '', $text);    // CJK symbols
    $text = (string) preg_replace('/[\x{25A0}-\x{25FF}]/u', '', $text);    // Geometric shapes
    $text = (string) preg_replace('/[\x{2190}-\x{21FF}]/u', '', $text);    // Arrows

    // Convert to Latin-1 (FPDF encoding)
    if (function_exists('iconv')) {
        $text = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text) ?: $text;
    } else {
        $text = utf8_decode($text);
    }

    // Final safety: strip any remaining non-printable/non-Latin-1 characters
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF\n\r\t]/', '', $text);

    return trim($text);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  PDF CLASS
// ═══════════════════════════════════════════════════════════════════════════════
class SmartPDF extends FPDF {
    public $siteName = '';
    public $contact  = '';
    public $cropLabel = '';

    function Header() {
        $this->SetFillColor(27, 94, 32);
        $this->Rect(0, 0, 210, 22, 'F');
        $this->SetTextColor(255,255,255);
        $this->SetFont('Arial','B',13);
        $this->SetXY(0, 4);
        $this->Cell(0, 8, cp($this->siteName . ' — Cumulative Farm Advisory'), 0, 0, 'C');
        $this->SetFont('Arial','',8);
        $this->SetXY(0, 13);
        $this->Cell(0, 5, cp('Crop: ' . $this->cropLabel . '   |   Last Updated: ' . date('d M Y')), 0, 0, 'C');
        $this->SetTextColor(0,0,0);
        $this->Ln(14);
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(120,120,120);
        $this->Cell(0, 5, cp($this->contact), 0, 0, 'C');
        $this->Ln(4);
        $this->Cell(0, 4, cp('Page ' . $this->PageNo() . ' of {nb}  |  Merged Reality Check  |  ' . $this->siteName), 0, 0, 'C');
        $this->SetTextColor(0,0,0);
    }

    function sectionHead($title, $r=27, $g=94, $b=32) {
        $this->Ln(3);
        $this->SetFillColor($r,$g,$b);
        $this->SetTextColor(255,255,255);
        $this->SetFont('Arial','B',10);
        $this->Cell(0, 8, cp($title), 0, 1, 'L', true);
        $this->SetTextColor(0,0,0);
        $this->Ln(2);
    }

    function segmentRow($key, $val, $shade=false) {
        if ($shade) $this->SetFillColor(245,250,245); else $this->SetFillColor(255,255,255);
        $this->SetFont('Arial','B',9);
        $this->Cell(65, 7, cp($key), 'B', 0, 'L', $shade);
        $this->SetFont('Arial','',9);
        $this->MultiCell(0, 7, cp($val), 'B', 'L', $shade);
    }

    // NbLines helper for MultiCell height calculation
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w==0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = max(1, ($w-2*$this->cMargin)*1000/$this->FontSize);
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i<$nb) {
            $c = $s[$i];
            if($c=="\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if($c==' ') $sep = $i;
            $l += (isset($cw[$c]) ? $cw[$c] : 0);
            if($l>$wmax) {
                if($sep==-1) { if($i==$j) $i++; }
                else $i = $sep+1;
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else $i++;
        }
        return $nl;
    }
}

// ── Table Renderer (Refined) ────────────────────────────────────────────────
function renderPDFTable($pdf, $data) {
    if (empty($data)) return;
    $pdf->SetAutoPageBreak(false);
    $filtered = [];
    $maxCols = 0;
    foreach ($data as $row) {
        $joined = implode('', array_map('trim', $row));
        if ($joined === '' || preg_match('/^[-:|\\s]+$/', $joined)) continue;
        $cleanedRow = array_map('trim', $row);
        $maxCols = max($maxCols, count($cleanedRow));
        $filtered[] = $cleanedRow;
    }
    if (empty($filtered)) { $pdf->SetAutoPageBreak(true, 14); return; }
    
    // Equalize columns (pad with empty string)
    foreach ($filtered as &$row) {
        while (count($row) < $maxCols) {
            $row[] = '';
        }
    }
    unset($row);

    $cols = $maxCols;
    $totalWidth = 182;
    $w = $totalWidth / max(1, $cols);
    
    foreach ($filtered as $idx => $row) {
        $bg = ($idx % 2 === 0);
        if ($idx === 0) {
            $pdf->SetFont('Arial','B',8);
            $pdf->SetFillColor(230,240,230);
        } else {
            $pdf->SetFont('Arial','',8);
            if ($bg) $pdf->SetFillColor(245,252,245); else $pdf->SetFillColor(255,255,255);
        }
        
        // Calculate required height for this row
        $maxH = 6;
        foreach ($row as $cell) {
            $nb = $pdf->NbLines($w, cp($cell));
            $maxH = max($maxH, $nb * 5);
        }

        // Page break if needed
        if ($pdf->GetY() + $maxH > 275) {
            $pdf->AddPage();
            // Optional: Re-draw header row if it's a long table
        }
        
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        foreach ($row as $j => $cell) {
            $pdf->Rect($x, $y, $w, $maxH, 'FD');
            $pdf->MultiCell($w, 5, cp(str_replace('**','',$cell)), 0, 'L');
            $x += $w;
            $pdf->SetXY($x, $y);
        }
        $pdf->Ln($maxH);
    }
    $pdf->SetAutoPageBreak(true, 14);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  BUILD PDF
// ═══════════════════════════════════════════════════════════════════════════════
$pdf = new SmartPDF('P','mm','A4');
$pdf->AliasNbPages();
$pdf->siteName  = $site_name;
$pdf->contact   = $contact_info;
$pdf->cropLabel = ($smart['crop'] ?? 'Unknown') . ' — ' . ($smart['location'] ?? '');
$pdf->SetMargins(14, 28, 14);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

// ── PART 1: ORIGINAL BASELINE PLAN (if exists) ──────────────────────────────
if (!empty($base_data)) {
    $pdf->SetFont('Arial','B',16);
    $pdf->SetTextColor(27,94,32);
    $pdf->Cell(0, 10, cp('PART 1: ORIGINAL BASELINE PLAN'), 0, 1, 'L');
    $pdf->SetTextColor(0,0,0);
    $pdf->Ln(2);

    // Render original stages
    global $stages; // from config.php
    foreach ($stages as $s) {
        $gkey = 'gemini_' . $s['gemini_key'];
        if (!empty($base_data[$gkey])) {
            $pdf->sectionHead($s['num'] . '. ' . $s['title'], 46, 125, 50);
            
            // Show inputs for this stage
            $pdf->SetFont('Arial','B',8); $pdf->Cell(0,5,cp('Your Baseline Inputs:'),0,1);
            $pdf->SetFont('Arial','',8);
            foreach ($s['fields'] as $f) {
                if (!empty($base_data[$f['name']])) {
                    $pdf->Cell(40, 4, cp($f['label'] . ':'), 0, 0);
                    $pdf->Cell(0, 4, cp($base_data[$f['name']]), 0, 1);
                }
            }
            $pdf->Ln(2);

            // Render AI content
            $lines = explode("\n", $base_data[$gkey]);
            $table_data = []; $in_table = false;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') { $pdf->Ln(2); continue; }
                if ($line[0] === '|' && substr_count($line, '|') > 1) {
                    $in_table = true; $table_data[] = explode('|', trim($line, '|')); continue;
                } else if ($in_table) {
                    renderPDFTable($pdf, $table_data); $in_table = false; $table_data = [];
                }
                if (substr($line, 0, 2) === '##') {
                    $pdf->SetFont('Arial','B',9); $pdf->Ln(1);
                    $pdf->Cell(0, 6, cp(ltrim($line,'# ')), 0, 1);
                } else if (preg_match('/^[-*]\s+(.+)/', $line, $mt)) {
                    $pdf->SetX(18); $pdf->Cell(4, 5, chr(149), 0, 0);
                    $pdf->MultiCell(0, 5, cp(str_replace('**','',$mt[1])));
                } else {
                    $pdf->SetFont('Arial', '', 9);
                    $pdf->MultiCell(0, 5, cp(str_replace('**','',$line)));
                }
            }
            if ($in_table) renderPDFTable($pdf, $table_data);
        }
    }
    $pdf->AddPage();
}

// ── PART 2: SMART REALITY CHECK UPDATE ──────────────────────────────────────
$pdf->SetFont('Arial','B',16);
$pdf->SetTextColor(230,81,0);
$pdf->Cell(0, 10, cp('PART 2: SMART REALITY CHECK UPDATE'), 0, 1, 'L');
$pdf->SetTextColor(0,0,0);
$pdf->Ln(2);

$fs = $smart['field_status'] ?? [];
$detected_stage = $smart['detected_stage'] ?? 'Unknown';

$pdf->sectionHead('Current Field Reality (Reported: ' . date('d M Y') . ')', 230, 81, 0);
$i = 0;
$fieldItems = [
    'Detected Stage'       => $detected_stage,
    'Plant Height'         => $fs['plant_height'] ?? 'N/A',
    'Leaf Color'           => $fs['leaf_color'] ?? 'N/A',
    'Crop Condition'       => $fs['crop_condition'] ?? 'N/A',
    'Growth Speed'         => $fs['growth_speed'] ?? 'N/A',
    'Pest / Disease'       => ($fs['pest_visible'] ?? 'None') . ' — ' . ($fs['affected_pct'] ?? '0') . '% affected',
    'Last Fertilizer'      => ($fs['last_fertilizer'] ?? 'N/A') . ' (date: ' . ($fs['fertilizer_date'] ?? '?') . ')',
    'Main Problem / Concern'=> $smart['problem_notes'] ?? 'Not specified',
];
foreach ($fieldItems as $k => $v) { $pdf->segmentRow($k . ':', $v, $i % 2 === 0); $i++; }

$pdf->sectionHead('AI Updated Advisory', 21, 101, 192);
$lines = explode("\n", $smart['updated_report'] ?? '');
$table_data = []; $in_table = false;
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') { $pdf->Ln(2); continue; }
    if ($line[0] === '|' && substr_count($line, '|') > 1) {
        $in_table = true; $table_data[] = explode('|', trim($line, '|')); continue;
    } else if ($in_table) {
        renderPDFTable($pdf, $table_data); $in_table = false; $table_data = [];
    }
    if (substr($line, 0, 3) === '## ') {
        $pdf->Ln(3); $pdf->SetFillColor(21, 101, 192); $pdf->SetTextColor(255,255,255);
        $pdf->SetFont('Arial','B',10); $pdf->Cell(0, 7, cp(ltrim($line, '# ')), 0, 1, 'L', true);
        $pdf->SetTextColor(0,0,0); $pdf->Ln(1);
    } else if (substr($line, 0, 4) === '### ') {
        $pdf->SetFont('Arial','B',9); $pdf->SetTextColor(46,125,50);
        $pdf->Cell(0, 6, cp(ltrim($line,'# ')), 0, 1); $pdf->SetTextColor(0,0,0);
    } else if (preg_match('/^[-*]\s+(.+)/', $line, $m)) {
        $pdf->SetX(18); $pdf->Cell(4, 5, chr(149), 0, 0);
        $pdf->MultiCell(0, 5, cp(str_replace('**','',$m[1])));
    } else {
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, cp(str_replace('**','',$line)));
    }
}
if ($in_table) renderPDFTable($pdf, $table_data);

// ── SAVE & UPDATE RECORD ──────────────────────────────────────────────────────
$farmer_slug = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $smart['farmer_name'] ?? 'Farm'));
$ts = date('Ymd_His');
$filename = $site_name . '_Cumulative_' . $farmer_slug . '_' . $ts . '.pdf';
$save_path = __DIR__ . '/uploads/reports/' . $filename;

if (!is_dir(__DIR__ . '/uploads/reports/')) @mkdir(__DIR__ . '/uploads/reports/', 0755, true);
$pdf->Output('F', $save_path);

// Update existing farm_reports record with the filename
if (!empty($smart['cumulative_report_id'])) {
    $upd = $pdo->prepare("UPDATE farm_reports SET pdf_filename = ? WHERE id = ? AND user_id = ?");
    $upd->execute([$filename, (int)$smart['cumulative_report_id'], $user_id]);
} else {
    // Fallback: This shouldn't happen if coming from smart_planner.php Step 4
    $combined_data = array_merge($base_data, $smart);
    $json_data = json_encode($combined_data, JSON_UNESCAPED_UNICODE);
    $ins = $pdo->prepare("INSERT INTO farm_reports (user_id, farmer_name, crop, location, season, land_area, pdf_filename, report_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $user_id, $smart['farmer_name'] ?? 'N/A', $smart['crop'] ?? 'N/A',
        $smart['location'] ?? 'N/A', $smart['season'] ?? 'N/A',
        $smart['land_area'] ?? 'N/A', $filename, $json_data
    ]);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
readfile($save_path);
exit;
