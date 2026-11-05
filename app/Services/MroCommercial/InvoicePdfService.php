<?php
namespace App\Services\MroCommercial;
use Dompdf\{Dompdf,Options};
final class InvoicePdfService {
 public function render(string $view,array $data):string {
  $options=new Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$options->set('defaultFont','DejaVu Sans');$options->setChroot(resource_path('views'));
  $pdf=new Dompdf($options);$pdf->setPaper('A4');$pdf->loadHtml(view($view,$data)->render(),'UTF-8');$pdf->render();$font=$pdf->getFontMetrics()->getFont('DejaVu Sans');$pdf->getCanvas()->page_text(500,815,'{PAGE_NUM} / {PAGE_COUNT}',$font,8,[.4,.45,.5]);return $pdf->output();
 }
 public static function money(int $minor):string{return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
}
