<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MroCommercial\InvoiceService;
use App\Services\MroCommercial\LegalDocumentService;
use App\Services\MroCommercial\PlanCatalogService;
use App\Services\MroCommercial\PortalAudit;
use App\Services\MroCommercial\SubscriptionCheckoutService;
use App\Services\MroCommercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MroCommercialController extends Controller
{
    private function company(Request $r): int
    {
        $user = $r->user();
        abort_unless($user && $user->is_active && $user->email_verified_at, 403);
        $id = (int) $r->session()->get('toir_company_id');
        abort_unless($id && $user->activeCompanies()->whereKey($id)->exists(), 403);

        return $id;
    }

    private function billingManager(Request $r): int
    {
        $id = $this->company($r);
        abort_unless($r->user()->activeCompanies()->whereKey($id)
            ->wherePivotIn('role', ['owner', 'admin'])->exists(), 403);

        return $id;
    }

    public function plans()
    {
        return response()->json(['data' => app(PlanCatalogService::class)->catalog(), 'payment_collection_enabled' => false, 'registration_enabled' => (bool) config('mro_commercial.public_registration_enabled')]);
    }

    public function subscription(Request $r)
    {
        $id = $this->company($r);

        return response()->json(['data' => app(SubscriptionService::class)->get($id), 'enforcement_enabled' => (bool) config('mro_commercial.subscription_enforcement_enabled'), 'billing_enabled' => (bool) config('mro_commercial.billing_enabled'), 'invoice_issuance_enabled' => (bool) config('mro_commercial.invoice_issuance_enabled'), 'can_manage_billing' => ($r->user()->activeCompanies()->whereKey($id)->wherePivotIn('role', ['owner', 'admin'])->exists()), 'can_generate_invoice' => SubscriptionCheckoutService::ready()]);
    }

    public function invoices(Request $r)
    {
        $company = $this->billingManager($r);

        return response()->json(DB::table('mro_invoices')->where('company_id', $company)->select('id', 'number', 'status', 'currency', 'total_minor', 'due_date', 'pdf_sha256', 'created_at')->latest('id')->paginate(25));
    }

    public function createInvoice(Request $r)
    {
        $company = $this->billingManager($r);
        $d = $r->validate(['plan_version_id' => 'required|integer', 'months' => 'required|integer|min:1|max:120', 'starts_on' => 'required|date_format:Y-m-d|after_or_equal:today', 'request_id' => 'required|uuid', 'legal_choices' => 'present|array', 'legal_choices.*' => 'boolean']);
        app(LegalDocumentService::class)->accept($r->user(), $d['legal_choices'], 'invoice', 'ru', 'KZ', $company);
        $i = app(SubscriptionCheckoutService::class)->generate($company, $r->user()->id, $d);

        return response()->json(['data' => ['id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'currency' => $i->currency, 'total_minor' => $i->total_minor, 'pdf_sha256' => $i->pdf_sha256, 'due_date' => $i->due_date]], 201);
    }

    public function invoicePdf(Request $r, int $invoice)
    {
        $company = $this->billingManager($r);
        abort_unless(DB::table('mro_invoices')->where('company_id', $company)->where('id', $invoice)->exists(), 404);

        return response(app(InvoiceService::class)->pdf($invoice))->header('Content-Type', 'application/pdf')->header('Cache-Control', 'private, no-store');
    }

    public function proof(Request $r, int $invoice)
    {
        $company = $this->billingManager($r);
        $i = DB::table('mro_invoices')->where('company_id', $company)->where('id', $invoice)->first();
        abort_unless($i, 404);
        abort_unless(config('mro_commercial.billing_enabled'), 409, 'Billing is disabled');
        abort_unless(in_array($i->status, ['issued', 'awaiting_payment', 'overdue'], true), 409);
        $r->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $f = $r->file('file');
        $bytes = file_get_contents($f->getRealPath());
        $path = 'mro-billing/'.$company.'/proofs/'.Str::uuid().'.'.$f->extension();
        abort_unless(Storage::disk('local')->put($path, $bytes), 500);
        try {
            DB::transaction(function () use ($invoice, $r, $path, $bytes, $f, $company) {
                $i = DB::table('mro_invoices')->where('id', $invoice)->lockForUpdate()->first();
                abort_unless(in_array($i->status, ['issued', 'awaiting_payment', 'overdue'], true), 409);
                DB::table('mro_payment_proofs')->insert(['invoice_id' => $invoice, 'user_id' => $r->user()->id, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'mime' => $f->getMimeType(), 'created_at' => now(), 'updated_at' => now()]);
                PortalAudit::record($r->user()->id, $company, 'payment.proof.uploaded', "invoice:$invoice");
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['status' => 'pending'], 201);
    }

    public function documents(Request $r)
    {
        return response()->json(['data' => app(LegalDocumentService::class)->current($r->query('locale', 'ru'), $r->query('jurisdiction', 'KZ'))]);
    }

    public function document(Request $r, int $version)
    {
        $v = app(LegalDocumentService::class)->find($version);
        abort_unless($v->status === 'published' && $v->effective_at && now()->greaterThanOrEqualTo($v->effective_at), 404);
        unset($v->pdf_path,$v->approval_note,$v->created_by);

        return response()->json(['data' => $v]);
    }

    public function legalPdf(Request $r, int $version)
    {
        $v = app(LegalDocumentService::class)->find($version);
        abort_unless($v->status === 'published' && $v->effective_at && now()->greaterThanOrEqualTo($v->effective_at), 404);

        return response(app(LegalDocumentService::class)->pdf($version))->header('Content-Type', 'application/pdf')->header('Cache-Control', 'no-store');
    }

    public function accept(Request $r)
    {
        $this->company($r);
        $d = $r->validate(['choices' => 'required|array', 'choices.*' => 'boolean', 'locale' => 'required|string|max:8', 'jurisdiction' => 'required|string|max:8']);
        app(LegalDocumentService::class)->accept($r->user(), $d['choices'], 'settings', $d['locale'], $d['jurisdiction'], $this->company($r));

        return response()->json(['status' => 'recorded']);
    }

    public function ownConsents(Request $r)
    {
        $this->company($r);

        return response()->json(['data' => DB::table('mro_legal_consents as c')->join('mro_legal_versions as v', 'v.id', '=', 'c.legal_version_id')->where('c.user_id', $r->user()->id)->where('c.company_id', $this->company($r))->select('c.id', 'c.legal_version_id', 'c.sha256', 'c.action', 'c.locale', 'c.created_at', 'v.title', 'v.version', 'v.type')->latest('c.id')->get()]);
    }
}
