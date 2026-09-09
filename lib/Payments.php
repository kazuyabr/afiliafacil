<?php
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
        return json_decode(file_get_contents($this->paymentsFile), true) ?? [];
    }

    public function get(int $id): ?array
    {
        foreach ($this->all() as $p) {
            if ($p['id'] === $id) return $p;
        }
        return null;
    }

    public function create(array $data): array
    {
        $payments = $this->all();
        $payment = [
            'id' => time() + random_int(1, 9999),
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
        $payments[] = $payment;
        $this->write($payments);
        return $payment;
    }

    public function approve(int $id): ?array
    {
        $payments = $this->all();
        foreach ($payments as &$p) {
            if ($p['id'] === $id) {
                $p['status'] = 'paid';
                $p['paid_at'] = date('Y-m-d H:i:s');
                $this->write($payments);

                Auth::setPlan($p['user_id'], $p['plan_id']);
                return $p;
            }
        }
        return null;
    }

    public function reject(int $id): ?array
    {
        $payments = $this->all();
        foreach ($payments as &$p) {
            if ($p['id'] === $id) {
                $p['status'] = 'rejected';
                $this->write($payments);
                return $p;
            }
        }
        return null;
    }

    public function listPending(): array
    {
        return array_filter($this->all(), fn($p) => $p['status'] === 'pending');
    }

    public function listByUser(int $userId): array
    {
        return array_filter($this->all(), fn($p) => $p['user_id'] === $userId);
    }

    private function write(array $payments): void
    {
        file_put_contents($this->paymentsFile, json_encode($payments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
