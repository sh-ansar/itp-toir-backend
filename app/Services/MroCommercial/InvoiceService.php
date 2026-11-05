<?php

namespace App\Services\MroCommercial;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class InvoiceService
{
    public function profile(?int $company = null): array
    {
        $p = DB::table('mro_billing_profiles')->where('key', $company ? 'company:'.$company : 'supplier')->first();

        return $p ? json_decode($p->details, true) : [];
    }

    public function saveProfile(array $input, ?int $company, int $actor): array
    {
        $d = Validator::make($input, [
            'legal_name' => 'required|string|max:250', 'registration_number' => 'required|string|max:80', 'address' => 'required|string|max:500', 'bank' => 'required|string|max:250', 'iban' => 'required|string|max:80', 'bic' => 'required|string|max:80', 'kbe' => 'nullable|string|max:20', 'payment_code' => 'nullable|string|max:30', 'signatory' => 'required|string|max:250', 'currency' => ['required', Rule::in(['KZT', 'GEL', 'USD', 'EUR'])], 'tax_basis_points' => 'required|integer|min:0|max:10000', 'tax_label' => 'required|string|max:120', 'valid_days' => 'required|integer|min:1|max:365', 'prefix' => ['required', 'regex:/^[A-Z0-9-]{1,16}$/'], 'service_name' => 'required|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($d, $company, $actor) {
            $old = $this->profile($company);
            $d = array_merge(array_intersect_key($old, array_flip(['logo_asset', 'stamp_asset'])), $d);
            DB::table('mro_billing_profiles')->updateOrInsert(['key' => $company ? 'company:'.$company : 'supplier'], ['company_id' => $company, 'details' => json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            PortalAudit::record($actor, $company, 'billing.profile.updated', $company ? 'company:'.$company : 'supplier', ['previous' => $old, 'current' => $d]);

            return $d;
        });
    }

    public function create(array $input, int $actor): object
    {
        $d = Validator::make($input, ['company_id' => 'required|integer|exists:companies,id', 'items' => 'required|array|min:1|max:100', 'items.*.description' => 'required|string|max:500', 'items.*.quantity' => 'required|integer|min:1|max:10000', 'items.*.unit_minor' => 'required|integer|min:0|max:100000000000', 'period_from' => 'required|date_format:Y-m-d', 'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'basis' => 'required|string|max:500', 'replaces_id' => 'nullable|integer', 'tax_inclusive' => 'sometimes|boolean'])->validate();
        $seller = $this->profile();
        $buyer = $this->profile((int) $d['company_id']);
        abort_unless($seller, 422, 'Complete supplier billing profile first');
        if (! $buyer) {
            $buyer = ['legal_name' => 'Личный кабинет №'.$d['company_id']];
        }foreach ([$seller] as $profile) {
            foreach (['legal_name', 'registration_number', 'address', 'bank', 'iban', 'bic', 'signatory', 'currency', 'tax_label', 'prefix', 'service_name'] as $key) {
                abort_unless(isset($profile[$key]) && $profile[$key] !== '', 422, 'Complete billing profile: '.$key);
            }
        }
        $subtotal = 0;
        foreach ($d['items'] as &$item) {
            $item['quantity'] = (int) $item['quantity'];
            $item['unit_minor'] = (int) $item['unit_minor'];
            $item['total_minor'] = $item['quantity'] * $item['unit_minor'];
            $subtotal += $item['total_minor'];
        }unset($item);
        abort_if($subtotal > 1000000000000000, 422, 'Invoice amount too large');
        $rate = (int) $seller['tax_basis_points'];
        $tax = intdiv($subtotal, 10000) * $rate + intdiv(($subtotal % 10000) * $rate + 5000, 10000);
        $total = $subtotal + $tax;
        if (! empty($d['tax_inclusive'])) {
            $total = $subtotal;
            $tax = intdiv($total, 10000 + $rate) * $rate + intdiv(($total % (10000 + $rate)) * $rate + intdiv(10000 + $rate, 2), 10000 + $rate);
            $subtotal = $total - $tax;
        }
        $seller = $this->withImages($seller);
        $s = ['seller' => $seller, 'buyer' => $buyer, 'items' => $d['items'], 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'basis' => $d['basis'], 'subscription' => app(SubscriptionService::class)->get($d['company_id'])?->snapshot, 'tax_basis_points' => (int) $seller['tax_basis_points'], 'tax_inclusive' => (bool) ($d['tax_inclusive'] ?? false), 'amount_words' => $this->amountWords($total, $seller['currency'])];

        return DB::transaction(function () use ($d, $s, $actor, $subtotal, $tax, $total, $seller) {
            if (! empty($d['replaces_id'])) {
                $old = DB::table('mro_invoices')->where('id', $d['replaces_id'])->where('company_id', $d['company_id'])->first();
                abort_unless($old && in_array($old->status, ['cancelled', 'replaced'], true), 422, 'Cancel original invoice first');
            }
            $id = DB::table('mro_invoices')->insertGetId(['company_id' => $d['company_id'], 'snapshot' => json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'currency' => $seller['currency'], 'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $total, 'due_date' => $d['due_date'], 'replaces_id' => $d['replaces_id'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            PortalAudit::record($actor, $d['company_id'], 'invoice.draft', "invoice:$id");

            return $this->find($id);
        });
    }

    public function find(int $id): object
    {
        $i = DB::table('mro_invoices')->where('id', $id)->first();
        abort_unless($i, 404);
        $i->snapshot = json_decode($i->snapshot, true);

        return $i;
    }

    public function preview(int $id): string
    {
        return app(InvoicePdfService::class)->render('billing.invoice', ['invoice' => $this->find($id), 'draft' => true]);
    }

    public function issue(int $id, int $actor): object
    {
        abort_unless(config('mro_commercial.invoice_issuance_enabled'), 409, 'Invoice issuance is disabled');
        $createdPath = null;
        try {
            return DB::transaction(function () use ($id, $actor, &$createdPath) {
                $row = DB::table('mro_invoices')->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                abort_unless($row->status === 'draft', 409, 'Invoice already issued');
                $i = $this->find($id);
                $key = 'invoices';
                DB::table('mro_invoice_sequences')->insertOrIgnore(['key' => $key, 'value' => 0]);
                $seq = DB::table('mro_invoice_sequences')->where('key', $key)->lockForUpdate()->first();
                $next = (int) $seq->value + 1;
                $i->number = $i->snapshot['seller']['prefix'].'-'.str_pad((string) $next, 7, '0', STR_PAD_LEFT);
                $i->issued_at = now()->toDateTimeString();
                $bytes = app(InvoicePdfService::class)->render('billing.invoice', ['invoice' => $i, 'draft' => false]);
                $createdPath = 'mro-billing/'.$i->company_id.'/invoices/'.Str::uuid().'.pdf';
                abort_unless(Storage::disk('local')->put($createdPath, $bytes), 500);
                DB::table('mro_invoice_sequences')->where('key', $key)->update(['value' => $next]);
                DB::table('mro_invoices')->where('id', $id)->update(['status' => 'issued', 'number' => $i->number, 'issued_at' => $i->issued_at, 'pdf_path' => $createdPath, 'pdf_sha256' => hash('sha256', $bytes), 'pdf_size' => strlen($bytes), 'updated_at' => now()]);
                if ($i->replaces_id) {
                    DB::table('mro_invoices')->where('id', $i->replaces_id)->update(['status' => 'replaced', 'updated_at' => now()]);
                }
                PortalAudit::record($actor, $i->company_id, 'invoice.issued', "invoice:$id", ['number' => $i->number, 'sha256' => hash('sha256', $bytes)]);

                return $this->find($id);
            });
        } catch (\Throwable $e) {
            if ($createdPath) {
                Storage::disk('local')->delete($createdPath);
            }throw $e;
        }
    }

    public function pdf(int $id): string
    {
        $i = $this->find($id);
        abort_unless($i->pdf_path && Storage::disk('local')->exists($i->pdf_path), 404);
        $bytes = Storage::disk('local')->get($i->pdf_path);
        abort_unless(hash_equals($i->pdf_sha256, hash('sha256', $bytes)), 409, 'Document integrity check failed');

        return $bytes;
    }

    public function transition(int $id, string $status, int $actor): object
    {
        return DB::transaction(function () use ($id, $status, $actor) {
            $i = DB::table('mro_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($i, 404);
            $allowed = ['issued' => ['awaiting_payment', 'cancelled'], 'awaiting_payment' => ['cancelled', 'overdue'], 'overdue' => ['cancelled']];
            abort_unless(in_array($status, $allowed[$i->status] ?? [], true), 409, 'Invalid invoice state');
            DB::table('mro_invoices')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            PortalAudit::record($actor, $i->company_id, 'invoice.'.$status, "invoice:$id");

            return $this->find($id);
        });
    }

    public function asset(UploadedFile $file, string $kind, int $actor): array
    {
        abort_unless(in_array($kind, ['logo', 'stamp'], true), 422);
        $size = @getimagesize($file->getRealPath());
        abort_unless($size && $size[0] <= 2400 && $size[1] <= 2400, 422, 'Image dimensions exceed 2400 pixels');
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        abort_unless($image, 422, 'Image cannot be decoded');
        try {
            abort_if(imagesx($image) > 2400 || imagesy($image) > 2400, 422, 'Image dimensions exceed 2400 pixels');
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
        } finally {
            imagedestroy($image);
        }
        $path = 'mro-billing/assets/'.Str::uuid().'.png';
        abort_unless(Storage::disk('local')->put($path, $bytes), 500);
        try {
            return DB::transaction(function () use ($path, $bytes, $kind, $actor) {
                DB::table('mro_billing_profiles')->insertOrIgnore(['key' => 'supplier', 'company_id' => null, 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('mro_billing_profiles')->where('key', 'supplier')->lockForUpdate()->first();
                $profile = json_decode($row->details, true);
                $profile[$kind.'_asset'] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
                DB::table('mro_billing_profiles')->where('id', $row->id)->update(['details' => json_encode($profile, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                PortalAudit::record($actor, null, 'billing.asset.updated', 'supplier', ['kind' => $kind, 'sha256' => hash('sha256', $bytes)]);

                return ['kind' => $kind, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    private function withImages(array $profile): array
    {
        foreach (['logo', 'stamp'] as $kind) {
            $asset = $profile[$kind.'_asset'] ?? null;
            if (! $asset) {
                continue;
            }$bytes = Storage::disk('local')->get($asset['path']);
            abort_unless(hash_equals($asset['sha256'], hash('sha256', $bytes)), 409, 'Billing image integrity check failed');
            $profile[$kind.'_data'] = 'data:image/png;base64,'.base64_encode($bytes);
            unset($profile[$kind.'_asset']);
        }

        return $profile;
    }

    private function amountWords(int $minor, string $currency): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter('ru', \NumberFormatter::SPELLOUT);

            return $f->format(intdiv($minor, 100)).' '.$currency.' '.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT).'/100';
        }
        // Never silently issue an invoice with missing required written amount.
        throw new \RuntimeException('PHP intl is required for invoice amounts in words');
    }
}
