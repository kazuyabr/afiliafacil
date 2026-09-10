<?php

require_once __DIR__ . '/Database.php';

class Payments
{
    private string $paymentsFile;

    public function __construct()
    {
        $this->paymentsFile = Config::getDataDir() . '/payments.json';
        if (!file_exists($this->paymentsFile)) {
            file_put_contents($this->paymentsFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function all(): array
    {
        if (Database::available()) {
            return \AfiliaFacil\Models\Payment::orderBy('created_at', 'asc')->get()
                ->map(fn($p) => $this->toArray($p))
                ->all();
        }
        return json_decode(file_get_contents($this->paymentsFile), true) ?? [];
    }

    public function get(int $id): ?array
    {
        if (Database::available()) {
            $payment = \AfiliaFacil\Models\Payment::find($id);
            return $payment ? $this->toArray($payment) : null;
        }
        foreach ($this->all() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }

    public function create(array $data): array
    {
        $payment = [
            'id' => $data['id'] ?? (time() + random_int(1, 9999)),
            'user_id' => $data['user_id'],
            'user_email' => $data['user_email'] ?? '',
            'plan_id' => $data['plan_id'],
            'cycle' => $data['cycle'] ?? 'monthly',
            'amount' => $data['amount'],
            'gateway' => $data['gateway'] ?? 'pix',
            'status' => $data['status'] ?? 'pending',
            'reference' => $data['reference'] ?? '',
            'payload' => $data['payload'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'paid_at' => null,
        ];

        if (Database::available()) {
            \AfiliaFacil\Models\Payment::create($payment);
        } else {
            $payments = $this->all();
            $payments[] = $payment;
            $this->write($payments);
        }

        return $payment;
    }

    public function setPayload(int $id, string $payload): void
    {
        if (Database::available()) {
            $payment = \AfiliaFacil\Models\Payment::find($id);
            if ($payment) {
                $payment->payload = $payload;
                $payment->save();
            }
            return;
        }
        $payments = $this->all();
        foreach ($payments as &$p) {
            if ($p['id'] === $id) { $p['payload'] = $payload; break; }
        }
        unset($p);
        $this->write($payments);
    }

    public function approve(int $id): ?array
    {
        $payment = $this->get($id);
        if (!$payment) return null;

        $paidAt = date('Y-m-d H:i:s');

        if (Database::available()) {
            $model = \AfiliaFacil\Models\Payment::find($id);
            $model->status = 'paid';
            $model->paid_at = $paidAt;
            $model->save();
        } else {
            $payments = $this->all();
            foreach ($payments as &$p) {
                if ($p['id'] === $id) { $p['status'] = 'paid'; $p['paid_at'] = $paidAt; break; }
            }
            unset($p);
            $this->write($payments);
        }

        Auth::setPlan((int)$payment['user_id'], $payment['plan_id']);

        $payment['status'] = 'paid';
        $payment['paid_at'] = $paidAt;
        return $payment;
    }

    public function reject(int $id): ?array
    {
        $payment = $this->get($id);
        if (!$payment) return null;

        if (Database::available()) {
            $model = \AfiliaFacil\Models\Payment::find($id);
            $model->status = 'rejected';
            $model->save();
        } else {
            $payments = $this->all();
            foreach ($payments as &$p) {
                if ($p['id'] === $id) { $p['status'] = 'rejected'; break; }
            }
            unset($p);
            $this->write($payments);
        }

        $payment['status'] = 'rejected';
        return $payment;
    }

    public function listPending(): array
    {
        return array_values(array_filter($this->all(), fn($p) => $p['status'] === 'pending'));
    }

    public function listByUser(int $userId): array
    {
        return array_values(array_filter($this->all(), fn($p) => $p['user_id'] === $userId));
    }

    private function toArray($model): array
    {
        return [
            'id' => (int)$model->id,
            'user_id' => (int)$model->user_id,
            'user_email' => $model->user_email,
            'plan_id' => $model->plan_id,
            'cycle' => $model->cycle,
            'amount' => (int)$model->amount,
            'gateway' => $model->gateway,
            'status' => $model->status,
            'reference' => $model->reference,
            'payload' => $model->payload ?? '',
            'created_at' => (string)$model->created_at,
            'paid_at' => $model->paid_at ? (string)$model->paid_at : null,
        ];
    }

    private function write(array $payments): void
    {
        file_put_contents($this->paymentsFile, json_encode($payments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
