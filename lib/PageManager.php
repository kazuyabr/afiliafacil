<?php
class PageManager
{
    private string $pagesFile;

    public function __construct()
    {
        $this->pagesFile = Config::getDataDir() . '/pages.json';
        if (!file_exists($this->pagesFile)) {
            file_put_contents($this->pagesFile, json_encode([], JSON_PRETTY_PRINT));
        }
    }

    public function list(): array
    {
        return $this->read();
    }

    public function get(int $id): ?array
    {
        foreach ($this->read() as $page) {
            if ($page['id'] === $id) return $page;
        }
        return null;
    }

    public function create(array $data): array
    {
        $pages = $this->read();
        $page = [
            'id' => time() + random_int(1, 9999),
            'user_id' => $data['user_id'] ?? (int)($_SESSION['user_id'] ?? 1),
            'name' => $data['name'] ?? 'Sem nome',
            'slug' => $data['slug'] ?? self::slugify($data['name'] ?? 'sem-nome'),
            'type' => $data['type'] ?? 'landing',
            'status' => $data['status'] ?? 'active',
            'html' => $data['html'] ?? '',
            'source_domain' => $data['source_domain'] ?? '',
            'affiliate_link' => $data['affiliate_link'] ?? '',
            'failed_assets' => $data['failed_assets'] ?? [],
            'domain' => $data['domain'] ?? '',
            'views' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $pages[] = $page;
        $this->write($pages);

        $dir = Config::getPagesDir() . '/' . $page['id'];
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (!empty($page['html'])) {
            file_put_contents($dir . '/index.html', $page['html']);
        }

        return $page;
    }

    public function countByUser(int $userId): int
    {
        return count(array_filter($this->read(), fn($p) => ($p['user_id'] ?? 1) === $userId));
    }

    public function update(int $id, array $data): ?array
    {
        $pages = $this->read();
        foreach ($pages as &$page) {
            if ($page['id'] === $id) {
                $page = array_merge($page, $data, ['updated_at' => date('Y-m-d H:i:s')]);
                $this->write($pages);

                if (isset($data['html'])) {
                    $dir = Config::getPagesDir() . '/' . $id;
                    if (!is_dir($dir)) mkdir($dir, 0777, true);
                    file_put_contents($dir . '/index.html', $data['html']);
                }

                return $page;
            }
        }
        return null;
    }

    public function delete(int $id): bool
    {
        $pages = $this->read();
        $pages = array_filter($pages, fn($p) => $p['id'] !== $id);
        $this->write(array_values($pages));

        $dir = Config::getPagesDir() . '/' . $id;
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
        return true;
    }

    public function incrementViews(int $id): void
    {
        $pages = $this->read();
        foreach ($pages as &$page) {
            if ($page['id'] === $id) {
                $page['views'] = ($page['views'] ?? 0) + 1;
                break;
            }
        }
        $this->write($pages);
    }

    public function getStats(): array
    {
        $pages = $this->read();
        return [
            'total' => count($pages),
            'active' => count(array_filter($pages, fn($p) => $p['status'] === 'active')),
            'draft' => count(array_filter($pages, fn($p) => $p['status'] === 'draft')),
            'total_views' => array_sum(array_column($pages, 'views')),
        ];
    }

    private function read(): array
    {
        if (!file_exists($this->pagesFile)) return [];
        return json_decode(file_get_contents($this->pagesFile), true) ?? [];
    }

    private function write(array $pages): void
    {
        file_put_contents($this->pagesFile, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }
}
